<?php
// admin/impersonate.php
// Switches the current admin session into a student's account ("survey mode").
// Requires the admin survey key and a valid CSRF token.

require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security/impersonate.php';

/* Resolve the REAL admin identity. While already impersonating, the active
   user_id is the student's, so fall back to the saved impersonator_id. */
$real_admin_id = impersonator_id();
if ($real_admin_id === 0) {
    $real_admin_id = (int)($_SESSION['user_id'] ?? 0);
}

$real_role = '';
if ($real_admin_id > 0) {
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $real_admin_id);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $real_role = $r ? $r['role'] : '';
}

if ($real_role !== 'admin') {
    redirect('/auth/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/admin/students.php');
}

csrf_verify();

/* If we are already surveying someone, finalize that session first so the
   audit trail stays accurate before switching to another student. */
if (is_impersonating()) {
    stop_impersonation($conn);
}

$student_id = (int)($_POST['student_id'] ?? 0);
$key        = $_POST['survey_key'] ?? '';

if ($student_id <= 0) {
    ui_message_page('danger', 'Invalid Student', 'No student was selected.', '/admin/students.php', 'Back to Students');
}

if (!verify_survey_key($conn, $key)) {
    if (survey_key_hash($conn) === '') {
        ui_message_page('warning', 'Survey Key Required',
            'The admin survey key has not been set yet. Set it under Survey Key settings before impersonating.',
            '/admin/survey_key.php', 'Set Survey Key');
    }
    ui_message_page('danger', 'Access Denied', 'The survey key you entered is incorrect.', '/admin/students.php', 'Back to Students');
}

if (start_impersonation($conn, $student_id, $real_admin_id)) {
    redirect('/student/dashboard.php');
}

ui_message_page('danger', 'Cannot Impersonate', 'That student account could not be loaded.', '/admin/students.php', 'Back to Students');
