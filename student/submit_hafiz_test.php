<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('student');
$student_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('hafiz_test.php');
}

csrf_verify();

if (!student_is_hafiz($conn, $student_id)) {
    echo 'You are not designated as a Hafiz student.';
    exit;
}

if (!db_table_exists($conn, 'hafiz_revision') || !db_table_exists($conn, 'hafiz_weekly_tests') || !db_table_exists($conn, 'hafiz_test_answers')) {
    echo 'The weekly test system is not set up yet.';
    exit;
}

$revision = hafiz_get_active_revision($conn, $student_id);
if (!$revision) {
    echo 'No active revision cycle.';
    exit;
}

// Resolve the current week's test (must be an in-progress draft)
$test = hafiz_get_latest_test($conn, (int)$revision['id'], hafiz_test_juz($conn, $revision));
if (!$test || $test['status'] !== 'draft' || (int)$test['student_id'] !== $student_id) {
    echo 'No active weekly test found.';
    exit;
}

// Enforce the deadline: time limit + grace period from started_at
$deadline = hafiz_test_deadline($test['started_at']);
if (strtotime($deadline) < time()) {
    try {
        $stmt = $conn->prepare("UPDATE hafiz_weekly_tests SET status = 'expired' WHERE id = ?");
        $stmt->bind_param("i", (int)$test['id']);
        $stmt->execute();
    } catch (Throwable $e) { /* ignore */ }
    echo 'Time has expired for this test. Please generate a new test to start over.';
    exit;
}

// Validate submitted answer ids + audio files
$answer_ids = $_POST['answer_id'] ?? [];
$audios = $_FILES['audio'] ?? null;
if (!is_array($answer_ids) || !$audios || !isset($audios['name']) || !is_array($audios['name'])) {
    echo 'No answers submitted.';
    exit;
}

$test_id = (int)$test['id'];

// Fetch this test's pending questions
$stmt = $conn->prepare("SELECT * FROM hafiz_test_answers WHERE test_id = ? AND status = 'pending' ORDER BY id ASC");
$stmt->bind_param("i", $test_id);
$stmt->execute();
$pending = [];
$by_id = [];
$rows = $stmt->get_result();
while ($r = $rows->fetch_assoc()) {
    $pending[] = $r;
    $by_id[(int)$r['id']] = $r;
}

if (!$pending) {
    echo 'All answers have already been submitted.';
    exit;
}

$upload_dir = dirname(__DIR__) . '/uploads/hafiz_test_audio/';
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$allowed = ['webm', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'aac'];
$saved = [];

foreach ($pending as $i => $q) {
    $aid = (int)$answer_ids[$i] ?? 0;
    if (!isset($by_id[$aid])) {
        echo 'Invalid answer ID.';
        exit;
    }

    if (!isset($audios['error'][$i]) || $audios['error'][$i] !== UPLOAD_ERR_OK) {
        echo 'Audio file not uploaded correctly for Question ' . ($i + 1) . '.';
        exit;
    }

    $ext = strtolower(pathinfo($audios['name'][$i], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) $ext = 'webm';

    $filename = 'htest_' . $test_id . '_' . $aid . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    if (!move_uploaded_file($audios['tmp_name'][$i], $upload_dir . $filename)) {
        // Rollback any files already saved for this batch
        foreach ($saved as $old) { @unlink($upload_dir . $old); }
        echo 'Failed to save audio file for Question ' . ($i + 1) . '.';
        exit;
    }
    $saved[] = $filename;
    $by_id[$aid]['_file'] = $filename;
}

// Update DB rows
$upd = $conn->prepare("UPDATE hafiz_test_answers SET audio_file = ?, status = 'submitted', answered_at = NOW() WHERE id = ?");
foreach ($pending as $q) {
    $qid = (int)$q['id'];
    if (!isset($by_id[$qid]['_file'])) continue;
    $file = $by_id[$qid]['_file'];
    $upd->bind_param("si", $file, $qid);
    if (!$upd->execute()) {
        foreach ($saved as $old) { @unlink($upload_dir . $old); }
        echo 'Failed to record an answer. Please try again.';
        exit;
    }
}

// Mark test as submitted
$stmt = $conn->prepare("UPDATE hafiz_weekly_tests SET status = 'submitted', submitted_at = NOW() WHERE id = ?");
$stmt->bind_param("i", $test_id);
$stmt->execute();

echo 'OK';