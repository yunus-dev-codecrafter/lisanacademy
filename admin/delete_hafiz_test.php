<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('hafiz_tests.php');
}

csrf_verify();

$test_id = (int)($_POST['test_id'] ?? 0);
if ($test_id <= 0 || !db_table_exists($conn, 'hafiz_weekly_tests')) {
    redirect('hafiz_tests.php');
}

// Remove student answer audio + admin feedback audio if they exist
if (db_table_exists($conn, 'hafiz_test_answers')) {
    $stmt = $conn->prepare("SELECT audio_file FROM hafiz_test_answers WHERE test_id = ?");
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
    $rows = $stmt->get_result();
    while ($r = $rows->fetch_assoc()) {
        if (!empty($r['audio_file'])) {
            $file = dirname(__DIR__) . '/uploads/hafiz_test_audio/' . basename($r['audio_file']);
            if (is_file($file)) @unlink($file);
        }
    }
    $stmt = $conn->prepare("DELETE FROM hafiz_test_answers WHERE test_id = ?");
    $stmt->bind_param("i", $test_id);
    $stmt->execute();
}

$stmt = $conn->prepare("SELECT admin_audio_file FROM hafiz_weekly_tests WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $test_id);
$stmt->execute();
$test = $stmt->get_result()->fetch_assoc();

if ($test && !empty($test['admin_audio_file'])) {
    $file = dirname(__DIR__) . '/uploads/admin_feedback/' . basename($test['admin_audio_file']);
    if (is_file($file)) @unlink($file);
}

$stmt = $conn->prepare("DELETE FROM hafiz_weekly_tests WHERE id = ?");
$stmt->bind_param("i", $test_id);
$stmt->execute();

redirect('hafiz_tests.php?deleted=1');