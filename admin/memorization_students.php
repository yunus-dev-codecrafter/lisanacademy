<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

if (!mem_engine_installed($conn)) {
    header("Location: dashboard.php?error=" . urlencode('Memorization engine is not migrated yet. Run the migration first.'));
    exit;
}

$snapshot = mem_students_snapshot($conn);
$lookup = [];
foreach ($snapshot as $row) {
    $row['name'] = $row['name'] ?? ('Student #' . (int)$row['id']);
    $st = mem_get_state($conn, (int)$row['id']);
    $task = $st ? mem_compute_task($conn, $st) : null;
    $row['_state'] = $st;
    $row['_task'] = $task;
    $row['pct'] = $st ? (int)round(((int)$st['pages_memorized'] / 604) * 100) : 0;
    $row['current_page'] = $st ? (int)$st['pages_memorized'] + 1 : 1;
    $lookup[] = $row;
}

$active_count = 0;
$paused_count = 0;
$pending_murajaah = 0;
foreach ($lookup as $r) {
    if (($r['_state']['status'] ?? '') === 'active') $active_count++;
    if (($r['_state']['status'] ?? '') === 'paused') $paused_count++;
    if (($r['_state']['status'] ?? '') === 'active' && ($r['_task']['task_type'] ?? '') === 'murajaah') $pending_murajaah++;
}
?>
<!DOCTYPE html>
<html>
<head>
<title>Memorization Students</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'memorization', 'Memorization', 'Students'); ?>
<?= csrf_field() ?>

<div class="page-hero animate-rise">
    <h1>Qur'an Memorization Students</h1>
    <p>Monitor every Memorizer's 604-page journey and Muraja'ah submissions.</p>
</div>

<?php if (isset($_GET['activated'])): ?>
    <div class="alert alert-success"><?= ui_icon('check-circle', 16) ?> Memorization activated.</div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($_GET['error']) ?></div>
<?php endif; ?>

<div class="stat-grid animate-rise d1">
    <div class="stat-card stat-gold">
        <span class="stat-ico"><?= ui_icon('users', 22) ?></span>
        <span class="stat-label">Memorizers</span>
        <span class="stat-value"><?= count($lookup) ?></span>
        <span class="stat-sub">Students with the designation</span>
    </div>
    <div class="stat-card stat-green">
        <span class="stat-ico"><?= ui_icon('bolt', 22) ?></span>
        <span class="stat-label">Active Journeys</span>
        <span class="stat-value"><?= $active_count ?></span>
        <span class="stat-sub">Unpaused &amp; progressing</span>
    </div>
    <div class="stat-card stat-blue">
        <span class="stat-ico"><?= ui_icon('pause', 22) ?></span>
        <span class="stat-label">Paused</span>
        <span class="stat-value"><?= $paused_count ?></span>
        <span class="stat-sub">Resume them when ready</span>
    </div>
    <div class="stat-card stat-gold" style="border-color:var(--emerald-300, #6ee7b7);">
        <span class="stat-ico"><?= ui_icon('refresh', 22) ?></span>
        <span class="stat-label">Muraja'ah Due</span>
        <span class="stat-value"><?= $pending_murajaah ?></span>
        <span class="stat-sub">Active students on an assessment day</span>
    </div>
</div>

<?php if (empty($lookup)): ?>
    <div class="empty animate-rise d2">
        <div class="empty-icon"><?= ui_icon('star', 40) ?></div>
        <div class="empty-title">No memorizing students yet</div>
        <p class="small" style="margin:0;">Designate a student as a Memorizer from their student detail page to get started.</p>
    </div>
<?php else: ?>

<div class="card animate-rise d2" style="margin-top:16px;overflow-x:auto;">
<table class="mem-table" style="width:100%;border-collapse:collapse;min-width:760px;">
    <thead>
    <tr style="text-align:left;border-bottom:1px solid var(--border);">
        <th style="padding:10px 8px;" class="small text-muted">Student</th>
        <th style="padding:10px 8px;" class="small text-muted">Current Page</th>
        <th style="padding:10px 8px;" class="small text-muted">Today's Task</th>
        <th style="padding:10px 8px;" class="small text-muted">Progress</th>
        <th style="padding:10px 8px;" class="small text-muted">Status</th>
        <th style="padding:10px 8px;" class="small text-muted">Last Update</th>
        <th style="padding:10px 8px;"></th>
    </tr>
    </thead>
    <tbody>
    <?php foreach ($lookup as $r): ?>
        <?php
            $st = $r['_state'];
            $task = $r['_task'];
            $is_active = (($st['status'] ?? '') === 'active');
            $status_cls = $is_active ? 'badge-green' : (($st['status'] ?? '') === 'completed' ? 'badge-blue' : 'badge-gold');
            $status_txt = $is_active ? 'Active' : (($st['status'] ?? '') === 'completed' ? 'Completed' : 'Paused');
            $task_label = '—';
            $task_code = 'none';
            if ($task) {
                if ($task['task_type'] === 'celebration') {
                    $task_label = 'Celebration day';
                    $task_code = 'celebration';
                } elseif ($task['task_type'] === 'memorization') {
                    $task_label = 'Memorize Page ' . (int)$task['start_page'];
                    $task_code = 'memorization';
                } else {
                    $sess = $task['day_session'] === 'full' ? '' : ' (' . ucfirst($task['day_session']) . ')';
                    $task_label = "Muraja'ah Pages " . (int)$task['start_page'] . '–' . (int)$task['end_page'] . $sess;
                    $task_code = 'murajaah';
                }
            }
            $last = (!empty($st['updated_at'])) ? date('d M Y, g:i A', strtotime($st['updated_at'])) : '—';
            $page_txt = $is_active || (int)$r['pct'] > 0 ? $r['current_page'] : 1;
        ?>
    <tr style="border-bottom:1px solid var(--border);">
        <td style="padding:10px 8px;">
            <strong><?= htmlspecialchars($r['name']) ?></strong>
            <div class="small text-muted">ID #<?= (int)$r['id'] ?></div>
        </td>
        <td style="padding:10px 8px;"><strong><?= (int)$page_txt ?></strong><span class="small text-muted"> / 604</span></td>
        <td style="padding:10px 8px;" class="small task-col" data-task="<?= $task_code ?>"><?= htmlspecialchars($task_label) ?></td>
        <td style="padding:10px 8px;min-width:140px;">
            <div class="progress" style="height:10px;margin-top:6px;">
                <div class="progress-fill" style="width:<?= (int)$r['pct'] ?>%;background:linear-gradient(90deg,#d97706,#f59e0b);"></div>
                <div class="progress-text" style="font-size:.6rem;"><?= (int)$r['pct'] ?>%</div>
            </div>
        </td>
        <td style="padding:10px 8px;"><span class="badge <?= $status_cls ?>"><?= $status_txt ?></span></td>
        <td style="padding:10px 8px;" class="small text-muted"><?= $last ?></td>
        <td style="padding:10px 8px;text-align:right;">
            <button class="btn btn-sm btn-gold" onclick="openManage(<?= (int)$r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name'])) ?>', '<?= $task_code ?>')"><?= ui_icon('settings', 14) ?> Manage</button>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<!-- Manage Modal -->
<div class="modal" id="manageModal">
    <div class="modal-content" style="max-width:520px;">
        <span class="modal-close" onclick="closeManage()">&times;</span>
        <h3 style="margin:0 0 4px;">Manage Memorizer</h3>
        <p class="small text-muted" id="manageName" style="margin:0 0 14px;"></p>

        <input type="hidden" id="manageStudentId" value="">

        <div id="manageTaskBox" class="panel" style="margin:0 0 12px;padding:10px 12px;">
            <span class="small text-muted">Today's task:</span>
            <strong id="manageTaskLabel" class="small" style="margin-left:6px;"></strong>
        </div>

        <div id="manageTaskWrap" style="display:none;">
            <form id="taskForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="task">
                <input type="hidden" name="student_id" value="">
                <button class="btn btn-gold btn-block" type="submit"><?= ui_icon('check', 16) ?> Mark Today's Memorization Done</button>
            </form>
            <p class="small text-muted" style="margin:6px 0 0 0;">Only valid on memorization days. On Muraja'ah days the teacher must Pass the session instead.</p>
            <hr style="border:none;border-top:1px solid var(--border);margin:14px 0;">
        </div>

        <form id="adjustForm">
            <?= csrf_field() ?>
            <label class="form-label">Adjust Page Count (0–604, pages memorized)</label>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input class="form-input" type="number" name="pages" min="0" max="604" value="0" required style="flex:1;min-width:120px;">
                <button class="btn" type="submit"><?= ui_icon('sliders', 15) ?> Apply</button>
            </div>
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="student_id" value="">
            <input class="form-input" type="text" name="notes" placeholder="Optional reason (visible in history)" style="margin-top:8px;">
        </form>

        <hr style="border:none;border-top:1px solid var(--border);margin:14px 0;">

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <form id="pauseForm" style="flex:1;min-width:140px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="pause">
                <input type="hidden" name="student_id" value="">
                <button class="btn btn-block btn-danger" type="submit"><?= ui_icon('pause', 15) ?> Pause Journey</button>
            </form>
            <form id="resumeForm" style="flex:1;min-width:140px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resume">
                <input type="hidden" name="student_id" value="">
                <button class="btn btn-block btn-gold" type="submit"><?= ui_icon('play', 15) ?> Resume Journey</button>
            </form>
        </div>
        <form id="healForm" style="margin-top:10px;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="heal">
            <input type="hidden" name="student_id" value="">
            <button class="btn btn-block btn-ghost" type="submit"><?= ui_icon('refresh', 15) ?> Run Self-Heal (repair pointers)</button>
        </form>

        <a class="btn btn-block" id="manageDetailLink" style="margin-top:10px;" href="#" onclick="return false;"><?= ui_icon('user', 15) ?> Open Student Detail</a>
    </div>
</div>
<?php endif; ?>

<style>
.mem-table td, .mem-table th { vertical-align: middle; }
</style>

<?php ui_page_end(); ?>

<script>
var csrfInput = document.querySelector('[name=csrf_token]');
var csrfToken = csrfInput ? csrfInput.value : '';
var taskByStudent = {};

function openManage(id, name, taskCode) {
    document.getElementById('manageStudentId').value = id;
    document.getElementById('manageName').textContent = name;
    document.getElementById('manageTaskWrap').style.display = (taskCode === 'memorization') ? 'block' : 'none';
    document.getElementById('manageTaskBox').style.display = 'block';
    var taskLabels = {
        'memorization': 'Memorization day — memorize one page, then mark done.',
        'murajaah': 'Muraja\'ah day — the student recites a range; review it in Teaching (Section F).',
        'celebration': 'Celebration day — nothing to submit.',
        'none': 'No task yet.'
    };
    document.getElementById('manageTaskLabel').textContent = taskLabels[taskCode] || taskCode;
    ['taskForm','adjustForm','pauseForm','resumeForm','healForm'].forEach(function(f) {
        if (document.getElementById(f)) {
            document.getElementById(f).querySelector('[name=student_id]').value = id;
        }
    });
    document.getElementById('manageDetailLink').href = 'student_detail.php?id=' + id;
    document.getElementById('manageModal').classList.add('open');
}

function closeManage() {
    document.getElementById('manageModal').classList.remove('open');
}

document.addEventListener('click', function(e) {
    var m = document.getElementById('manageModal');
    if (m && m.classList.contains('open') && e.target === m) closeManage();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeManage();
});

/* Submit all modal forms via AJAX to memorization_action.php */
['taskForm','adjustForm','pauseForm','resumeForm','healForm'].forEach(function(fid) {
    var form = document.getElementById(fid);
    if (!form) return;
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var fd = new FormData(form);
        fd.append('csrf_token', csrfToken);
        fetch('memorization_action.php', {method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'}})
            .then(function(r) { return r.text(); })
            .then(function(res) {
                if (res.trim() === 'OK') { location.reload(); }
                else alert(res);
            })
            .catch(function() { alert('Action failed. Please try again.'); });
    });
});
</script>

</body>
</html>