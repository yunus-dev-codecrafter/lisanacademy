<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('student');
$student_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('hafiz_test.php');
}

csrf_verify();

if (!student_is_hafiz($conn, $student_id)) {
    ui_message_page('danger', 'Not a Hafiz', 'You are not designated as a Hafiz student.', 'hafiz_revision.php', 'Hafiz Revision', 'close');
    exit;
}

if (!db_table_exists($conn, 'hafiz_revision') || !db_table_exists($conn, 'hafiz_weekly_tests')) {
    ui_message_page('danger', 'Not Ready', 'The weekly test system has not been set up yet. Please contact the admin.', 'hafiz_revision.php', 'Hafiz Revision', 'close');
    exit;
}

$revision = hafiz_get_active_revision($conn, $student_id);
if (!$revision) {
    ui_message_page('danger', 'No Active Revision', 'You do not have an active revision cycle. Please contact the admin.', 'hafiz_revision.php', 'Hafiz Revision', 'close');
    exit;
}

// Only allowed once the weekly quota (20 pages) is met and the week is not skipped
if (!hafiz_week_test_qualified($conn, $revision)) {
    ui_message_page('warning', 'Test Locked', 'The weekly test becomes available after you recite 20 pages this week.', 'hafiz_revision.php', 'Hafiz Revision', 'close');
    exit;
}

$test = hafiz_create_weekly_test($conn, $student_id, $revision, 3);
if (!$test) {
    ui_message_page('danger', 'Could Not Start', 'We could not generate a weekly test. Please ensure you have completed revision pages to draw questions from.', 'hafiz_test.php', 'Weekly Test', 'close');
    exit;
}

redirect('hafiz_test.php');