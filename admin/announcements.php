<?php
require '../config/security/helpers.php';
require_role('admin');
require '../auth/auth_check.php';
require '../config/db.php';

// Handle new announcement submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['title'], $_POST['message'])) {
    csrf_verify();
    $title = trim($_POST['title']);
    $message = trim($_POST['message']);

    if ($title !== '' && $message !== '') {
        $stmt = $conn->prepare("INSERT INTO announcements (title, message, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("ss", $title, $message);
        $stmt->execute();
        header("Location: announcements.php");
        exit;
    }
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    csrf_verify();
    $id = (int)$_POST['delete'];
    $stmt = $conn->prepare("DELETE FROM announcements WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    header("Location: announcements.php");
    exit;
}

// Fetch announcements
$announcements = $conn->query("SELECT id, title, message, created_at FROM announcements ORDER BY created_at DESC");
?>
<!DOCTYPE html>
<html>
<head>
<title>Admin Announcements</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'announcements', 'Announcements', 'Manage'); ?>

<div class="page-hero animate-rise">
    <h1>Manage Announcements</h1>
    <p>Share updates that appear on every student dashboard.</p>
</div>

<!-- New Announcement Form -->
<div class="card animate-rise" style="max-width:720px;margin-bottom:26px;">
    <h3 style="margin-top:0;">Create New Announcement</h3>
    <form method="post" accept-charset="UTF-8">
        <?= csrf_field() ?>
        <div class="form-group">
            <label class="form-label" for="title">Title</label>
            <input class="form-input" type="text" id="title" name="title" required maxlength="255" placeholder="Enter announcement title">
        </div>

        <div class="form-group">
            <label class="form-label" for="message">Message</label>
            <textarea class="form-textarea" id="message" name="message" rows="6" required placeholder="Enter your message here"></textarea>
        </div>

        <button type="submit" class="btn btn-gold"><?= ui_icon('send', 16) ?> Send Announcement</button>
    </form>
</div>

<?php if ($announcements->num_rows === 0): ?>
    <div class="empty animate-rise">
        <div class="empty-icon"><?= ui_icon('bell', 40) ?></div>
        <div class="empty-title">No announcements found</div>
        <p class="small" style="margin:0;">Create your first announcement above.</p>
    </div>
<?php else: ?>
    <?php while ($row = $announcements->fetch_assoc()): ?>
        <div class="card animate-rise">
            <div class="card-title" style="display:flex;flex-wrap:wrap;align-items:baseline;justify-content:space-between;gap:8px;">
                <h3 style="margin:0;"><?= htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                <span class="small text-muted">Sent <?= date('d M Y H:i', strtotime($row['created_at'])) ?></span>
            </div>
            <p style="margin:0 0 14px;"><?= nl2br(htmlspecialchars($row['message'], ENT_QUOTES, 'UTF-8')) ?></p>
            <form method="post" action="announcements.php" style="display:inline;" onsubmit="return confirm('Delete this announcement?');">
                <input type="hidden" name="delete" value="<?= $row['id'] ?>">
                <?= csrf_field() ?>
                <button class="btn btn-sm" style="background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border);"><?= ui_icon('trash', 15) ?> Delete</button>
            </form>
        </div>
    <?php endwhile; ?>
<?php endif; ?>

<?php ui_page_end(); ?>

</body>
</html>