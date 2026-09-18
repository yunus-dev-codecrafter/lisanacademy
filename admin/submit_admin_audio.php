<?php
require '../config/security/helpers.php';
require_role('admin');
include '../auth/auth_check.php';
include '../config/db.php';
require '../config/audio_fix.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Invalid request.');
}

// Validate IDs
if (!isset($_POST['plan_id'], $_POST['student_id']) || !is_numeric($_POST['plan_id']) || !is_numeric($_POST['student_id'])) {
    ui_message_page('danger', 'Invalid Request', 'Invalid lesson or student ID.', 'teaching.php', 'Back to Teaching Dashboard', 'close');
}

$plan_id = (int)$_POST['plan_id'];      // lesson_id
$student_id = (int)$_POST['student_id'];

// Validate uploaded file
if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== 0) {
    ui_message_page('danger', 'Upload Failed', 'No audio was uploaded. Please go back and attach an audio file, then try again.', 'teaching.php', 'Back to Teaching Dashboard', 'close');
}

// Create upload directory if it doesn't exist
$uploadDir = __DIR__ . '/../uploads/admin_audio/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Save uploaded file — normalize it so it is guaranteed to play back (see
// config/audio_fix.php). Videos and oversized files are rejected here.
$res = audio_save_upload($_FILES['audio']['tmp_name'], $uploadDir, 'admin_', $_FILES['audio']['name']);
if (!$res['ok']) {
    ui_message_page('danger', 'Upload Failed', $res['error'], 'teaching.php', 'Back to Teaching Dashboard', 'close');
}
$filename = $res['file'];

// Insert admin audio record
$stmt = $conn->prepare("
    INSERT INTO admin_audio (student_id, learning_plan_id, audio_file, sent_at)
    VALUES (?, ?, ?, NOW())
");
$stmt->bind_param("iis", $student_id, $plan_id, $filename);

if (!$stmt->execute()) {
    ui_message_page('danger', 'Upload Failed', 'Could not save the recitation to the database. Please try again.', 'teaching.php', 'Back to Teaching Dashboard', 'close');
}

// Update lessons table to mark lesson as sent
$update = $conn->prepare("UPDATE lessons SET status = 'sent' WHERE id = ?");
$update->bind_param("i", $plan_id);
$update->execute();

ui_message_page('success', 'Recitation Sent', 'The admin recitation was sent successfully! The student can now listen, practice and submit their recitation.', 'teaching.php', 'Back to Teaching Dashboard', 'send');