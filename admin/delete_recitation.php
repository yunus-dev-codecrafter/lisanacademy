<?php
require '../config/security/helpers.php';
require_role('admin');
include '../auth/auth_check.php';
include '../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('recitations_list.php');
}
csrf_verify();

/* Where to go back afterwards (teaching.php kept for legacy callers). */
$return_to = ($_POST['return_to'] ?? '') === 'recitations_list' ? 'recitations_list.php' : 'teaching.php';

function _unlink_student_audio($filename) {
    if (empty($filename)) return;
    $file = __DIR__ . '/../uploads/student_audio/' . basename($filename);
    if (is_file($file)) @unlink($file);
}

function _unlink_admin_feedback($filename) {
    if (empty($filename)) return;
    $file = __DIR__ . '/../uploads/admin_feedback/' . basename($filename);
    if (is_file($file)) @unlink($file);
}

/* Delete ONE standard recitation (soft delete, legacy behavior). Returns 1 on success. */
function _delete_standard($conn, $id) {
    $id = (int)$id;
    if ($id <= 0) return 0;
    $stmt = $conn->prepare("SELECT audio_file FROM student_recitation WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $rec = $stmt->get_result()->fetch_assoc();
    if ($rec && !empty($rec['audio_file'])) _unlink_student_audio($rec['audio_file']);
    $stmt = $conn->prepare("UPDATE student_recitation SET student_deleted = 1 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->affected_rows > 0 ? 1 : 0;
}

/* Delete ONE hafiz session (hard delete + media cleanup). Returns 1 on success. */
function _delete_hafiz($conn, $id) {
    $id = (int)$id;
    if ($id <= 0 || !db_table_exists($conn, 'hafiz_sessions')) return 0;
    $stmt = $conn->prepare("SELECT audio_file, admin_audio_feedback FROM hafiz_sessions WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    if ($s) {
        _unlink_student_audio($s['audio_file'] ?? '');
        _unlink_admin_feedback($s['admin_audio_feedback'] ?? '');
    }
    $stmt = $conn->prepare("DELETE FROM hafiz_sessions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->affected_rows > 0 ? 1 : 0;
}

/* Delete ONE murajaah session (hard delete + media cleanup). Returns 1 on success. */
function _delete_murajaah($conn, $id) {
    $id = (int)$id;
    if ($id <= 0 || !db_table_exists($conn, 'quran_murajaah_sessions')) return 0;
    $stmt = $conn->prepare("SELECT audio_file, admin_audio_feedback FROM quran_murajaah_sessions WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $s = $stmt->get_result()->fetch_assoc();
    if ($s) {
        _unlink_student_audio($s['audio_file'] ?? '');
        _unlink_admin_feedback($s['admin_audio_feedback'] ?? '');
    }
    $stmt = $conn->prepare("DELETE FROM quran_murajaah_sessions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    return $stmt->affected_rows > 0 ? 1 : 0;
}

/* Normalize the row type coming from the list page. */
function _norm_type($t) {
    $t = strtolower(trim((string)$t));
    if (strpos($t, 'hafiz') !== false) return 'hafiz';
    if (strpos($t, 'muraja') !== false) return 'murajaah';
    return 'standard';
}

function _delete_one($conn, $type, $id) {
    switch (_norm_type($type)) {
        case 'hafiz':    return _delete_hafiz($conn, $id);
        case 'murajaah': return _delete_murajaah($conn, $id);
        default:         return _delete_standard($conn, $id);
    }
}

$action = $_POST['action'] ?? 'single';

/* ── BULK: delete selected checkboxes (values like "hafiz:12") ── */
if ($action === 'delete_selected') {
    $items = $_POST['items'] ?? [];
    if (!is_array($items)) $items = [$items];
    $n = 0;
    foreach ($items as $it) {
        $parts = explode(':', (string)$it, 2);
        if (count($parts) === 2) $n += _delete_one($conn, $parts[0], (int)$parts[1]);
        elseif (is_numeric($it)) $n += _delete_standard($conn, (int)$it);
    }
    redirect($return_to . '?deleted=' . $n);
}

/* ── BULK: delete ALL within a scope (type + status filters) ── */
if ($action === 'delete_all') {
    $scope_type = $_POST['scope_type'] ?? 'all';     // all | standard | hafiz | murajaah
    $scope_status = $_POST['scope_status'] ?? 'all'; // all | pending | accepted-ish | rejected-ish
    $n = 0;

    $status_match = function ($status) use ($scope_status) {
        if ($scope_status === 'all') return true;
        $s = strtolower((string)$status);
        if ($scope_status === 'pending') return $s === 'pending';
        if ($scope_status === 'accepted') return in_array($s, ['accepted', 'passed'], true);
        if ($scope_status === 'rejected') return in_array($s, ['rejected', 'failed'], true);
        return true;
    };

    if (in_array($scope_type, ['all', 'standard'], true)) {
        $res = $conn->query("SELECT id, status FROM student_recitation WHERE student_deleted = 0");
        while ($res && ($r = $res->fetch_assoc())) {
            if ($status_match($r['status'])) $n += _delete_standard($conn, (int)$r['id']);
        }
    }
    if (in_array($scope_type, ['all', 'hafiz'], true) && db_table_exists($conn, 'hafiz_sessions')) {
        $res = $conn->query("SELECT id, status FROM hafiz_sessions");
        while ($res && ($r = $res->fetch_assoc())) {
            if ($status_match($r['status'])) $n += _delete_hafiz($conn, (int)$r['id']);
        }
    }
    if (in_array($scope_type, ['all', 'murajaah'], true) && db_table_exists($conn, 'quran_murajaah_sessions')) {
        $res = $conn->query("SELECT id, status FROM quran_murajaah_sessions");
        while ($res && ($r = $res->fetch_assoc())) {
            if ($status_match($r['status'])) $n += _delete_murajaah($conn, (int)$r['id']);
        }
    }
    redirect($return_to . '?deleted=' . $n);
}

/* ── SINGLE delete (legacy: POST rec_id, optional rec_type) ── */
$rec_id = (int)($_POST['rec_id'] ?? 0);
$rec_type = $_POST['rec_type'] ?? 'standard';
if ($rec_id <= 0) redirect($return_to);

_delete_one($conn, $rec_type, $rec_id);
redirect($return_to . '?deleted=1');
