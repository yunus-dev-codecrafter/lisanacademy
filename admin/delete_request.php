<?php
require '../config/security/helpers.php';
require_role('admin');
include '../auth/auth_check.php';
include '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['lesson_id'])) {
    csrf_verify();
    $lesson_id = (int)$_POST['lesson_id'];

    // Delete the lesson request from lessons table
    $stmt = $conn->prepare("DELETE FROM lessons WHERE id = ?");
    $stmt->bind_param("i", $lesson_id);
    $stmt->execute();

    // Optional: you can also delete related recitations if needed
    $stmt2 = $conn->prepare("DELETE FROM student_recitation WHERE learning_plan_id = ?");
    $stmt2->bind_param("i", $lesson_id);
    $stmt2->execute();

    header("Location: teaching.php?request_deleted=1");
    exit;
} else {
    // If accessed directly, redirect back
    header("Location: teaching.php");
    exit;
}
?>