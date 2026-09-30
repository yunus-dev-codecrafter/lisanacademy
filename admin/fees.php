<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');
$admin_id = (int)($_SESSION['user_id'] ?? 0);

/* ---------- POST actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if (term_fees_installed($conn)) {
        if ($action === 'create_term') {
            $label = trim($_POST['term_label'] ?? '');
            $amount = (float)($_POST['amount'] ?? 0);
            $due = trim($_POST['due_date'] ?? '');
            if ($label !== '') {
                try {
                    if ($due !== '') {
                        $stmt = $conn->prepare("INSERT INTO term_fees (term_label, amount, due_date) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_date = VALUES(due_date)");
                        $stmt->bind_param("sds", $label, $amount, $due);
                        $stmt->execute();
                    } else {
                        $stmt = $conn->prepare("INSERT INTO term_fees (term_label, amount, due_date) VALUES (?, ?, NULL) ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_date = VALUES(due_date)");
                        $stmt->bind_param("sd", $label, $amount);
                        $stmt->execute();
                    }
                } catch (Throwable $e) { /* ignore, shown below */ }
            }
        } elseif ($action === 'mark_paid' || $action === 'mark_pending') {
            $term_id = (int)($_POST['term_id'] ?? 0);
            $student_id = (int)($_POST['student_id'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($term_id > 0 && $student_id > 0) {
                $status = $action === 'mark_paid' ? 'paid' : 'pending';
                try {
                    if ($status === 'paid') {
                        $paid_at = date('Y-m-d H:i:s');
                        $stmt = $conn->prepare("INSERT INTO term_fee_payments (term_id, student_id, status, paid_at, note, recorded_by)
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE status = VALUES(status), paid_at = VALUES(paid_at), note = VALUES(note), recorded_by = VALUES(recorded_by)");
                        $stmt->bind_param("iisssi", $term_id, $student_id, $status, $paid_at, $note, $admin_id);
                        $stmt->execute();
                    } else {
                        $stmt = $conn->prepare("INSERT INTO term_fee_payments (term_id, student_id, status, paid_at, note, recorded_by)
                            VALUES (?, ?, ?, NULL, ?, ?)
                            ON DUPLICATE KEY UPDATE status = VALUES(status), paid_at = NULL, note = VALUES(note), recorded_by = VALUES(recorded_by)");
                        $stmt->bind_param("iissi", $term_id, $student_id, $status, $note, $admin_id);
                        $stmt->execute();
                    }
                } catch (Throwable $e) { /* ignore */ }
            }
        }
    }
    $redir = 'fees.php?term_id=' . (int)($_POST['term_id'] ?? $_GET['term_id'] ?? 0);
    redirect($redir === 'fees.php?term_id=0' ? 'fees.php' : $redir);
}

$installed = term_fees_installed($conn);
$terms = $installed ? term_fees_all($conn) : [];
$term_id = (int)($_GET['term_id'] ?? 0);
$current = null;
if ($installed) {
    if ($term_id > 0) {
        foreach ($terms as $t) { if ((int)$t['id'] === $term_id) { $current = $t; break; } }
    }
    if (!$current) $current = term_fee_current($conn);
    if ($current) $term_id = (int)$current['id'];
}

$filter = $_GET['status'] ?? 'all'; // all | paid | unpaid
$q = trim($_GET['q'] ?? '');
if (!in_array($filter, ['all', 'paid', 'unpaid'], true)) $filter = 'all';

$rows = [];
$paid_count = 0; $unpaid_count = 0; $total_students = 0;
if ($installed && $current) {
    $map = term_fee_status_map($conn, $term_id);
    try {
        $res = $conn->query("SELECT id, name, email FROM users WHERE role = 'student' ORDER BY name ASC");
        while ($res && ($s = $res->fetch_assoc())) {
            $sid = (int)$s['id'];
            $pay = $map[$sid] ?? null;
            $is_paid = $pay && ((string)$pay['status'] === 'paid' || (string)$pay['status'] === 'waived');
            $total_students++;
            if ($is_paid) $paid_count++; else $unpaid_count++;
            $row = $s + ['is_paid' => $is_paid, 'pay' => $pay];
            // filters
            if ($filter === 'paid' && !$is_paid) continue;
            if ($filter === 'unpaid' && $is_paid) continue;
            if ($q !== '' && stripos($s['name'] . ' ' . $s['email'], $q) === false) continue;
            $rows[] = $row;
        }
    } catch (Throwable $e) { /* ignore */ }
}

/* CSV export */
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $installed && $current) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="term_fees_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $current['term_label']) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Email', 'Status', 'Paid At', 'Note']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['name'], $r['email'], $r['is_paid'] ? 'paid' : 'unpaid',
            $r['pay']['paid_at'] ?? '', $r['pay']['note'] ?? '']);
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Term Fees</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'fees', 'Term Fees', 'Payments'); ?>

<div class="page-hero animate-rise">
    <h1>Term School Fees</h1>
    <p>At a glance: who has paid this term's fees and who has not. (Separate from the N500 exam reopen fee on <a href="exam_defaults.php">Defaulters &amp; Payments</a>.)</p>
</div>

<?php if (!$installed): ?>
    <div class="alert alert-warning animate-rise">
        <?= ui_icon('alert', 16) ?> Term-fees tables are missing. Run
        <a href="db_migrate18.php"><strong>Migration 18</strong></a> first.
    </div>
<?php else: ?>

<div class="card animate-rise d1">
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_term">
        <div class="form-group" style="margin:0;min-width:180px;">
            <label class="form-label">New term label</label>
            <input class="form-input" name="term_label" placeholder="e.g. 2026 Term 1" required maxlength="100">
        </div>
        <div class="form-group" style="margin:0;width:140px;">
            <label class="form-label">Amount (₦)</label>
            <input class="form-input" name="amount" type="number" min="0" step="50" value="0">
        </div>
        <div class="form-group" style="margin:0;width:170px;">
            <label class="form-label">Due date</label>
            <input class="form-input" name="due_date" type="date">
        </div>
        <button class="btn btn-gold" type="submit"><?= ui_icon('check', 15) ?> Create Term</button>
    </form>
</div>

<?php if (!$current): ?>
    <div class="empty animate-rise"><div class="empty-title">No term yet</div><p class="small">Create your first term above, then mark payments below.</p></div>
<?php else: ?>
<div class="stat-grid animate-rise d1">
    <div class="stat-card stat-green"><span class="stat-ico"><?= ui_icon('check-circle', 22) ?></span><span class="stat-label">Paid — <?= htmlspecialchars($current['term_label']) ?></span><span class="stat-value"><?= $paid_count ?></span><span class="stat-sub"><?= htmlspecialchars(number_format((float)$current['amount'], 0)) ?> ₦ expected each</span></div>
    <div class="stat-card stat-gold"><span class="stat-ico"><?= ui_icon('clock', 22) ?></span><span class="stat-label">Unpaid</span><span class="stat-value"><?= $unpaid_count ?></span><span class="stat-sub">Needs follow-up</span></div>
    <div class="stat-card stat-blue"><span class="stat-ico"><?= ui_icon('users', 22) ?></span><span class="stat-label">Total Students</span><span class="stat-value"><?= $total_students ?></span><span class="stat-sub">Across all categories</span></div>
</div>

<div class="card animate-rise d2" style="margin-top:14px;">
    <form method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <div class="form-group" style="margin:0;min-width:180px;">
            <label class="form-label">Term</label>
            <select class="form-input" name="term_id" onchange="this.form.submit()">
                <?php foreach ($terms as $t): ?>
                    <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === $term_id ? 'selected' : '' ?>><?= htmlspecialchars($t['term_label']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin:0;">
            <label class="form-label">Status</label>
            <select class="form-input" name="status" onchange="this.form.submit()">
                <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All</option>
                <option value="paid" <?= $filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                <option value="unpaid" <?= $filter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
            </select>
        </div>
        <div class="form-group" style="margin:0;flex:1;min-width:200px;">
            <label class="form-label">Search</label>
            <input class="form-input" type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name or email…">
        </div>
        <button class="btn btn-ghost" type="submit"><?= ui_icon('search', 15) ?> Filter</button>
        <a class="btn btn-ghost" href="fees.php?term_id=<?= $term_id ?>&status=<?= $filter ?>&q=<?= urlencode($q) ?>&export=csv"><?= ui_icon('download', 15) ?> CSV</a>
    </form>
</div>

<div class="table-wrap animate-rise d2">
    <table class="table">
        <thead><tr><th>Student</th><th>Status</th><th>Paid At / Note</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['name']) ?></strong><br><span class="small text-muted"><?= htmlspecialchars($r['email']) ?></span></td>
                <td><?= $r['is_paid'] ? '<span class="badge badge-green">Paid</span>' : '<span class="badge badge-gold">Unpaid</span>' ?></td>
                <td class="small text-muted">
                    <?= $r['pay']['paid_at'] ? htmlspecialchars(date('d M Y', strtotime($r['pay']['paid_at']))) : '—' ?>
                    <?= !empty($r['pay']['note']) ? '<div>' . htmlspecialchars($r['pay']['note']) . '</div>' : '' ?>
                </td>
                <td>
                    <?php if ($r['is_paid']): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark <?= htmlspecialchars(addslashes($r['name'])) ?> as UNPAID?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_pending">
                            <input type="hidden" name="term_id" value="<?= $term_id ?>">
                            <input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-ghost" type="submit">Mark Unpaid</button>
                        </form>
                    <?php else: ?>
                        <form method="POST" style="display:inline-flex;gap:6px;flex-wrap:wrap;" onsubmit="return confirm('Confirm fee received from <?= htmlspecialchars(addslashes($r['name'])) ?>?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="mark_paid">
                            <input type="hidden" name="term_id" value="<?= $term_id ?>">
                            <input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>">
                            <input class="form-input" name="note" placeholder="Receipt / note (optional)" style="max-width:150px;padding:4px 8px;font-size:.8rem;">
                            <button class="btn btn-sm btn-gold" type="submit"><?= ui_icon('check', 13) ?> Paid</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($rows)): ?>
            <tr><td colspan="4" class="text-center text-muted">No students match this filter.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>
