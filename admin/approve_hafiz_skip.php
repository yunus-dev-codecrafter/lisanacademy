<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

$is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($is_ajax) { echo 'Invalid request'; exit; }
    redirect('students.php');
}

csrf_verify();

$student_id = (int)($_POST['student_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($student_id <= 0 || !in_array($action, ['approve', 'revoke'], true)) {
    if ($is_ajax) { echo 'Invalid parameters'; exit; }
    redirect('students.php');
}

if (!db_column_exists($conn, 'users', 'hafiz') || !db_table_exists($conn, 'hafiz_revision')) {
    if ($is_ajax) { echo 'Hafiz schema not found. Run db_migrate10.php first.'; exit; }
    redirect('db_migrate10.php');
}

// Get active revision
$check = $conn->prepare("SELECT id FROM hafiz_revision WHERE student_id = ? AND status = 'active' LIMIT 1");
$check->bind_param("i", $student_id);
$check->execute();
$rev = $check->get_result()->fetch_assoc();

if (!$rev) {
    $msg = 'No active revision cycle found for this student.';
    if ($is_ajax) { echo $msg; exit; }
    redirect('student_detail.php?id=' . $student_id);
}

$revision_id = (int)$rev['id'];

if ($action === 'approve') {
    $stmt = $conn->prepare("UPDATE hafiz_revision SET skip_approved = 1, skip_approved_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $revision_id);
    $stmt->execute();
    $msg = 'Weekly skip approved for this student.';
} else {
    $stmt = $conn->prepare("UPDATE hafiz_revision SET skip_approved = 0, skip_approved_at = NULL WHERE id = ?");
    $stmt->bind_param("i", $revision_id);
    $stmt->execute();
    $msg = 'Weekly skip approval revoked.';
}

if ($is_ajax) {
    echo 'OK';
} else {
    redirect('student_detail.php?id=' . $student_id);
}
