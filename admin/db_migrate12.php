<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$password_type = strtolower(db_column_type($conn, 'users', 'password'));
preg_match('/^varchar\((\d+)\)$/', $password_type, $m);
$password_width = $m ? (int)$m[1] : 0;

/* Count users whose stored hash cannot be verified (truncated or legacy). */
function broken_password_count($conn) {
    try {
        $res = $conn->query("SELECT LENGTH(password) AS plen, password FROM users");
        $bad = 0;
        while ($row = $res->fetch_assoc()) {
            $hash = (string)$row['password'];
            $ok = (strlen($hash) >= 60
                && (strncmp($hash, '$2y$', 4) === 0
                    || strncmp($hash, '$2a$', 4) === 0
                    || strncmp($hash, '$2b$', 4) === 0
                    || strncmp($hash, '$argon2i$', 9) === 0
                    || strncmp($hash, '$argon2id$', 10) === 0));
            if (!$ok) $bad++;
        }
        return $bad;
    } catch (Throwable $e) {
        return 0;
    }
}

$broken_count = broken_password_count($conn);

$missing = [];
if ($password_width === 0 || $password_width < 60) $missing[] = 'users.password widened to 60+ chars';
if ($broken_count > 0) $missing[] = "reset $broken_count broken account password(s)";

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. Widen users.password so bcrypt hashes (60 chars) are never truncated. */
    if ($password_width > 0 && $password_width < 60) {
        $old_width = $password_width;
        try {
            $conn->query("ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL");
            $password_type  = 'varchar(255)';
            $password_width = 255;
            $steps[] = ['users.password', "widened from varchar($old_width) to VARCHAR(255)"];
        } catch (Throwable $e) {
            $steps[] = ['users.password', 'ERROR — ' . $e->getMessage()];
        }
    } elseif ($password_width >= 60) {
        $steps[] = ['users.password', 'already wide enough (' . $password_type . ')'];
    } else {
        $steps[] = ['users.password', 'SKIPPED — could not read column type (' . ($password_type ?: 'missing') . ')'];
    }

    $broken_count = broken_password_count($conn);
    $steps[] = ['broken password hashes', $broken_count > 0 ? "$broken_count still broken — reset them via the student detail page" : 'none found'];
}

/* ============ BROKEN ACCOUNTS LIST ============ */
$broken_accounts = [];
try {
    $res = $conn->query("SELECT id, name, email, role, password, LENGTH(password) AS plen FROM users ORDER BY id DESC");
    while ($row = $res->fetch_assoc()) {
        $hash = (string)$row['password'];

        $ok = (strlen($hash) >= 60
            && (strncmp($hash, '$2y$', 4) === 0
                || strncmp($hash, '$2a$', 4) === 0
                || strncmp($hash, '$2b$', 4) === 0
                || strncmp($hash, '$argon2i$', 9) === 0
                || strncmp($hash, '$argon2id$', 10) === 0));
        if ($ok) continue;
        $broken_accounts[] = [
            'id'    => (int)$row['id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
            'stored_chars' => (int)$row['plen'],
        ];
    }
} catch (Throwable $e) {
    $broken_accounts = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Password Schema Migration</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Password Schema Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Password Migration &amp; Hash Diagnostics</h1>
    <p>Ensures the <code>users.password</code> column can hold a full bcrypt hash (60 chars) and lists every account whose stored hash cannot be verified. Safe to run again — the ALTER only runs when the column is narrower than 60 characters.</p>
</div>

<?php if ($ran): ?>
    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;"><?= ui_icon('check-circle', 18) ?> Migration Result</h3>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Object</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($steps as $st): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($st[0]) ?></code></td>
                        <td>
                            <?php if (strncmp($st[1], 'ERROR', 5) === 0): ?>
                                <span class="badge badge-red">Failed</span>
                                <div class="small text-muted"><?= htmlspecialchars($st[1]) ?></div>
                            <?php elseif (strpos($st[1], 'still broken') !== false): ?>
                                <span class="badge badge-red"><?= htmlspecialchars($st[1]) ?></span>
                            <?php elseif (strpos($st[1], 'already') === 0): ?>
                                <span class="badge badge-grey"><?= htmlspecialchars($st[1]) ?></span>
                            <?php else: ?>
                                <span class="badge badge-green"><?= htmlspecialchars($st[1]) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Status</h3>
        <p class="small" style="margin:0 0 6px;">
            <strong>users.password</strong> type: <code><?= htmlspecialchars($password_type ?: 'unknown') ?></code>
            (<?= $password_width > 0 && $password_width < 60 ? 'TOO NARROW for bcrypt — hashes get truncated' : 'fits a full 60-char hash' ?>)
        </p>
        <p class="small" style="margin:0 0 10px;"><strong>Accounts that cannot log in</strong> (broken/truncated hash): <?= $broken_count ?></p>

        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-bottom:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the column is wide enough and every password hash verifies.</div>
        <?php else: ?>
            <div class="alert alert-warning"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> item(s) to address: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <form method="POST" onsubmit="return confirm('Widen the users.password column now?');">
                <?= csrf_field() ?>
                <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('refresh', 17) ?> Run Migration (widen password column)</button>
            </form>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <a class="btn btn-ghost" href="db_audit.php"><?= ui_icon('grid', 16) ?> DB Audit</a>
            <a class="btn btn-ghost" href="students.php"><?= ui_icon('users', 16) ?> Students</a>
        </div>
    </div>

<?php endif; ?>

<?php if (!empty($broken_accounts)): ?>
    <div class="card card-danger animate-rise" style="max-width:840px;">
        <div class="card-title" style="display:flex;align-items:center;gap:8px;">
            <?= ui_icon('lock', 18) ?>
            <h3 style="margin:0;"><?= count($broken_accounts) ?> account(s) have a broken password hash</h3>
        </div>
        <p class="small" style="margin:0 0 10px;">These users get <em>"Invalid email or password"</em> even with the correct password. Reset each one from their student detail page (or with the admin reset form). Truncated hashes cannot be recovered — the password must be re-set.</p>
        <div class="table-wrap" style="margin-top:12px;">
            <table class="table">
                <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Stored chars</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($broken_accounts as $row): ?>
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
<?php else: ?>
    <div class="alert alert-success animate-rise" style="max-width:840px;"><?= ui_icon('check-circle', 16) ?> Every password in the database is a valid hash.</div>
<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>