<?php
require '../config/security/helpers.php';
require_role('admin');
require '../auth/auth_check.php';
require '../config/db.php';

/* What columns does the users table actually have? */
$cols = [];
$r = $conn->query("SHOW COLUMNS FROM users");
while ($row = $r->fetch_assoc()) { $cols[$row['Field']] = 1; }

/* ------------- Password hash health -------------
   Every user's password must be a valid bcrypt hash (60 chars, $2y$/$2a$/$2b$
   prefix) so login_process.php's password_verify() works. A hash that is
   shorter than 60 chars was almost certainly truncated by a too-narrow
   password column at INSERT time — such users can never log in until their
   password is reset (admin/student_detail.php -> Reset Password). */
$hash_issues = [];
try {
    $res = $conn->query("SELECT id, name, email, role, LENGTH(password) AS plen, password FROM users ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) {
        $hash = (string)$row['password'];
        $ok = (strlen($hash) >= 60
            && (strncmp($hash, '$2y$', 4) === 0
                || strncmp($hash, '$2a$', 4) === 0
                || strncmp($hash, '$2b$', 4) === 0));
        if ($ok) continue;
        $hash_issues[] = [
            'id'    => (int)$row['id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
            'stored_chars' => (int)$row['plen'],
        ];
    }
} catch (Throwable $e) {
    $hash_issues = [];
}

/* ------------- 3. Orphan checks ------------- */
function audit_rows($conn, $sql) {
    $out = [];
    $res = $conn->query($sql);
    if ($res) { while ($row = $res->fetch_assoc()) $out[] = $row; }
    return $out;
}
$audits = [
    'Lessons with a student that no longer exists' =>
        audit_rows($conn, "SELECT l.id, l.student_id FROM lessons l LEFT JOIN users u ON u.id=l.student_id WHERE u.id IS NULL LIMIT 50"),
    'Recitations with a missing lesson' =>
        audit_rows($conn, "SELECT sr.id, sr.learning_plan_id FROM student_recitation sr LEFT JOIN lessons l ON l.id=sr.learning_plan_id WHERE l.id IS NULL LIMIT 50"),
    'Recitations with a missing student' =>
        audit_rows($conn, "SELECT sr.id, sr.student_id FROM student_recitation sr LEFT JOIN users u ON u.id=sr.student_id WHERE u.id IS NULL LIMIT 50"),
    'Learning plans with a missing student' =>
        audit_rows($conn, "SELECT sl.id, sl.student_id FROM student_learning sl LEFT JOIN users u ON u.id=sl.student_id WHERE u.id IS NULL LIMIT 50"),
    'Learning plans with a missing surah' =>
        audit_rows($conn, "SELECT sl.id, sl.surah_id FROM student_learning sl LEFT JOIN surahs s ON s.id=sl.surah_id WHERE s.id IS NULL LIMIT 50"),
    'Admin audio with no student' =>
        audit_rows($conn, "SELECT aa.id, aa.student_id FROM admin_audio aa LEFT JOIN users u ON u.id=aa.student_id WHERE u.id IS NULL LIMIT 50"),
    'Admin audio with no lesson' =>
        audit_rows($conn, "SELECT aa.id, aa.learning_plan_id FROM admin_audio aa LEFT JOIN lessons l ON l.id=aa.learning_plan_id WHERE l.id IS NULL LIMIT 50"),
    'Live recitation requests with no student' =>
        audit_rows($conn, "SELECT lr.id, lr.student_id FROM live_recitation_requests lr LEFT JOIN users u ON u.id=lr.student_id WHERE u.id IS NULL LIMIT 50"),
    'Duplicate student_learning (student+surah)' =>
        audit_rows($conn, "SELECT student_id, surah_id, COUNT(*) c FROM student_learning GROUP BY student_id, surah_id HAVING c > 1 LIMIT 50"),
];

/* ------------- Orphan uploaded files ------------- */
$orphan_files = [];
$base = __DIR__ . '/../uploads/student_audio/';
if (is_dir($base)) {
    foreach (glob($base . '*') as $f) {
        if (is_dir($f)) continue;
        $name = basename($f);
        $stmt = $conn->prepare("SELECT id FROM student_recitation WHERE audio_file = ? LIMIT 1");
        $stmt->bind_param("s", $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) $orphan_files[] = $name;
    }
}

$stale = [];
foreach ($audits as $label => $rows) {
    if (!empty($rows)) { $stale[$label] = $rows; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database Audit</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Database Audit', 'Health'); ?>

<div class="page-hero animate-rise">
    <h1>Database &amp; Data Integrity Audit</h1>
    <p>Read-only checks: missing references, orphaned files, schema notes.</p>
</div>

<div class="stat-grid animate-rise d1">
    <div class="stat-card stat-green">
        <span class="stat-label">Issues Found</span>
        <span class="stat-value"><?= count($stale) ?></span>
        <span class="stat-sub">Category groups with problems</span>
    </div>
    <div class="stat-card stat-<?= empty($orphan_files) ? 'green' : 'red' ?>">
        <span class="stat-label">Orphaned Audio Files</span>
        <span class="stat-value"><?= count($orphan_files) ?></span>
        <span class="stat-sub">Files with no DB row</span>
    </div>
    <div class="stat-card stat-<?= empty($hash_issues) ? 'green' : 'red' ?>">
        <span class="stat-label">Broken Passwords</span>
        <span class="stat-value"><?= count($hash_issues) ?></span>
        <span class="stat-sub">Non-bcrypt / truncated hashes</span>
    </div>
</div>

<?php if (empty($stale) && empty($orphan_files) && empty($hash_issues)): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('check-circle', 40) ?></div>
        <div class="empty-title">No integrity problems detected!</div>
        <p class="small" style="margin:0;">All foreign references are valid and no orphaned audio files were found.</p>
    </div>
<?php endif; ?>

<?php foreach ($stale as $label => $rows): ?>
    <div class="card animate-rise d1">
        <div class="card-title" style="display:flex;align-items:center;gap:8px;">
            <?= ui_icon('alert', 18) ?>
            <h3 style="margin:0;"><?= htmlspecialchars($label) ?></h3>
        </div>
        <?php if (count($rows) > 20): ?>
            <div class="panel"><?= count($rows) ?> rows found — showing the first 20.</div>
        <?php endif; ?>
        <div class="table-wrap" style="margin-top:12px;">
            <table class="table">
                <thead><tr><?php foreach(array_keys($rows[0]) as $k): ?><th><?= htmlspecialchars($k) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach(array_slice($rows,0,20) as $row): ?>
                    <tr><?php foreach($row as $k => $v): ?><td><?= htmlspecialchars((string)$v) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php if (!empty($hash_issues)): ?>
    <div class="card card-danger animate-rise">
        <div class="card-title" style="display:flex;align-items:center;gap:8px;">
            <?= ui_icon('lock', 18) ?>
            <h3 style="margin:0;"><?= count($hash_issues) ?> account(s) cannot log in — broken password hash</h3>
        </div>
        <p class="small" style="margin:0 0 10px;">The stored hash is not a valid bcrypt hash (probably truncated by a too-narrow <code>users.password</code> column), so <code>password_verify()</code> always fails. Run <a href="db_migrate12.php"><strong>Password Schema Migration</strong></a>, then reset each account below from the student's detail page.</p>
        <div class="table-wrap" style="margin-top:12px;">
            <table class="table">
                <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Stored chars</th><th></th></tr></thead>
                <tbody>
                <?php foreach (array_slice($hash_issues, 0, 50) as $row): ?>
                    <tr>
                        <td><?= $row['id'] ?></td>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['email']) ?></td>
                        <td><?= htmlspecialchars($row['role']) ?></td>
                        <td><?= $row['stored_chars'] ?> (need 60)</td>
                        <td><?php if ($row['role'] === 'student'): ?><a class="btn btn-sm" href="student_detail.php?id=<?= $row['id'] ?>">Reset</a><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php if (!empty($orphan_files)): ?>
    <div class="card card-danger animate-rise">
        <div class="card-title"><h3><?= htmlspecialchars(count($orphan_files)) ?> orphaned student audio files (no DB row)</h3></div>
        <div class="panel" style="max-height:260px;overflow-y:auto;margin:0;">
            <?php foreach ($orphan_files as $name): ?><div class="small" style="padding:3px 0;"><?= htmlspecialchars($name) ?></div><?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (empty($cols['phone'])): ?>
    <div class="alert alert-warning"><?= ui_icon('info', 16) ?> The <code>users</code> table has no <code>phone</code> column — the WhatsApp "Message Student" feature falls back to the academy number. Run <a href="db_migrate07.php"><strong>Database Migration</strong></a> to add it (and backfill it from applications).</div>
<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>