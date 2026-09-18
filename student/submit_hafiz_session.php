<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

require_role('student');
$student_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('hafiz_revision.php');
}

csrf_verify();

$action = $_POST['action'] ?? '';

/* Handle new cycle start */
if ($action === 'start_new_cycle') {
    if (!db_table_exists($conn, 'hafiz_revision')) {
        redirect('hafiz_revision.php');
    }

    $revision = hafiz_get_active_revision($conn, $student_id);
    if ($revision) {
        // Complete the current cycle first
        $rid = (int)$revision['id'];
        $stmt = $conn->prepare("UPDATE hafiz_revision SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $rid);
        $stmt->execute();
    }

    // Create new cycle
    $now = date('Y-m-d H:i:s');
    $next_cycle = 1;
    if ($revision) {
        $next_cycle = (int)$revision['cycle_no'] + 1;
    }

    $stmt = $conn->prepare("
        INSERT INTO hafiz_revision (student_id, cycle_no, current_page, week_started_at, status, started_at)
        VALUES (?, ?, 1, ?, 'active', ?)
    ");
    $stmt->bind_param("iiss", $student_id, $next_cycle, $now, $now);
    $stmt->execute();

    redirect('hafiz_revision.php');
}

/* Handle recitation submission */
$page_no = (int)($_POST['page_no'] ?? 0);
$revision_id = (int)($_POST['revision_id'] ?? 0);

if ($page_no < 1 || $page_no > 604 || $revision_id <= 0) {
    echo 'Invalid page or revision.';
    exit;
}

if (!student_is_hafiz($conn, $student_id)) {
    echo 'You are not a Hafiz student.';
    exit;
}

// Verify this page is the correct required page
$revision = hafiz_get_active_revision($conn, $student_id);
if (!$revision || (int)$revision['id'] !== $revision_id) {
    echo 'Invalid revision cycle.';
    exit;
}

$required_page = hafiz_required_page($conn, $revision);
if ($page_no !== $required_page) {
    echo 'You can only recite the required page (Page ' . $required_page . ').';
    exit;
}

// Check can recite
$can_check = hafiz_can_recite($conn, $student_id);
if (!$can_check['ok']) {
    echo $can_check['reason'];
    exit;
}

// Handle audio upload
if ($action === 'audio') {
    if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        echo 'Audio file not uploaded correctly.';
        exit;
    }

    $upload_dir = dirname(__DIR__) . '/uploads/student_audio/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $res = audio_save_upload($_FILES['audio']['tmp_name'], $upload_dir, 'hafiz_' . $student_id . '_', $_FILES['audio']['name']);
    if ($res['ok']) {
        $rec = hafiz_record_submission($conn, $student_id, $revision_id, $page_no, 'audio', $res['file']);
        echo $rec['ok'] ? 'OK' : $rec['reason'];
        exit;
    } else {
        if (is_file($_FILES['audio']['tmp_name'])) @unlink($_FILES['audio']['tmp_name']);
        echo $res['error'];
        exit;
    }
}

// Handle live request
if ($action === 'live') {
    $day = trim($_POST['day'] ?? '');
    $time_val = trim($_POST['time'] ?? '');

    if ($day === '' || $time_val === '') {
        echo 'Please provide a day and time.';
        exit;
    }

    // Record submission (pending live session)
    $res = hafiz_record_submission($conn, $student_id, $revision_id, $page_no, 'live');
    if (!$res['ok']) {
        echo $res['reason'];
        exit;
    }

    // Build WhatsApp message
    $whatsapp_number = setting($conn, 'whatsapp_number', '2348029979040');
    $student_name = '';
    $s = $conn->prepare("SELECT name FROM users WHERE id = ?");
    $s->bind_param("i", $student_id);
    $s->execute();
    $r = $s->get_result()->fetch_assoc();
    if ($r) $student_name = $r['name'];

    $date_formatted = date('l, d M Y', strtotime($day));
    $time_formatted = date('g:i A', strtotime($time_val));

    $msg = "Assalamu alaikum Ustadh. I would like to recite Page {$page_no} of the Qur'an off-head (revision).\n\nDay: {$date_formatted}\nTime: {$time_formatted}\n\nStudent: {$student_name}";

    $wa_link = 'https://wa.me/' . $whatsapp_number . '?text=' . rawurlencode($msg);
    echo 'OK|' . $wa_link;
    exit;
}

echo 'Unknown action.';
