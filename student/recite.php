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
            <button id="stopBtn" class="btn btn-danger" disabled><?= ui_icon('stop', 16) ?> Stop &amp; Submit</button>
        </div>
        <audio id="preview" controls class="hidden" style="margin-top:16px;"></audio>
        <button id="sendBtn" class="btn btn-gold btn-block" style="margin-top:12px;" disabled><?= ui_icon('send', 16) ?> Send Recitation</button>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($submit_error ?? '') ?></div>
        <?php endif; ?>
    </div>
</div>

<?php
/* Handle audio submission (form posts to same page) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['audio'])) {
    $student_id = (int)$_SESSION['user_id'];
    $learning_plan_id = (int)($_POST['learning_plan_id'] ?? 0);
    if ($learning_plan_id <= 0) {
        $submit_error = 'Missing learning plan.';
    } elseif ($_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
        $submit_error = 'Audio upload failed.';
    } else {
        /* The lesson must actually belong to this student — prevents orphaned rows. */
        $stmt = $conn->prepare("SELECT id FROM lessons WHERE id = ? AND student_id = ? LIMIT 1");
        $stmt->bind_param("ii", $learning_plan_id, $student_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $submit_error = 'Invalid lesson.';
        } else {
            $upload_dir = __DIR__ . '/../uploads/student_audio/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['audio']['name'], PATHINFO_EXTENSION));
            $filename = 'student_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            if (!move_uploaded_file($_FILES['audio']['tmp_name'], $upload_dir . $filename)) {
                $submit_error = 'Upload failed.';
            } else {
                $stmt = $conn->prepare("INSERT INTO student_recitation (student_id, learning_plan_id, audio_file, submitted_at) VALUES (?, ?, ?, NOW())");
                $stmt->bind_param("iis", $student_id, $learning_plan_id, $filename);
                $stmt->execute();
                header("Location: recitation_sent.php");
                exit;
            }
        }
    }
}
?>

<script>
const startBtn = document.getElementById('startBtn');
const stopBtn = document.getElementById('stopBtn');
const sendBtn = document.getElementById('sendBtn');
const preview = document.getElementById('preview');
let recorder = null;
let chunks = [];
let audioBlob = null;

const MIME = (window.MediaRecorder && (
    MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' :
    MediaRecorder.isTypeSupported('audio/webm;codecs=opus') ? 'audio/webm;codecs=opus' : ''
)) || '';
const REC_EXT = MIME === 'audio/mp4' ? 'm4a' : 'webm';

startBtn.addEventListener('click', () => {
    chunks = [];
    audioBlob = null;
    navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
        recorder = new MediaRecorder(stream, MIME ? { mimeType: MIME } : undefined);
        recorder.ondataavailable = e => { if (e.data.size) chunks.push(e.data); };
        recorder.onstop = () => {
            audioBlob = new Blob(chunks, { type: MIME || 'audio/webm' });
            preview.src = URL.createObjectURL(audioBlob);
            preview.classList.remove('hidden');
            sendBtn.disabled = false;
            stream.getTracks().forEach(t => t.stop());
        };
        recorder.start();
        startBtn.disabled = true;
        stopBtn.disabled = false;
    }).catch(() => alert('Microphone access denied'));
});

stopBtn.addEventListener('click', () => {
    if (recorder && recorder.state === 'recording') {
        recorder.stop();
        stopBtn.disabled = true;
    }
});

sendBtn.addEventListener('click', () => {
    if (!audioBlob) return alert('No recording found');
    const fd = new FormData();
    fd.append('audio', audioBlob, 'recitation.' + REC_EXT);
    fd.append('learning_plan_id', '<?= (int)($_GET['lesson_id'] ?? 0) ?>');
    fetch('recite.php', { method: 'POST', body: fd })
        .then(r => (location.href = 'recitation_sent.php'))
        .catch(err => alert('Error submitting recitation: ' + err));
});
</script>

</body>
</html>