<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('teaching.php');
}

csrf_verify();

$session_id = (int)($_POST['session_id'] ?? 0);
$status = $_POST['status'] ?? '';
$feedback = trim($_POST['feedback'] ?? '');

if ($session_id <= 0 || !in_array($status, ['passed', 'failed'], true)) {
    redirect('teaching.php');
}

if (!db_table_exists($conn, 'quran_murajaah_sessions')) {
    redirect('teaching.php');
}

// Handle admin audio feedback upload
$admin_audio = null;
if (isset($_FILES['admin_audio']) && $_FILES['admin_audio']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['webm', 'mp3', 'm4a', 'ogg', 'wav', 'mp4', 'm4v', 'mov', '3gp', '3gpp', 'aac'];
    $ext = strtolower(pathinfo($_FILES['admin_audio']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) $ext = 'mp3';

    $filename = 'feedback_mem_' . $session_id . '_' . time() . '.' . $ext;
    $upload_dir = dirname(__DIR__) . '/uploads/admin_feedback/';
    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

    if (move_uploaded_file($_FILES['admin_audio']['tmp_name'], $upload_dir . $filename)) {
        $admin_audio = $filename;
    }
}

$admin_id = (int)($_SESSION['user_id'] ?? 0);
$res = mem_mark_murajaah($conn, $session_id, $status, $feedback, $admin_audio, $admin_id);

redirect('teaching.php');