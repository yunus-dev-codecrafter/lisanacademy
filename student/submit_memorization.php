<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

require_role('student');
$student_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('quran_memorization.php');
}

csrf_verify();

if (!student_is_memorizing($conn, $student_id)) {
    exit('You are not enrolled in Qur\'an Memorization.');
}

$action = $_POST['action'] ?? '';

/* Mark today's memorization page complete */
if ($action === 'memorized') {
    $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || !empty($_POST['ajax']);
    $page = (int)($_POST['page'] ?? 0);
    $res = mem_record_memorization($conn, $student_id, $page);
    if ($res['ok']) {
        if ($is_ajax) exit('OK');
        redirect('quran_memorization.php?marked=' . $page);
    }
    if ($is_ajax) exit($res['reason'] ?? 'Could not record the page.');
    redirect('quran_memorization.php?error=' . urlencode($res['reason'] ?? 'Could not record the page.'));
}

/* Acknowledge a celebration day and continue */
if ($action === 'acknowledge') {
    $res = mem_acknowledge_celebration($conn, $student_id);
    if ($res['ok']) {
        redirect('quran_memorization.php');
    }
    exit($res['reason'] ?? 'Could not acknowledge the celebration.');
}

/* Muraja'ah submission (audio / live) */
if ($action === 'murajaah') {
    $session_type = ($_POST['session_type'] ?? '') === 'live' ? 'live' : 'audio';

    if ($session_type === 'audio') {
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            exit('Audio file not uploaded correctly.');
        }

        $upload_dir = dirname(__DIR__) . '/uploads/student_audio/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $res = audio_save_upload($_FILES['audio']['tmp_name'], $upload_dir, 'mem_' . $student_id . '_', $_FILES['audio']['name']);
        if ($res['ok']) {
            $sub = mem_submit_murajaah($conn, $student_id, 'audio', $res['file']);
            if (!$sub['ok']) {
                if (is_file($upload_dir . $res['file'])) @unlink($upload_dir . $res['file']);
                exit($sub['reason'] ?? 'Could not save the submission.');
            }
            exit('OK');
        }
        if (is_file($_FILES['audio']['tmp_name'])) @unlink($_FILES['audio']['tmp_name']);
        exit($res['error']);
    }

    /* Live via WhatsApp */
    $day = trim($_POST['day'] ?? '');
    $time_val = trim($_POST['time'] ?? '');
    if ($day === '' || $time_val === '') {
        exit('Please provide a day and time.');
    }

    $sub = mem_submit_murajaah($conn, $student_id, 'live');
    if (!$sub['ok']) {
        exit($sub['reason'] ?? 'Could not schedule the live session.');
    }

    $task = $sub['task'];
    $range = 'Pages ' . (int)$task['start_page'] . '–' . (int)$task['end_page'];
    $session_hint = $task['day_session'] === 'morning' ? ' (Morning Session)' : ($task['day_session'] === 'evening' ? ' (Evening Session)' : '');

    $whatsapp_number = setting($conn, 'whatsapp_number', '2348029979040');
    $student_name = '';
    $s = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $s->bind_param("i", $student_id);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    if ($r) $student_name = $r['name'];

    $date_formatted = date('l, d M Y', strtotime($day));
    $time_formatted = date('g:i A', strtotime($time_val));

    $msg = "Assalamu alaikum Ustadh. I would like to recite $range of the Qur'an off-head (Muraja'ah)$session_hint.\n\nDay: $date_formatted\nTime: $time_formatted\n\nStudent: $student_name";

    $wa_link = 'https://wa.me/' . $whatsapp_number . '?text=' . rawurlencode($msg);
    exit('OK|' . $wa_link);
}

exit('Unknown action.');