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

if (!db_column_exists($conn, 'users', 'memorizing')) {
    if ($is_ajax) { echo 'Memorization column not found. Run db_migrate16.php first.'; exit; }
    redirect('db_migrate16.php');
}

if ($action === 'set') {
    // Toggle Qur'an Memorization ON (mutually exclusive with Hafiz)
    $hafiz_clear = db_column_exists($conn, 'users', 'hafiz') ? ', hafiz = 0' : '';
    $stmt = $conn->prepare("UPDATE users SET memorizing = 1$hafiz_clear WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();

    // Note: users.hafiz is now 0, which safely disables hafiz revision access
    // while keeping their hafiz_revision row intact for future revival.

    // Initialize / activate the memorization state row
    mem_start($conn, $student_id, 1);

    $msg = 'Student designated as memorizer.';

} elseif ($action === 'unset') {
    // Toggle Qur'an Memorization OFF
    $stmt = $conn->prepare("UPDATE users SET memorizing = 0 WHERE id = ? AND role = 'student'");
    $stmt->bind_param("i", $student_id);
    $stmt->execute();

    // Pause the memorization journey (state is preserved for future revival)
    if (db_table_exists($conn, 'quran_memorization')) {
        try {
            $ps = $conn->prepare("UPDATE quran_memorization SET status = 'paused', updated_at = NOW() WHERE student_id = ? AND status = 'active'");
            $ps->bind_param("i", $student_id);
            $ps->execute();
        } catch (Throwable $e) { /* ignore */ }
    }

    $msg = 'Memorization designation removed.';

} else {
    if ($is_ajax) { echo 'Unknown action'; exit; }
    redirect('students.php');
}

if ($is_ajax) {
    echo 'OK';
} else {
    redirect('student_detail.php?id=' . $student_id);
}