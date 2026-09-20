<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

$filter = $_GET['filter'] ?? 'all';
$where  = '';
if ($filter === 'pending')  $where = "WHERE e.status = 'pending'";
if ($filter === 'approved') $where = "WHERE e.status = 'approved'";
if ($filter === 'rejected') $where = "WHERE e.status = 'rejected'";
if ($filter === 'draft')    $where = "WHERE e.status = 'draft'";

$schema_ready = exam_attempts_has_exam_columns($conn);
$tier_ready   = exam_tier_schema_ready($conn);
$attempts = null;
if ($schema_ready && $tier_ready) {
    $attempts = $conn->query("
        SELECT e.id, e.student_id, e.term_id, e.submitted_at, e.status, e.overall_rating, e.overall_score, e.mistakes_count,
               e.question_count, e.total_days, e.day_no,
               u.name, u.email,
               (SELECT COUNT(*) FROM exam_answers a WHERE a.attempt_id = e.id) AS q_count,
               (SELECT COUNT(*) FROM exam_answers a WHERE a.attempt_id = e.id AND a.status = 'submitted') AS ans_count
        FROM exam_attempts e
        JOIN users u ON u.id = e.student_id
        $where
        ORDER BY e.submitted_at DESC, e.id DESC
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Exam Submissions</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'exams', 'Exam Submissions', 'Exams'); ?>

<div class="page-hero animate-rise">
    <h1>Exam Submissions</h1>
    <p>Review student examination attempts and release results.</p>
</div>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
    <a class="btn btn-gold" href="exam_settings.php"><?= ui_icon('clock', 16) ?> Exam Settings / Toggle Mode</a>
    <a class="btn btn-ghost" href="exam_defaults.php"><?= ui_icon('alert', 16) ?> Defaulters &amp; Payments</a>
    <a class="btn <?= $filter === 'all' ? '' : 'btn-ghost' ?>" href="?filter=all">All</a>
    <a class="btn <?= $filter === 'draft' ? '' : 'btn-ghost' ?>" href="?filter=draft">In Progress</a>
    <a class="btn <?= $filter === 'pending' ? '' : 'btn-ghost' ?>" href="?filter=pending">Pending</a>
    <a class="btn <?= $filter === 'approved' ? '' : 'btn-ghost' ?>" href="?filter=approved">Accepted</a>
    <a class="btn <?= $filter === 'rejected' ? '' : 'btn-ghost' ?>" href="?filter=rejected">Retake</a>
</div>

<?php if ($attempts === null): ?>
    <div class="alert alert-warning animate-rise">
        <?= ui_icon('alert', 16) ?> The exam schema is incomplete (missing the <code>term_id</code>/<code>mistakes_count</code> columns or the
        <strong>tiered-exam columns</strong> <code>question_count</code>/<code>total_days</code>/<code>day_no</code>).
        Run <a href="db_migrate.php"><strong>Database Migration</strong></a> then
        <a href="db_migrate06.php"><strong>Migration (v06)</strong></a> first — this page cannot be shown safely until then.
    </div>
<?php elseif ($attempts->num_rows === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('clipboard', 40) ?></div>
        <div class="empty-title">No exam submissions found</div>
        <p class="small" style="margin:0;">When students submit their exam, attempts will appear here.</p>
    </div>
<?php else: ?>
    <div class="table-wrap animate-rise d1">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Student</th>
                    <th>Term</th>
                    <th>Questions</th>
                    <th>Submitted</th>
                    <th>Status</th>
                    <th>Mistakes / Overall</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($e = $attempts->fetch_assoc()): ?>
                <tr>
                    <td><span class="badge badge-grey">#<?= (int)$e['id'] ?></span></td>
                    <td>
                        <strong><?= htmlspecialchars($e['name']) ?></strong><br>
                        <span class="small text-muted"><?= htmlspecialchars($e['email']) ?></span>
                    </td>
                    <td><span class="badge badge-grey"><?= htmlspecialchars(exam_term_label($e['term_id'], $conn)) ?></span></td>
                    <td>
                        <?php if ($e['status'] === 'draft'): ?>
                            <strong><?= (int)$e['ans_count'] ?> / <?= (int)($e['question_count'] ?: $e['q_count']) ?></strong>
                            <span class="small text-muted">answered<?= (int)$e['total_days'] > 1 ? ' · day ' . (int)$e['day_no'] . '/' . (int)$e['total_days'] : '' ?></span>
                        <?php else: ?>
                            <?= (int)$e['q_count'] ?> / <?= (int)($e['question_count'] ?: 3) ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $e['status'] === 'draft' ? '—' : ($e['submitted_at'] ? date('d M Y H:i', strtotime($e['submitted_at'])) : '—') ?></td>
                    <td>
                        <?php if ($e['status'] === 'draft'): ?>
                            <span class="badge badge-blue">In Progress</span>
                        <?php elseif ($e['status'] === 'pending'): ?>
                            <span class="badge badge-gold">Pending Review</span>
                        <?php elseif ($e['status'] === 'rejected'): ?>
                            <span class="badge badge-red">Retake Required</span>
                        <?php else: ?>
                            <span class="badge badge-green">Accepted</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($e['status'] === 'pending'): ?>
                            —
                        <?php elseif ($e['status'] === 'draft'): ?>
                            —
                        <?php else: ?>
                            <?= (int)$e['mistakes_count'] ?> / max 3<br>
                            <span class="small text-muted"><?= htmlspecialchars($e['overall_rating'] ?? '') ?> (<?= (int)$e['overall_score'] ?>%)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <?php if ($e['status'] === 'draft'): ?>
                                <span class="small text-muted">Student is still answering</span>
                            <?php else: ?>
                            <a class="btn btn-sm" href="review_exam.php?id=<?= (int)$e['id'] ?>"><?= ui_icon('check-circle', 14) ?> <?= $e['status'] === 'pending' ? 'Review' : 'View' ?></a>
                            <?php if ($e['status'] === 'pending'): ?>
                                <form method="POST" action="reset_exam_attempt.php" onsubmit="return confirm('Reset this student\'s pending attempt so they can resubmit? Their audio will be deleted.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="attempt_id" value="<?= (int)$e['id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit"><?= ui_icon('refresh', 14) ?> Reset</button>
                                </form>
                            <?php else: ?>
                                <form method="POST" action="reset_exam_attempt.php" onsubmit="return confirm('Delete this reviewed exam permanently? The student\'s exam audio will be deleted and their exam locks cleared.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="attempt_id" value="<?= (int)$e['id'] ?>">
                                    <button class="btn btn-sm btn-danger" type="submit"><?= ui_icon('trash', 14) ?> Delete</button>
                                </form>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>
