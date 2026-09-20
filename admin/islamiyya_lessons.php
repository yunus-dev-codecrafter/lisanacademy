<?php
/**
 * Digital Islamiyya — Admin Lesson + Quiz Manager (per book).
 *
 * The "Manage Lessons" button in islamiyya.php lands here. Admin picks a
 * book (?book=ID), uploads its lessons (audio/video media files) and adds
 * at least one auto-graded MCQ quiz question per lesson. A book only goes
 * LIVE once every expected lesson is uploaded AND every lesson has a quiz.
 */
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

$book_id = (int)($_GET['book'] ?? $_POST['book_id'] ?? 0);
$book = $book_id > 0 ? islamiyya_book($conn, $book_id) : null;
if (!$book) {
    ui_message_page('warning', 'Book Not Found', 'Pick a book from the Book Manager first.', 'islamiyya.php', 'Back to Book Manager', 'book-open');
}

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['act'] ?? '';
    $book_id = (int)($_POST['book_id'] ?? 0);
    $book = islamiyya_book($conn, $book_id);
    if (!$book) {
        ui_message_page('warning', 'Book Not Found', 'Pick a book from the Book Manager first.', 'islamiyya.php', 'Back to Book Manager', 'book-open');
    }

    /* ---------- SAVE LESSON (add / edit) ---------- */
    if ($act === 'save_lesson') {
        $lesson_id = (int)($_POST['lesson_id'] ?? 0);
        $lesson_no = max(1, (int)($_POST['lesson_no'] ?? 1));
        $title     = trim($_POST['lesson_title'] ?? '');
        $media_type = ($_POST['lesson_media_type'] ?? $book['media_type'] ?? 'audio') === 'video' ? 'video' : 'audio';

        if ($title === '') {
            $err = 'Lesson title is required.';
        } else {
            /* lesson_no must stay unique within the book */
            $dup = null;
            try {
                $stmt = $conn->prepare("SELECT id FROM islamiyya_lessons WHERE book_id = ? AND lesson_no = ? LIMIT 1");
                $stmt->bind_param("ii", $book_id, $lesson_no);
                $stmt->execute();
                $dup = $stmt->get_result()->fetch_assoc();
            } catch (Throwable $e) { $dup = null; }
            if ($dup && (int)$dup['id'] !== $lesson_id) {
                $err = 'Lesson #' . $lesson_no . ' already exists for this book — pick another number.';
            } else {
                $media_file = null;
                /* Supported types: expanded to cover phone recorders (3GP/AMR),
                   WhatsApp/Telegram voice notes (OPUS/OGG), and common
                   compressed formats. MP3 64kbps mono remains the recommended
                   upload for small-but-clear voice lessons. */
                $audio_ok = ['mp3', 'm4a', 'm4b', 'ogg', 'oga', 'opus', 'aac', 'wav', 'flac', 'wma', '3gp', 'amr', 'webm'];
                $video_ok = ['mp4', 'webm', 'mov'];
                $audio_label = 'MP3, M4A, OGG, OPUS, AAC, WAV, FLAC, 3GP, AMR, WMA or WebM audio';
                $video_label = 'MP4, WebM or MOV';
                if (!empty($_FILES['media']) && (int)($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $upload_err = (int)($_FILES['media']['error'] ?? UPLOAD_ERR_NO_FILE);
                    if ($upload_err !== UPLOAD_ERR_OK) {
                        if ($upload_err === UPLOAD_ERR_INI_SIZE || $upload_err === UPLOAD_ERR_FORM_SIZE) {
                            $max_ini = function_exists('ini_get') ? (string)@ini_get('upload_max_filesize') : '';
                            $err = 'File too large for the server to accept'
                                . ($max_ini !== '' ? ' (server limit: ' . $max_ini . ').' : '.')
                                . ' Compress it first — mono MP3 64kbps is about 0.5 MB per minute — then try again.';
                        } elseif ($upload_err === UPLOAD_ERR_PARTIAL) {
                            $err = 'Upload was interrupted. Please try uploading the file again.';
                        } else {
                            $err = 'Upload failed (code ' . $upload_err . '). Please try again with a smaller file.';
                        }
                    } else {
                        $ext = strtolower(pathinfo((string)$_FILES['media']['name'], PATHINFO_EXTENSION));
                        $allowed  = $media_type === 'video' ? $video_ok : $audio_ok;
                        $label    = $media_type === 'video' ? $video_label : $audio_label;
                        if ($ext === '') {
                            $err = 'That file has no extension. Rename it with an extension (' . $label . ') and try again.';
                        } elseif (!in_array($ext, $allowed, true)) {
                            $err = 'File type .' . $ext . ' is not supported. Use: ' . $label . '.'
                                . ($media_type === 'audio' ? ' Tip: convert phone recordings (3GP/AMR) or voice notes to MP3 64kbps mono before uploading.' : '');
                        } else {
                            /* Light MIME sanity check: block scripts disguised as media
                               without rejecting legit phone audio on odd server setups. */
                            $mime_ok = true;
                            if (function_exists('finfo_open')) {
                                try {
                                    $fi = @finfo_open(FILEINFO_MIME_TYPE);
                                    if ($fi) {
                                        $mime = (string)@finfo_file($fi, (string)$_FILES['media']['tmp_name']);
                                        @finfo_close($fi);
                                        if ($mime !== '' && preg_match('#^(text/|application/(x-php|x-sh|x-executable|x-msdownload))#i', $mime)) {
                                            $mime_ok = false;
                                        }
                                    }
                                } catch (Throwable $e) { $mime_ok = true; }
                            }
                            if (!$mime_ok) {
                                $err = 'That file does not look like a real media file. Please upload a genuine audio/video file.';
                            } else {
                                $dest = __DIR__ . '/../uploads/islamiyya_media';
                                if (!is_dir($dest)) @mkdir($dest, 0775, true);
                                $fname = 'book' . $book_id . '_lesson' . $lesson_no . '_' . time() . '.' . $ext;
                                if (move_uploaded_file($_FILES['media']['tmp_name'], $dest . '/' . $fname)) {
                                    $media_file = $fname;
                                } else {
                                    $err = 'Could not move the uploaded media file. Check that uploads/islamiyya_media is writable, then try again.';
                                }
                            }
                        }
                    }
                }

                if ($err === '') {
                    if ($lesson_id > 0) {
                        $old = islamiyya_lesson($conn, $lesson_id);
                        if (!$old || (int)$old['book_id'] !== $book_id) {
                            $err = 'Lesson not found.';
                        } else {
                            if ($media_file !== null && !empty($old['media_file'])) {
                                @unlink(__DIR__ . '/../uploads/islamiyya_media/' . basename($old['media_file']));
                                $stmt = $conn->prepare("UPDATE islamiyya_lessons SET lesson_no=?, title=?, media_type=?, media_file=? WHERE id=?");
                                $stmt->bind_param("isssi", $lesson_no, $title, $media_type, $media_file, $lesson_id);
                            } elseif ($media_file !== null) {
                                $stmt = $conn->prepare("UPDATE islamiyya_lessons SET lesson_no=?, title=?, media_type=?, media_file=? WHERE id=?");
                                $stmt->bind_param("isssi", $lesson_no, $title, $media_type, $media_file, $lesson_id);
                            } else {
                                $stmt = $conn->prepare("UPDATE islamiyya_lessons SET lesson_no=?, title=?, media_type=? WHERE id=?");
                                $stmt->bind_param("issi", $lesson_no, $title, $media_type, $lesson_id);
                            }
                            if ($stmt->execute()) {
                                /* keep student progress aligned when the number changes */
                                if ((int)$old['lesson_no'] !== $lesson_no && db_table_exists($conn, 'islamiyya_lesson_progress')) {
                                    try {
                                        $up = $conn->prepare("UPDATE islamiyya_lesson_progress SET lesson_no=? WHERE book_id=? AND lesson_no=?");
                                        $old_no = (int)$old['lesson_no'];
                                        $up->bind_param("iii", $lesson_no, $book_id, $old_no);
                                        $up->execute();
                                    } catch (Throwable $e) { /* ignore */ }
                                }
                                $msg = 'Lesson #' . $lesson_no . ' updated.';
                            } else {
                                $err = 'Could not save the lesson.';
                            }
                        }
                    } else {
                        if ($media_file === null && $err === '') {
                            $err = 'Upload the lesson media file (' . ($media_type === 'video' ? $video_label : $audio_label) . ').';
                        } else {
                            $stmt = $conn->prepare("INSERT INTO islamiyya_lessons (book_id, lesson_no, title, media_type, media_file) VALUES (?,?,?,?,?)");
                            $stmt->bind_param("iisss", $book_id, $lesson_no, $title, $media_type, $media_file);
                            if ($stmt->execute()) {
                                $msg = 'Lesson #' . $lesson_no . ' uploaded. Now add its quiz question below.';
                            } else {
                                @unlink(__DIR__ . '/../uploads/islamiyya_media/' . basename($media_file));
                                $err = 'Could not save the lesson (is the lesson number already used?).';
                            }
                        }
                    }
                }
            }
        }
    }

    /* ---------- DELETE LESSON ---------- */
    if ($act === 'delete_lesson') {
        $lesson_id = (int)($_POST['lesson_id'] ?? 0);
        $lesson = islamiyya_lesson($conn, $lesson_id);
        if (!$lesson || (int)$lesson['book_id'] !== $book_id) {
            $err = 'Lesson not found.';
        } else {
            if (!empty($lesson['media_file'])) @unlink(__DIR__ . '/../uploads/islamiyya_media/' . basename($lesson['media_file']));
            $stmt = $conn->prepare("DELETE FROM islamiyya_questions WHERE lesson_id=?");
            $stmt->bind_param("i", $lesson_id);
            $stmt->execute();
            if (db_table_exists($conn, 'islamiyya_lesson_progress')) {
                $stmt = $conn->prepare("DELETE FROM islamiyya_lesson_progress WHERE book_id=? AND lesson_no=?");
                $lno = (int)$lesson['lesson_no'];
                $stmt->bind_param("ii", $book_id, $lno);
                $stmt->execute();
            }
            $stmt = $conn->prepare("DELETE FROM islamiyya_lessons WHERE id=?");
            $stmt->bind_param("i", $lesson_id);
            $stmt->execute();
            $msg = 'Lesson #' . (int)$lesson['lesson_no'] . ' and its quiz deleted.';
        }
    }

    /* ---------- SAVE QUIZ QUESTION (add / edit) ---------- */
    if ($act === 'save_question') {
        $question_id = (int)($_POST['question_id'] ?? 0);
        $lesson_id   = (int)($_POST['lesson_id'] ?? 0);
        $lesson = islamiyya_lesson($conn, $lesson_id);
        if (!$lesson || (int)$lesson['book_id'] !== $book_id) {
            $err = 'Lesson not found.';
        } else {
            $qtext = trim($_POST['question_text'] ?? '');
            $oa = trim($_POST['option_a'] ?? '');
            $ob = trim($_POST['option_b'] ?? '');
            $oc = trim($_POST['option_c'] ?? '');
            $od = trim($_POST['option_d'] ?? '');
            $correct = strtolower(trim($_POST['correct_option'] ?? ''));
            $sort = (int)($_POST['sort_order'] ?? 0);
            if ($qtext === '' || $oa === '' || $ob === '' || $oc === '' || $od === '') {
                $err = 'Question text and all four options (A–D) are required.';
            } elseif (!in_array($correct, ['a', 'b', 'c', 'd'], true)) {
                $err = 'Pick the correct option (A, B, C or D).';
            } else {
                if ($question_id > 0) {
                    $stmt = $conn->prepare("UPDATE islamiyya_questions SET question_text=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_option=?, sort_order=? WHERE id=? AND lesson_id=?");
                    $stmt->bind_param("ssssssiii", $qtext, $oa, $ob, $oc, $od, $correct, $sort, $question_id, $lesson_id);
                    $ok = $stmt->execute();
                } else {
                    $stmt = $conn->prepare("INSERT INTO islamiyya_questions (lesson_id, question_text, option_a, option_b, option_c, option_d, correct_option, sort_order) VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->bind_param("issssssi", $lesson_id, $qtext, $oa, $ob, $oc, $od, $correct, $sort);
                    $ok = $stmt->execute();
                }
                $msg = $ok ? 'Quiz question saved.' : 'Could not save the quiz question.';
                if (!$ok) $err = $msg;
            }
        }
    }

    /* ---------- DELETE QUIZ QUESTION ---------- */
    if ($act === 'delete_question') {
        $question_id = (int)($_POST['question_id'] ?? 0);
        $lesson_id   = (int)($_POST['lesson_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM islamiyya_questions WHERE id=? AND lesson_id=?");
        $stmt->bind_param("ii", $question_id, $lesson_id);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $msg = 'Quiz question deleted.';
        } else {
            $err = 'Question not found.';
        }
    }

    $e = $err !== '' ? '&e=' . rawurlencode($err) : '';
    $m = $msg !== '' ? '&m=' . rawurlencode($msg) : '';
    header('Location: islamiyya_lessons.php?book=' . $book_id . $m . $e);
    exit;
}

/* ============ VIEW STATE ============ */
$msg = trim($_GET['m'] ?? '');
$err = trim($_GET['e'] ?? '');
$edit_lesson_id = (int)($_GET['edit_lesson'] ?? 0);
$edit_question_id = (int)($_GET['edit_question'] ?? 0);
$focus_lesson = (int)($_GET['focus'] ?? 0);

$lessons = islamiyya_book_lessons($conn, $book_id);
$readiness = islamiyya_book_readiness($conn, $book);
$expected = (int)$readiness['expected'];
$tbd = $expected <= 0;
$next_no = 1;
foreach ($lessons as $l) $next_no = max($next_no, (int)$l['lesson_no'] + 1);

$editing_lesson = $edit_lesson_id > 0 ? islamiyya_lesson($conn, $edit_lesson_id) : null;
if ($editing_lesson && (int)$editing_lesson['book_id'] !== $book_id) $editing_lesson = null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Lessons — <?= htmlspecialchars($book['title'], ENT_QUOTES) ?></title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'islamiyya', 'Manage Lessons', $book['title']); ?>

<div class="page-hero animate-rise">
    <h1>Manage Lessons — <?= htmlspecialchars($book['title'], ENT_QUOTES) ?></h1>
    <p>
        Upload each lesson's media file, then add at least one quiz question per lesson.
        <?php if ($tbd): ?>
            Expected count is <strong>TBD</strong> — set it in the Book Manager once uploads clarify the scope.
        <?php else: ?>
            Progress: <strong><?= (int)$readiness['uploaded'] ?>/<?= $expected ?></strong> lessons uploaded.
        <?php endif; ?>
    </p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;">
        <a class="btn btn-sm btn-ghost" href="islamiyya.php?book=<?= $book_id ?>"><?= ui_icon('arrow-left', 14) ?> Back to Books</a>
        <a class="btn btn-sm btn-gold" href="islamiyya_lessons.php?book=<?= $book_id ?>&edit_lesson=0"><?= ui_icon('plus', 14) ?> New Lesson<?= empty($lessons) ? '' : ' (#' . $next_no . ')' ?></a>
    </div>
</div>

<?php if ($msg !== ''): ?>
    <div class="alert alert-success animate-rise"><?= ui_icon('check', 16) ?> <span style="flex:1;"><?= htmlspecialchars($msg, ENT_QUOTES) ?></span></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <div class="alert alert-danger animate-rise"><?= ui_icon('alert', 16) ?> <span style="flex:1;"><?= htmlspecialchars($err, ENT_QUOTES) ?></span></div>
<?php endif; ?>

<!-- ============ ADD / EDIT LESSON FORM ============ -->
<?php if ($edit_lesson_id >= 0 && ($edit_lesson_id === 0 || $editing_lesson)): ?>
<?php
    $f_no    = $editing_lesson ? (int)$editing_lesson['lesson_no'] : $next_no;
    $f_title = $editing_lesson ? $editing_lesson['title'] : '';
    $f_media = $editing_lesson ? $editing_lesson['media_type'] : ($book['media_type'] ?? 'audio');
?>
<div class="card animate-rise" style="margin-bottom:18px;" id="lesson-form">
    <div class="card-title"><h3><?= $editing_lesson ? 'Edit Lesson #' . (int)$editing_lesson['lesson_no'] : 'Upload New Lesson #' . $next_no ?></h3></div>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save_lesson">
        <input type="hidden" name="book_id" value="<?= $book_id ?>">
        <input type="hidden" name="lesson_id" value="<?= (int)($editing_lesson['id'] ?? 0) ?>">
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Lesson number <span class="text-danger">*</span></label>
                <input class="form-input" type="number" name="lesson_no" min="1" value="<?= $f_no ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Media type</label>
                <select class="form-select" name="lesson_media_type">
                    <option value="audio" <?= $f_media === 'audio' ? 'selected' : '' ?>>Audio</option>
                    <option value="video" <?= $f_media === 'video' ? 'selected' : '' ?>>Video</option>
                </select>
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Lesson title <span class="text-danger">*</span></label>
                <input class="form-input" type="text" name="lesson_title" value="<?= htmlspecialchars($f_title, ENT_QUOTES) ?>" required placeholder="e.g. Lesson 1 — Introduction">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Media file <?= $editing_lesson ? '<span class="text-muted">(leave empty to keep current file)</span>' : '<span class="text-danger">*</span>' ?></label>
                <input class="form-input" type="file" name="media" accept="audio/*,video/*,.3gp,.amr,.opus,.oga,.aac,.flac,.wma,.m4b" <?= $editing_lesson ? '' : 'required' ?>>
                <p class="small text-muted" style="margin:4px 0 0;">Audio: MP3, M4A, OGG, OPUS, AAC, WAV, FLAC, 3GP, AMR, WMA or WebM audio. Video: MP4, WebM or MOV.<br>For small-but-clear voice lessons use <strong>mono MP3 64kbps, 44100 Hz</strong> (~0.5 MB/min). Example: <code>ffmpeg -i input.3gp -vn -ac 1 -ar 44100 -b:a 64k lesson.mp3</code><br><span class="text-muted">Note: 3GP/AMR/WMA upload fine but some browsers cannot play them — MP3 plays everywhere.</span></p>
                <?php if ($editing_lesson && !empty($editing_lesson['media_file'])): ?>
                    <p class="small text-muted" style="margin:4px 0 0;">Current file: <code><?= htmlspecialchars($editing_lesson['media_file'], ENT_QUOTES) ?></code></p>
                <?php endif; ?>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
            <button class="btn btn-gold" type="submit"><?= ui_icon('check', 16) ?> <?= $editing_lesson ? 'Save Changes' : 'Upload Lesson' ?></button>
            <?php if ($editing_lesson): ?><a class="btn btn-ghost" href="islamiyya_lessons.php?book=<?= $book_id ?>"><?= ui_icon('close', 16) ?> Cancel</a><?php endif; ?>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ============ LESSON LIST ============ -->
<?php if (empty($lessons)): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('video', 40) ?></div>
        <h3>No lessons yet</h3>
        <p>Upload Lesson #1 above to start building this book.</p>
    </div>
<?php endif; ?>

<?php foreach ($lessons as $lesson): ?>
<?php
    $lid = (int)$lesson['id'];
    $lno = (int)$lesson['lesson_no'];
    $questions = islamiyya_lesson_questions($conn, $lid);
    $editing_q = ($edit_question_id > 0 && ($focus_lesson === $lid || $focus_lesson === 0));
    $q_row = null;
    if ($editing_q) {
        foreach ($questions as $qq) if ((int)$qq['id'] === $edit_question_id) { $q_row = $qq; break; }
        if (!$q_row) $editing_q = false;
    }
    $show_q_form = (isset($_GET['add_question']) && (int)$_GET['add_question'] === $lid) || $editing_q;
?>
<div class="card animate-rise" style="margin-bottom:14px;" id="lesson-<?= $lno ?>">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <span class="badge <?= empty($questions) ? 'badge-gold' : 'badge-green' ?>">Lesson <?= $lno ?></span>
        <h3 style="margin:0;flex:1;min-width:180px;"><?= htmlspecialchars($lesson['title'], ENT_QUOTES) ?></h3>
        <span class="small text-muted"><?= $lesson['media_type'] === 'video' ? 'Video' : 'Audio' ?> · <?= count($questions) ?> quiz question(s)</span>
    </div>

    <div style="margin:10px 0;">
        <?php if ($lesson['media_type'] === 'video'): ?>
            <video controls preload="none" style="width:100%;max-width:520px;border-radius:8px;" src="../uploads/islamiyya_media/<?= htmlspecialchars($lesson['media_file'], ENT_QUOTES) ?>"></video>
        <?php else: ?>
            <audio controls preload="none" style="width:100%;max-width:520px;" src="../uploads/islamiyya_media/<?= htmlspecialchars($lesson['media_file'], ENT_QUOTES) ?>"></audio>
        <?php endif; ?>
    </div>

    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px;">
        <a class="btn btn-sm btn-ghost" href="islamiyya_lessons.php?book=<?= $book_id ?>&edit_lesson=<?= $lid ?>#lesson-form"><?= ui_icon('notes', 14) ?> Edit Lesson</a>
        <a class="btn btn-sm btn-gold" href="islamiyya_lessons.php?book=<?= $book_id ?>&add_question=<?= $lid ?>#qform-<?= $lid ?>"><?= ui_icon('plus', 14) ?> Add Quiz Question</a>
        <form method="POST" onsubmit="return confirm('Delete Lesson #<?= $lno ?>, its media file and ALL its quiz questions? This cannot be undone.');">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="delete_lesson">
            <input type="hidden" name="book_id" value="<?= $book_id ?>">
            <input type="hidden" name="lesson_id" value="<?= $lid ?>">
            <button class="btn btn-sm btn-danger" type="submit"><?= ui_icon('trash', 14) ?> Delete Lesson</button>
        </form>
    </div>

    <?php if (empty($questions)): ?>
        <div class="alert alert-warning" style="margin:8px 0 0;"><?= ui_icon('alert', 15) ?> <span style="flex:1;">No quiz question yet — the book cannot go live until every lesson has at least one.</span></div>
    <?php else: ?>
        <div class="table-wrap" style="margin-top:8px;">
            <table class="table">
                <thead><tr><th style="width:36px;">#</th><th>Question</th><th>Correct</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($questions as $i => $qq): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <strong><?= htmlspecialchars($qq['question_text'], ENT_QUOTES) ?></strong>
                            <div class="small text-muted">A. <?= htmlspecialchars($qq['option_a'], ENT_QUOTES) ?> · B. <?= htmlspecialchars($qq['option_b'], ENT_QUOTES) ?> · C. <?= htmlspecialchars($qq['option_c'], ENT_QUOTES) ?> · D. <?= htmlspecialchars($qq['option_d'], ENT_QUOTES) ?></div>
                        </td>
                        <td><span class="badge badge-green"><?= strtoupper(htmlspecialchars($qq['correct_option'], ENT_QUOTES)) ?></span></td>
                        <td style="white-space:nowrap;">
                            <a class="btn btn-sm btn-ghost" href="islamiyya_lessons.php?book=<?= $book_id ?>&focus=<?= $lid ?>&edit_question=<?= (int)$qq['id'] ?>#qform-<?= $lid ?>"><?= ui_icon('notes', 13) ?> Edit</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this quiz question?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="act" value="delete_question">
                                <input type="hidden" name="book_id" value="<?= $book_id ?>">
                                <input type="hidden" name="lesson_id" value="<?= $lid ?>">
                                <input type="hidden" name="question_id" value="<?= (int)$qq['id'] ?>">
                                <button class="btn btn-sm btn-danger" type="submit"><?= ui_icon('trash', 13) ?> Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if ($show_q_form): ?>
    <?php
        $q_text = $q_row['question_text'] ?? '';
        $q_a = $q_row['option_a'] ?? '';
        $q_b = $q_row['option_b'] ?? '';
        $q_c = $q_row['option_c'] ?? '';
        $q_d = $q_row['option_d'] ?? '';
        $q_ok = $q_row['correct_option'] ?? 'a';
    ?>
    <form method="POST" id="qform-<?= $lid ?>" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--border);">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save_question">
        <input type="hidden" name="book_id" value="<?= $book_id ?>">
        <input type="hidden" name="lesson_id" value="<?= $lid ?>">
        <input type="hidden" name="question_id" value="<?= (int)($q_row['id'] ?? 0) ?>">
        <h4 style="margin:0 0 8px;"><?= $q_row ? 'Edit Quiz Question' : 'New Quiz Question — Lesson #' . $lno ?></h4>
        <div class="form-group" style="margin-bottom:8px;">
            <label class="form-label">Question <span class="text-danger">*</span></label>
            <textarea class="form-textarea" name="question_text" rows="2" required><?= htmlspecialchars($q_text, ENT_QUOTES) ?></textarea>
        </div>
        <div class="form-grid">
            <div class="form-group"><label class="form-label">Option A <span class="text-danger">*</span></label><input class="form-input" type="text" name="option_a" value="<?= htmlspecialchars($q_a, ENT_QUOTES) ?>" required></div>
            <div class="form-group"><label class="form-label">Option B <span class="text-danger">*</span></label><input class="form-input" type="text" name="option_b" value="<?= htmlspecialchars($q_b, ENT_QUOTES) ?>" required></div>
            <div class="form-group"><label class="form-label">Option C <span class="text-danger">*</span></label><input class="form-input" type="text" name="option_c" value="<?= htmlspecialchars($q_c, ENT_QUOTES) ?>" required></div>
            <div class="form-group"><label class="form-label">Option D <span class="text-danger">*</span></label><input class="form-input" type="text" name="option_d" value="<?= htmlspecialchars($q_d, ENT_QUOTES) ?>" required></div>
            <div class="form-group"><label class="form-label">Correct option <span class="text-danger">*</span></label>
                <select class="form-select" name="correct_option">
                    <?php foreach (['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'] as $v => $lbl): ?>
                        <option value="<?= $v ?>" <?= $q_ok === $v ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:8px;">
            <button class="btn btn-sm btn-gold" type="submit"><?= ui_icon('check', 14) ?> Save Question</button>
            <a class="btn btn-sm btn-ghost" href="islamiyya_lessons.php?book=<?= $book_id ?>#lesson-<?= $lno ?>">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<?php ui_page_end(); ?>
</body>
</html>
