<?php
require '../config/security/helpers.php';
require_role('admin');
include '../auth/auth_check.php';
include '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['rec_id'])) {
    redirect('teaching.php');
}
csrf_verify();

$rec_id = (int)$_POST['rec_id'];

/* Remove the uploaded audio file if it exists */
$stmt = $conn->prepare("SELECT audio_file FROM student_recitation WHERE id = ?");
$stmt->bind_param("i", $rec_id);
$stmt->execute();
$rec = $stmt->get_result()->fetch_assoc();

if ($rec && !empty($rec['audio_file'])) {
    $file = __DIR__ . '/../uploads/student_audio/' . basename($rec['audio_file']);
    if (is_file($file)) {
        @unlink($file);
    }
}

/* Soft delete so the row drops out of admin & student listings */
$stmt = $conn->prepare("UPDATE student_recitation SET student_deleted = 1 WHERE id = ?");
$stmt->bind_param("i", $rec_id);
$stmt->execute();

$stmt->close();
header("Location: teaching.php?deleted=1");
exit;
?>