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

if ($student_id <= 0 || $action === '') {
    if ($is_ajax) { echo 'Invalid parameters'; exit; }
    redirect('students.php');
}

if (!db_column_exists($conn, 'users', 'hafiz')) {
    if ($is_ajax) { echo 'Hafiz column not found. Run db_migrate10.php first.'; exit; }
    redirect('db_migrate10.php');
}

if ($action === 'set') {
    // Toggle Hafiz ON
    $stmt = $conn->prepare("UPDATE users SET hafiz = 1 WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();

    // Create first revision cycle if none exists
    if (db_table_exists($conn, 'hafiz_revision')) {
        $check = $conn->prepare("SELECT id FROM hafiz_revision WHERE student_id = ? AND status = 'active' LIMIT 1");
        $check->bind_param("i", $student_id);
        $check->execute();
        if (!$check->get_result()->fetch_assoc()) {
            $now = date('Y-m-d H:i:s');
            $ins = $conn->prepare("
                INSERT INTO hafiz_revision (student_id, cycle_no, current_page, week_started_at, status, started_at)
                VALUES (?, 1, 1, ?, 'active', ?)
            ");
            $ins->bind_param("iss", $student_id, $now, $now);
            $ins->execute();
        }
    }

    $msg = 'Student designated as Hafiz.';

} elseif ($action === 'unset') {
    // Toggle Hafiz OFF
    $stmt = $conn->prepare("UPDATE users SET hafiz = 0 WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    $msg = 'Student designation removed.';

} else {
    if ($is_ajax) { echo 'Unknown action'; exit; }
    redirect('students.php');
}

if ($is_ajax) {
    echo 'OK';
} else {
    redirect('student_detail.php?id=' . $student_id);
}
