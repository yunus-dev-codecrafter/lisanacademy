<?php
require '../config/security/helpers.php';
require_role('student');
include '../auth/auth_check.php';
require '../config/db.php';

if (student_is_hafiz($conn, (int)($_SESSION['user_id'] ?? 0))) {
    header("Location: hafiz_revision.php");
    exit;
}

if (student_in_exam($conn, (int)($_SESSION['user_id'] ?? 0))) {
    header("Location: exam.php");
    exit;
}

if (student_exam_locked($conn, (int)$_SESSION['user_id'])) {
    header("Location: exam_defaulted.php");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Record Recitation</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/assets/CSS/base.css">
    <link rel="stylesheet" href="/assets/CSS/components.css">
    <link rel="stylesheet" href="/assets/CSS/layout.css">
    <link rel="stylesheet" href="/assets/CSS/icons.css">
</head>
<body>

<div class="center-screen">
    <div class="card animate-rise" style="max-width:440px;text-align:center;padding:34px;">
        <div class="live-icon" style="font-size:2.4rem;"><?= ui_icon('mic', 34) ?></div>
        <h2>Record Your Recitation</h2>
        <p class="small text-muted">Press start, recite your assigned verses, then stop and submit.</p>

        <div style="display:flex;gap:10px;justify-content:center;margin-top:16px;">
            <button id="startBtn" class="btn"><?= ui_icon('mic', 16) ?> Start Recording</button>
            <button id="stopBtn" class="btn btn-danger" disabled><?= ui_icon('stop', 16) ?> Stop</button>
        </div>
        <audio id="preview" controls class="hidden" style="margin-top:16px;"></audio>
        <button id="sendBtn" class="btn btn-gold btn-block" style="margin-top:12px;" disabled><?= ui_icon('send', 16) ?> Send Recitation</button>
        <p id="reciteMsg" class="small text-muted hidden" style="margin-top:10px;">Uploading… please wait.</p>

        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
            <p class="small text-muted" style="margin:0 0 8px;">iPhone camera recording? Upload the video file instead:</p>
            <input type="file" id="uploadFallback" accept="audio/*,video/*,.m4a,.mp3,.wav,.ogg,.webm,.aac,.mp4,.m4v,.mov,.3gp" style="width:100%;">
            <button id="uploadBtn" class="btn btn-ghost btn-block" style="margin-top:8px;" disabled><?= ui_icon('upload', 16) ?> Upload Selected File</button>
        </div>
    </div>
</div>

<script src="/assets/js/recorder.js"></script>
<script>
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const sendBtn = document.getElementById('sendBtn');
const preview = document.getElementById('preview');
const reciteMsg = document.getElementById('reciteMsg');
const uploadFallback = document.getElementById('uploadFallback');
const uploadBtn = document.getElementById('uploadBtn');
let recorder = null;
let chunks = [];
let audioBlob = null;
let recMime = '';
let recExt = 'm4a';
let recMaxTimer = null;
let recStream = null;

const REC_MAX_MS = 5 * 60 * 1000;
const LESSON_ID = <?= (int)($_GET['lesson_id'] ?? 0) ?>;

function showMsg(t) { if (reciteMsg) { reciteMsg.textContent = t; reciteMsg.classList.remove('hidden'); } }
function hideMsg() { if (reciteMsg) reciteMsg.classList.add('hidden'); }

function submitBlob(blob, filename) {
    if (!blob || blob.size === 0) { alert('Recording is empty. Please record again.'); return; }
    if (!LESSON_ID) { alert('Missing lesson. Please open this page from My Lessons.'); return; }
    const fd = new FormData();
    fd.append('audio', blob, filename);
    fd.append('learning_plan_id', String(LESSON_ID));
    showMsg('Uploading… please wait.');
    sendBtn.disabled = true;
    if (uploadBtn) uploadBtn.disabled = true;
    fetch('submit_recitation.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
        .then(r => r.text())
        .then(res => {
            if (res.trim() === 'OK') { location.href = 'recitation_sent.php'; }
            else { hideMsg(); alert(res); sendBtn.disabled = false; if (uploadBtn) uploadBtn.disabled = false; }
        })
        .catch(err => { hideMsg(); alert('Error submitting recitation: ' + err); sendBtn.disabled = false; if (uploadBtn) uploadBtn.disabled = false; });
}

startBtn.addEventListener('click', () => {
    chunks = [];
    audioBlob = null;
    if (!window.Recorder || !Recorder.supported()) { alert('Recording is not supported in this browser. Please use the file upload below.'); return; }
    const picked = Recorder.pick();
    recMime = picked.mime; recExt = picked.ext;
    navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
        recStream = stream;
        try { recorder = Recorder.create(stream, recMime); }
        catch (e) { alert('Recording is not supported on this device. Please use the file upload below.'); stream.getTracks().forEach(t => t.stop()); return; }
        try { recMime = recorder.mimeType || recMime; } catch (e) {}
        recorder.ondataavailable = e => { if (e.data && e.data.size) chunks.push(e.data); };
        recorder.onerror = () => { clearTimeout(recMaxTimer); alert('Recording failed. Please try again or upload a file below.'); startBtn.disabled = false; stopBtn.disabled = true; };
        recorder.onstop = () => {
            clearTimeout(recMaxTimer);
            audioBlob = Recorder.makeBlob(chunks, recorder, recMime);
            if (!audioBlob || audioBlob.size === 0) { alert('Recording is empty. Please record again.'); startBtn.disabled = false; return; }
            preview.src = URL.createObjectURL(audioBlob);
            preview.classList.remove('hidden');
            try { preview.load(); } catch (e) {}
            sendBtn.disabled = false;
            if (recStream) recStream.getTracks().forEach(t => t.stop());
            recStream = null;
        };
        try { recorder.start(1000); }
        catch (e) { alert('Could not start recording. Please use the file upload below.'); stream.getTracks().forEach(t => t.stop()); return; }
        recMaxTimer = setTimeout(() => {
            if (recorder && recorder.state === 'recording') {
                recorder.stop();
                alert('Recording stopped automatically after 5 minutes.');
            }
        }, REC_MAX_MS);
        startBtn.disabled = true;
        stopBtn.disabled = false;
    }).catch(() => alert('Microphone access denied. Please allow access or upload a file below.'));
});

stopBtn.addEventListener('click', () => {
    if (recorder && recorder.state === 'recording') {
        recorder.stop();
        stopBtn.disabled = true;
    }
});

sendBtn.addEventListener('click', () => {
    if (!audioBlob) return alert('No recording found');
    submitBlob(audioBlob, 'recitation.' + recExt);
});

if (uploadFallback) uploadFallback.addEventListener('change', () => {
    if (uploadBtn) uploadBtn.disabled = !(uploadFallback.files && uploadFallback.files.length);
});
if (uploadBtn) uploadBtn.addEventListener('click', () => {
    if (!uploadFallback.files || !uploadFallback.files.length) return alert('Choose a file first.');
    submitBlob(uploadFallback.files[0], uploadFallback.files[0].name);
});
</script>
<script src="/assets/js/audio_player.js"></script>

</body>
</html>