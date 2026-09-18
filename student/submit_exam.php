<?php
require '../config/security/helpers.php';
require '../auth/auth_check.php';
require '../config/db.php';
require '../config/audio_fix.php';
require_role('student');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Invalid request');
}

csrf_verify();

$student_id = (int)$_SESSION['user_id'];

/* Exam must be open globally or for this student only */
if (!can_take_exam($conn, $student_id)) {
    exit('Exam is currently closed for you.');
}
if (student_exam_locked($conn, $student_id) && !student_exam_access($conn, $student_id)) {
    exit('Your exam is locked. Contact the academy to pay the N500 fee to reopen it.');
}

/* Self-heal: make sure the columns this flow reads/writes exist. A missing
   column (e.g. exam_answers.audio_file) previously surfaced as a confusing
   HTML "Something went wrong / try again in a moment" page. */
try {
    ensure_exam_submit_schema($conn);
} catch (Throwable $e) {
    error_log('Exam submit schema check failed: ' . $e->getMessage());
}

/* The student must have an in-progress (draft) attempt in the current term */
try {
    $term = exam_term_info($conn);
    $term_id = $term ? (int)$term['id'] : 0;
    $stmt = $conn->prepare("SELECT * FROM exam_attempts WHERE student_id = ? AND term_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ii", $student_id, $term_id);
    $stmt->execute();
    $attempt = $stmt->get_result()->fetch_assoc();
} catch (Throwable $e) {
    error_log('Exam submit failed (draft lookup): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit('Could not load your exam in progress. Please refresh and try again.');
}
if (!$attempt) {
    exit('No exam in progress. Start your exam first.');
}
$attempt_id  = (int)$attempt['id'];
$current_day = (int)$attempt['day_no'];

/* Questions still pending for today only */
try {
    $stmt = $conn->prepare("SELECT * FROM exam_answers WHERE attempt_id = ? AND day_no = ? AND status = 'pending' ORDER BY id ASC");
    $stmt->bind_param("ii", $attempt_id, $current_day);
    $stmt->execute();
    $pending_rows = [];
    $pending_map  = [];
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) {
        $pending_rows[$r['id']] = $r;
        $pending_map[$r['id']] = true;
    }
} catch (Throwable $e) {
    error_log('Exam submit failed (pending lookup): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit('Could not load today\'s questions. Please refresh and try again.');
}
if (count($pending_rows) === 0) {
    exit('Nothing to submit for today.');
}

$answer_ids = $_POST['answer_id'] ?? [];
$has_audio  = isset($_FILES['audio']);
$count      = count($answer_ids);

if ($count === 0 || !$has_audio || count($_FILES['audio']['name']) !== $count) {
    exit('Missing audio for some questions. Please try again.');
}

/* Completed surah set for validation */
$completed_ids = [];
try {
    $res = $conn->query("SELECT surah_id FROM student_learning WHERE student_id = $student_id AND status = 'completed'");
    while ($row = $res->fetch_assoc()) {
        $completed_ids[(int)$row['surah_id']] = true;
    }
} catch (Throwable $e) {
    error_log('Exam submit failed (completed lookup): ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit('Could not verify your completed surahs. Please refresh and try again.');
}

/* Validate every submitted answer row belongs to TODAY's pending set */
$payload = [];
for ($i = 0; $i < $count; $i++) {
    $aid = (int)($answer_ids[$i] ?? 0);
    if ($aid <= 0 || !isset($pending_map[$aid])) {
        exit('Invalid question submitted.');
    }
    $row = $pending_rows[$aid];

    $surah_id = (int)$row['surah_id'];
    $from     = (int)$row['from_verse'];
    $to       = (int)$row['to_verse'];
    if (!isset($completed_ids[$surah_id])) {
        exit('Invalid question — surah not completed.');
    }
    if ($from < 1 || $to < $from) {
        exit('Invalid question — bad verse range. Please contact the academy.');
    }

    $audio_ok = $_FILES['audio']['error'][$i] === UPLOAD_ERR_OK;
    if (!$audio_ok || empty($_FILES['audio']['name'][$i])) {
        exit('Missing audio for question ' . ($i + 1) . '. Please record all answers and resubmit.');
    }

    $payload[] = [
        'aid'  => $aid,
        'tmp'  => $_FILES['audio']['tmp_name'][$i],
        'name' => $_FILES['audio']['name'][$i],
    ];
}

/* All valid — save audio files first, then persist in one transaction. */
$upload_dir = __DIR__ . '/../uploads/exam_audio/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$saved_files = [];
foreach ($payload as $i => $q) {
    $res = audio_save_upload($q['tmp'], $upload_dir, 'exam_' . $attempt_id . '_' . $q['aid'] . '_', $q['name']);
    if (!$res['ok']) {
        foreach ($saved_files as $f) {
            if (is_file($f)) @unlink($f);
        }
        exit($res['error']);
    }
    $filename = $res['file'];
    $saved_files[] = $upload_dir . $filename;
    $payload[$i]['filename'] = $filename;
}

try {
    $conn->begin_transaction();

    $stmt = $conn->prepare("UPDATE exam_answers SET audio_file = ?, status = 'submitted', answered_at = NOW() WHERE id = ?");
    foreach ($payload as $q) {
        $stmt->bind_param("si", $q['filename'], $q['aid']);
        $stmt->execute();
    }

    /* Any pending questions left anywhere in the attempt? */
    $res = $conn->query("SELECT COUNT(*) c, MIN(day_no) md FROM exam_answers WHERE attempt_id = $attempt_id AND status = 'pending'");
    $r = $res->fetch_assoc();
    $remaining = (int)$r['c'];

    if ($remaining === 0) {
        /* Everything answered — send the whole attempt for review. */
        $conn->query("UPDATE exam_attempts SET status = 'pending', submitted_at = NOW() WHERE id = $attempt_id");
    } else {
        /* Advance to the next day that still has pending questions. */
        $next_day = max($current_day + 1, (int)$r['md']);
        $conn->query("UPDATE exam_attempts SET day_no = $next_day WHERE id = $attempt_id");
    }

    $conn->commit();
} catch (Throwable $e) {
    try { $conn->rollback(); } catch (Throwable $e2) { /* ignore */ }
    foreach ($saved_files as $f) {
        if (is_file($f)) @unlink($f);
    }
    error_log('Exam submit failed: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit('Could not save your answers. Please try again.');
}

echo "OK";