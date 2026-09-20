<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/**
 * Return charset/collation info for announcements text columns.
 * Never throws. e.g. ['title' => ['charset' => 'utf8', 'collation' => 'utf8_general_ci'], ...]
 */
function announcements_charset_status($conn) {
    $out = [];
    try {
        $res = $conn->query("
            SELECT COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'announcements'
              AND COLUMN_NAME IN ('title', 'message')
        ");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $out[$row['COLUMN_NAME']] = [
                    'charset'   => $row['CHARACTER_SET_NAME'],
                    'collation' => $row['COLLATION_NAME'],
                ];
            }
        }
    } catch (Throwable $e) {
        // status display only; errors surface during migration run
    }
    return $out;
}

/* ============ CURRENT SCHEMA STATUS ============ */
$table_exists = db_table_exists($conn, 'announcements');
$charset_status = $table_exists ? announcements_charset_status($conn) : [];

$missing = [];
if (!$table_exists) {
    $missing[] = 'announcements (table)';
} else {
    foreach (['title', 'message'] as $col) {
        $cs = strtolower((string)($charset_status[$col]['charset'] ?? ''));
        if ($cs !== 'utf8mb4') {
            $missing[] = "announcements.$col charset (" . ($charset_status[$col]['charset'] ?? 'unknown') . ' → utf8mb4)';
        }
    }
}

$pending = count($missing);

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    if (!db_table_exists($conn, 'announcements')) {
        $steps[] = ['announcements (table)', 'ERROR — table does not exist, nothing to convert'];
    } else {
        try {
            $conn->query("ALTER TABLE announcements CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $steps[] = ['announcements charset', 'converted to utf8mb4 / utf8mb4_unicode_ci'];
        } catch (Throwable $e) {
            // Fallback for old MySQL / key-length limits (error 1071): shrink title key width, then convert.
            if (stripos($e->getMessage(), '1071') !== false || stripos($e->getMessage(), 'key was too long') !== false) {
                try {
                    $conn->query("ALTER TABLE announcements MODIFY title VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL");
                    $conn->query("ALTER TABLE announcements MODIFY message TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $steps[] = ['announcements charset', 'converted with VARCHAR(191) fallback for title (key-length limit)'];
                } catch (Throwable $e2) {
                    $steps[] = ['announcements charset', 'ERROR — ' . $e2->getMessage()];
                }
            } else {
                $steps[] = ['announcements charset', 'ERROR — ' . $e->getMessage()];
            }
        }
    }
}

$missing_after = [];
$table_exists_after = db_table_exists($conn, 'announcements');
if (!$table_exists_after) {
    $missing_after[] = 'announcements (table)';
} else {
    $after_status = announcements_charset_status($conn);
    foreach (['title', 'message'] as $col) {
        if (strtolower((string)($after_status[$col]['charset'] ?? '')) !== 'utf8mb4') {
            $missing_after[] = "announcements.$col charset";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements Emoji — Database Migration</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Announcements Emoji Migration', 'System'); ?>

<div class="page-hero animate-rise">
    <h1>Database Migration — Announcements Emoji Support</h1>
    <p>Converts <code>announcements</code> text columns to <code>utf8mb4 / utf8mb4_unicode_ci</code> so emoji (🎉🔥 etc.) save and display instead of turning into <code>???</code>. Safe to run again — every step checks current charset first. Existing <code>???</code> rows are left untouched and must be re-posted.</p>
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
                                <?php elseif (strpos($st[1], 'already') === 0 || strpos($st[1], 'skipped') === 0): ?>
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
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> Announcements now support emoji. Post a test announcement with 🎉 to confirm it renders on the admin list and student pages.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <a class="btn btn-ghost" href="announcements.php"><?= ui_icon('bell', 16) ?> Back to Announcements</a>
            <a class="btn btn-ghost" href="dashboard.php"><?= ui_icon('grid', 16) ?> Dashboard</a>
        </div>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if (!$table_exists): ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> The <code>announcements</code> table does not exist yet — create an announcement first, then re-run this migration.</div>
        <?php elseif ($pending === 0): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — <code>announcements.title</code> and <code>announcements.message</code> are already <code>utf8mb4</code>.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> column(s) need conversion: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
            <p class="small text-muted">
                Emoji need 4-byte <code>utf8mb4</code>. Columns still on <code>utf8</code>/<code>latin1</code> silently turn emoji into
                <code>?</code> on <code>INSERT</code> — that damage is irreversible, so affected rows must be re-posted after the fix.
            </p>
            <form method="POST" onsubmit="return confirm('Convert announcements text columns to utf8mb4 now?');">
                <?= csrf_field() ?>
                <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('refresh', 17) ?> Run Migration</button>
            </form>
        <?php endif; ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
            <a class="btn btn-ghost" href="announcements.php"><?= ui_icon('bell', 16) ?> Back to Announcements</a>
            <a class="btn btn-ghost" href="dashboard.php"><?= ui_icon('grid', 16) ?> Dashboard</a>
        </div>
    </div>

<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>
