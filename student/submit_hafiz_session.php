<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('student');
$student_id = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('hafiz_revision.php');
}

csrf_verify();

$action = $_POST['action'] ?? '';

/* Handle skip request */
if ($action === 'request_skip') {
    if (!db_table_exists($conn, 'hafiz_revision')) {
        redirect('hafiz_revision.php');
    }

    $revision = hafiz_get_active_revision($conn, $student_id);
    if ($revision) {
        $stmt = $conn->prepare("UPDATE hafiz_revision SET skip_approved = 0 WHERE id = ?");
        $rid = (int)$revision['id'];
        $stmt->bind_param("i", $rid);
        $stmt->execute();
    }
    echo 'OK';
    exit;
}

/* Handle new cycle start */
if ($action === 'start_new_cycle') {
    if (!db_table_exists($conn, 'hafiz_revision')) {
        redirect('hafiz_revision.php');
    }

    $revision = hafiz_get_active_revision($conn, $student_id);
    if ($revision) {
        // Complete the current cycle first
        $rid = (int)$revision['id'];
        $conn->prepare("UPDATE hafiz_revision SET status = 'completed', completed_at = NOW() WHERE id = ?")->bind_param("i", $rid);
        $conn->execute();
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

// Verify this page is the correct next page
$revision = hafiz_get_active_revision($conn, $student_id);
if (!$revision || (int)$revision['id'] !== $revision_id) {
    echo 'Invalid revision cycle.';
    exit;
}

if ((int)$revision['current_page'] !== $page_no) {
    echo 'You can only recite the next sequential page (Page ' . (int)$revision['current_page'] . ').';
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

    $allowed = ['webm', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'aac'];
    $ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) $ext = 'webm';

    $filename = 'hafiz_' . $student_id . '_' . time() . '_' . rand(1000, 9999) . '.' . $ext;
    $upload_dir = dirname(__DIR__) . '/uploads/student_audio/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    if (move_uploaded_file($_FILES['audio']['tmp_name'], $upload_dir . $filename)) {
        $now = date('Y-m-d H:i:s');
        $stmt = $conn->prepare("
            INSERT INTO hafiz_sessions (student_id, revision_id, page_no, session_type, audio_file, status, submitted_at)
            VALUES (?, ?, ?, 'audio', ?, 'pending', ?)
        ");
        $stmt->bind_param("iiiss", $student_id, $revision_id, $page_no, $filename, $now);
        $stmt->execute();
        echo 'OK';
        exit;
    } else {
        echo 'Failed to save audio file.';
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

    // Insert as pending live session
    $now = date('Y-m-d H:i:s');
    $session_type = 'live';
    $stmt = $conn->prepare("
        INSERT INTO hafiz_sessions (student_id, revision_id, page_no, session_type, status, submitted_at)
        VALUES (?, ?, ?, 'live', 'pending', ?)
    ");
    $stmt->bind_param("iiis", $student_id, $revision_id, $page_no, $now);
    $stmt->execute();

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
