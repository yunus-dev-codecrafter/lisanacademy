<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

if (!db_table_exists($conn, 'hafiz_weekly_tests')) {
    ui_page_start('admin', 'dashboard', 'Weekly Tests', 'Hafiz Tests');
    echo '<div class="page-hero animate-rise"><h1>Weekly Tests</h1><p>Hafiz weekly tests.</p></div>';
    echo '<div class="card"><div class="alert alert-warning">' . ui_icon('alert', 16) . ' The weekly test system has not been set up yet. Please run <code>admin/db_migrate11.php</code>.</div></div>';
    ui_page_end();
    exit;
}

$filter = $_GET['filter'] ?? 'submitted';
$allowed_filters = ['submitted', 'passed', 'failed', 'draft', 'expired', 'all'];
if (!in_array($filter, $allowed_filters, true)) $filter = 'submitted';

$sql = "
    SELECT t.id, t.student_id, t.week_no, t.status, t.started_at, t.submitted_at, t.reviewed_at,
           u.name AS student_name, u.email AS student_email,
           (SELECT COUNT(*) FROM hafiz_test_answers a WHERE a.test_id = t.id) AS q_count
    FROM hafiz_weekly_tests t
    JOIN users u ON u.id = t.student_id
";
$where = [];
$params = [];
$types = '';
if ($filter !== 'all') {
    $where[] = "t.status = ?";
    $params[] = $filter;
    $types .= 's';
}
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY t.started_at DESC, t.id DESC';

$tests = [];
$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$rows = $stmt->get_result();
while ($r = $rows->fetch_assoc()) $tests[] = $r;

$counts = [];
$cres = $conn->query("SELECT status, COUNT(*) c FROM hafiz_weekly_tests GROUP BY status");
while ($cr = $cres->fetch_assoc()) $counts[$cr['status']] = (int)$cr['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Weekly Tests — Hafiz</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'dashboard', 'Hafiz Weekly Tests', 'Teaching'); ?>

<div class="page-hero animate-rise">
    <h1><?= ui_icon('calendar-check', 24) ?> Hafiz Weekly Tests</h1>
    <p>Every week, once a Hafiz student recites their 20 pages, they take a 3-question test on completed revision. Listen to the recordings and mark Pass or Fail.</p>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
    <a class="btn <?= $filter === 'submitted' ? 'btn-gold' : 'btn-ghost' ?>" href="hafiz_tests.php?filter=submitted">Awaiting Review <?= $filter === 'submitted' ? '<span class="badge badge-red">' . (int)($counts['submitted'] ?? 0) . '</span>' : '' ?></a>
    <a class="btn <?= $filter === 'passed' ? 'btn-gold' : 'btn-ghost' ?>" href="hafiz_tests.php?filter=passed">Passed (<?= (int)($counts['passed'] ?? 0) ?>)</a>
    <a class="btn <?= $filter === 'failed' ? 'btn-gold' : 'btn-ghost' ?>" href="hafiz_tests.php?filter=failed">Failed (<?= (int)($counts['failed'] ?? 0) ?>)</a>
    <a class="btn <?= $filter === 'draft' ? 'btn-gold' : 'btn-ghost' ?>" href="hafiz_tests.php?filter=draft">In Progress (<?= (int)($counts['draft'] ?? 0) ?>)</a>
    <a class="btn <?= $filter === 'expired' ? 'btn-gold' : 'btn-ghost' ?>" href="hafiz_tests.php?filter=expired">Expired (<?= (int)($counts['expired'] ?? 0) ?>)</a>
    <a class="btn <?= $filter === 'all' ? 'btn-gold' : 'btn-ghost' ?>" href="hafiz_tests.php?filter=all">All</a>
</div>

<div class="card animate-rise d1">
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Week</th>
                    <th>Status</th>
                    <th>Questions</th>
                    <th>Started</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$tests): ?>
                    <tr><td colspan="7" class="small text-muted">No weekly tests in this view.</td></tr>
                <?php endif; ?>
                <?php foreach ($tests as $t): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($t['student_name']) ?></strong>
                            <div class="small text-muted"><?= htmlspecialchars($t['student_email']) ?></div>
                        </td>
                        <td><span class="badge badge-blue">Week <?= (int)$t['week_no'] ?></span></td>
                        <td>
                            <?php if ($t['status'] === 'passed'): ?>
                                <span class="badge badge-green">Passed</span>
                            <?php elseif ($t['status'] === 'failed'): ?>
                                <span class="badge badge-red">Failed</span>
                            <?php elseif ($t['status'] === 'submitted'): ?>
                                <span class="badge badge-gold">Awaiting Review</span>
                            <?php elseif ($t['status'] === 'draft'): ?>
                                <span class="badge badge-blue">In Progress</span>
                            <?php else: ?>
                                <span class="badge badge-grey"><?= ucfirst($t['status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$t['q_count'] ?></td>
                        <td class="small"><?= $t['started_at'] ? date('d M Y, g:i A', strtotime($t['started_at'])) : '—' ?></td>
                        <td class="small"><?= $t['submitted_at'] ? date('d M Y, g:i A', strtotime($t['submitted_at'])) : '—' ?></td>
                        <td style="text-align:right;">
                            <a class="btn btn-sm <?= $t['status'] === 'submitted' ? 'btn-gold' : 'btn-ghost' ?>" href="review_hafiz_test.php?id=<?= (int)$t['id'] ?>">
                                <?= $t['status'] === 'submitted' ? ui_icon('gavel', 14) . ' Review' : ui_icon('eye', 14) . ' View' ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php ui_page_end(); ?>

</body>
</html>