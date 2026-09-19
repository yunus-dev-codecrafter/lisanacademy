<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* =====================
   VALIDATE STUDENT ID
===================== */
$student_id = (int)($_GET['id'] ?? 0);
if ($student_id <= 0) {
    redirect('students.php');
}

/* =====================
   FETCH STUDENT
   (users.phone may not exist on every DB — build the query safely)
===================== */
$has_phone = db_column_exists($conn, 'users', 'phone');

$stmt = $conn->prepare($has_phone
    ? "SELECT id, name, email, phone FROM users WHERE id = ? AND role = 'student'"
    : "SELECT id, name, email FROM users WHERE id = ? AND role = 'student'");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$student['phone'] = $has_phone ? (string)($student['phone'] ?? '') : '';

if (!$student) {
    redirect('students.php');
}

/* Fall back to the applicant's WhatsApp number (from the application the
   student was activated from) whenever users.phone is empty. */
if ($student['phone'] === '' && db_table_exists($conn, 'applications')) {
    try {
        $stmt = $conn->prepare("SELECT whatsapp_phone FROM applications WHERE email = ? AND whatsapp_phone IS NOT NULL AND whatsapp_phone != '' ORDER BY id DESC LIMIT 1");
        $stmt->bind_param("s", $student['email']);
        $stmt->execute();
        $wa = $stmt->get_result()->fetch_assoc();
        if ($wa) $student['phone'] = (string)$wa['whatsapp_phone'];
    } catch (Throwable $e) { /* ignore */ }
}

/* True when we had to point the WhatsApp link at the academy's own number. */
$fallback_to_academy = ($student['phone'] === '');

/* =====================
   IDLE DETECTION
   "Inactive" = no ACADEMIC activity (recitations, lesson requests, live
   sessions, exams, hafiz revision) for 14+ days.
===================== */
$IDLE_DAYS = 14;

function student_last_academic_activity($conn, $student_id) {
    $last = null;
    $queries = [];

    if (db_table_exists($conn, 'student_recitation')) {
        $queries[] = "SELECT MAX(submitted_at) t FROM student_recitation WHERE student_id = $student_id";
    }
    if (db_table_exists($conn, 'lessons') && db_column_exists($conn, 'lessons', 'created_at')) {
        $queries[] = "SELECT MAX(created_at) t FROM lessons WHERE student_id = $student_id";
    }
    if (db_table_exists($conn, 'live_recitation_requests')) {
        $queries[] = "SELECT MAX(created_at) t FROM live_recitation_requests WHERE student_id = $student_id";
    }
    if (db_table_exists($conn, 'exam_attempts')) {
        $queries[] = "SELECT MAX(submitted_at) t FROM exam_attempts WHERE student_id = $student_id";
    }
    if (db_table_exists($conn, 'exam_answers') && db_column_exists($conn, 'exam_answers', 'answered_at')) {
        $queries[] = "SELECT MAX(ea.answered_at) t FROM exam_answers ea JOIN exam_attempts e ON e.id = ea.attempt_id WHERE e.student_id = $student_id";
    }
    if (db_table_exists($conn, 'hafiz_sessions')) {
        $queries[] = "SELECT MAX(submitted_at) t FROM hafiz_sessions WHERE student_id = $student_id";
    }
    if (db_table_exists($conn, 'hafiz_weekly_tests')) {
        $queries[] = "SELECT MAX(submitted_at) t FROM hafiz_weekly_tests WHERE student_id = $student_id";
    }

    foreach ($queries as $sql) {
        try {
            $row = $conn->query($sql)->fetch_assoc();
            if ($row && !empty($row['t'])) {
                $ts = strtotime($row['t']);
                if ($ts !== false && ($last === null || $ts > $last)) {
                    $last = $ts;
                }
            }
        } catch (Throwable $e) { /* ignore */ }
    }
    return $last;
}

$last_activity_ts = student_last_academic_activity($conn, $student_id);
$idle_days = $last_activity_ts !== null
    ? (int)floor((time() - $last_activity_ts) / 86400)
    : null;
$is_idle = $last_activity_ts !== null && $idle_days >= $IDLE_DAYS;

/* =====================
   PRE-WRITTEN MESSAGES
   The admin toggles between these and can edit the chosen one before sending.
===================== */
$presets = [
    'accepted' => [
        'label' => 'Recitation Accepted',
        'desc'  => 'Alhamdulillah, your recitation was accepted — you may request your next lesson.',
        'text'  => "Assalamualaikum {name},\n\n"
                . "Alhamdulillah! Your recitation has been accepted and you have completed this learning circle. "
                . "You may now request your next lesson.\n\n"
                . "Reminder: Consistency in the Qur'an brings light to the heart and barakah to time.",
    ],
    'rejected' => [
        'label' => 'Recitation Needs Correction',
        'desc'  => 'Your recitation was reviewed and needs correction — please recite again.',
        'text'  => "Assalamualaikum {name},\n\n"
                . "Your last recitation was reviewed and needs correction. "
                . "Please go through the feedback carefully and recite again.\n\n"
                . "Reminder: The Qur'an is not rushed. Take your time, perfect it, and Allah will reward every effort.",
    ],
    'pending'  => [
        'label' => 'Recitation Under Review',
        'desc'  => 'Your recitation is still being reviewed — please be patient.',
        'text'  => "Assalamualaikum {name},\n\n"
                . "Your last recitation is currently being reviewed. "
                . "Please be patient — your teacher will get back to you with the result soon.\n\n"
                . "Reminder: Patience with the Qur'an is never wasted time.",
    ],
    'lesson'   => [
        'label' => 'New Lesson / Lesson Request',
        'desc'  => 'A new lesson was sent, or the lesson request is being prepared.',
        'text'  => "Assalamualaikum {name},\n\n"
                . "Your new lesson has been sent. Please listen attentively, practice well, "
                . "and submit your recitation for review.\n\n"
                . "Reminder: The best of you are those who learn the Qur'an and teach it.",
    ],
    'idle'     => [
        'label' => 'Student Idle / Inactive',
        'desc'  => 'No academic activity for 14+ days — re-engage this student.',
        'text'  => "Assalamualaikum {name},\n\n"
                . "We miss you! You haven't participated in any lessons for a while — "
                . "it's been {days} days since your last activity in the academy. "
                . "Your Qur'an journey continues whenever you're ready; please log in, "
                . "continue your lessons and submit your next recitation.\n\n"
                . "Reminder: Consistency in the Qur'an brings light to the heart and barakah to time.",
    ],
];

/* Pick the preset that best matches the student's current state. The message
   is decided from the student's LATEST recitation across ALL lessons — never
   just the latest lesson — so an accepted recitation can never be shown as
   "needs correction" because of an older lesson. */
$recitation = $conn->query("
    SELECT sr.status
    FROM student_recitation sr
    JOIN lessons l ON l.id = sr.learning_plan_id
    WHERE sr.student_id = $student_id
    ORDER BY sr.id DESC
    LIMIT 1
")->fetch_assoc();

$default_key = 'lesson';
if ($recitation && $recitation['status'] === 'rejected') {
    $default_key = 'rejected';
} elseif ($recitation && $recitation['status'] === 'pending') {
    $default_key = 'pending';
} elseif ($recitation && $recitation['status'] === 'accepted') {
    $default_key = 'accepted';
}

/* Inject the student's name (and, for the idle preset, their idle-day count)
   into every preset — used server-side for the first render and passed to JS
   for switching. */
$filled = [];
foreach ($presets as $key => $preset) {
    $text = str_replace('{name}', $student['name'], $preset['text']);
    if ($idle_days !== null) {
        $text = str_replace('{days}', $idle_days, $text);
    }
    $filled[$key] = $text;
}
$selected_text = $filled[$default_key];

/* WhatsApp link — use the student's own number; only fall back to the academy
   number when no phone is on file for the student. */
$student_phone = !empty($student['phone'])
    ? $student['phone']
    : setting($conn, 'whatsapp_number', '2348029979040');
$wa_number  = preg_replace('/[^0-9]/', '', $student_phone);
$wa_base    = "https://wa.me/$wa_number";
$whatsapp_link = "$wa_base?text=" . urlencode($selected_text);
?>
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Message Student</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'students', 'Message Student', 'WhatsApp'); ?>

<div class="card animate-rise" style="max-width:640px;">
    <div style="display:flex;align-items:center;gap:14px;margin-bottom:8px;">
        <div class="avatar letter" style="width:46px;height:46px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,var(--emerald-700),var(--emerald-500));color:#fff;font-weight:700;font-size:1.2rem;"><?= htmlspecialchars(ui_initial($student['name'])) ?></div>
        <div>
            <h3 style="margin:0;"><?= htmlspecialchars($student['name']) ?></h3>
            <p class="small text-muted" style="margin:0;"><?= htmlspecialchars($student['email']) ?></p>
            <?php if (!empty($student['phone'])): ?>
                <p class="small text-muted" style="margin:2px 0 0;"><?= ui_icon('phone', 13) ?> +<?= htmlspecialchars(preg_replace('/[^0-9]/', '', $student['phone'])) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($fallback_to_academy): ?>
        <div class="alert alert-warning" style="margin:0 0 12px;">
            <?= ui_icon('alert', 16) ?>
            <span style="flex:1;"><strong>No phone on file for this student.</strong> This link opens the academy's WhatsApp instead of the student's. Run <a href="db_migrate07.php"><strong>Database Migration</strong></a> and make sure a phone number is saved for the student (add/edit it when activating or adding the student).</span>
        </div>
    <?php endif; ?>

    <?php if ($is_idle): ?>
        <div class="alert alert-warning" style="margin:0 0 12px;">
            <?= ui_icon('clock', 16) ?>
            <span style="flex:1;"><strong>This student has been idle for about <?= htmlspecialchars($idle_days) ?> days.</strong> No academic activity since <?= htmlspecialchars(date('d M Y', $last_activity_ts)) ?>. The <strong>Student Idle / Inactive</strong> message below may be appropriate.</span>
        </div>
    <?php elseif ($last_activity_ts !== null): ?>
        <p class="small text-muted" style="margin:0 0 12px;">Last academic activity: <?= htmlspecialchars(date('d M Y', $last_activity_ts)) ?> (<?= htmlspecialchars($idle_days) ?> day<?= $idle_days === 1 ? '' : 's' ?> ago).</p>
    <?php else: ?>
        <p class="small text-muted" style="margin:0 0 12px;">No academic activity recorded yet.</p>
    <?php endif; ?>

    <div class="form-group">
        <label class="form-label">Choose a Message</label>
        <?php foreach ($presets as $key => $preset): ?>
            <label class="preset-option" data-key="<?= $key ?>" style="display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border:1px solid var(--border);border-radius:10px;margin-bottom:8px;cursor:pointer;<?= $key === $default_key ? 'background:#ecfdf5;border-color:var(--emerald-500);' : ($key === 'idle' && $is_idle ? 'background:#fffbeb;border-color:#d97706;' : '') ?>">
                <input type="radio" name="preset" value="<?= $key ?>" <?= $key === $default_key ? 'checked' : '' ?> style="margin-top:3px;accent-color:var(--emerald-600);">
                <span style="flex:1;min-width:0;">
                    <strong style="display:block;"><?= htmlspecialchars($preset['label']) ?></strong>
                    <span class="small text-muted" style="display:block;font-size:.78rem;"><?= htmlspecialchars($preset['desc']) ?></span>
                </span>
            </label>
        <?php endforeach; ?>
    </div>

    <div class="form-group">
        <label class="form-label">Message Preview <span class="small text-muted">(editable — fine-tune before sending)</span></label>
        <textarea class="form-textarea" id="messageText" style="min-height:220px;"><?= htmlspecialchars($selected_text) ?></textarea>
    </div>

    <a id="waLink" href="<?= htmlspecialchars($whatsapp_link) ?>" target="_blank">
        <button class="btn btn-gold btn-block btn-lg"><?= ui_icon('phone', 17) ?> Send via WhatsApp</button>
    </a>
</div>

<?php ui_page_end(); ?>

<script>
const PRESETS = <?= json_encode($filled, JSON_UNESCAPED_SLASHES) ?>;
const WA_BASE = <?= json_encode($wa_base, JSON_UNESCAPED_SLASHES) ?>;
const msgText = document.getElementById('messageText');
const waLink  = document.getElementById('waLink');

function updateLink(){
    waLink.href = WA_BASE + '?text=' + encodeURIComponent(msgText.value);
}

function selectPreset(key){
    if (PRESETS[key]) {
        msgText.value = PRESETS[key];
        updateLink();
    }
}

document.querySelectorAll('input[name="preset"]').forEach(function(radio){
    radio.addEventListener('change', function(){
        selectPreset(this.value);
        document.querySelectorAll('.preset-option').forEach(function(opt){
            var active = opt.getAttribute('data-key') === this.value;
            opt.style.background = active ? '#ecfdf5' : '';
            opt.style.borderColor = active ? 'var(--emerald-500)' : '';
        }, this);
    });
});

msgText.addEventListener('input', updateLink);
updateLink();
</script>

</body>
</html>