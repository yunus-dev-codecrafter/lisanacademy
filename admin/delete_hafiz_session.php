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
if ($session_id <= 0 || !db_table_exists($conn, 'hafiz_sessions')) {
    redirect('teaching.php');
}

// Remove the student's audio file + admin feedback audio if they exist
$stmt = $conn->prepare("SELECT audio_file, admin_audio_feedback FROM hafiz_sessions WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $session_id);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

if ($session) {
    if (!empty($session['audio_file'])) {
        $file = dirname(__DIR__) . '/uploads/student_audio/' . basename($session['audio_file']);
        if (is_file($file)) @unlink($file);
    }
    if (!empty($session['admin_audio_feedback'])) {
        $file = dirname(__DIR__) . '/uploads/admin_feedback/' . basename($session['admin_audio_feedback']);
        if (is_file($file)) @unlink($file);
    }
}

$stmt = $conn->prepare("DELETE FROM hafiz_sessions WHERE id = ?");
$stmt->bind_param("i", $session_id);
$stmt->execute();

redirect('teaching.php?deleted=1');