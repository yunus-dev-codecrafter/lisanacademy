<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* Missing schema would crash the activate flow — direct the admin to the migration runner. */
$schema_ready = users_has_exam_columns($conn) && exam_attempts_has_exam_columns($conn) && exam_terms_has_schema($conn);

/* Tiered-exam columns (question_count / total_days / day_no) — needed for the 3/7/10 flow. */
$tier_ready = exam_tier_schema_ready($conn);

/* exam_selected column (admin picks which students participate in the exam). */
$selected_ready = db_column_exists($conn, 'users', 'exam_selected');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'activate' && !$schema_ready) {
        redirect('db_migrate.php');
    }

    /* Apply the admin's participant selection to the users table. Safe to
       call when the column is missing (no-op), so pages never crash. */
    $apply_participants = function ($conn, $selected_ready) {
        if (!$selected_ready) return;
        $selected_ids = array_filter(array_map('intval', (array)($_POST['student_ids'] ?? [])));
        $conn->query("UPDATE users SET exam_selected = 0 WHERE role = 'student'");
        if (!empty($selected_ids)) {
            $ids_str = implode(',', array_unique($selected_ids));
            $conn->query("UPDATE users SET exam_selected = 1 WHERE role = 'student' AND id IN ($ids_str)");
        }
    };

    if ($action === 'activate') {
        $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('exam_mode', 'on')
                      ON DUPLICATE KEY UPDATE setting_value = 'on'");
        $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('exam_started_at', NOW())
                      ON DUPLICATE KEY UPDATE setting_value = NOW()");

        /* Start a new exam term for the selected term (1/2/3).
           The term closes automatically on its term END DATE, or when the
           admin deactivates it manually — there is no fixed 10-day window. */
        $term_no = (int)($_POST['term_no'] ?? current_term_no());
        if ($term_no < 1 || $term_no > 3) $term_no = current_term_no();
        $year   = (int)date('Y');
        $window = term_date_windows($year)[$term_no];
        $end    = $window['end'];

        $conn->query("INSERT INTO exam_terms (activated_at, auto_close_at, term_no, school_year)
                      VALUES (NOW(), '$end', $term_no, $year)");
        $term_id = (int)$conn->insert_id;
        $conn->query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('current_term_id', $term_id)
                      ON DUPLICATE KEY UPDATE setting_value = $term_id");

        /* Fresh term = clear all previous default/payment flags */
        $conn->query("UPDATE users SET exam_defaulted = 0, exam_owed = 0, exam_access = 0 WHERE role = 'student'");

        /* Only the students the admin ticked are qualified for this exam. */
        $apply_participants($conn, $selected_ready);
    } elseif ($action === 'save_participants') {
        $apply_participants($conn, $selected_ready);
    } elseif ($action === 'deactivate') {
        finalize_exam_term($conn);
    } elseif ($action === 'reset') {
        reset_exam_section($conn);
        redirect('exam_settings.php?reset=1');
    }

    redirect('exam_settings.php');
}

$exam_mode = exam_mode_on($conn);
$started   = setting($conn, 'exam_started_at', '');
$term      = exam_term_info($conn);
$term_label = $term ? exam_term_label($term) : '';

$days_left = null;
if ($term && empty($term['deactivated_at']) && !empty($term['auto_close_at'])) {
    $days_left = (int)ceil((strtotime($term['auto_close_at']) - time()) / 86400);
    if ($days_left < 0) $days_left = 0;
}

/* Stats */
$pending   = 0;
$approved  = 0;
$rejected  = 0;
$defaulters = 0;
$drafts    = 0;
$selected_count = 0;
try {
    $pending    = (int)$conn->query("SELECT COUNT(*) c FROM exam_attempts WHERE status = 'pending'")->fetch_assoc()['c'];
    $approved   = (int)$conn->query("SELECT COUNT(*) c FROM exam_attempts WHERE status = 'approved'")->fetch_assoc()['c'];
    $rejected   = (int)$conn->query("SELECT COUNT(*) c FROM exam_attempts WHERE status = 'rejected'")->fetch_assoc()['c'];
    $drafts     = (int)$conn->query("SELECT COUNT(*) c FROM exam_attempts WHERE status = 'draft'")->fetch_assoc()['c'];
    $defaulters = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE exam_defaulted = 1")->fetch_assoc()['c'];
    if ($selected_ready) {
        $selected_count = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role = 'student' AND exam_selected = 1")->fetch_assoc()['c'];
    }
} catch (Throwable $e) {
    $pending = 0; $approved = 0; $rejected = 0; $drafts = 0; $defaulters = 0; $selected_count = 0;
}

/* All students, enriched with completed-surah count and whether they have any
   attempt in the current term — helps the admin decide who is qualified. */
$participants = null;
if ($schema_ready && $selected_ready) {
    $term_id_cur = $term ? (int)$term['id'] : 0;
    $participants = $conn->query("
        SELECT u.id, u.name, u.email, u.exam_selected,
               (SELECT COUNT(*) FROM student_learning sl WHERE sl.student_id = u.id AND sl.status = 'completed') AS completed,
               (SELECT COUNT(*) FROM exam_attempts ea WHERE ea.student_id = u.id AND ea.term_id = $term_id_cur) AS term_attempts
        FROM users u
        WHERE u.role = 'student'
        ORDER BY u.name ASC
    ");
}

/* The checkbox list used inside the activate / save-participants forms. */
$participant_list_html = '';
if ($selected_ready && $participants && $participants->num_rows > 0) {
    $participant_list_html = '<div class="form-group" style="margin-top:14px;">'
        . '<label class="form-label">Qualified Students</label>'
        . '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px;">'
        . '<button class="btn btn-sm" type="button" onclick="toggleParticipants(true)">Select all</button>'
        . '<button class="btn btn-sm btn-ghost" type="button" onclick="toggleParticipants(false)">Clear all</button>'
        . '<input class="form-input participant-search-inline" type="search" placeholder="Search students…" autocomplete="off" style="flex:1;min-width:150px;">'
        . '</div>'
        . '<div class="panel" style="max-height:300px;overflow-y:auto;margin:0;padding:4px;">';
    while ($p = $participants->fetch_assoc()) {
        $participant_list_html .= '<label class="participant-row" data-search="' . htmlspecialchars(strtolower(($p['name'] ?? '') . ' ' . ($p['email'] ?? ''))) . '" style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-bottom:1px solid var(--border);cursor:pointer;font-size:.9rem;">'
            . '<input type="checkbox" name="student_ids[]" value="' . (int)$p['id'] . '" ' . ((int)$p['exam_selected'] === 1 ? 'checked' : '') . ' style="accent-color:var(--emerald-600);">'
            . '<span style="flex:1;min-width:0;"><strong>' . htmlspecialchars($p['name']) . '</strong>'
            . '<span class="small text-muted" style="display:block;font-size:.78rem;word-break:break-word;">' . htmlspecialchars($p['email']) . '</span></span>'
            . '<span class="small text-muted" style="white-space:nowrap;">' . (int)$p['completed'] . ' surah' . ((int)$p['completed'] === 1 ? '' : 's') . ' done</span>'
            . ((int)$p['term_attempts'] > 0 ? ' <span class="badge badge-gold">In exam</span>' : '')
            . '</label>';
    }
    $participant_list_html .= '</div>'
        . '<p class="small text-muted" style="margin:8px 0 0;"><strong>Only selected students</strong> participate in the exam and pause their lessons — everyone else continues their normal lessons.</p>'
        . '</div>';
    $participants->data_seek(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Exam Settings</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'exams', 'Exam Settings', 'Exams'); ?>

<div class="page-hero animate-rise">
    <h1>Examination Control</h1>
    <p>Activate or deactivate examination mode. While active, students can only sit the exam.</p>
</div>

<?php if (!$schema_ready): ?>
    <div class="alert alert-warning animate-rise">
        <?= ui_icon('alert', 16) ?> The database is missing the exam schema. Run
        <a href="db_migrate.php"><strong>Database Migration</strong></a> before activating exam mode.
    </div>
<?php elseif (!$tier_ready): ?>
    <div class="alert alert-warning animate-rise">
        <?= ui_icon('alert', 16) ?> The database is missing the <strong>tiered-exam columns</strong> (question_count / total_days / day_no). Run
        <a href="db_migrate06.php"><strong>Database Migration (v06)</strong></a> so the 3/7/10-question exam works correctly.
    </div>
<?php endif; ?>

<?php if (isset($_GET['reset']) && $_GET['reset'] === '1'): ?>
    <div class="alert alert-success animate-rise">
        <?= ui_icon('check-circle', 16) ?>
        <span style="flex:1;"><strong>Exam section reset.</strong> All submissions, answers and terms were deleted, exam mode is off, and every student's exam flags were cleared.</span>
    </div>
<?php endif; ?>

<div class="stat-grid animate-rise d1">
    <div class="stat-card <?= $exam_mode ? 'stat-gold' : 'stat-green' ?>">
        <span class="stat-ico"><?= ui_icon('clock') ?></span>
        <span class="stat-label">Exam Mode</span>
        <span class="stat-value"><?= $exam_mode ? 'ON' : 'OFF' ?></span>
        <?php if ($exam_mode && $started): ?>
            <span class="stat-sub"><?= htmlspecialchars($term_label) ?> · Started <?= date('d M Y H:i', strtotime($started)) ?> <?= $days_left !== null ? '· closes in ~' . $days_left . ' day(s)' : '' ?></span>
        <?php else: ?>
            <span class="stat-sub">Students follow their normal lessons</span>
        <?php endif; ?>
    </div>
    <a href="#participants" class="stat-card <?= $selected_count ? 'stat-gold' : 'stat-green' ?>">
        <span class="stat-ico"><?= ui_icon('users') ?></span>
        <span class="stat-label">Qualified Students</span>
        <span class="stat-value"><?= $selected_count ?></span>
        <span class="stat-sub">Selected to participate this term</span>
    </a>
    <a href="exams.php" class="stat-card stat-blue">
        <span class="stat-ico"><?= ui_icon('notes') ?></span>
        <span class="stat-label">Pending Submissions</span>
        <span class="stat-value"><?= $pending ?></span>
        <span class="stat-sub">Awaiting your review</span>
    </a>
    <div class="stat-card stat-green">
        <span class="stat-ico"><?= ui_icon('check-circle') ?></span>
        <span class="stat-label">Accepted Exams</span>
        <span class="stat-value"><?= $approved ?></span>
        <span class="stat-sub">Results released to students</span>
    </div>
    <div class="stat-card stat-red">
        <span class="stat-ico"><?= ui_icon('alert') ?></span>
        <span class="stat-label">Defaulters</span>
        <span class="stat-value"><?= $defaulters ?></span>
        <span class="stat-sub">Missed exam · owe N500</span>
    </div>
    <div class="stat-card <?= $drafts ? 'stat-gold' : 'stat-green' ?>">
        <span class="stat-ico"><?= ui_icon('clock') ?></span>
        <span class="stat-label">In Progress</span>
        <span class="stat-value"><?= $drafts ?></span>
        <span class="stat-sub">Started but not finished</span>
    </div>
</div>

<div class="card animate-rise d1" style="max-width:760px;">
    <h3 style="margin-top:0;"><?= $exam_mode ? 'Deactivate Examination Mode' : 'Activate Examination Mode' ?></h3>

    <?php if ($exam_mode): ?>
        <p class="small text-muted">
            <strong>Only qualified (selected) students</strong> pause their normal lessons and take the exam —
            everyone else keeps following their normal lessons. The term
            <strong><?= htmlspecialchars($term_label) ?></strong> stays open until its end date
            (<?= $days_left !== null ? "about $days_left day(s) remaining" : 'closing soon' ?>) or until you
            deactivate it. When it closes, selected students who never submitted will be locked from normal lessons
            until they pay the N500 fee and pass; unselected students are unaffected.
        </p>
        <form method="POST" onsubmit="return confirm('Deactivate exam mode now? Selected students who did not pass will be flagged as defaulters; unselected students are unaffected.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="deactivate">
            <button class="btn btn-danger btn-lg"><?= ui_icon('close', 17) ?> Deactivate Exam Mode Now</button>
        </form>
    <?php else: ?>
        <p class="small text-muted">
            Activating will pause <strong>only the students you select below</strong> — they will be redirected to the
            exam page and blocked from new lesson requests and recitation submissions. Every unselected student
            continues their normal lessons. Pick which of the 3 academy terms this exam covers — the term stays open
            until its end date (Term 1: Jan–Apr · Term 2: May–Aug · Term 3: Sep–Dec) or until you deactivate it.
        </p>
        <form method="POST" onsubmit="return confirm('Activate exam mode for this term? Only the selected students will participate.');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="activate">
            <div class="form-group" style="margin-top:14px;">
                <label class="form-label" for="term_no">Exam Term</label>
                <select class="form-select" name="term_no" id="term_no">
                    <?php $cur = current_term_no(); ?>
                    <option value="1" <?= $cur === 1 ? 'selected' : '' ?>>Term 1 (Jan – Apr)</option>
                    <option value="2" <?= $cur === 2 ? 'selected' : '' ?>>Term 2 (May – Aug)</option>
                    <option value="3" <?= $cur === 3 ? 'selected' : '' ?>>Term 3 (Sep – Dec)</option>
                </select>
            </div>

            <?php if (!$selected_ready): ?>
                <div class="alert alert-warning" style="margin:0 0 12px;">
                    <?= ui_icon('alert', 16) ?>
                    <span style="flex:1;">The <code>users.exam_selected</code> column is missing. Run
                    <a href="db_migrate08.php"><strong>Database Migration (v08)</strong></a> so you can choose which
                    students participate in the exam.</span>
                </div>
            <?php elseif ($participants && $participants->num_rows === 0): ?>
                <div class="alert alert-info" style="margin:0 0 12px;"><?= ui_icon('info', 16) ?> No students have been added yet.</div>
            <?php elseif ($participants): ?>
                <?= $participant_list_html ?>
            <?php endif; ?>

            <button class="btn btn-gold btn-lg"><?= ui_icon('check', 17) ?> Activate Exam Mode for Selected Students</button>
        </form>
    <?php endif; ?>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;">
        <a class="btn btn-ghost" href="exams.php"><?= ui_icon('notes', 16) ?> Manage Exam Submissions</a>
        <a class="btn btn-ghost" href="exam_defaults.php"><?= ui_icon('alert', 16) ?> Defaulters &amp; Payments</a>
    </div>
</div>

<div id="participants" class="card animate-rise d1" style="max-width:760px;margin-top:18px;">
    <h3 style="margin-top:0;display:flex;align-items:center;gap:8px;"><?= ui_icon('users', 18) ?> Qualified Students for <?= $exam_mode ? 'This Term' : 'Next Term' ?></h3>
    <?php if ($selected_ready): ?>
        <p class="small text-muted" style="margin:0 0 10px;">
            The completed-surah count helps you judge readiness — exam questions are drawn from completed surahs.
            Students with an <span class="badge badge-gold">In exam</span> badge have already started or submitted.
        </p>
        <?php if ($participants && $participants->num_rows === 0): ?>
            <div class="empty"><div class="empty-title">No students yet</div></div>
        <?php elseif ($participants): ?>
            <?php if ($exam_mode): ?>
                <form method="POST" onsubmit="return confirm('Save the qualified-student selection for this term?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_participants">
                    <?= $participant_list_html ?>
                    <button class="btn btn-gold btn-lg"><?= ui_icon('users', 17) ?> Save Qualified Students</button>
                </form>
            <?php else: ?>
                <div class="alert alert-info" style="margin:0;">
                    <?= ui_icon('info', 16) ?>
                    <span style="flex:1;">Tick the students you want to participate <strong>in the Activate form above</strong>,
                    then click <strong>Activate Exam Mode</strong>. Your selection is applied the moment the exam is activated.</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <div class="alert alert-warning" style="margin:0;">
            <?= ui_icon('alert', 16) ?> Run
            <a href="db_migrate08.php"><strong>Database Migration (v08)</strong></a> to enable qualified-student selection.
        </div>
    <?php endif; ?>
</div>

<div class="card animate-rise d1" style="max-width:620px;border:1px solid var(--danger-border);margin-top:18px;">
    <h3 style="margin-top:0;color:var(--danger);display:flex;align-items:center;gap:8px;"><?= ui_icon('refresh', 18) ?> Reset Exam Section</h3>
    <p class="small text-muted">
        Permanently wipes the whole exam section and returns it to a clean default state — regardless of the current mode,
        term, submissions or flags:
    </p>
    <ul class="small" style="margin:0 0 14px;padding-left:20px;">
        <li>Deletes <strong>every exam submission and answer</strong> (audio files included).</li>
        <li>Deletes <strong>every exam term</strong>.</li>
        <li>Switches exam mode <strong>off</strong> and clears the active term.</li>
        <li>Clears every student's <strong>default / payment / access</strong> flags so lessons are unlocked.</li>
    </ul>
    <form method="POST" onsubmit="return confirm('This will DELETE every exam submission, answer and term, turn exam mode OFF and clear all student exam flags. This CANNOT be undone. Continue?');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset">
        <button class="btn btn-danger btn-lg" type="submit"><?= ui_icon('refresh', 17) ?> Reset Exam Section</button>
    </form>
</div>

<?php ui_page_end(); ?>

<script>
function toggleParticipants(on){
    document.querySelectorAll('[name="student_ids[]"]').forEach(function(c){ c.checked = on; });
}

function filterParticipantRows(input){
    var q = input.value.toLowerCase().trim();
    var scope = input.closest('.form-group, .card') || document;
    var rows = scope.querySelectorAll('.participant-row');
    rows.forEach(function(r){
        r.style.display = (q === '' || (r.getAttribute('data-search') || '').indexOf(q) !== -1) ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', function(){
    var ps = document.getElementById('participantSearch');
    if (ps) ps.addEventListener('input', function(){ filterParticipantRows(this); });
    document.querySelectorAll('.participant-search-inline').forEach(function(inp){
        inp.addEventListener('input', function(){ filterParticipantRows(this); });
    });
});

/* Warn the admin if they activate exam mode without selecting any qualified student. */
document.addEventListener('submit', function(e){
    var f = e.target;
    if (!f || !f.querySelector) return;
    var actionInput = f.querySelector('input[type="hidden"][value="activate"]');
    if (!actionInput) return;
    var boxes = f.querySelectorAll('[name="student_ids[]"]');
    if (boxes.length === 0) return;
    var any = false;
    boxes.forEach(function(c){ if (c.checked) any = true; });
    if (!any) {
        if (!confirm('No students are selected as qualified for this exam. Activate anyway? Only selected students participate — unselected students continue their normal lessons.')) {
            e.preventDefault();
        }
    }
});
</script>

</body>
</html>
