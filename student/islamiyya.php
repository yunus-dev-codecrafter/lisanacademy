<?php
/* ================================================================
 * DIGITAL ISLAMIYYA — STUDENT CATALOG
 * ----------------------------------------------------------------
 * A beautiful storefront for the classical-books program ("Digital
 * Islamiyya" — Tauhid, Fiqh, Hadith, Seerah, Arabic, now fully
 * online). Each book card shows its real uploaded cover, its live /
 * coming-soon state, and a per-book "Save my slot" button backed by
 * the islamiyya_interests table (db_migrate15) so students register
 * interest while a book is still being prepared.
 *
 * THE SEAL: a book only ever opens once BOTH hold:
 *   (a) its admin record is status='live', AND
 *   (b) every expected lesson is uploaded AND every lesson has at
 *       least one quiz question — islamiyya_book_readiness().
 * Until then the card stays sealed "Coming Soon" — nothing leaks,
 * but the student CAN save a slot. Live cards link to the learn
 * page (student/islamiyya_learn.php) which enforces its own
 * unlock-only-when-ready gate.
 * ================================================================ */
require __DIR__ . '/../config/security/helpers.php';
require __DIR__ . '/../auth/auth_check.php';
require __DIR__ . '/../config/db.php';

require_role('student');

$student_id = (int)$_SESSION['user_id'];

/* ------------------- POST: subscribe + save-slot ------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $filter = isset($_POST['filter']) && in_array($_POST['filter'], ['all', 'live', 'coming'], true)
        ? (string)$_POST['filter']
        : 'all';

    /* per-book slot saving (notify-me interest) */
    $slot_book = (int)($_POST['slot_book'] ?? 0);
    $slot_act  = $_POST['slot_act'] ?? '';
    if ($slot_book > 0 && ($slot_act === 'save' || $slot_act === 'unsave')) {
        if ($slot_act === 'save') {
            islamiyya_save_interest($conn, $student_id, $slot_book);
        } else {
            islamiyya_remove_interest($conn, $student_id, $slot_book);
        }
        header('Location: islamiyya.php?filter=' . $filter . '#book-' . $slot_book);
        exit;
    }

    /* global release notifications */
    $want     = (int)($_POST['subscribe'] ?? -1);          /* -1 = no-op, 0/1 explicit */
    $current  = islamiyya_subscribed($conn, $student_id) ? 1 : 0;
    $new      = $want >= 0 ? $want : $current;
    if ($new !== $current) {
        $stmt = $conn->prepare("UPDATE users SET islamiyya_subscribed = ? WHERE id = ?");
        $stmt->bind_param('ii', $new, $student_id);
        $stmt->execute();
    }

    header('Location: islamiyya.php?filter=' . $filter);
    exit;
}

/* --------------------------- page state --------------------------- */
$filter = isset($_GET['filter']) && in_array($_GET['filter'], ['all', 'live', 'coming'], true)
    ? (string)$_GET['filter']
    : 'all';

$is_subscribed = islamiyya_subscribed($conn, $student_id) ? 1 : 0;

/* books + readiness (readiness = book + lessons + full question pack present) */
$books       = islamiyya_books($conn) ?? [];
$live_count  = 0;
$coming_count = 0;
$cards       = [];

foreach ($books as $b) {
    $ready = islamiyya_book_readiness($conn, $b) ?? [];
    $open  = islamiyya_book_is_live($conn, $b);

    if ($open) { $live_count++; } else { $coming_count++; }

    if ($filter === 'live'   && !$open) continue;
    if ($filter === 'coming' &&  $open) continue;

    $bid = (int)$b['id'];
    $cards[] = [
        'book' => $b,
        'open' => $open,
        'have' => (int)($ready['uploaded']  ?? 0),
        'want' => (int)($ready['expected']  ?? 0),
        'miss' => is_array($ready['missing_questions'] ?? null) ? count($ready['missing_questions']) : (int)($ready['missing_questions'] ?? 0),
        'saved' => islamiyya_has_interest($conn, $student_id, $bid),
        'slot_count' => islamiyya_interest_count($conn, $bid),
    ];
}

/* ------------------------------- UI -------------------------------- */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Digital Islamiyya — Student Catalog</title>
<?= ui_css() ?>
</head>
<?php
ui_page_start('student', 'islamiyya', 'Digital Islamiyya',
    'Classical books with audio/video lessons and quizzes — save your slot');
?>

<!-- ============ HERO: what Digital Islamiyya is ============ -->
<div class="hero-banner animate-rise" style="overflow:hidden;">
    <span class="hero-banner-ico"><?= ui_icon('book-open', 26) ?></span>
    <div style="flex:1;min-width:240px;">
        <span class="badge badge-gold" style="margin-bottom:6px;"><?= ui_icon('star', 12) ?> New Program</span>
        <h1 style="margin:4px 0 6px;">Digital Islamiyya</h1>
        <p style="margin:0;">Learn the classical Islamic texts with your own teacher — audio and video lessons, graded quizzes, and PDF handouts. Your path through <strong>Tauhid, Fiqh, Hadith, Seerah and Arabic</strong>, unlocking lesson by lesson.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
            <span class="badge badge-soft">Tauhid</span>
            <span class="badge badge-soft">Fiqh</span>
            <span class="badge badge-soft">Hadith</span>
            <span class="badge badge-soft">Seerah</span>
            <span class="badge badge-soft">Arabic</span>
        </div>
    </div>
    <div style="flex:0 0 auto;">
        <?php if (is_file(__DIR__ . '/../assets/images/digital_islamiyya.png')): ?>
            <img src="../assets/images/digital_islamiyya.png" alt="Digital Islamiyya — classical Islamic books program" loading="lazy" style="width:180px;max-width:40vw;border-radius:12px;box-shadow:0 10px 24px rgba(0,0,0,.25);">
        <?php endif; ?>
    </div>
</div>

<!-- ============ HOW IT WORKS + NOTIFY ============ -->
<div class="card animate-rise d1" style="margin-bottom:16px;">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:center;">
        <div style="flex:1;min-width:240px;display:flex;gap:14px;flex-wrap:wrap;">
            <div class="small"><strong><?= ui_icon('video', 14) ?> 1. Study</strong><br><span class="text-muted">Listen or watch each lesson.</span></div>
            <div class="small"><strong><?= ui_icon('check-circle', 14) ?> 2. Pass the quiz</strong><br><span class="text-muted">Score 90%+ to unlock the next lesson.</span></div>
            <div class="small"><strong><?= ui_icon('file-text', 14) ?> 3. Keep the handout</strong><br><span class="text-muted">PDF unlocks after Lesson 1.</span></div>
        </div>
        <form method="post" style="margin:0;">
            <?php csrf_field(); ?>
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES) ?>">
            <input type="hidden" name="subscribe" value="<?= $is_subscribed === 1 ? '0' : '1' ?>">
            <button type="submit" class="btn <?= $is_subscribed === 1 ? 'btn-ghost' : 'btn-gold' ?>">
                <?= ui_icon($is_subscribed === 1 ? 'check' : 'bell', 16) ?>
                <?= $is_subscribed === 1 ? 'You\'ll be notified of releases' : 'Notify me when books go live' ?>
            </button>
        </form>
    </div>
</div>

<div class="page-card">
    <div class="page-head">
        <div>
            <h2>Book Catalog</h2>
            <p class="muted small">
                <?php if ($live_count > 0): ?>
                    <?= $live_count ?> book(s) available now — start learning today.
                <?php else: ?>
                    Books are still being prepared — <strong>save your slot</strong> on any book and we'll keep you first in line.
                <?php endif; ?>
                <?php if ($coming_count > 0): ?><?= $coming_count ?> coming soon.<?php endif; ?>
            </p>
        </div>
    </div>

    <div class="nav-tabs">
        <a class="nav-tab <?= $filter === 'all'    ? 'active' : '' ?>"
            href="islamiyya.php?filter=all">All (<?= count($books) ?>)</a>
        <a class="nav-tab <?= $filter === 'live'   ? 'active' : '' ?>"
            href="islamiyya.php?filter=live">Available (<?= $live_count ?>)</a>
        <a class="nav-tab <?= $filter === 'coming' ? 'active' : '' ?>"
            href="islamiyya.php?filter=coming">Coming Soon (<?= $coming_count ?>)</a>
    </div>
</div>

<div class="grid-3">
<?php if (!empty($cards)): ?>
<?php foreach ($cards as $card):
    $b      = $card['book'];
    $open   = $card['open'];
    $have   = $card['have'];
    $want   = $card['want'];
    $tbd    = $want <= 0;
    $saved  = $card['saved'];
    $slots  = $card['slot_count'];
    $title  = trim((string)($b['title'] ?? 'Untitled Book'));
    $title_en = trim((string)($b['title_en'] ?? ''));
    $author = trim((string)($b['author'] ?? ''));
    $desc   = trim((string)($b['description'] ?? ''));
    $media  = trim((string)($b['media_type'] ?? 'audio'));
    $cover_file = trim((string)($b['cover_image'] ?? ''));
    $cover_path = ($cover_file !== '' && is_file(__DIR__ . '/../uploads/islamiyya_covers/' . basename($cover_file)))
        ? '../uploads/islamiyya_covers/' . rawurlencode(basename($cover_file))
        : '';
    $pct    = (!$tbd && $want > 0) ? (int)round(($have / $want) * 100) : 0;
    ?>
    <article class="islam-card <?= $open ? 'is-live' : 'is-coming' ?>" id="book-<?= (int)$b['id'] ?>">
        <div class="islam-cover">
            <span class="islam-flag <?= $open ? 'flag-live' : 'flag-coming' ?>">
                <?= ui_icon($open ? 'check' : 'clock', 13) ?>
                <?= $open ? 'Available' : 'Coming Soon' ?>
            </span>
            <div class="cover-art">
                <?php if ($cover_path !== ''): ?>
                    <img src="<?= htmlspecialchars($cover_path, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES) ?>" loading="lazy">
                <?php else: ?>
                    <div class="cover-placeholder">
                        <?= ui_icon($media === 'video' ? 'video' : 'book-open', 34) ?>
                    </div>
                <?php endif; ?>
            </div>
            <strong class="cover-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></strong>
            <?php if ($title_en !== ''): ?><span class="cover-author muted small"><?= htmlspecialchars($title_en, ENT_QUOTES) ?></span><?php endif; ?>
            <?php if ($author !== ''): ?><span class="cover-author muted small"><?= ui_icon('user', 12) ?> <?= htmlspecialchars($author, ENT_QUOTES) ?></span><?php endif; ?>
        </div>
        <div class="islam-card-body">
            <?php if ($desc !== ''): ?><p class="small muted"><?= ui_icon('notes', 13) ?> <?= htmlspecialchars($desc, ENT_QUOTES) ?></p><?php endif; ?>

            <?php if ($open): ?>
                <div class="progress-row">
                    <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
                    <span class="small muted"><?= $have ?> / <?= $want ?> lessons</span>
                </div>
                <a class="btn btn-gold full"
                    href="islamiyya_learn.php?book=<?= (int)$b['id'] ?>">
                    <?= ui_icon('arrow-right', 15) ?> Start learning
                </a>
            <?php else: ?>
                <p class="small muted"><?= ui_icon('clock', 13) ?>
                    <?= $tbd ? 'Being prepared — lesson count (TBD) will be announced soon.' : 'Being prepared — ' . $have . ' of ' . $want . ' lessons ready so far.' ?>
                    <?php if ($slots > 0): ?> <strong><?= $slots ?></strong> student(s) already saved a slot.<?php endif; ?>
                </p>
                <form method="post" style="margin:0;">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter, ENT_QUOTES) ?>">
                    <input type="hidden" name="slot_book" value="<?= (int)$b['id'] ?>">
                    <input type="hidden" name="slot_act" value="<?= $saved ? 'unsave' : 'save' ?>">
                    <button type="submit" class="btn <?= $saved ? 'btn-ghost' : 'btn-gold' ?> full">
                        <?= ui_icon($saved ? 'check' : 'bell', 15) ?>
                        <?= $saved ? 'Slot saved — withdraw' : 'Save my slot' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach;
else: ?>
    <div class="page-card"><p class="muted center pad">
        <?= ui_icon('book-open', 22) ?><br>
        No books in this view yet — check another tab or come back soon.
    </p></div>
<?php endif; ?>
</div>

<?php ui_page_end(); ?>
</body>
</html>
