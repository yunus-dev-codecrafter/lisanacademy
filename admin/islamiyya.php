<?php
/**
 * Digital Islamiyya — Admin Book Manager
 *
 * Admin can add/edit books (title, author, description, media type, expected
 * lesson count), upload a cover image + single all-lessons PDF handout, then
 * publish/unpublish. A book only becomes LIVE when every expected lesson is
 * uploaded AND every lesson has at least one quiz question (readiness gate).
 * Until then students see a "Coming Soon" block.
 */
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('admin');

/* ============ ACTIONS (POST) ============ */
$msg  = '';
$err  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $act = $_POST['act'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'save') {
        $title        = trim($_POST['title'] ?? '');
        $title_en     = trim($_POST['title_en'] ?? '');
        $author       = trim($_POST['author'] ?? '');
        $description  = trim($_POST['description'] ?? '');
        $media_type   = ($_POST['media_type'] ?? 'audio') === 'video' ? 'video' : 'audio';
        $total_lessons= max(1, (int)($_POST['total_lessons'] ?? 1));
        $sort_order   = (int)($_POST['sort_order'] ?? 0);
        $status       = ($_POST['status'] ?? '') === 'live' ? 'live' : 'coming_soon';

        if ($title === '') {
            $err = 'Book title is required.';
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE islamiyya_books SET title=?, title_en=?, author=?, description=?, media_type=?, total_lessons=?, sort_order=?, status=? WHERE id=?");
                $stmt->bind_param("sssssiisi", $title, $title_en, $author, $description, $media_type, $total_lessons, $sort_order, $status, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO islamiyya_books (title, title_en, author, description, media_type, total_lessons, sort_order, status) VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param("sssssiis", $title, $title_en, $author, $description, $media_type, $total_lessons, $sort_order, $status);
            }
            if ($stmt->execute()) {
                $book_id = $id > 0 ? $id : (int)$conn->insert_id;
                $msg = $id > 0 ? 'Book updated.' : 'Book added. Now upload its lessons below.';
                $redirect_id = $book_id;
            } else {
                $err = 'Could not save the book.';
            }
        }
    }

    if ($act === 'toggle') {
        if ($id > 0 && islamiyya_book($conn, $id)) {
            $book = islamiyya_book($conn, $id);
            $new  = $book['status'] === 'live' ? 'coming_soon' : 'live';
            $conn->prepare("UPDATE islamiyya_books SET status=? WHERE id=?")->execute([$new, $id]);
            $msg = $new === 'live' ? 'Book published (students can now see it).' : 'Book unpublished.';
        } else {
            $err = 'Book not found.';
        }
    }

    if ($act === 'delete') {
        if ($id > 0 && islamiyya_book($conn, $id)) {
            $stmt = $conn->prepare("SELECT media_file FROM islamiyya_lessons WHERE book_id=?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $medias = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            foreach ($medias as $m) {
                if (!empty($m['media_file'])) @unlink(__DIR__ . '/../uploads/islamiyya_media/' . basename($m['media_file']));
            }
            $conn->prepare("DELETE FROM islamiyya_questions WHERE lesson_id IN (SELECT id FROM islamiyya_lessons WHERE book_id=?)")->execute([$id]);
            $conn->prepare("DELETE FROM islamiyya_lessons WHERE book_id=?" )->execute([$id]);
            $conn->prepare("DELETE FROM islamiyya_lesson_progress WHERE book_id=?")->execute([$id]);
            $conn->prepare("DELETE FROM islamiyya_book_progress WHERE book_id=?")->execute([$id]);
            $b = islamiyya_book($conn, $id);
            if (!empty($b['cover_image'])) @unlink(__DIR__ . '/../uploads/islamiyya_covers/' . basename($b['cover_image']));
            if (!empty($b['pdf_file']))     @unlink(__DIR__ . '/../uploads/islamiyya_pdfs/'   . basename($b['pdf_file']));
            $conn->prepare("DELETE FROM islamiyya_books WHERE id=?")->execute([$id]);
            $msg = 'Book and all its content deleted.';
        } else {
            $err = 'Book not found.';
        }
    }

    if ($act === 'cover' || $act === 'pdf') {
        $field = $act === 'cover' ? 'cover' : 'pdf';
        if ($id > 0 && !empty($_FILES[$field]) && $_FILES[$field]['error'] === 0) {
            $dir  = $act === 'cover' ? 'islamiyya_covers' : 'islamiyya_pdfs';
            $dest = __DIR__ . '/../uploads/' . $dir;
            if (!is_dir($dest)) @mkdir($dest, 0775, true);
            $ext  = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            $ok   = false;
            if ($act === 'cover') {
                $ok = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
            } else {
                $ok = $ext === 'pdf';
            }
            if ($ok) {
                $fname = 'book' . $id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest . '/' . $fname)) {
                    $col  = $act === 'cover' ? 'cover_image' : 'pdf_file';
                    $stmt = $conn->prepare("UPDATE islamiyya_books SET $col=? WHERE id=?");
                    $stmt->bind_param("si", $fname, $id);
                    $stmt->execute();
                    $msg = 'File uploaded.';
                } else {
                    $err = 'Could not move the uploaded file.';
                }
            } else {
                $err = $act === 'cover' ? 'Cover must be an image.' : 'Handout must be a PDF.';
            }
        } else {
            $err = 'No file selected.';
        }
    }

    /* publish-policy: derive target from session + live-book detection (no headers yet) */
    $registered = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    $dashboard_url = ($_SESSION['role'] ?? '') === 'admin' ? '/admin/dashboard.php' : '/student/dashboard.php';
    $e = $err !== '' ? '&e=' . rawurlencode($err) : '';
    $m = $msg !== '' ? '&m='  . rawurlencode($msg)  : '';
    $redir = 'islamiyya.php'
        . (isset($redirect_id) && $redirect_id > 0 ? '?book=' . (int)$redirect_id : '')
        . (str_contains($e, 'e=') ? $e . $m : $m);
    header('Location: ' . $redir);
    exit;
}

/* ============ VIEW STATE ============ */
$filter = $_GET['filter'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$msg    = trim($_GET['m'] ?? '');
$err    = trim($_GET['e'] ?? '');
$focus  = (int)($_GET['book'] ?? 0);

$all_books  = islamiyya_books($conn);
$edit_id    = (int)($_GET['edit'] ?? 0);
$editing    = $edit_id > 0 ? islamiyya_book($conn, $edit_id) : null;
$lessons_meta = [];

$counts = ['all' => count($all_books), 'live' => 0, 'coming' => 0];
foreach ($all_books as $b) {
    if (islamiyya_book_is_live($conn, $b)) $counts['live']++; else $counts['coming']++;
    $readiness = islamiyya_book_readiness($conn, $b);
    $lessons_meta[(int)$b['id']] = $readiness;
}

$shown = [];
foreach ($all_books as $b) {
    $live = islamiyya_book_is_live($conn, $b);
    if ($filter === 'live'   && !$live) continue;
    if ($filter === 'coming' && $live)  continue;
    if ($search !== '') {
        $hay = $b['title'] . ' ' . ($b['title_en'] ?? '') . ' ' . ($b['author'] ?? '');
        if (stripos($hay, $search) === false) continue;
    }
    $shown[] = $b;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Digital Islamiyya — Book Manager</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'islamiyya', 'Digital Islamiyya', 'Manage books'); ?>

<div class="page-hero animate-rise">
    <h1>Digital Islamiyya — Book Manager</h1>
    <p>Add the classical books students study, upload their lessons and quizzes, then publish. A book goes LIVE only when every lesson is uploaded and every lesson has a quiz question.</p>
</div>

<?php if ($msg !== ''): ?>
    <div class="alert alert-success animate-rise"><?= ui_icon('check', 16) ?> <span style="flex:1;"><?= htmlspecialchars($msg, ENT_QUOTES) ?></span></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
    <div class="alert alert-danger animate-rise"><?= ui_icon('alert', 16) ?> <span style="flex:1;"><?= htmlspecialchars($err, ENT_QUOTES) ?></span></div>
<?php endif; ?>

<?php if ($focus > 0): ?>
    <div class="alert alert-info animate-rise">
        <?= ui_icon('bulb', 16) ?> <span style="flex:1;"><strong>Book saved.</strong> Continue building it: add its lessons under "Manage Lessons".</span>
        <a class="btn btn-sm btn-gold" href="islamiyya_lessons.php?book=<?= (int)$focus ?>"><?= ui_icon('arrow-right', 14) ?> Manage Lessons</a>
    </div>
<?php endif; ?>

<!-- ============ TOOLBAR: filters + search + add ============ -->
<div class="card animate-rise" style="margin-bottom:18px;">
    <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
        <a class="btn btn-sm <?= $filter === 'all' ? 'btn-gold' : 'btn-ghost' ?>" href="islamiyya.php">All (<?= $counts['all'] ?>)</a>
        <a class="btn btn-sm <?= $filter === 'live' ? 'btn-gold' : 'btn-ghost' ?>" href="islamiyya.php?filter=live">Live (<?= $counts['live'] ?>)</a>
        <a class="btn btn-sm <?= $filter === 'coming' ? 'btn-gold' : 'btn-ghost' ?>" href="islamiyya.php?filter=coming">Coming Soon (<?= $counts['coming'] ?>)</a>
        <div style="flex:1"></div>
        <form method="GET" action="islamiyya.php" style="display:flex;gap:8px;align-items:center;">
            <input class="form-input" type="search" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" placeholder="Search books…" style="width:190px;">
            <button class="btn btn-sm btn-ghost" type="submit"><?= ui_icon('search', 15) ?></button>
            <a class="btn btn-sm btn-gold" href="islamiyya.php?edit=0"><?= ui_icon('plus', 15) ?> New Book</a>
        </form>
    </div>
</div>

<?php if (empty($shown) && $edit_id === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('book-open', 40) ?></div>
        <h3>No books yet</h3>
        <p>Add your first Digital Islamiyya book to begin building the classical reading program.</p>
        <a class="btn btn-gold" href="islamiyya.php?edit=0"><?= ui_icon('plus', 16) ?> Add Your First Book</a>
    </div>
<?php endif; ?>

<!-- ============ ADD / EDIT FORM ============ -->
<?php if ($edit_id >= 0): ?>
<?php
    $e_title       = $editing['title'] ?? '';
    $e_title_en    = $editing['title_en'] ?? '';
    $e_author      = $editing['author'] ?? '';
    $e_description = $editing['description'] ?? '';
    $e_media       = $editing['media_type'] ?? 'audio';
    $e_total       = (int)($editing['total_lessons'] ?? 1);
    $e_sort        = (int)($editing['sort_order'] ?? 0);
    $e_status      = $editing['status'] ?? 'coming_soon';
?>
<div class="card animate-rise" style="margin-bottom:18px;">
    <div class="card-title"><h3><?= $editing ? 'Edit Book' : 'Add New Book' ?></h3><span class="small text-muted"><?= $editing ? 'Editing #' . (int)$editing['id'] : 'Fill in the book details' ?></span></div>
    <form method="POST" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="save">
        <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Title (Arabic) <span class="text-danger">*</span></label>
                <input class="form-input" type="text" name="title" value="<?= htmlspecialchars($e_title, ENT_QUOTES) ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">English/transliteration title</label>
                <input class="form-input" type="text" name="title_en" value="<?= htmlspecialchars($e_title_en, ENT_QUOTES) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Author/Subject</label>
                <input class="form-input" type="text" name="author" value="<?= htmlspecialchars($e_author, ENT_QUOTES) ?>">
            </div>
            <div class="form-group">
                <label class="form-label">Media type</label>
                <select class="form-select" name="media_type">
                    <option value="audio" <?= $e_media === 'audio' ? 'selected' : '' ?>>Audio</option>
                    <option value="video" <?= $e_media === 'video' ? 'selected' : '' ?>>Video</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Total lessons (expected)</label>
                <input class="form-input" type="number" name="total_lessons" min="1" value="<?= $e_total ?>" required>
            </div>
            <div class="form-group">
                <label class="form-label">Sort order</label>
                <input class="form-input" type="number" name="sort_order" min="0" value="<?= $e_sort ?>">
            </div>
            <div class="form-group" style="grid-column:1/-1;">
                <label class="form-label">Description</label>
                <textarea class="form-textarea" name="description" rows="3"><?= htmlspecialchars($e_description, ENT_QUOTES) ?></textarea>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
            <button class="btn btn-gold" type="submit"><?= ui_icon('check', 16) ?> <?= $editing ? 'Save Changes' : 'Create Book' ?></button>
            <?php if ($editing): ?><a class="btn btn-ghost" href="islamiyya.php"><?= ui_icon('close', 16) ?> Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php if ($editing): ?>
        <?php /* inline cover + pdf uploads for the book being edited */ ?>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;padding-top:14px;border-top:1px solid var(--border);">
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="cover">
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <input type="file" name="cover" accept="image/*" style="display:none" id="coverFile<?= (int)$editing['id'] ?>" onchange="this.form.submit()">
                <button class="btn btn-sm btn-ghost" type="button" onclick="document.getElementById('coverFile<?= (int)$editing['id'] ?>').click()"><?= ui_icon('image', 15) ?> Upload Cover</button>
            </form>
            <form method="POST" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="act" value="pdf">
                <input type="hidden" name="id" value="<?= (int)$editing['id'] ?>">
                <input type="file" name="pdf" accept="application/pdf" style="display:none" id="pdfFile<?= (int)$editing['id'] ?>" onchange="this.form.submit()">
                <button class="btn btn-sm btn-ghost" type="button" onclick="document.getElementById('pdfFile<?= (int)$editing['id'] ?>').click()"><?= ui_icon('file-text', 15) ?> Upload All-Lessons PDF</button>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ============ BOOK LIST ============ -->
<?php foreach ($shown as $book): ?>
<?php
    $book_id   = (int)$book['id'];
    $live      = islamiyya_book_is_live($conn, $book);
    $readiness = $lessons_meta[$book_id] ?? islamiyya_book_readiness($conn, $book);
    $uploaded  = (int)$readiness['uploaded'];
    $expected  = (int)$readiness['expected'];
    $missing_q = $readiness['missing_questions'] ?? [];
    $pct       = $expected > 0 ? min(100, (int)round($uploaded / $expected * 100)) : 0;
    $has_pdf   = !empty($book['pdf_file']);
?>
<div class="card animate-rise" style="margin-bottom:14px;">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;">
        <?php if (!empty($book['cover_image'])): ?>
            <img src="../uploads/islamiyya_covers/<?= htmlspecialchars($book['cover_image'], ENT_QUOTES) ?>" alt="" style="width:84px;height:110px;object-fit:cover;border-radius:8px;background:var(--emerald-50);">
        <?php else: ?>
            <div style="width:84px;height:110px;border-radius:8px;background:linear-gradient(135deg,var(--emerald-700),var(--emerald-500));display:flex;align-items:center;justify-content:center;color:#fff;"><?= ui_icon('book-open', 30) ?></div>
        <?php endif; ?>
        <div style="flex:1;min-width:230px;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;">
                <h3 style="margin:0;"><?= htmlspecialchars($book['title'], ENT_QUOTES) ?></h3>
                <?php if (!empty($book['title_en'])): ?><span class="small text-muted"><?= htmlspecialchars($book['title_en'], ENT_QUOTES) ?></span><?php endif; ?>
                <?php if ($live): ?><span class="badge badge-green"><?= ui_icon('check', 12) ?> Live</span>
                <?php else: ?><span class="badge badge-gold"><?= ui_icon('clock', 12) ?> Coming Soon</span><?php endif; ?>
            </div>
            <?php if (!empty($book['author'])): ?><p class="small text-muted" style="margin:3px 0 0;"><?= ui_icon('user', 13) ?> <?= htmlspecialchars($book['author'], ENT_QUOTES) ?></p><?php endif; ?>
            <?php if (!empty($book['description'])): ?><p class="small" style="margin:4px 0 0;"><?= htmlspecialchars($book['description'], ENT_QUOTES) ?></p><?php endif; ?>

            <div class="progress" style="margin:10px 0 8px;max-width:340px;">
                <div class="progress-fill" style="width:<?= $pct ?>%;"></div>
                <div class="progress-text"><?= $pct ?>% ready</div>
            </div>
            <p class="small text-muted" style="margin:0 0 10px;">
                <?= ui_icon('video', 13) ?> <?= $book['media_type'] === 'video' ? 'Video' : 'Audio' ?> ·
                <?= $uploaded ?>/<?= $expected ?> lessons uploaded ·
                <?php if ($uploaded < $expected): ?>
                    <?= ($expected - $uploaded) ?> lesson(s) still to add
                <?php elseif (!empty($missing_q)): ?>
                    quiz question missing for lesson<?= count($missing_q) > 1 ? 's' : '' ?> #<?= implode(', ', array_map('intval', $missing_q)) ?>
                <?php else: ?>
                    ready to publish
                <?php endif; ?>
                <?= $has_pdf ? ' · ' . ui_icon('file-text', 12) . ' PDF ready' : '' ?>
            </p>

            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                <a class="btn btn-sm btn-gold" href="islamiyya.php?edit=<?= $book_id ?>"><?= ui_icon('notes', 14) ?> Edit</a>
                <a class="btn btn-sm btn-ghost" href="islamiyya_lessons.php?book=<?= $book_id ?>"><?= ui_icon('video', 14) ?> Manage Lessons</a>
                <form method="POST" onsubmit="return confirm('<?= $live ? 'Unpublish this book? Students will see it as Coming Soon again.' : 'Publish this book? It will only go live to students after every lesson AND quiz question is uploaded.' ?>');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="toggle">
                    <input type="hidden" name="id" value="<?= $book_id ?>">
                    <button class="btn btn-sm <?= $live ? 'btn-danger-ghost' : 'btn-gold' ?>" type="submit"><?= ui_icon($live ? 'close' : 'send', 14) ?> <?= $live ? 'Unpublish' : 'Publish' ?></button>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this book and ALL its lessons, quizzes and progress? This cannot be undone.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="act" value="delete">
                    <input type="hidden" name="id" value="<?= $book_id ?>">
                    <button class="btn btn-sm btn-danger-ghost" type="submit"><?= ui_icon('trash', 14) ?> Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<?php if (empty($shown) && $edit_id === 0): ?>
<?php endif; ?>

<?php ui_page_end(); ?>
</body>
</html>
