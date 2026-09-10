<?php
require '../config/security/helpers.php';
require '../auth/auth_check.php';
require '../config/db.php';
require_role('student');

if (student_is_hafiz($conn, (int)($_SESSION['user_id'] ?? 0))) {
    if ($is_ajax) { echo 'You are a Hafiz student. Please use the Qur\'an Revision page.'; exit; }
    header("Location: hafiz_revision.php");
    exit;
}

/* True when the request came from the in-page recorder (fetch), not a plain form. */
$is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if (student_in_exam($conn, (int)($_SESSION['user_id'] ?? 0))) {
    if ($is_ajax) { echo 'Exam mode is active. You cannot submit recitations until the exam is concluded.'; exit; }
    exit('Exam mode is active. You cannot submit recitations until the exam is concluded.');
}

if (student_exam_locked($conn, (int)$_SESSION['user_id'])) {
    if ($is_ajax) { echo 'Your exam is outstanding. You cannot submit recitations until it is accepted.'; exit; }
    header("Location: exam_defaulted.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Invalid request');
}

/* Validate lesson ID */
if (!isset($_POST['learning_plan_id']) || !is_numeric($_POST['learning_plan_id'])) {
    exit('Learning plan ID missing');
}

/* Validate file */
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    exit('Audio upload failed');
}

$student_id        = (int)$_SESSION['user_id'];
$learning_plan_id  = (int)$_POST['learning_plan_id'];

/* Ensure upload directory exists */
$upload_dir = __DIR__ . '/../uploads/student_audio/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

/* Save file — only ever accept real audio extensions; anything else is a
   failed attempt to sneak a script onto the server, so fall back to .webm. */
$ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION));
if (!in_array($ext, ['webm', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'aac'], true)) {
    $ext = 'webm';
}
$filename = 'student_' . time() . '_' . rand(1000,9999) . '.' . $ext;
$target = $upload_dir . $filename;

if (!move_uploaded_file($_FILES['audio']['tmp_name'], $target)) {
    exit('Upload failed');
}

/* Insert recitation */
$stmt = $conn->prepare("
    INSERT INTO student_recitation (student_id, learning_plan_id, audio_file, submitted_at)
    VALUES (?, ?, ?, NOW())
");
$stmt->bind_param("iis", $student_id, $learning_plan_id, $filename);

if (!$stmt->execute()) {
    exit('Database error');
}

/* Fetch-style requests get a plain OK so the JS can react without loading the dashboard. */
if ($is_ajax) {
    echo 'OK';
    exit;
}

/* Regular form posts go to the styled confirmation page. */
header("Location: recitation_sent.php");
exit;