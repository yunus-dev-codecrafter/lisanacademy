<?php
require __DIR__ . '/../config/security/helpers.php';
require __DIR__ . '/../auth/auth_check.php';
require __DIR__ . '/../config/db.php';
require_role('student');

$student_id = (int)$_SESSION['user_id'];

/* Device type — iPhone students get a file-upload path (web recording is unreliable on iOS) */
$device_row = $conn->query("SELECT device_type FROM users WHERE id = $student_id")->fetch_assoc();
$device_type = $device_row['device_type'] ?? 'android';

if (!student_is_hafiz($conn, $student_id)) {
    ui_message_page('warning', 'Not a Hafiz Student', 'This page is only available to Hafiz students.', 'dashboard.php', 'Dashboard', 'close');
    exit;
}

if (!db_table_exists($conn, 'hafiz_revision') || !db_table_exists($conn, 'hafiz_weekly_tests')) {
    ui_message_page('warning', 'Not Ready', 'The weekly test system has not been set up yet. Please contact the admin.', 'hafiz_revision.php', 'Hafiz Revision', 'close');
    exit;
}

$revision = hafiz_get_active_revision($conn, $student_id);
if (!$revision) {
    ui_message_page('warning', 'No Active Revision', 'You do not have an active revision cycle. Please contact the admin.', 'hafiz_revision.php', 'Hafiz Revision', 'close');
    exit;
}

$state = hafiz_week_test_state($conn, $revision);
$mode = $state['state'];
$test = $state['test'];
$juz_no = hafiz_test_juz($conn, $revision);

/* Question rows for in-progress / pending / passed / failed states */
$questions = [];
if ($test && in_array($mode, ['in_progress', 'pending', 'passed', 'failed'], true)) {
    $questions = hafiz_week_test_answers($conn, (int)$test['id']);
}
$ti = hafiz_test_time_limit();
$deadline_ts = ($test && $test['started_at']) ? strtotime(hafiz_test_deadline($test['started_at'])) : 0;
$now_ts = time();
$seconds_left = $deadline_ts > 0 ? max(0, $deadline_ts - $now_ts) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Weekly Test — Hafiz Revision</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'dashboard', 'Weekly Test', 'Hafiz Revision'); ?>

<div class="page-hero animate-rise">
    <h1><?= ui_icon('calendar-check', 24) ?> Weekly Test — Juz <?= $juz_no ?></h1>
    <p class="text-muted" style="margin:6px 0 0;">After completing each Juz, you take a short test on the pages you've completed. 4 questions, <?= $ti['time_limit'] ?> minutes plus <?= $ti['grace'] ?> minutes grace. Pass to continue, fail to retake.</p>
</div>

<?php if ($mode === 'locked'): ?>
    <div class="card animate-rise d1">
        <div class="card-title"><h3 style="margin:0;"><?= ui_icon('lock', 18) ?> Test Locked</h3></div>
        <p class="small text-muted" style="margin:0 0 12px;"><?= htmlspecialchars($state['reason']) ?></p>
        <a class="btn btn-ghost" href="hafiz_revision.php"><?= ui_icon('arrow-left', 16) ?> Back to Revision</a>
    </div>

<?php elseif ($mode === 'available'): ?>
    <div class="card animate-rise d1" style="max-width:640px;">
        <div class="card-title"><h3 style="margin:0;"><?= ui_icon('play', 18) ?> Ready When You Are</h3></div>
        <p class="small text-muted" style="margin:0 0 6px;">You have completed this Juz — every page has been approved by your teacher. Generate your test now.</p>
        <ul class="small" style="margin:0 0 14px;padding-left:20px;">
            <li><strong>4 questions</strong>, each a passage of at least 10 verses from a surah you've already revised.</li>
            <li>Questions stay <strong>hidden until you click Generate</strong> — the timer starts immediately.</li>
            <li>You have <strong><?= $ti['time_limit'] ?> minutes</strong> to answer, with a <strong><?= $ti['grace'] ?>-minute grace</strong> to submit.</li>
            <li>If the time runs out before you submit, you <strong>start over</strong> with new random questions.</li>
            <li>Your teacher listens to your answers and marks you <strong>Pass</strong> or <strong>Fail</strong>.</li>
        </ul>
        <form method="POST" action="start_hafiz_test.php" onsubmit="return confirm('Start the weekly test now? The timer begins immediately and the questions will only appear after you click OK.');">
            <?= csrf_field() ?>
            <button class="btn btn-gold btn-lg" type="submit"><?= ui_icon('bolt', 18) ?> Generate Weekly Test</button>
        </form>
    </div>

<?php elseif ($mode === 'expired'): ?>
    <div class="card card-danger animate-rise d1" style="max-width:640px;">
        <div class="card-title"><h3 style="margin:0;"><?= ui_icon('clock', 18) ?> Time Expired</h3></div>
        <p class="small" style="margin:0 0 12px;"><?= htmlspecialchars($state['reason']) ?> You'll get a brand-new set of random questions.</p>
        <form method="POST" action="start_hafiz_test.php">
            <?= csrf_field() ?>
            <button class="btn btn-gold" type="submit"><?= ui_icon('refresh', 17) ?> Start a New Test</button>
        </form>
    </div>

<?php elseif ($mode === 'in_progress'): ?>
    <div class="card animate-rise d1" style="max-width:900px;">
        <div class="card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
            <h3 style="margin:0;"><?= ui_icon('mic', 18) ?> Your Weekly Test</h3>
            <div style="display:flex;align-items:center;gap:10px;">
                <div class="badge badge-gold" id="timerBadge"><?= gmdate('i:s', $seconds_left) ?></div>
                <a class="btn btn-sm btn-ghost" href="hafiz_revision.php"><?= ui_icon('arrow-left', 14) ?> Exit</a>
            </div>
        </div>
        <p class="small text-muted" style="margin:8px 0 0;">
            <strong><?= $ti['time_limit'] ?> min</strong> answering time + <strong><?= $ti['grace'] ?> min grace</strong> to submit.
            <?= count($questions) ?> question<?= count($questions) === 1 ? '' : 's' ?> — recite each one from memory.
        </p>
        <?php if ($deadline_ts > 0): ?>
        <script>window.__testDeadline = <?= $deadline_ts ?>; window.__tlLimit = <?= $ti['time_limit'] * 60 ?>; window.__tlGrace = 0;</script>
        <?php endif; ?>
    </div>

    <?php if ($questions): ?>
    <form id="testForm">
        <?= csrf_field() ?>
        <?php foreach ($questions as $i => $q): ?>
        <div class="card animate-rise d<?= (($i % 4) + 1) ?>" data-idx="<?= $i ?>" data-aid="<?= (int)$q['id'] ?>">
            <div class="card-title" style="display:flex;align-items:center;gap:10px;">
                <span class="badge badge-blue">Question <?= $i + 1 ?> / <?= count($questions) ?></span>
                <h3 style="margin:0;font-size:1.05rem;">
                    Recite Surah <?= htmlspecialchars($q['surah_name']) ?>
                    <?php $ar = arabic_text($q['surah_name_ar'] ?? ''); if ($ar !== ''): ?><span class="arabic"><?= htmlspecialchars($ar) ?></span><?php endif; ?>
                    from verse <?= (int)$q['from_verse'] ?> to <?= (int)$q['to_verse'] ?>
                </h3>
            </div>
            <p class="small text-muted" style="margin-top:4px;">Recite these verses clearly from memory, then press stop and preview before submitting.</p>

            <?php if ($device_type === 'iphone'): ?>
                <div class="alert alert-info" style="margin:10px 0 0;">
                    <?= ui_icon('info', 15) ?>
                    <span style="flex:1;">Because web recording is unreliable on iPhone, record this passage in your phone's <strong>Voice Memos</strong> app, then <strong>upload the audio file</strong> below.</span>
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

        <div class="card card-gold animate-rise d3">
            <p class="small" style="margin:0 0 10px;"><strong>Before you submit:</strong> make sure all <?= count($questions) ?> questions have an audio answer. Once submitted you cannot change them — your teacher will review and mark Pass or Fail.</p>
            <button type="button" class="btn btn-gold btn-lg btn-block" onclick="submitTest()"><?= ui_icon('send', 18) ?> Submit Weekly Test</button>
            <p id="submitMsg" class="small text-muted hidden" style="margin:10px 0 0;">Submitting… please wait.</p>
        </div>
    </form>
    <?php else: ?>
        <div class="card animate-rise d1">
            <p class="small" style="margin:0;">This test has no questions yet. Please try generating a new one.</p>
            <form method="POST" action="start_hafiz_test.php" style="margin-top:10px;">
                <?= csrf_field() ?>
                <button class="btn btn-gold" type="submit"><?= ui_icon('refresh', 17) ?> Generate New Test</button>
            </form>
        </div>
    <?php endif; ?>

<?php elseif ($mode === 'pending'): ?>
    <div class="card animate-rise d1">
        <div class="card-title"><h3 style="margin:0;"><?= ui_icon('clock', 18) ?> Submitted — Awaiting Review</h3></div>
        <p class="small text-muted" style="margin:0 0 12px;"><?= htmlspecialchars($state['reason']) ?> Your teacher will listen to your answers and mark you Pass or Fail.</p>
        <?php if ($test && $test['submitted_at']): ?>
            <p class="small"><strong>Submitted:</strong> <?= date('d M Y, g:i A', strtotime($test['submitted_at'])) ?></p>
        <?php endif; ?>
        <a class="btn btn-ghost" href="hafiz_revision.php"><?= ui_icon('arrow-left', 16) ?> Back to Revision</a>
    </div>

<?php elseif ($mode === 'passed'): ?>
    <div class="card card-gold animate-rise d1">
        <div class="card-title"><h3 style="margin:0;"><?= ui_icon('check-circle', 18) ?> Test Passed</h3></div>
        <p class="small" style="margin:0 0 10px;"><?= htmlspecialchars($state['reason']) ?> You may continue reciting your next pages.</p>
        <?php if ($test && $test['reviewed_at']): ?>
            <p class="small text-muted"><strong>Reviewed:</strong> <?= date('d M Y, g:i A', strtotime($test['reviewed_at'])) ?></p>
        <?php endif; ?>
        <?= render_test_questions_readonly($questions, $test) ?>
        <div style="margin-top:12px;">
            <a class="btn btn-gold" href="hafiz_revision.php"><?= ui_icon('arrow-right', 16) ?> Continue Reciting</a>
        </div>
    </div>

<?php elseif ($mode === 'failed'): ?>
    <div class="card card-danger animate-rise d1" style="max-width:640px;">
        <div class="card-title"><h3 style="margin:0;"><?= ui_icon('close', 18) ?> Test Not Passed — Retake Required</h3></div>
        <p class="small" style="margin:0 0 6px;"><?= htmlspecialchars($state['reason']) ?> You must <strong>pass a retake</strong> before you can continue reciting. A fresh set of random questions will be generated.</p>
        <?php if ($test): ?>
            <?= render_test_questions_readonly($questions, $test) ?>
        <?php endif; ?>
        <form method="POST" action="start_hafiz_test.php" style="margin-top:12px;">
            <?= csrf_field() ?>
            <button class="btn btn-gold" type="submit"><?= ui_icon('refresh', 18) ?> Retake the Test</button>
        </form>
    </div>

<?php endif; ?>

<?php ui_page_end(); ?>

<script>
const qcount = <?= in_array($mode, ['in_progress'], true) ? count($questions ?? []) : 0 ?>;
const recorders = {};
const chunks = {};
const blobs = {};

/* Pick a container the browser can actually record into (Safari/iOS only does audio/mp4) */
const MIME = (window.MediaRecorder && (
    MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' :
    MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : ''
)) || '';
const REC_EXT = MIME === 'audio/mp4' ? 'm4a' : 'webm';

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

/* No usable recorder in this browser -> force upload mode */
if (qcount > 0 && (!window.MediaRecorder || MIME === '')) {
    for (let i = 0; i < qcount; i++) {
        const rw = document.getElementById('recwrap_' + i);
        const fw = document.getElementById('filewrap_' + i);
        if (rw) rw.classList.add('hidden');
        if (fw) fw.classList.remove('hidden');
    }
}

/* Countdown timer */
(function () {
    const badge = document.getElementById('timerBadge');
    const deadline = window.__testDeadline || 0;
    const limitSec = window.__tlLimit || 0;
    if (!badge || !deadline) return;
    const fmt = function (s) {
        const m = Math.floor(s / 60);
        const sec = s % 60;
        return (m < 10 ? '0' : '') + m + ':' + (sec < 10 ? '0' : '') + sec;
    };
    let warned = false;
    const tick = function () {
        const left = deadline - Math.floor(Date.now() / 1000);
        if (left <= 0) {
            badge.textContent = '00:00';
            badge.classList.add('badge-red');
            alert('Time is up! Your test has expired. You will need to generate a new one.');
            location.href = 'hafiz_test.php';
            return;
        }
        badge.textContent = fmt(left);
        if (!warned && left <= (window.__tlGrace + 0)) {
            warned = true;
        }
        setTimeout(tick, 1000);
    };
    tick();
})();

function startRec(i) {
    chunks[i] = [];
    navigator.mediaDevices.getUserMedia({ audio: true })
        .then(s => {
            const rec = new MediaRecorder(s, MIME ? { mimeType: MIME } : undefined);
            rec.ondataavailable = e => { if (e.data.size) chunks[i].push(e.data); };
            rec.onstop = () => {
                blobs[i] = new Blob(chunks[i], { type: MIME || 'audio/webm' });
                const p = document.getElementById('preview_' + i);
                p.src = URL.createObjectURL(blobs[i]);
                p.classList.remove('hidden');
                p.load();
                s.getTracks().forEach(t => t.stop());
            };
            recorders[i] = rec;
            rec.start();
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

function submitTest() {
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

    fetch('submit_hafiz_test.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
        .then(r => r.text())
        .then(res => {
            const trimmed = res.trim();
            if (trimmed === 'OK') {
                location.href = 'hafiz_test.php';
            } else {
                const looksLikeHtml = /^</.test(trimmed);
                document.getElementById('submitMsg').classList.add('hidden');
                if (looksLikeHtml) {
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
<?php
/* Shared read-only question renderer (passed/failed/pending review views). */
function render_test_questions_readonly($questions, $test) {
    $html = '';
    if (!$questions) return '';
    $html .= '<div style="margin-top:12px;">';
    foreach ($questions as $idx => $q) {
        $html .= '<div class="panel" style="margin:.5rem 0;">';
        $html .= '<strong>Question ' . ($idx + 1) . ':</strong> Recite Surah ' . htmlspecialchars($q['surah_name'] ?? '');
        $ar = arabic_text($q['surah_name_ar'] ?? '');
        if ($ar !== '') $html .= ' <span class="arabic">' . htmlspecialchars($ar) . '</span>';
        $html .= ' from verse ' . (int)$q['from_verse'] . ' to ' . (int)$q['to_verse'];
        if (!empty($q['audio_file'])) {
            $html .= '<br><audio controls preload="none" style="width:100%;margin-top:6px;" src="../uploads/hafiz_test_audio/' . htmlspecialchars($q['audio_file']) . '"></audio>';
        }
        $html .= '</div>';
    }
    if ($test && !empty($test['admin_feedback'])) {
        $html .= '<div class="panel">' . ui_icon('chat', 14) . ' <strong>Teacher’s feedback:</strong><br>' . nl2br(htmlspecialchars($test['admin_feedback'])) . '</div>';
    }
    if ($test && !empty($test['admin_audio_file'])) {
        $html .= '<div class="panel">' . ui_icon('mic', 14) . ' <strong>Teacher’s audio notes:</strong><br>'
               . '<audio controls preload="none" style="width:100%;margin-top:6px;" src="../uploads/admin_feedback/' . htmlspecialchars($test['admin_audio_file']) . '"></audio></div>';
    }
    $html .= '</div>';
    return $html;
}