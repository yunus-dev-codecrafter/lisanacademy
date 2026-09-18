<?php
require '../config/security/helpers.php';
require '../auth/auth_check.php';
require '../config/db.php';
require_role('student');

$student_id = (int)$_SESSION['user_id'];

/* Device type — iPhone students get a file-upload path (web recording is unreliable on iOS) */
$device_row = $conn->query("SELECT device_type FROM users WHERE id = $student_id")->fetch_assoc();
$device_type = $device_row['device_type'] ?? 'android';

/* ============ EXAM MODE ============ */
$exam_mode        = student_in_exam($conn, $student_id);
$exam_active_glob = exam_mode_on($conn);
$student_access   = student_exam_access($conn, $student_id);
$locked           = student_exam_locked($conn, $student_id);
$can_take         = can_take_exam($conn, $student_id);
$term_info        = exam_term_info($conn);
$term_label       = $term_info ? exam_term_label($term_info) : '';

/* ============ COMPLETED SURAHS ============ */
$completed = [];
try {
    $res = $conn->query("
        SELECT s.id, s.name_en, s.name_ar, s.total_verses
        FROM student_learning sl
        JOIN surahs s ON s.id = sl.surah_id
        WHERE sl.student_id = $student_id AND sl.status = 'completed'
        ORDER BY s.id ASC
    ");
    while ($row = $res->fetch_assoc()) {
        $completed[] = $row;
    }
} catch (Throwable $e) {
    $completed = [];
}

/* ============ EXISTING ATTEMPT (scoped to the current exam term) ============ */
$attempt = null;
$answers = [];
$current_term_id = $term_info ? (int)$term_info['id'] : 0;
try {
    $stmt = $conn->prepare("SELECT * FROM exam_attempts WHERE student_id = ? AND term_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->bind_param("ii", $student_id, $current_term_id);
    $stmt->execute();
    $attempt = $stmt->get_result()->fetch_assoc();

    if ($attempt) {
        $stmt = $conn->prepare("
            SELECT a.*, s.name_en AS surah_name, s.name_ar AS surah_name_ar
            FROM exam_answers a
            JOIN surahs s ON s.id = a.surah_id
            WHERE a.attempt_id = ?
            ORDER BY a.day_no ASC, a.id ASC
        ");
        $stmt->bind_param("i", $attempt['id']);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $answers[] = $row;
    }
} catch (Throwable $e) {
    $attempt = null;
}

/* ============ TIER + DAY CAP ============ */
$tier = exam_tier_info($conn, $student_id);

$days_left_in_exam = null;
if ($term_info && empty($term_info['deactivated_at']) && !empty($term_info['auto_close_at'])) {
    $days_left_in_exam = (int)ceil((strtotime($term_info['auto_close_at']) - time()) / 86400);
    if ($days_left_in_exam < 0) $days_left_in_exam = 0;
}
$max_days_available = (int)$tier['max_days'];
if ($days_left_in_exam !== null) {
    $max_days_available = min($max_days_available, max(1, $days_left_in_exam));
}

/* Draft state */
$draft        = ($attempt && $attempt['status'] === 'draft');
$draft_day    = $draft ? (int)$attempt['day_no'] : 0;
$draft_total  = $draft ? (int)$attempt['total_days'] : 0;
$draft_questions = [];
$draft_answered  = 0;
$draft_today_pending = 0;
$draft_today_total   = 0;
if ($draft) {
    foreach ($answers as $a) {
        $d = (int)$a['day_no'];
        if (!isset($draft_questions[$d])) $draft_questions[$d] = ['total' => 0, 'done' => 0, 'items' => []];
        $draft_questions[$d]['total']++;
        $draft_questions[$d]['items'][] = $a;
        if ($a['status'] === 'submitted') {
            $draft_questions[$d]['done']++;
            $draft_answered++;
        }
    }
    if (isset($draft_questions[$draft_day])) {
        $draft_today_total   = $draft_questions[$draft_day]['total'];
        $draft_today_pending = $draft_today_total - $draft_questions[$draft_day]['done'];
    }
}
$draft_total_questions = count($answers);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Examination</title>
<?= ui_css() ?>
<style>
    .card-gold .panel{color:var(--text);}
    .card-gold .panel .small{color:var(--text-muted);}
    .card-gold .panel strong{color:var(--emerald-900);}
</style>
</head>
<?php ui_page_start('student', 'exam', 'Exam', 'Examination'); ?>

<div class="page-hero animate-rise">
    <h1><?= ui_icon('notes', 20) ?> Qur’an Examination</h1>
    <p>Recite the assigned portions of the surahs you have completed so far.<?= $term_label !== '' ? ' <span class="badge badge-gold">' . htmlspecialchars($term_label) . '</span>' : '' ?></p>
</div>

<?= exam_criteria_html() ?>

<?php if ($locked && !$student_access): ?>
    <div class="card card-danger animate-rise" style="max-width:640px;text-align:center;padding:30px;">
        <div style="font-size:2.4rem;"><?= ui_icon('lock', 34) ?></div>
        <h3 style="margin:8px 0 6px;color:var(--danger);">Exam Required — Payment Due</h3>
        <p class="small text-muted" style="margin:0 0 8px;">
            You did not participate in the last examination term. Until you complete it, your normal lessons stay locked.
        </p>
        <p style="margin:0 0 14px;">
            Pay the sum of <strong>₦500</strong> to the academy. Once the admin confirms your payment, the exam will be
            opened for you <em>only</em> while other students continue their normal lessons. After your exam is accepted
            (≤ 3 mistakes), your lessons are restored automatically.
        </p>
        <?php
        $whatsapp = setting($conn, 'whatsapp_number', '2348029979040');
        ?>
        <a class="btn btn-gold btn-lg"
           href="https://wa.me/<?= htmlspecialchars($whatsapp) ?>?text=<?= rawurlencode('Assalamualaikum, I missed the term examination. I want to pay the N500 exam fee to reopen my exam.') ?>"
           target="_blank">
           <?= ui_icon('phone', 16) ?> Pay ₦500 via WhatsApp
        </a>
        <p class="small text-muted" style="margin:12px 0 0;">
            Already paid? Contact the academy to confirm. You will see your exam here once the admin opens it.
        </p>
    </div>

<?php elseif (!$can_take): ?>
    <?php if ($exam_active_glob): ?>
        <div class="card card-gold animate-rise">
            <div class="card-title"><h3 style="margin-top:0;">You were not selected for this term's exam</h3></div>
            <p class="small" style="margin:0;">An exam term is currently active, but you were <strong>not selected</strong> to participate this time. Please continue with your normal lessons — may Allah bless your progress!</p>
            <a class="btn btn-gold mt-2" href="my_learning.php"><?= ui_icon('book', 16) ?> Go to My Learning</a>
        </div>
    <?php else: ?>
        <div class="card card-gold animate-rise">
            <div class="card-title"><h3 style="margin-top:0;">Examination is currently closed</h3></div>
            <p class="small" style="margin:0;">There is no active exam right now. Continue with your normal lessons — an exam will be announced when available.</p>
            <a class="btn btn-gold mt-2" href="my_learning.php"><?= ui_icon('book', 16) ?> Go to My Learning</a>
        </div>
    <?php endif; ?>

<?php elseif (count($completed) === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('book', 40) ?></div>
        <div class="empty-title">You need at least one completed surah</div>
        <p class="small" style="margin:0;">Exam questions are drawn from the surahs you have completed. Finish at least one surah first.</p>
    </div>

<?php elseif ($attempt && $attempt['status'] === 'pending'): ?>
    <div class="card animate-rise">
        <div class="card-title">
            <h3 style="margin:0;display:flex;align-items:center;gap:10px;"><?= ui_icon('clock', 18) ?> Exam Submitted</h3>
        </div>
        <p class="small text-muted">Your examination has been submitted and is awaiting review by your teacher. Your result will appear here once it has been graded.</p>
        <p class="small"><strong>Submitted:</strong> <?= date('d M Y H:i', strtotime($attempt['submitted_at'])) ?></p>
        <p class="small text-muted">Remember the acceptance rule: your result is accepted only if <strong>3 mistakes or fewer</strong> are found in your entire recitation.</p>
        <a class="btn btn-ghost" href="dashboard.php"><?= ui_icon('arrow-left', 16) ?> Back to Dashboard</a>
    </div>

<?php elseif ($attempt && $attempt['status'] === 'approved'): ?>
    <div class="card card-gold animate-rise">
        <div class="card-title"><h3 style="margin-top:0;"><?= ui_icon('check-circle', 18) ?> Exam Accepted</h3></div>
        <p class="small" style="margin:0 0 10px;">
            <?= ui_icon('check', 15) ?> Congratulations! Your recitation had <strong><?= (int)$attempt['mistakes_count'] ?> mistake(s)</strong>
            (maximum allowed is 3). You may continue your normal lessons.
        </p>
        <div class="stat-grid" style="margin-bottom:10px;">
            <div class="stat-card stat-gold">
                <span class="stat-ico"><?= ui_icon('star', 20) ?></span>
                <span class="stat-label">Overall Rating</span>
                <span class="stat-value" style="font-size:1.4rem;"><?= htmlspecialchars($attempt['overall_rating'] ?? '—') ?></span>
                <span class="stat-sub"><?= (int)$attempt['overall_score'] ?> / 100</span>
            </div>
            <div class="stat-card stat-green">
                <span class="stat-ico"><?= ui_icon('check-circle', 20) ?></span>
                <span class="stat-label">Date</span>
                <span class="stat-value" style="font-size:1.2rem;"><?= date('d M Y', strtotime($attempt['submitted_at'])) ?></span>
                <span class="stat-sub">Reviewed <?= $attempt['reviewed_at'] ? date('d M Y', strtotime($attempt['reviewed_at'])) : '—' ?></span>
            </div>
        </div>

        <?php foreach ($answers as $idx => $a): ?>
            <div class="panel" style="margin:.5rem 0;">
                <strong>Question <?= $idx + 1 ?>:</strong> Recite Surah <?= htmlspecialchars($a['surah_name']) ?><?php $ar = arabic_text($a['surah_name_ar'] ?? ''); if ($ar !== ''): ?> <span class="arabic"><?= htmlspecialchars($ar) ?></span><?php endif; ?> from verse <?= (int)$a['from_verse'] ?> to <?= (int)$a['to_verse'] ?><br>
                <span class="small">Rating: <strong><?= htmlspecialchars($a['rating'] ?? '—') ?></strong></span>
                <?php if (!empty($a['feedback'])): ?><br><span class="small">Feedback: <?= nl2br(htmlspecialchars($a['feedback'])) ?></span><?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($attempt['overall_feedback'])): ?>
            <div class="panel"><strong>Teacher’s overall feedback:</strong><br><?= nl2br(htmlspecialchars($attempt['overall_feedback'])) ?></div>
        <?php endif; ?>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a class="btn btn-gold" href="exam_result.php?attempt=<?= (int)$attempt['id'] ?>"><?= ui_icon('download', 16) ?> Download Result</a>
            <a class="btn btn-ghost" href="certificate.php"><?= ui_icon('gem', 16) ?> View Certificate</a>
        </div>
    </div>

<?php elseif ($attempt && $attempt['status'] === 'rejected'): ?>
    <div class="card card-danger animate-rise">
        <div class="card-title"><h3 style="margin-top:0;"><?= ui_icon('close', 18) ?> Retake Required</h3></div>
        <p class="small" style="margin:0 0 8px;">
            <?= ui_icon('alert', 16) ?> More than the allowed number of mistakes were found in your last recitation:
            <strong><?= (int)$attempt['mistakes_count'] ?> mistakes</strong> (maximum allowed is <strong>3</strong>).
            You must <strong>retake</strong> this term's examination.
        </p>
        <?php if (!empty($attempt['overall_feedback'])): ?>
            <div class="panel"><strong>Teacher’s feedback on your last attempt:</strong><br><?= nl2br(htmlspecialchars($attempt['overall_feedback'])) ?></div>
        <?php endif; ?>
        <p class="small text-muted" style="margin:10px 0 0;">
            Record a fresh attempt below. Your new submission will be reviewed against the same rule (≤ 3 mistakes).
        </p>
    </div>
    <?php require __DIR__ . '/_exam_start_panel.php'; ?>

<?php elseif ($draft): ?>
    <?php
    $today_items = $draft_questions[$draft_day]['items'] ?? [];
    $today_pending_items = array_values(array_filter($today_items, function ($a) {
        return $a['status'] === 'pending';
    }));
    $done_today = $draft_today_total - $draft_today_pending;
    ?>
    <div class="card animate-rise" style="max-width:680px;">
        <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <h3 style="margin:0;display:flex;align-items:center;gap:10px;"><?= ui_icon('calendar', 18) ?> Day <?= $draft_day ?> of <?= $draft_total ?></h3>
            <span class="badge badge-blue"><?= $done_today ?> / <?= $draft_today_total ?> answered today</span>
        </div>
        <div class="progress" style="height:10px;margin:12px 0 6px;">
            <div class="progress-bar" style="width:<?= $draft_total_questions > 0 ? round(($draft_answered / $draft_total_questions) * 100) : 0 ?>%;"></div>
        </div>
        <p class="small text-muted" style="margin:0;">
            Overall progress: <strong><?= $draft_answered ?> / <?= $draft_total_questions ?></strong> questions completed
            (<?= $draft_total_questions - $draft_answered ?> remaining). You can complete one day at a time within the exam window.
        </p>
    </div>

    <?php if (count($today_pending_items) === 0): ?>
        <div class="card card-gold animate-rise">
            <p class="small" style="margin:0 0 10px;"><?= ui_icon('check-circle', 16) ?> Day <?= $draft_day ?> is complete!</p>
            <?php if ($draft_answered >= $draft_total_questions): ?>
                <p class="small text-muted" style="margin:0;">All your questions are answered. Your exam will be sent for review.</p>
                <a class="btn btn-gold mt-2" href="exam.php"><?= ui_icon('refresh', 16) ?> Refresh</a>
            <?php else: ?>
                <p class="small text-muted" style="margin:0;">Move on to the next day whenever you are ready.</p>
                <a class="btn btn-gold mt-2" href="exam.php"><?= ui_icon('arrow-right', 16) ?> Continue to Day <?= $draft_day + 1 ?></a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-warning animate-rise">
            <?= ui_icon('alert', 16) ?>
            <span>Answer the <strong><?= count($today_pending_items) ?></strong> question(s) for today. Remember the acceptance rule: your result is accepted only if <strong>3 mistakes or fewer</strong> are found in your entire recitation.</span>
        </div>

        <form id="examForm">
            <?= csrf_field() ?>
            <?php foreach ($today_pending_items as $i => $a): ?>
                <div class="card animate-rise d<?= (($i % 4) + 1) ?>" data-idx="<?= $i ?>" data-aid="<?= (int)$a['id'] ?>">
                    <div class="card-title" style="display:flex;align-items:center;gap:10px;">
                        <span class="badge badge-blue">Question <?= $i + 1 ?> / <?= count($today_pending_items) ?> (Day <?= $draft_day ?>)</span>
                        <h3 style="margin:0;font-size:1.05rem;">
                            Recite Surah <?= htmlspecialchars($a['surah_name']) ?>
                            <?php $ar = arabic_text($a['surah_name_ar'] ?? ''); if ($ar !== ''): ?><span class="arabic"><?= htmlspecialchars($ar) ?></span><?php endif; ?>
                            from verse <?= (int)$a['from_verse'] ?> to <?= (int)$a['to_verse'] ?>
                        </h3>
                    </div>
                    <p class="small text-muted" style="margin-top:4px;">Press start, recite the verses clearly, then stop and preview before submitting.</p>

                    <?php if ($device_type === 'iphone'): ?>
                        <div class="alert alert-info" style="margin:10px 0 0;">
                            <?= ui_icon('info', 15) ?>
                            <span style="flex:1;">Because web recording is unreliable on iPhone, record each portion in your phone's <strong>Voice Memos</strong> app, then <strong>upload the audio file</strong> for this question below.</span>
                        </div>
                    <?php else: ?>
                        <div id="recwrap_<?= $i ?>" style="margin-top:10px;">
                            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                <button type="button" class="btn btn-sm" id="start_<?= $i ?>" onclick="startRec(<?= $i ?>)"><?= ui_icon('mic', 15) ?> Start Recording</button>
                                <button type="button" class="btn btn-sm btn-ghost" id="stop_<?= $i ?>" onclick="stopRec(<?= $i ?>)" disabled><?= ui_icon('stop', 15) ?> Stop</button>
                            </div>
                            <audio id="preview_<?= $i ?>" controls class="hidden" style="margin-top:12px;"></audio>
                        </div>
                        <button type="button" class="btn btn-sm btn-ghost" id="toggle_<?= $i ?>" style="margin-top:10px;"><?= ui_icon('upload', 14) ?> Having trouble recording? Upload an audio file instead</button>
                    <?php endif; ?>

                    <div id="filewrap_<?= $i ?>" class="<?= $device_type === 'iphone' ? '' : 'hidden' ?>" style="margin-top:10px;">
                        <label class="form-label" for="file_<?= $i ?>">Audio file for Question <?= $i + 1 ?></label>
                        <input class="file-input" type="file" name="audio[]" id="file_<?= $i ?>" accept="audio/*">
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="card card-gold animate-rise d2">
                <p class="small" style="margin:0 0 10px;">
                    <strong>Before you submit today's answers:</strong> make sure you have recorded or uploaded every
                    question for today. You can submit one day at a time — tomorrow's questions open automatically.
                </p>
                <button type="button" class="btn btn-gold btn-lg btn-block" onclick="submitDay()"><?= ui_icon('send', 18) ?> Submit Day <?= $draft_day ?> Answers</button>
                <p id="submitMsg" class="small text-muted hidden" style="margin:10px 0 0;">Submitting… please wait.</p>
            </div>
        </form>
    <?php endif; ?>

<?php else: ?>
    <?php require __DIR__ . '/_exam_start_panel.php'; ?>
<?php endif; ?>

<?php ui_page_end(); ?>

<script>
const qcount = <?= $draft ? count($today_pending_items ?? []) : 0 ?>;
const recorders = {};
const chunks = {};
const blobs = {};
const recMaxTimers = {};

/* Pick a container the browser can actually record into (Safari/iOS only does audio/mp4) */
const MIME = (window.MediaRecorder && (
    MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' :
    MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : ''
)) || '';
const REC_EXT = MIME === 'audio/mp4' ? 'm4a' : 'webm';
const REC_OPTS = MIME
    ? { mimeType: MIME, audioBitsPerSecond: 48000, videoBitsPerSecond: 0 }
    : { audioBitsPerSecond: 48000, videoBitsPerSecond: 0 };
const REC_MAX_MS = 5 * 60 * 1000;

/* Toggle between in-browser recorder and file upload, per question */
for (let i = 0; i < qcount; i++) {
    const toggle = document.getElementById('toggle_' + i);
    if (toggle) {
        toggle.addEventListener('click', function () {
            document.getElementById('recwrap_' + i).classList.toggle('hidden');
            document.getElementById('filewrap_' + i).classList.toggle('hidden');
        });
    }
}

/* No usable recorder in this browser (e.g. older iPhone) -> force upload mode */
if (!window.MediaRecorder || MIME === '') {
    for (let i = 0; i < qcount; i++) {
        const rw = document.getElementById('recwrap_' + i);
        const fw = document.getElementById('filewrap_' + i);
        if (rw) rw.classList.add('hidden');
        if (fw) fw.classList.remove('hidden');
    }
}

function startRec(i) {
    chunks[i] = [];
    navigator.mediaDevices.getUserMedia({ audio: true })
        .then(s => {
            const rec = new MediaRecorder(s, REC_OPTS);
            rec.ondataavailable = e => { if (e.data.size) chunks[i].push(e.data); };
            rec.onstop = () => {
                clearTimeout(recMaxTimers[i]);
                blobs[i] = new Blob(chunks[i], { type: MIME || 'audio/webm' });
                const p = document.getElementById('preview_' + i);
                p.src = URL.createObjectURL(blobs[i]);
                p.classList.remove('hidden');
                p.load();
                s.getTracks().forEach(t => t.stop());
            };
            recorders[i] = rec;
            rec.start(1000);
            recMaxTimers[i] = setTimeout(() => {
                if (recorders[i] && recorders[i].state === 'recording') {
                    recorders[i].stop();
                    alert('Recording stopped automatically after 5 minutes.');
                }
            }, REC_MAX_MS);
            document.getElementById('start_' + i).disabled = true;
            document.getElementById('stop_' + i).disabled = false;
        })
        .catch(() => {
            alert('Microphone access was denied. You can upload an audio file instead.');
            const rw = document.getElementById('recwrap_' + i);
            const fw = document.getElementById('filewrap_' + i);
            if (rw) rw.classList.add('hidden');
            if (fw) fw.classList.remove('hidden');
        });
}

function stopRec(i) {
    if (recorders[i] && recorders[i].state === 'recording') {
        recorders[i].stop();
        document.getElementById('stop_' + i).disabled = true;
    }
}

function submitDay() {
    if (qcount === 0) return;
    for (let i = 0; i < qcount; i++) {
        const inp = document.getElementById('file_' + i);
        const hasRec = !!blobs[i];
        const hasFile = inp && inp.files.length > 0;
        if (!hasRec && !hasFile) {
            alert('Please record or upload an answer for question ' + (i + 1) + ' before submitting.');
            return;
        }
    }
    document.getElementById('submitMsg').classList.remove('hidden');

    const fd = new FormData();
    for (let i = 0; i < qcount; i++) {
        const card = document.querySelector('[data-idx="' + i + '"]');
        const aid = card ? card.dataset.aid : '';
        const inp = document.getElementById('file_' + i);
        const hasRec = !!blobs[i];
        const hasFile = inp && inp.files.length > 0;
        fd.append('answer_id[]', aid);
        if (hasFile && !hasRec) {
            fd.append('audio[]', inp.files[0]);
        } else {
            fd.append('audio[]', blobs[i], 'question_' + (i + 1) + '.' + REC_EXT);
        }
    }
    fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);

    fetch('submit_exam.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
        .then(r => r.text())
        .then(res => {
            const trimmed = res.trim();
            if (trimmed === 'OK') {
                location.href = 'exam.php';
            } else {
                const looksLikeHtml = /^</.test(trimmed);
                document.getElementById('submitMsg').classList.add('hidden');
                if (looksLikeHtml) {
                    /* Server returned a full page (e.g. logged-out redirect). */
                    let hint = '';
                    try {
                        const doc = new DOMParser().parseFromString(trimmed, 'text/html');
                        const el = doc.querySelector('h3, h1, title');
                        if (el) hint = el.textContent.trim();
                    } catch (e) { /* ignore */ }
                    alert('Submission failed. ' + (hint ? 'The server returned: "' + hint + '". ' : '') + 'If your session expired, please log in again and resubmit.');
                } else {
                    alert('Submission failed. ' + trimmed);
                }
            }
        })
        .catch(() => {
            document.getElementById('submitMsg').classList.add('hidden');
            alert('Submission failed. Please check your connection and try again.');
        });
}
</script>

</body>
</html>
