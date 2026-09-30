<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];
if (db_table_exists($conn, 'term_fees') === false) $missing[] = 'term_fees (table)';
if (db_table_exists($conn, 'term_fee_payments') === false) $missing[] = 'term_fee_payments (table)';

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. term_fees table */
    if (!db_table_exists($conn, 'term_fees')) {
        try {
            $conn->query("
                CREATE TABLE term_fees (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    term_label VARCHAR(100) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                    due_date DATE NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_term_label (term_label)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['term_fees (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['term_fees (table)', 'ERROR - ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['term_fees (table)', 'already present'];
    }

    /* 2. term_fee_payments table */
    if (!db_table_exists($conn, 'term_fee_payments')) {
        try {
            $conn->query("
                CREATE TABLE term_fee_payments (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    term_id INT NOT NULL,
                    student_id INT NOT NULL,
                    status ENUM('pending','paid','waived') NOT NULL DEFAULT 'pending',
                    paid_at DATETIME NULL,
                    note VARCHAR(255) NULL,
                    recorded_by INT NULL,
                    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_term_student (term_id, student_id),
                    INDEX idx_term_status (term_id, status),
                    INDEX idx_student (student_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            $steps[] = ['term_fee_payments (table)', 'created'];
        } catch (Throwable $e) {
            $steps[] = ['term_fee_payments (table)', 'ERROR - ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['term_fee_payments (table)', 'already present'];
    }
}

$missing_after = [];
if (db_table_exists($conn, 'term_fees') === false) $missing_after[] = 'term_fees';
if (db_table_exists($conn, 'term_fee_payments') === false) $missing_after[] = 'term_fee_payments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Term Fees — Database Migration 18</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Term Fees Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Term Fees Tracking</h1>
    <p>Creates the <code>term_fees</code> + <code>term_fee_payments</code> tables so the admin can see at a glance who has paid this term's school fees and who has not. Safe to run again.</p>
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
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> All schema objects are now in place. <a href="fees.php"><strong>Open Term Fees</strong></a></div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>.</div>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — term-fees tables already exist. <a href="fees.php"><strong>Open Term Fees</strong></a></div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <form method="POST" onsubmit="return confirm('Run the term-fees schema migration now?');">
                <?= csrf_field() ?>
                <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('refresh', 17) ?> Run Migration</button>
            </form>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <a class="btn btn-ghost" href="dashboard.php"><?= ui_icon('grid', 16) ?> Dashboard</a>
        </div>
    </div>
<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>
