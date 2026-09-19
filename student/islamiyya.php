<?php
/* ================================================================
 * DIGITAL ISLAMIYYA — STUDENT CATALOG (Stage 1)
 * ----------------------------------------------------------------
 * A curated digital catalog of foundational Islamic-studies books
 * ("Digital Islamiyya" — the older siblings' curriculum, now fully
 * online). Stage 1 ships the *sealed* storefront:
 *
 *   • A book only ever shows as ready once BOTH hold:
 *       (a) its admin record is status='live', AND
 *       (b) every lesson in it has uploads AND its full question
 *           pack (quiz) is present — islamiyya_book_readiness().
 *   • Until then the catalog shows a sealed "Coming Soon" card —
 *     nothing is exposed, nothing can be opened, nothing leaks.
 *   • A student may subscribe (users.islamiyya_subscribed) to be
 *     notified the moment any book goes live.
 *
 * THE SEAL: no book is forced open here. The catalog only ever
 * surfaces what the admin explicitly published. When a book IS
 * live the card links to the sealed learn page
 * (student/islamiyya_learn.php) which enforces its own
 * unlock-only-when-ready gate.
 * ================================================================ */
require __DIR__ . '/../config/security/helpers.php';
require __DIR__ . '/../auth/auth_check.php';
require __DIR__ . '/../config/db.php';

require_role('student');

$student_id = (int)$_SESSION['user_id'];

/* ------------------- subscribe toggle (own POST) ------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $want     = (int)($_POST['subscribe'] ?? -1);          /* -1 = no-op, 0/1 explicit */
    $current  = islamiyya_subscribed($conn, $student_id)    ? 1 : 0 + 0;
    $new      = $want >= 0 ? $want : $current;
    if ($new !== $current) {
        $stmt = $conn->prepare("UPDATE users SET islamiyya_subscribed = ? WHERE id = ?");
        $stmt->bind_param('ii', $new, $student_id);
        $stmt->execute();
    }

    $filter = isset($_POST['filter']) && in_array($_POST['filter'], ['all', 'live', 'coming'], true)
        ? (string)$_POST['filter']
        : 'all';
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
    $open  = (int)($b['status'] ?? 0) === 1 && !empty($ready['ready']);

    if ($open) { $live_count++; } else { $coming_count++; }

    if ($filter === 'live'   && !$open) continue;
    if ($filter === 'coming' &&  $open) continue;

    $cards[] = [
        'book' => $b,
        'open' => $open,
        'have' => (int)($ready['uploaded']  ?? 0),
        'want' => (int)($ready['expected']  ?? 0),
        'miss' => (int)($ready['missing_questions'] ?? 0),
    ];
}

/* ------------------------------- UI -------------------------------- */
ui_page_start('student', 'islamiyya', 'Digital Islamiyya',
    'A sealed, coming-soon catalog — books open one at a time, only once fully ready');
?>

<div class="page-card">
    <div class="page-head">
        <div>
            <h2>Digital Islamiyya <span class="badge badge-soft">Stage 1</span></h2>
            <p class="muted small">Curated foundational books — each with audio/video lessons and a quiz check. A
                book stays sealed until the admin finishes uploading every lesson. Nothing opens before then.</p>
        </div>

        <form method="post" class="inline">
            <?php csrf_field(); ?>
            <input type="hidden" name="subscribe" value="<?= $is_subscribed === 1 ? '0' : '1' ?>">
            <button type="submit"
                class="btn <?= $is_subscribed === 1 ? 'btn-ghost' : 'btn-gold' ?>">
                <i data-lucide="<?= $is_subscribed === 1 ? 'bell-off' : 'bell' ?>"></i>
                <?= $is_subscribed === 1
                    ? 'Unsubscribe from releases'
                    : 'Notify me when books go live' ?>
            </button>
        </form>
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
<?php if ($cards) foreach ($cards as $card):
    $b      = $card['book'];
    $open   = $card['open'];
    $have   = $card['have'];
    $want   = $card['want'];
    $miss   = $card['miss'];
    $title  = trim((string)($b['title_en'] ?? $b['title'] ?? $b['title_ar'] ?? 'Untitled Book'));
    $author = trim((string)($b['author'] ?? ''));
    $desc   = trim((string)($b['description'] ?? ''));
    $media  = trim((string)($b['media_type'] ?? 'audio'));
    $slug   = strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title));
    $cover  = "assets/images/" . $slug . '.png';
    $pct    = $want > 0 ? (int)round(($have / $want) * 100) : 0;
    ?>
    <article class="islam-card <?= $open ? 'is-live' : 'is-coming' ?>">
        <div class="islam-cover">
            <span class="islam-flag <?= $open ? 'flag-live' : 'flag-coming' ?>">
                <i data-lucide="<?= $open ? 'play' : 'lock' ?>"></i>
                <?= $open ? 'Available' : 'Coming Soon' ?>
            </span>
            <div class="cover-art">
                <?php if (is_file($_SERVER['DOCUMENT_ROOT'] . '/' . $cover)): ?>
                    <img src="/<?= htmlspecialchars($cover, ENT_QUOTES) ?>" alt="<?= htmlspecialchars($title, ENT_QUOTES) ?>" loading="lazy">
                <?php else: ?>
                    <div class="cover-placeholder">
                        <i data-lucide="<?= $media === 'video' ? 'video' : 'mic' ?>"></i>
                    </div>
                <?php endif; ?>
            </div>
            <strong class="cover-title"><?= htmlspecialchars($title, ENT_QUOTES) ?></strong>
            <?php if ($author !== ''): ?><span class="cover-author muted small"><?= htmlspecialchars($author, ENT_QUOTES) ?></span><?php endif; ?>
        </div>
        <div class="islam-card-body">
            <?php if ($desc !== ''): ?><p class="small muted"><i data-lucide="file-text" class="i-16"></i> <?= htmlspecialchars($desc, ENT_QUOTES) ?></p><?php endif; ?>

            <?php if ($open): ?>
                <div class="progress-row">
                    <div class="progress"><div class="progress-bar" style="width:<?= $pct ?>%"></div></div>
                    <span class="small muted"><?= $have ?> / <?= $want ?> lessons</span>
                </div>
                <a class="btn btn-gold full"
                    href="islamiyya_learn.php?book=<?= (int)$b['id'] ?>">
                    <i data-lucide="arrow-right"></i> Start learning
                </a>
            <?php else: ?>
                <p class="small muted"><i data-lucide="lock" class="i-16"></i>
                    Sealed — the admin is still preparing this book. Check back for updates.</p>
                <a class="btn btn-ghost full disabled" aria-disabled="true">
                    <i data-lucide="hourglass"></i> Coming Soon
                </a>
            <?php endif; ?>
        </div>
    </article>
<?php endforeach;
else:
    echo '<div class="page-card"><p class="muted center pad">No books in this view yet.</p></div>';
endif; ?>
</div>

<?php ui_page_end(); ?>
