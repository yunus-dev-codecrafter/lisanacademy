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

/* Request the teacher to recite today's page (Bug #1 assistance) */
if ($action === 'assist') {
    $is_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest' || !empty($_POST['ajax']);
    $state = mem_get_state($conn, $student_id);
    if (!$state || $state['status'] !== 'active') { if ($is_ajax) exit('Memorization is not active.'); redirect('quran_memorization.php'); }
    $task = mem_compute_task($conn, $state);
    if ($task['task_type'] !== 'memorization') {
        if ($is_ajax) exit('Assistance is only available on memorization days.');
        redirect('quran_memorization.php?error=' . urlencode('Assistance is only available on memorization days.'));
    }
    $note = trim($_POST['note'] ?? '');
    $res = mem_assistance_submit($conn, $student_id, [
        'task_day'   => (int)$task['day_number'],
        'start_page' => (int)$task['start_page'],
        'end_page'   => (int)$task['end_page'],
        'note'       => $note,
    ]);
    if ($res['ok']) {
        if ($is_ajax) exit('OK');
        redirect('quran_memorization.php?assist_requested=1');
    }
    if ($is_ajax) exit($res['reason'] ?? 'Could not send the request.');
    redirect('quran_memorization.php?error=' . urlencode($res['reason'] ?? 'Could not send the request.'));
}

/* Muraja'ah submission (video / in-person / live) */
if ($action === 'murajaah') {
    $session_type = $_POST['session_type'] ?? '';

    /* VIDEO submission — the only accepted recording format for Muraja'ah */
    if ($session_type === 'video') {
        if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
            exit('Video file not uploaded correctly.');
        }

        $tmp_file = $_FILES['audio']['tmp_name'];
        $size = filesize($tmp_file);
        if ($size <= 0) {
            exit('The uploaded video file is empty.');
        }
        if (defined('AUDIO_MAX_UPLOAD_BYTES') && $size > AUDIO_MAX_UPLOAD_BYTES) {
            $mb = number_format(AUDIO_MAX_UPLOAD_BYTES / 1048576, 0);
            exit('That file is too large (' . number_format($size / 1048576, 1) . ' MB). Maximum allowed is ' . $mb . ' MB.');
        }

        /* Read the file to locate top-level boxes (mobile cameras place moov at the end of the file) */
        $bin = @file_get_contents($tmp_file);
        $sniffed = ($bin !== false && $bin !== '') ? audio_sniff_type($bin) : '';
        if ($sniffed === 'webm') {
            exit('WebM video is not accepted for Muraja\'ah. Please record with your camera and upload an MP4-family file (.mp4 / .mov / .m4v / .3gp).');
        }
        if ($sniffed !== 'mp4' || !audio_detect_video_track($bin)) {
            exit('Only VIDEO recitations are accepted for Muraja\'ah. Audio-only files are rejected — record yourself at a distance where your face and surroundings are visible.');
        }

        $upload_dir = dirname(__DIR__) . '/uploads/student_audio/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $res = audio_save_upload($_FILES['audio']['tmp_name'], $upload_dir, 'mem_vid_' . $student_id . '_', $_FILES['audio']['name']);
        if ($res['ok']) {
            $sub = mem_submit_murajaah($conn, $student_id, 'video', $res['file']);
            if (!$sub['ok']) {
                if (is_file($upload_dir . $res['file'])) @unlink($upload_dir . $res['file']);
                exit($sub['reason'] ?? 'Could not save the submission.');
            }
            exit('OK');
        }
        if (is_file($_FILES['audio']['tmp_name'])) @unlink($_FILES['audio']['tmp_name']);
        exit($res['error']);
    }

    /* Recited (or to be recited) in person — teacher confirms in the queue */
    if ($session_type === 'inperson') {
        $sub = mem_submit_murajaah($conn, $student_id, 'inperson', '');
        if (!$sub['ok']) {
            exit($sub['reason'] ?? 'Could not save the submission.');
        }
        exit('OK');
    }

    /* Pure audio is no longer accepted for Muraja'ah */
    if ($session_type === 'audio') {
        exit('Audio-only recitations are no longer accepted for Muraja\'ah. Please upload a VIDEO recording, schedule a live call, or recite in person.');
    }

    /* Live via WhatsApp (backwards compatible) */
    if ($session_type !== 'live') {
        exit('Unknown submission type.');
    }
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