<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('students.php');
}

csrf_verify();

$student_id = (int)($_POST['student_id'] ?? 0);
$password   = $_POST['password'] ?? '';

if ($student_id <= 0) {
    redirect('students.php');
}

if (mb_strlen($password) < 6) {
    redirect('student_detail.php?id=' . $student_id . '&error=' . urlencode('Password must be at least 6 characters'));
}

$stmt = $conn->prepare("SELECT id FROM users WHERE id=? AND role='student'");
$stmt->bind_param("i", $student_id);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    redirect('students.php');
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("UPDATE users SET password=? WHERE id=? AND role='student'");
$stmt->bind_param("si", $hash, $student_id);
$stmt->execute();

redirect('student_detail.php?id=' . $student_id . '&password_reset=1');