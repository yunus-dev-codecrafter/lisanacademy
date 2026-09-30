<?php
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';

require_role('student');

$whatsapp = setting($conn, 'whatsapp_number', '2348029979040');
$name     = $_SESSION['name'] ?? '';

$fee_msg = "Assalamualaikum, my name is $name. I would like to pay my next term school fees. Please let me know the amount and payment details.";
$fee_url = 'https://wa.me/' . $whatsapp . '?text=' . rawurlencode($fee_msg);

/* Own term-fee status (read-only for students) */
$my_term = function_exists('term_fee_current') ? term_fee_current($conn) : null;
$my_status = 'unknown';
if ($my_term && function_exists('term_fee_student_status')) {
    $my_status = term_fee_student_status($conn, (int)$my_term['id'], (int)($_SESSION['user_id'] ?? 0));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pay Next Term Fees</title>
<?= ui_css() ?>
</head>
<?php ui_page_start('student', 'holiday', 'Pay Next Term Fees', 'Fees'); ?>

<div class="animate-rise" style="max-width:560px;margin:0 auto;">
    <div class="card" style="padding:28px;">
        <div class="text-center" style="margin-bottom:10px;">
            <div style="font-size:2.2rem;"><?= ui_icon('send', 34) ?></div>
            <h2 style="margin:6px 0 2px;">Next Term School Fees</h2>
            <p class="small text-muted" style="margin:0;">Secure your place for next term by arranging your fees before resumption.</p>
        </div>

        <?php if ($my_term): ?>
        <div class="panel" style="margin-bottom:12px;text-align:center;">
            <?php if ($my_status === 'paid'): ?>
                <span class="badge badge-green">Paid — <?= htmlspecialchars($my_term['term_label']) ?></span>
                <p class="small text-muted" style="margin:8px 0 0;">Jazakumullahu khairan — your payment for this term is confirmed.</p>
            <?php else: ?>
                <span class="badge badge-gold">Pending — <?= htmlspecialchars($my_term['term_label']) ?></span>
                <p class="small text-muted" style="margin:8px 0 0;">Amount: <strong>₦<?= htmlspecialchars(number_format((float)$my_term['amount'], 0)) ?></strong><?= !empty($my_term['due_date']) ? ' · Due: ' . htmlspecialchars(date('d M Y', strtotime($my_term['due_date']))) : '' ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="panel">
            <strong>How it works:</strong>
            <ul class="small" style="margin:8px 0 0;padding-left:20px;">
                <li>Tap the button below to message the academy on WhatsApp.</li>
                <li>Mention <strong>your name</strong> and that you are paying <strong>next term school fees</strong>.</li>
                <li>The academy will reply with the amount and payment details, then confirm your payment.</li>
            </ul>
        </div>

        <a class="btn btn-gold btn-lg btn-block mt-2" href="<?= htmlspecialchars($fee_url) ?>" target="_blank">
            <?= ui_icon('phone', 18) ?> Pay via WhatsApp
        </a>

        <p class="small text-muted" style="margin:14px 0 0;text-align:center;">
            Already paid? The academy will confirm your payment when the new term begins.
        </p>
    </div>

    <div style="text-align:center;margin-top:14px;">
        <a class="btn btn-ghost" href="holiday.php"><?= ui_icon('arrow-left', 16) ?> Back to Holiday</a>
    </div>
</div>

<?php ui_page_end(); ?>

</body>
</html>
