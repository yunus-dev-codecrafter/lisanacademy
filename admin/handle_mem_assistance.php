<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/audio_fix.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('teaching.php');
}

csrf_verify();

$request_id = (int)($_POST['request_id'] ?? 0);
if ($request_id <= 0 || !db_table_exists($conn, 'mem_assistance_requests')) {
    redirect('teaching.php?error=' . urlencode('Invalid assistance request.'));
}

/* Optional teacher audio recitation of the page */
$admin_audio = null;
if (isset($_FILES['admin_audio']) && $_FILES['admin_audio']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = dirname(__DIR__) . '/uploads/admin_feedback/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    $res = audio_save_upload($_FILES['admin_audio']['tmp_name'], $upload_dir, 'assist_' . $request_id . '_', $_FILES['admin_audio']['name']);
    if ($res['ok']) {
        $admin_audio = $res['file'];
    } else {
        redirect('teaching.php?error=' . urlencode($res['error']));
    }
}

$admin_notes = trim($_POST['admin_notes'] ?? '');
$admin_id = (int)($_SESSION['user_id'] ?? 0);

$ok = mem_mark_assistance_fulfilled($conn, $request_id, $admin_id, $admin_audio, $admin_notes);

if ($ok) {
    redirect('teaching.php?assist_done=1');
}
redirect('teaching.php?error=' . urlencode('Could not mark the request fulfilled.'));