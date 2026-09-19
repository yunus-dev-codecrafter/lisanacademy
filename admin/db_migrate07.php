<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ CURRENT SCHEMA STATUS ============ */
$missing = [];

if (!db_column_exists($conn, 'users', 'phone')) $missing[] = 'users.phone';
if (!db_column_exists($conn, 'student_learning', 'start_verse')) $missing[] = 'student_learning.start_verse';

$pending = count($missing);

/* Confirmed WhatsApp numbers queued for seeding onto students (matched by
   exact name). Add new entries here instead of editing records by hand —
   re-running the migration is safe because seeding is idempotent. */
$PHONE_SEED = [
    'Aminu Ishaq Tukur' => '+234 810 919 9300',
];
$has_phone_seed = count($PHONE_SEED) > 0;

/* ============ RUN MIGRATION ============ */
$steps = [];
$ran = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $ran = true;

    /* 1. users.phone (used by the admin "Message Student" WhatsApp feature) */
    if (!db_column_exists($conn, 'users', 'phone')) {
        try {
            $conn->query("ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL");
            $steps[] = ['users.phone', 'added'];
        } catch (Throwable $e) {
            $steps[] = ['users.phone', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['users.phone', 'already present'];
    }

    /* 2. Backfill users.phone from the applicant's WhatsApp number when the
          accounts were activated from an application (matched by email). */
    if (db_column_exists($conn, 'users', 'phone') && db_table_exists($conn, 'applications')) {
        try {
            $conn->query("
                UPDATE users u
                LEFT JOIN applications a ON a.email = u.email
                SET u.phone = COALESCE(NULLIF(u.phone, ''), a.whatsapp_phone)
                WHERE u.role = 'student'
                  AND a.whatsapp_phone IS NOT NULL
                  AND a.whatsapp_phone != ''
            ");
            $steps[] = ['users.phone backfill (from applications)', 'done'];
        } catch (Throwable $e) {
            $steps[] = ['users.phone backfill (from applications)', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['users.phone backfill (from applications)', 'skipped (users.phone or applications missing)'];
    }

    /* 3. student_learning.start_verse (student chooses where in a surah to begin) */
    if (!db_column_exists($conn, 'student_learning', 'start_verse')) {
        try {
            $conn->query("ALTER TABLE student_learning ADD COLUMN start_verse INT NOT NULL DEFAULT 1 AFTER verses_per_request");
            $steps[] = ['student_learning.start_verse', 'added'];
        } catch (Throwable $e) {
            $steps[] = ['student_learning.start_verse', 'ERROR — ' . $e->getMessage()];
        }
    } else {
        $steps[] = ['student_learning.start_verse', 'already present'];
    }

    /* 4. Seed confirmed WhatsApp numbers for specific students (matched by
          exact name). Add new entries to $PHONE_SEED here instead of editing
          student records by hand — re-running the migration is safe. */
    if (db_column_exists($conn, 'users', 'phone')) {
        foreach ($PHONE_SEED as $name => $phone) {
            try {
                $normalized = preg_replace('/[^0-9]/', '', (string)$phone);
                $stmt = $conn->prepare("UPDATE users SET phone = ? WHERE name = ? AND role = 'student'");
                $stmt->bind_param("ss", $normalized, $name);
                $stmt->execute();
                $affected = $conn->affected_rows;
                $steps[] = ['users.phone seed (' . $name . ')', $affected > 0 ? "updated ($affected)" : 'no matching student'];
            } catch (Throwable $e) {
                $steps[] = ['users.phone seed (' . $name . ')', 'ERROR — ' . $e->getMessage()];
            }
        }
    } else {
        $steps[] = ['users.phone seed (specific students)', 'skipped (users.phone missing)'];
    }
}

$missing_after = [];
if (!db_column_exists($conn, 'users', 'phone')) $missing_after[] = 'users.phone';
if (!db_column_exists($conn, 'student_learning', 'start_verse')) $missing_after[] = 'student_learning.start_verse';
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
    <h1>Database Migration — Student Phone &amp; Start Verse</h1>
    <p>Adds the <code>users.phone</code> column (backfilled from applications) and the <code>student_learning.start_verse</code> column. Safe to run again — every step checks whether the object already exists before touching it.</p>
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
            <div class="alert alert-success" style="margin-top:12px;"><?= ui_icon('check-circle', 16) ?> All schema objects are now in place.</div>
        <?php else: ?>
            <div class="alert alert-warning" style="margin-top:12px;"><?= ui_icon('alert', 16) ?> Still missing: <?= htmlspecialchars(implode(', ', $missing_after)) ?>. Review the errors above and retry.</div>
        <?php endif; ?>
    </div>
<?php else: ?>

    <div class="card animate-rise d1" style="max-width:760px;">
        <h3 style="margin-top:0;">Current Schema Status</h3>
        <?php if ($pending === 0 && !$has_phone_seed): ?>
            <div class="alert alert-success" style="margin-top:0;"><?= ui_icon('check-circle', 16) ?> Nothing to do — the database already has every column this feature needs.</div>
        <?php else: ?>
            <?php if ($pending > 0): ?>
                <div class="alert alert-warning" style="margin-top:0;"><?= ui_icon('alert', 16) ?> <strong><?= $pending ?></strong> object(s) missing: <code><?= htmlspecialchars(implode('</code>, <code>', $missing)) ?></code></div>
                <p class="small text-muted">
                    <strong>users.phone</strong> lets the admin "Message Student" WhatsApp feature open the student's own
                    number instead of falling back to the academy number. <strong>student_learning.start_verse</strong>
                    powers the new "start from a chosen verse" option in My Learning.
                </p>
            <?php endif; ?>
            <?php if ($has_phone_seed): ?>
                <div class="alert alert-info" style="margin-top:0;"><?= ui_icon('phone', 16) ?> <strong><?= count($PHONE_SEED) ?></strong> confirmed WhatsApp number(s) queued for seeding (matched by student name). Re-running is safe — existing numbers are simply re-set to these.</div>
            <?php endif; ?>
            <form method="POST" onsubmit="return confirm('Run the student-phone + start-verse schema migration now?');">
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