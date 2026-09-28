<?php
// htdocs/register.php — Public student application form.
// Submissions are stored as PENDING applications, reviewed by the admin.

require_once __DIR__ . '/config/security/session.php';
require_once __DIR__ . '/config/security/helpers.php';
require_once __DIR__ . '/config/db.php';

$whatsapp_number = setting($conn, 'whatsapp_number', '2348029979040');
$contact_wa      = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $whatsapp_number);
$success = isset($_GET['success']);
$error   = isset($_GET['error']) ? trim($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — Lisanun Mubeen Academy</title>
<meta name="description" content="Apply to join Lisanun Mubeen Academy — a structured online Qur'an learning academy.">
<meta name="theme-color" content="#064e3b">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Lisanun Mubeen">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32x32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/CSS/base.css">
<link rel="stylesheet" href="/assets/CSS/components.css">
<link rel="stylesheet" href="/assets/CSS/layout.css">
<link rel="stylesheet" href="/assets/CSS/homepage.css">
<style>
    .reg-nav{position:static;background:linear-gradient(160deg,var(--emerald-950),var(--emerald-800))}
    .reg-body{min-height:calc(100vh - 130px);display:flex;align-items:flex-start;justify-content:center;padding:56px 18px}
    .reg-card{width:100%;max-width:620px}
    .reg-head{text-align:center;margin-bottom:24px}
    .reg-head h1{color:var(--emerald-950)}
    .reg-head p{color:var(--text-muted);margin-top:8px}
    .price-chip{display:inline-flex;align-items:center;gap:8px;margin-top:14px;padding:8px 16px;border-radius:999px;background:var(--emerald-50);border:1px solid var(--emerald-100);color:var(--emerald-800);font-weight:700;font-size:.9rem}
    .steps-line{display:flex;gap:8px;margin-top:16px;flex-wrap:wrap;justify-content:center}
    .steps-line span{font-size:.78rem;color:var(--text-muted);background:var(--surface-muted);border:1px solid var(--border);padding:6px 12px;border-radius:999px}
    .note-box{background:var(--info-bg);border:1px solid var(--info-border);color:#1e40af;border-radius:var(--radius);padding:14px 16px;font-size:.88rem;display:flex;gap:10px;align-items:flex-start;margin-bottom:18px}
    @media (min-width:768px){ .reg-nav{position:static} }
</style>
</head>
<body>

<!-- ============ NAVBAR ============ -->
<nav class="site-nav reg-nav">
    <div class="container">
        <a class="brand" href="/">
            <span class="brand-mark"><?= ui_icon('book-open', 20) ?></span>
            <span class="brand-name">Lisanun Mubeen<small>Academy</small></span>
        </a>
        <div class="nav-links">
            <a href="/#home">Home</a>
            <a href="/#features">Features</a>
            <a href="/#pricing">Pricing</a>
            <a href="/#faq">FAQ</a>
            <a href="/#contact">Contact</a>
        </div>
        <div class="nav-cta">
            <a class="btn btn-sm btn-outline" href="/auth/login.php">Login</a>
            <a class="btn btn-sm btn-gold" href="/register.php">Register Now</a>
        </div>
    </div>
</nav>

<div class="reg-body">
    <div class="reg-card">

        <?php if ($success): ?>
            <div class="card animate-rise" style="text-align:center;padding:40px 30px;border-top:5px solid var(--success);">
                <div style="font-size:2.6rem;margin-bottom:12px;color:var(--success);"><?= ui_icon('check-circle', 40) ?></div>
                <h2 style="margin:0 0 8px;">Application Received</h2>
                <p class="small text-muted" style="margin:0 0 16px;">
                    Assalamu alaikum. Thank you for applying to <strong>Lisanun Mubeen Academy</strong>. Your application is
                    now <strong>pending review</strong>. We will contact you through WhatsApp with the next steps, including
                    payment details (₦3,000 per term) and account activation.
                </p>
                <div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap;">
                    <a class="btn btn-gold" href="<?= $contact_wa ?>" target="_blank" rel="noopener"><?= ui_icon('phone', 16) ?> Chat with us on WhatsApp</a>
                    <a class="btn btn-ghost" href="/"><?= ui_icon('arrow-left', 16) ?> Back to Home</a>
                </div>
            </div>

        <?php else: ?>

            <div class="reg-head">
                <h1>Apply to Join the Academy</h1>
                <p>Complete the short form below — your application goes straight to the academy for review.</p>
                <div class="price-chip"><?= ui_icon('gem', 16) ?> ₦3,000 / term &nbsp;·&nbsp; 4 months &nbsp;·&nbsp; 3 terms a year</div>
                <div class="steps-line">
                    <span>1. Apply</span><span>2. We contact you on WhatsApp</span><span>3. Pay &amp; get activated</span><span>4. Start learning</span>
                </div>
            </div>

            <div class="card" style="padding:30px;border-radius:var(--radius-lg);box-shadow:var(--shadow-md);">

                <?php if ($error !== ''): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="note-box">
                    <?= ui_icon('info', 17) ?>
                    <span>We do not charge you online. After we receive your application, we contact you through
                    WhatsApp to confirm payment and arrangements before your account is activated.</span>
                </div>

                <form method="POST" action="register_process.php" novalidate>
                    <?= csrf_field() ?>

                    <div class="form-group">
                        <label class="form-label" for="full_name">Full Name <span class="req">*</span></label>
                        <input class="form-input" type="text" id="full_name" name="full_name"
                               placeholder="e.g. Ahmad Yunus" required maxlength="100"
                               value="<?= htmlspecialchars($_SESSION['reg_old']['full_name'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="whatsapp_phone">WhatsApp Phone Number <span class="req">*</span></label>
                        <input class="form-input" type="tel" id="whatsapp_phone" name="whatsapp_phone"
                               placeholder="e.g. 0801 234 5678" required maxlength="30" inputmode="tel"
                               value="<?= htmlspecialchars($_SESSION['reg_old']['whatsapp_phone'] ?? '') ?>">
                        <small class="text-muted" style="font-size:.78rem;">We use this number to contact you about your application.</small>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="email">Email Address</label>
                        <input class="form-input" type="email" id="email" name="email"
                               placeholder="you@example.com" maxlength="120"
                               value="<?= htmlspecialchars($_SESSION['reg_old']['email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="learning_info">Your Learning Information <span class="req">*</span></label>
                        <textarea class="form-textarea" id="learning_info" name="learning_info" required
                                  placeholder="e.g. I am a beginner and want to learn how to read the Qur'an from the beginning. I can attend lessons in the evening."><?= htmlspecialchars($_SESSION['reg_old']['learning_info'] ?? '') ?></textarea>
                        <small class="text-muted" style="font-size:.78rem;">Tell us a little about your current level and how you would like to learn.</small>
                    </div>

                    <button type="submit" class="btn btn-gold btn-lg btn-block" style="margin-top:6px;"><?= ui_icon('send', 17) ?> Submit Application</button>
                </form>
            </div>

            <p class="small text-muted" style="text-align:center;margin-top:16px;">
                Already a student? <a href="/auth/login.php"><strong>Login here</strong></a> · Back to <a href="/"><strong>Home</strong></a>
            </p>

        <?php endif; ?>
    </div>
</div>

<script src="/assets/js/pwa.js"></script>
</body>
</html>
