<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

$schema_ready = db_table_exists($conn, 'applications');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if (!$schema_ready) {
        redirect('db_migrate06.php');
    }

    if ($action === 'status' && $id > 0) {
        $status = $_POST['status'] ?? '';
        $allowed = ['pending', 'contacted', 'payment_pending', 'payment_confirmed', 'active', 'rejected'];
        if (in_array($status, $allowed, true)) {
            $note = trim($_POST['admin_note'] ?? '');
            $stmt = $conn->prepare("UPDATE applications SET status = ?, admin_note = IF(? = '', admin_note, ?) WHERE id = ?");
            $stmt->bind_param("sssi", $status, $note, $note, $id);
            $stmt->execute();
        }
        redirect('applications.php');

    } elseif ($action === 'activate' && $id > 0) {
        /* Turn an approved application into an actual student account. */
        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $pass     = $_POST['password'] ?? '';
        $phone    = trim($_POST['phone'] ?? '');
        $designation = (int)($_POST['designation'] ?? ($_POST['hafiz'] ?? 0));
        $hafiz = $designation === 2 ? 1 : 0;
        $memorizing = $designation === 1 ? 1 : 0;

        if ($name === '' || $email === '' || $pass === '') {
            redirect('applications.php?error=' . urlencode('Name, email and password are required to activate.'));
        }
        if (strlen($pass) < 6) {
            redirect('applications.php?error=' . urlencode('Password must be at least 6 characters.'));
        }

        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            redirect('applications.php?error=' . urlencode("A student with email $email already exists."));
        }

        $hashed = password_hash($pass, PASSWORD_DEFAULT);
        $has_phone = db_column_exists($conn, 'users', 'phone');
        $has_hafiz = db_column_exists($conn, 'users', 'hafiz');
        $has_mem = db_column_exists($conn, 'users', 'memorizing');

        if ($has_phone && $has_hafiz && $has_mem) {
            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role, device_type, suspended, blocked, hafiz, memorizing) VALUES (?, ?, ?, ?, 'student', 'iphone', 0, 0, ?, ?)");
            $stmt->bind_param("ssssii", $name, $email, $phone, $hashed, $hafiz, $memorizing);
        } elseif ($has_phone && $has_hafiz) {
            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role, device_type, suspended, blocked, hafiz) VALUES (?, ?, ?, ?, 'student', 'iphone', 0, 0, ?)");
            $stmt->bind_param("ssssi", $name, $email, $phone, $hashed, $hafiz);
        } elseif ($has_phone) {
            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role, device_type, suspended, blocked) VALUES (?, ?, ?, ?, 'student', 'iphone', 0, 0)");
            $stmt->bind_param("ssss", $name, $email, $phone, $hashed);
        } else {
            $stmt = $conn->prepare("INSERT INTO users (name, email, password, role, device_type, suspended, blocked) VALUES (?, ?, ?, 'student', 'iphone', 0, 0)");
            $stmt->bind_param("sss", $name, $email, $hashed);
        }
        $stmt->execute();
        $new_student_id = (int)$conn->insert_id;

        /* If Hafiz, create first revision cycle */
        if ($hafiz === 1 && $new_student_id > 0 && db_table_exists($conn, 'hafiz_revision')) {
            try {
                $now = date('Y-m-d H:i:s');
                $ins = $conn->prepare("
                    INSERT INTO hafiz_revision (student_id, cycle_no, current_page, week_started_at, status, started_at)
                    VALUES (?, 1, 1, ?, 'active', ?)
                ");
                $ins->bind_param("iss", $new_student_id, $now, $now);
                $ins->execute();
            } catch (Throwable $e) { /* ignore */ }
        }

        /* If Memorizer, initialize the memorization state */
        if ($memorizing === 1 && $new_student_id > 0) {
            mem_start($conn, $new_student_id, 1);
        }

        /* Referral auto-link: match pending friend invites by phone. */
        if ($phone !== '' && db_column_exists($conn, 'student_invites', 'status')) {
            try {
                $stmt = $conn->prepare("UPDATE student_invites SET status = 'joined', joined_student_id = ? WHERE friend_phone = ? AND status = 'invited' ORDER BY id DESC LIMIT 1");
                $stmt->bind_param("is", $new_student_id, $phone);
                $stmt->execute();
            } catch (Throwable $e) { /* ignore */ }
        }

        $note = 'Activated on ' . date('d M Y H:i') . '. Login: ' . $email . '.';
        $stmt = $conn->prepare("UPDATE applications SET status = 'active', admin_note = ? WHERE id = ?");
        $stmt->bind_param("si", $note, $id);
        $stmt->execute();

        redirect('applications.php?activated=1');

    } elseif ($action === 'delete' && $id > 0) {
        $stmt = $conn->prepare("DELETE FROM applications WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        redirect('applications.php');
    }

    redirect('applications.php');
}

$filter = $_GET['filter'] ?? 'pending';
$where  = '';
$statuses = ['pending', 'contacted', 'payment_pending', 'payment_confirmed', 'active', 'rejected'];
if (in_array($filter, $statuses, true)) {
    $where = "WHERE status = '" . $conn->real_escape_string($filter) . "'";
} else {
    $filter = 'all';
    $where  = '';
}

$apps = null;
if ($schema_ready) {
    $apps = $conn->query("
        SELECT * FROM applications
        $where
        ORDER BY FIELD(status, 'pending','contacted','payment_pending','payment_confirmed','active','rejected'), id DESC
    ");
}

$counts = ['all' => 0, 'pending' => 0, 'contacted' => 0, 'payment_pending' => 0, 'payment_confirmed' => 0, 'active' => 0, 'rejected' => 0];
if ($schema_ready) {
    try {
        $res = $conn->query("SELECT status, COUNT(*) c FROM applications GROUP BY status");
        while ($r = $res->fetch_assoc()) {
            if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
        }
        $counts['all'] = array_sum($counts);
    } catch (Throwable $e) { /* ignore */ }
}

$status_labels = [
    'pending'          => ['New Application', 'badge-gold'],
    'contacted'        => ['Contacted', 'badge-blue'],
    'payment_pending'  => ['Payment Pending', 'badge-gold'],
    'payment_confirmed' => ['Payment Confirmed', 'badge-blue'],
    'active'           => ['Active', 'badge-green'],
    'rejected'         => ['Rejected', 'badge-red'],
];

$activated = isset($_GET['activated']);
$error     = isset($_GET['error']) ? trim($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Applications — Lisanun Mubeen Academy</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'applications', 'Applications', 'Management'); ?>

<div class="page-hero animate-rise">
    <h1>Student Applications</h1>
    <p>Public registration requests. Contact applicants on WhatsApp, confirm payment, then activate their accounts.</p>
</div>

<?php if (!$schema_ready): ?>
    <div class="alert alert-warning animate-rise">
        <?= ui_icon('alert', 16) ?> The <code>applications</code> table does not exist yet. Run
        <a href="db_migrate06.php"><strong>Database Migration</strong></a> to enable public registration.
    </div>
<?php endif; ?>

<?php if ($activated): ?>
    <div class="alert alert-success animate-rise">
        <?= ui_icon('check-circle', 16) ?>
        <span style="flex:1;"><strong>Student account activated.</strong> Share the login details with the student privately — the password is only communicated by you.</span>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger animate-rise"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
    <a class="btn <?= $filter === 'all' ? '' : 'btn-ghost' ?>" href="?filter=all">All (<?= $counts['all'] ?>)</a>
    <a class="btn <?= $filter === 'pending' ? '' : 'btn-ghost' ?>" href="?filter=pending">New (<?= $counts['pending'] ?>)</a>
    <a class="btn <?= $filter === 'contacted' ? '' : 'btn-ghost' ?>" href="?filter=contacted">Contacted (<?= $counts['contacted'] ?>)</a>
    <a class="btn <?= $filter === 'payment_pending' ? '' : 'btn-ghost' ?>" href="?filter=payment_pending">Payment Pending (<?= $counts['payment_pending'] ?>)</a>
    <a class="btn <?= $filter === 'payment_confirmed' ? '' : 'btn-ghost' ?>" href="?filter=payment_confirmed">Payment Confirmed (<?= $counts['payment_confirmed'] ?>)</a>
    <a class="btn <?= $filter === 'active' ? '' : 'btn-ghost' ?>" href="?filter=active">Active (<?= $counts['active'] ?>)</a>
    <a class="btn <?= $filter === 'rejected' ? '' : 'btn-ghost' ?>" href="?filter=rejected">Rejected (<?= $counts['rejected'] ?>)</a>
</div>

<?php if (!$schema_ready || !$apps || $apps->num_rows === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('clipboard', 40) ?></div>
        <div class="empty-title">No applications found</div>
        <p class="small" style="margin:0;">New public registrations will appear here as soon as students apply.</p>
    </div>
<?php else: ?>
    <div class="table-wrap animate-rise d1">
        <table class="table">
            <thead>
                <tr>
                    <th>Applicant</th>
                    <th>WhatsApp</th>
                    <th>Learning Info</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($a = $apps->fetch_assoc()): ?>
                    <?php
                    $wa = normalize_phone_to_intl($a['whatsapp_phone']);
                    $wa_link = 'https://wa.me/' . $wa . '?text=' . rawurlencode(
                        "Assalamu alaikum {$a['full_name']},\n\n" .
                        "Thank you for your interest in Lisanun Mubeen Academy.\n\n" .
                        "To activate your application, kindly pay the sum of N3,000 only (one term fee) into the account below:\n\n" .
                        "Bank: OPay\n" .
                        "Account Name: Yunus Zakari Abdulhameed\n" .
                        "Account Number: 9168862025\n\n" .
                        "After payment, please send your payment receipt/proof on this chat so we can confirm and activate your account immediately.\n\n" .
                        "JazakAllah khair."
                    );
                    ?>
                <tr>
                    <td class="cell-wrap">
                        <strong><?= htmlspecialchars($a['full_name']) ?></strong><br>
                        <span class="small text-muted"><?= htmlspecialchars($a['email'] ?: '—') ?></span>
                        <?php if (!empty($a['admin_note'])): ?>
                            <div class="small" style="margin-top:4px;color:var(--emerald-700);"><?= nl2br(htmlspecialchars($a['admin_note'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="small">+<?= htmlspecialchars($wa) ?></span></td>
                    <td class="cell-wrap">
                        <span class="small text-muted"><?= htmlspecialchars($a['learning_info'] ?: '—') ?></span>
                    </td>
                    <td><span class="small"><?= date('d M Y', strtotime($a['created_at'])) ?></span></td>
                    <td>
                        <?php $label = $status_labels[$a['status']] ?? ['Unknown', 'badge-grey']; ?>
                        <span class="badge <?= $label[1] ?>"><?= $label[0] ?></span>
                    </td>
                    <td>
                        <div style="display:flex;flex-direction:column;gap:8px;min-width:190px;">
                            <a class="btn btn-sm" style="background:linear-gradient(135deg,#25d366,#128c7e);color:#fff;" href="<?= $wa_link ?>" target="_blank" rel="noopener">
                                <?= ui_icon('phone', 14) ?> Contact on WhatsApp
                            </a>

                            <?php if ($a['status'] !== 'active' && $a['status'] !== 'rejected'): ?>
                                <form method="POST" style="display:flex;gap:6px;flex-wrap:wrap;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="status">
                                    <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                    <select class="form-select" name="status" style="flex:1;min-width:130px;padding:7px 10px;font-size:.82rem;">
                                        <?php foreach ($status_labels as $sk => $sl): ?>
                                            <?php if ($sk === 'active') continue; ?>
                                            <option value="<?= $sk ?>" <?= $sk === $a['status'] ? 'selected' : '' ?>><?= $sl[0] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-sm btn-ghost" type="submit"><?= ui_icon('check', 14) ?></button>
                                </form>
                            <?php endif; ?>

                            <?php if ($a['status'] === 'payment_confirmed' || $a['status'] === 'active'): ?>
                                <button class="btn btn-sm btn-gold" type="button" onclick="openActivate(<?= (int)$a['id'] ?>)">
                                    <?= ui_icon('user', 14) ?> Activate Account
                                </button>
                            <?php endif; ?>

                            <form method="POST" onsubmit="return confirm('Delete this application permanently?');" style="display:inline;">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit"><?= ui_icon('trash', 14) ?> Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Activate modal -->
<div class="modal" id="activateModal">
    <div class="modal-content" style="max-width:480px;">
        <span class="modal-close" onclick="closeActivate()">&times;</span>
        <h3 style="margin:0 0 6px;">Activate Student Account</h3>
        <p class="small text-muted" style="margin:0 0 16px;">
            This creates a real student account for the applicant. Set an initial password, then share the login
            details with the student privately (never in URLs, never stored in plain text).
        </p>
        <form method="POST" id="activateForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="activate">
            <input type="hidden" name="id" id="act_id">
            <div class="form-group">
                <label class="form-label" for="act_name">Full Name</label>
                <input class="form-input" type="text" id="act_name" name="name" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="act_email">Email (login)</label>
                <input class="form-input" type="email" id="act_email" name="email" required>
            </div>
            <div class="form-group">
                <label class="form-label" for="act_phone">Phone (WhatsApp)</label>
                <input class="form-input" type="tel" id="act_phone" name="phone" placeholder="e.g. 0801 234 5678">
            </div>
            <div class="form-group">
                <label class="form-label" for="act_password">Initial Password</label>
                <input class="form-input" type="text" id="act_password" name="password" required minlength="6">
                <small class="text-muted" style="font-size:.78rem;">Share this password with the student privately after activating.</small>
            </div>
            <?php if (db_column_exists($conn, 'users', 'hafiz')): ?>
            <div class="form-group">
                <label class="form-label" for="act_hafiz">Student Type</label>
                <select class="form-select" id="act_hafiz" name="designation">
                    <option value="0" selected>Non-Hafiz (Learner)</option>
                    <?php if (db_column_exists($conn, 'users', 'memorizing')): ?>
                    <option value="1">Memorizer (Qur'an Memorization)</option>
                    <?php endif; ?>
                    <option value="2">Hafiz (Revision Mode)</option>
                </select>
                <small class="text-muted" style="font-size:.78rem;">Memorizers memorize 604 pages day-by-day; Hafiz students revise from memory.</small>
            </div>
            <?php endif; ?>
            <button class="btn btn-gold btn-lg btn-block" type="submit"><?= ui_icon('user', 17) ?> Create Student Account</button>
        </form>
    </div>
</div>

<?php ui_page_end(); ?>

<script>
var appsData = <?php
    if ($schema_ready) {
        $res = $conn->query("SELECT id, full_name, whatsapp_phone, email FROM applications");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        echo json_encode($rows);
    } else {
        echo '[]';
    }
?>;

function openActivate(id) {
    var a = appsData.find(function (x) { return Number(x.id) === Number(id); });
    if (!a) return alert('Application not found.');
    document.getElementById('act_id').value = a.id;
    document.getElementById('act_name').value = a.full_name;
    document.getElementById('act_email').value = a.email || '';
    document.getElementById('act_phone').value = a.whatsapp_phone;
    document.getElementById('act_password').value = '';
    document.getElementById('activateModal').classList.add('open');
}
function closeActivate() {
    document.getElementById('activateModal').classList.remove('open');
}
document.addEventListener('click', function (e) {
    var m = document.getElementById('activateModal');
    if (m && m.classList.contains('open') && e.target === m) closeActivate();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeActivate();
});
</script>

</body>
</html>
