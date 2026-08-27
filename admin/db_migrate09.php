<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];

if (!db_table_exists($conn, 'admin_impersonation_log')) {
    $missing[] = 'admin_impersonation_log';
}

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* admin_impersonation_log — audit trail for admin "login as student"
       survey sessions: who impersonated whom, when, and from what IP. */
    if (!db_table_exists($conn, 'admin_impersonation_log')) {
        try {
            $conn->query("
                CREATE TABLE admin_impersonation_log (
                    id         INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id   INT NOT NULL,
                    student_id INT NOT NULL,
                    started_at DATETIME NOT NULL,
                    ended_at   DATETIME NULL,
                    admin_ip   VARCHAR(45) NULL,
                    INDEX (admin_id),
                    INDEX (student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['admin_impersonation_log', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['admin_impersonation_log', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['admin_impersonation_log', 'already present'];
    }
}

$missing_after = [];
if (!db_table_exists($conn, 'admin_impersonation_log')) $missing_after[] = 'admin_impersonation_log';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Database Migration</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Database Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Impersonation Audit Log</h1>
    <p>Adds the <code>admin_impersonation_log</code> table used to record every admin
       &ldquo;login as student&rdquo; survey session (who, whom, when, from what IP).
       Safe to run again — it checks whether the table already exists.</p>
</div>

<?php if ($ran): ?>
    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;"><?= ui_icon('check-circle', 18) ?> Migration Result</h3>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th>Object</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($steps as $st): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($st[0]) ?></code></td>
                            <td>
                                <?php if (strncmp($st[1], 'ERROR', 5) === 0): ?>
                                    <span class="badge badge-red">Failed</span>
                                    <div class="small text-muted"><?= htmlspecialchars($st[1]) ?></div>
                                <?php elseif ($st[1] === 'already present'): ?>
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

        <?php if (empty($missing_after)): ?>
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> All schema objects are now in place. Go to <a href="survey_key.php">Survey Key</a> to set the admin survey password.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the <code>admin_impersonation_log</code> table already exists.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <p class="small text-muted">
                The impersonation audit log records each survey session so there is an
                accountability trail for who viewed which student account and when.
            </p>
            <form method="POST" onsubmit="return confirm('Create the admin_impersonation_log table now?');">
                <?= csrf_field() ?>
                <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('refresh', 17) ?> Run Migration</button>
            </form>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <a class="btn btn-ghost" href="survey_key.php"><?= ui_icon('lock', 16) ?> Survey Key</a>
            <a class="btn btn-ghost" href="dashboard.php"><?= ui_icon('grid', 16) ?> Dashboard</a>
        </div>
    </div>

<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>
