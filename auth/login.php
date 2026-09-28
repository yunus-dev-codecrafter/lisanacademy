<?php
// auth/login.php

require_once __DIR__ . '/../config/security/session.php';
require_once __DIR__ . '/../config/security/helpers.php';

$error = isset($_GET['error']) ? trim($_GET['error']) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Lisanun Mubeen Academy — Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#064e3b">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Lisanun Mubeen">
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32x32.png">
<link rel="stylesheet" href="../assets/CSS/base.css">
<link rel="stylesheet" href="../assets/CSS/components.css">
<link rel="stylesheet" href="../assets/CSS/layout.css">
<link rel="stylesheet" href="../assets/CSS/icons.css">
</head>
<body>

<div class="center-screen animate-rise">
    <div style="width:100%;max-width:420px;">

        <!-- Brand -->
        <div class="text-center" style="margin-bottom:22px;">
            <div style="width:64px;height:64px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;border-radius:18px;background:linear-gradient(135deg, var(--emerald-800), var(--emerald-600));box-shadow:var(--shadow-green);font-size:1.9rem;"><?= ui_icon('book-open', 30) ?></div>
            <h1 style="margin:0 0 4px;font-size:1.5rem;">Lisanun Mubeen Academy</h1>
            <p class="text-muted small" style="margin:0;">Come learn and let the Qur’an be your life companion</p>
        </div>

        <!-- Card -->
        <div class="card" style="padding:30px;border-radius:var(--radius-lg);box-shadow:var(--shadow-md);">

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['error']) && $_GET['error'] === 'suspended'): ?>
                <div class="alert alert-warning">
                    Your account is currently suspended. Please contact the academy to restore access.
                </div>
            <?php endif; ?>

            <form method="POST" action="login_process.php">
                <div class="form-group">
                    <label class="form-label" for="email">Email</label>
                    <input class="form-input" type="email" id="email" name="email" placeholder="you@example.com" required autofocus>
                </div>
                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-input" type="password" id="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn btn-block btn-lg">Sign In</button>
            </form>
        </div>

        <div style="display:flex;justify-content:center;gap:16px;margin-top:16px;flex-wrap:wrap;">
            <a class="btn btn-gold" href="../register.php"><?= ui_icon('user', 16) ?> Register Now</a>
            <a class="btn btn-ghost" href="../index.php"><?= ui_icon('arrow-left', 16) ?> Back to Home</a>
        </div>

        <p class="text-center small text-muted" style="margin-top:20px;">© Lisanun Mubeen Academy 2026</p>
    </div>
</div>

<script src="/assets/js/pwa.js"></script>
</body>
</html>