<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

$is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('memorization_students.php');
}

csrf_verify();

$student_id = (int)($_POST['student_id'] ?? 0);
$action = $_POST['action'] ?? '';

if ($student_id <= 0 || !mem_engine_installed($conn)) {
    if ($is_ajax) { echo 'Invalid request.'; }
    redirect('memorization_students.php');
    exit;
}

$reply = function (string $msg, bool $ok = true) use ($is_ajax) {
    if ($is_ajax) { echo $msg; exit; }
    if ($ok) { redirect('memorization_students.php'); }
    redirect('memorization_students.php?error=' . urlencode($msg));
    exit;
};

switch ($action) {

    /* Mark today's memorization task done on the student's behalf */
    case 'task':
        $state = mem_get_state($conn, $student_id);
        if (!$state || $state['status'] !== 'active') { $reply('Memorization is not active.', false); }
        $task = mem_compute_task($conn, $state);
        if ($task['task_type'] !== 'memorization') {
            $reply("Today is a " . ucfirst($task['task_type']) . " day — cannot force-complete.", false);
        }
        $res = mem_record_memorization($conn, $student_id, (int)$task['start_page']);
        $reply($res['ok'] ? 'OK' : ($res['reason'] ?? 'Could not record.'), $res['ok']);
        break;

    /* Move the student to an arbitrary page count (0..604) */
    case 'adjust':
        $pages = (int)($_POST['pages'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $res = mem_adjust($conn, $student_id, $pages, (int)($_SESSION['user_id'] ?? 0), $notes);
        $reply($res['ok'] ? 'OK' : ($res['reason'] ?? 'Could not adjust.'), $res['ok']);
        break;

    case 'pause':
        $reply(mem_pause($conn, $student_id) ? 'OK' : 'Could not pause.', true);
        break;

    case 'resume':
        $reply(mem_resume($conn, $student_id) ? 'OK' : 'Could not resume.', true);
        break;

    case 'heal':
        mem_self_heal($conn, $student_id);
        $reply('OK', true);
        break;

    default:
        $reply('Unknown action.', false);
}

exit;