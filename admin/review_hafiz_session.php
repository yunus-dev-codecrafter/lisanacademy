<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('teaching.php');
}

csrf_verify();

$session_id = (int)($_POST['session_id'] ?? 0);
$status = $_POST['status'] ?? '';
$rating = trim($_POST['rating'] ?? '');
$feedback = trim($_POST['feedback'] ?? '');

if ($session_id <= 0 || !in_array($status, ['accepted', 'rejected'], true)) {
    redirect('teaching.php');
}

if (!db_table_exists($conn, 'hafiz_sessions') || !db_table_exists($conn, 'hafiz_revision')) {
    redirect('teaching.php');
}

// Fetch the session
$stmt = $conn->prepare("SELECT * FROM hafiz_sessions WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if (!$session) {
    redirect('teaching.php');
}

$revision_id = (int)$session['revision_id'];
$student_id = (int)$session['student_id'];
$page_no = (int)$session['page_no'];

// Handle admin audio feedback upload
$admin_audio = null;
if (isset($_FILES['admin_audio']) && $_FILES['admin_audio']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['webm', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'aac'];
    $ext = strtolower(pathinfo($_FILES['admin_audio']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) $ext = 'mp3';

    $filename = 'feedback_hafiz_' . $session_id . '_' . time() . '.' . $ext;
    $upload_dir = dirname(__DIR__) . '/uploads/admin_feedback/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    if (move_uploaded_file($_FILES['admin_audio']['tmp_name'], $upload_dir . $filename)) {
        $admin_audio = $filename;
    }
}

// Update the session
$now = date('Y-m-d H:i:s');
if ($admin_audio) {
    $stmt = $conn->prepare("
        UPDATE hafiz_sessions SET status = ?, rating = ?, feedback = ?, admin_audio_feedback = ?, reviewed_at = ?
        WHERE id = ?
    ");
    $stmt->bind_param("sssssi", $status, $rating, $feedback, $admin_audio, $now, $session_id);
} else {
    $stmt = $conn->prepare("
        UPDATE hafiz_sessions SET status = ?, rating = ?, feedback = ?, reviewed_at = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssssi", $status, $rating, $feedback, $now, $session_id);
}
$stmt->execute();

// If accepted, keep the weekly log up to date
if ($status === 'accepted') {
    // Update weekly log
    if (db_table_exists($conn, 'hafiz_weekly_log')) {
        $revision = null;
        $rev_stmt = $conn->prepare("SELECT * FROM hafiz_revision WHERE id = ?");
        $rev_stmt->bind_param("i", $revision_id);
        $rev_stmt->execute();
        $revision = $rev_stmt->get_result()->fetch_assoc();

        if ($revision) {
            $week_no = hafiz_current_week_no($revision);
            $pages = hafiz_pages_this_week($conn, $revision_id, $week_no);
            $target_met = $pages >= 24 ? 1 : 0;

            $stmt = $conn->prepare("
                INSERT INTO hafiz_weekly_log (revision_id, student_id, week_no, pages_completed, target_met, week_started_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    pages_completed = VALUES(pages_completed),
                    target_met = VALUES(target_met)
            ");
            $week_started = $revision['week_started_at'];
            $stmt->bind_param("iiiiis", $revision_id, $student_id, $week_no, $pages, $target_met, $week_started);
            $stmt->execute();
        }
    }
}

redirect('teaching.php');
