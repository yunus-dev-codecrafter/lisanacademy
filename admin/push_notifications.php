<?php
// admin/push_notifications.php — Web Push (VAPID) setup & diagnostics.
// Generates the signing keys that let the server deliver notifications +
// app-icon badges even when the app is closed. Admin only.
require_once __DIR__ . '/../config/security/helpers.php';
require_once __DIR__ . '/../auth/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security/push_send.php';

require_role('admin');

$messages = [];
$selftest = null;

/** Generate a fresh VAPID P-256 keypair using openssl. Returns array or ['error'=>..]. */
function push_admin_generate_keys($subject) {
    if (!function_exists('openssl_pkey_new')) return ['error' => 'PHP openssl extension is not available on this server.'];
    $res = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if ($res === false) return ['error' => 'openssl could not generate an EC P-256 key.'];
    $privPem = '';
    if (!openssl_pkey_export($res, $privPem)) return ['error' => 'openssl could not export the private key.'];
    $der = push_b64url_decode(trim(preg_replace('/-----[^-]+-----|\\s+/', '', $privPem)));
    if ($der === null) return ['error' => 'Could not parse generated key.'];
    // SEC1 layout: ... 04 20 <32B d> ... 03 42 00 <65B pub 04||X||Y>
    $d = null; $pub = null;
    $p = strpos($der, "\x04\x20");
    if ($p !== false && strlen($der) >= $p + 34) $d = substr($der, $p + 2, 32);
    $q = strpos($der, "\x03\x42\x00");
    if ($q !== false && strlen($der) >= $q + 68) $pub = substr($der, $q + 4, 65);
    if ($d === null || strlen($d) !== 32) return ['error' => 'Could not extract private scalar from key.'];
    if ($pub === null || strlen($pub) !== 65 || $pub[0] !== "\x04") return ['error' => 'Could not extract public point from key.'];
    return [
        'public'  => push_b64url_encode($pub),
        'private' => push_b64url_encode($d),
        'subject' => $subject,
    ];
}

/** Self-test: sign a JWT with stored keys and verify it with the public key. */
function push_admin_selftest($keys) {
    if (!function_exists('openssl_verify')) return ['ok' => false, 'detail' => 'openssl_verify unavailable.'];
    $jwt = push_build_jwt('https://selftest.invalid', $keys['subject'], $keys['private'], $keys['public']);
    if ($jwt === null) return ['ok' => false, 'detail' => 'JWT signing failed.'];
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return ['ok' => false, 'detail' => 'Malformed JWT.'];
    $pub = push_b64url_decode($keys['public']);
    if ($pub === null || strlen($pub) !== 65) return ['ok' => false, 'detail' => 'Bad stored public key.'];
    // SubjectPublicKeyInfo for P-256.
    $spki = "\x30\x59\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01"
          . "\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07\x03\x42\x00" . $pub;
    $pubPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $ok = openssl_verify($parts[0] . '.' . $parts[1], push_b64url_decode($parts[2]), $pubPem, OPENSSL_ALGO_SHA256);
    return $ok === 1 ? ['ok' => true, 'detail' => 'Sign + verify round-trip passed.']
                     : ['ok' => false, 'detail' => 'Signature did not verify.'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $intent = $_POST['intent'] ?? '';

    if ($intent === 'generate') {
        $subject = trim($_POST['subject'] ?? '');
        if ($subject === '' || strpos($subject, '@') === false) {
            $subject = 'mailto:admin@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            if (strpos($subject, 'mailto:') !== 0) $subject = 'mailto:' . $subject;
        }
        if (strpos($subject, 'mailto:') !== 0) $subject = 'mailto:' . $subject;
        $keys = push_admin_generate_keys($subject);
        if (isset($keys['error'])) {
            $messages[] = ['danger', 'Key generation failed: ' . $keys['error']];
        } else {
            $json = json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents(push_vapid_keys_path(), $json, LOCK_EX) === false) {
                $messages[] = ['danger', 'Keys generated but could not write config/vapid_keys.json (check folder permissions).'];
            } else {
                @chmod(push_vapid_keys_path(), 0600);
                $selftest = push_admin_selftest($keys);
                $messages[] = ['success', 'New VAPID keys saved. Public key: ' . substr($keys['public'], 0, 20) . '…'];
                $messages[] = $selftest['ok'] ? ['success', 'Self-test: ' . $selftest['detail']]
                                              : ['danger', 'Self-test FAILED: ' . $selftest['detail']];
            }
        }
    } elseif ($intent === 'test') {
        // Tickle-push to this admin's own device subscription.
        $uid = (int)($_SESSION['user_id'] ?? 0);
        $sent = push_notify_user($conn, $uid, 'general');
        $settings = function_exists('get_user_notification_settings') ? get_user_notification_settings($conn, $uid) : [];
        if (!$sent && empty($settings['push_subscription'])) {
            $messages[] = ['warning', 'No push subscription saved for your account yet. On your phone: open the site, tap “Turn On Notifications”, grant permission, then reload this page and retry.'];
        } elseif ($sent) {
            $messages[] = ['success', 'Test push accepted by the push service. Minimize/close the app on your phone — it should arrive within seconds with the unread-count badge.'];
        } else {
            $messages[] = ['danger', 'Push service did not accept the test. Check server error log (push_send entries) and that cron/HTTPS is working.'];
        }
    } elseif ($intent === 'selftest') {
        $keys = push_get_vapid_keys();
        if ($keys === null) {
            $messages[] = ['warning', 'No VAPID keys configured yet — nothing to test.'];
        } else {
            $selftest = push_admin_selftest($keys);
            $messages[] = $selftest['ok'] ? ['success', 'Self-test: ' . $selftest['detail']]
                                          : ['danger', 'Self-test FAILED: ' . $selftest['detail']];
        }
    }
}

$keys = push_get_vapid_keys();
$openssl_ok = function_exists('openssl_sign') && function_exists('openssl_pkey_new');
$curl_ok = function_exists('curl_init') && function_exists('curl_multi_init');
$subCount = 0;
try {
    if (function_exists('ensure_notification_tables')) ensure_notification_tables($conn);
    $r = $conn->query("SELECT COUNT(*) c FROM user_notification_settings WHERE push_subscription IS NOT NULL AND push_subscription != ''");
    if ($r && ($row = $r->fetch_assoc())) $subCount = (int)$row['c'];
} catch (Throwable $e) { /* ignore */ }
$cron_url = (isset($_SERVER['HTTP_HOST']) ? ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']) : 'https://YOUR-DOMAIN') . '/cron/daily_reminder.php';
?>
<!DOCTYPE html>
<html>
<head>
<title>Push Notifications Setup</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?= ui_css() ?>
</head>
<?php ui_page_start('admin', 'settings', 'Push Setup', 'Notifications'); ?>

<div class="page-hero animate-rise">
    <h1>Push Notifications Setup</h1>
    <p>Enables real mobile notifications + the <strong>1 / 2 / 3 app-icon badge</strong> even when the app is closed (Web Push + VAPID). One-time setup.</p>
</div>

<?php foreach ($messages as [$kind, $text]): ?>
    <div class="alert alert-<?= htmlspecialchars($kind) ?>" style="max-width:720px;margin-bottom:12px;"><?= htmlspecialchars($text) ?></div>
<?php endforeach; ?>

<div class="card animate-rise d1" style="max-width:720px;margin-bottom:20px;">
    <h3>System Status</h3>
    <ul style="margin:14px 0;padding-left:20px;line-height:2;">
        <li>PHP openssl (signing): <strong><?= $openssl_ok ? '<span style="color:var(--success);">Available</span>' : '<span style="color:var(--danger);">Missing — ask host to enable php-openssl</span>' ?></strong></li>
        <li>PHP curl (delivery): <strong><?= $curl_ok ? '<span style="color:var(--success);">Available</span>' : '<span style="color:var(--danger);">Missing — ask host to enable php-curl</span>' ?></strong></li>
        <li>VAPID keys: <strong><?= $keys ? '<span style="color:var(--success);">Configured</span>' : '<span style="color:var(--danger);">Not configured</span>' ?></strong>
            <?php if ($keys): ?><span class="small text-muted"> (<?= htmlspecialchars(substr($keys['public'], 0, 16)) ?>… · <?= htmlspecialchars($keys['subject']) ?>)</span><?php endif; ?>
        </li>
        <li>Devices subscribed: <strong><?= $subCount ?></strong></li>
        <li>Service worker: <span class="small text-muted">serves <code>/sw.js</code> with push + badge handlers (v3)</span></li>
    </ul>
</div>

<div class="card animate-rise d2" style="max-width:720px;margin-bottom:20px;">
    <h3>1. Generate VAPID Keys (once)</h3>
    <p class="small text-muted">Stored in <code>config/vapid_keys.json</code> (never committed — already git-ignored). Regenerating invalidates old subscriptions; devices re-subscribe automatically on next visit.</p>
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;">
        <?= csrf_field() ?>
        <input type="hidden" name="intent" value="generate">
        <label class="small" style="flex:1;min-width:220px;">Contact email (VAPID subject)<br>
            <input class="form-input" type="email" name="subject" placeholder="admin@example.com" value="<?= htmlspecialchars($keys['subject'] ?? '') ?>" style="width:100%;">
        </label>
        <button type="submit" class="btn btn-gold"><?= ui_icon('lock', 16) ?> <?= $keys ? 'Regenerate Keys' : 'Generate Keys' ?></button>
    </form>
    <?php if ($keys): ?>
    <form method="POST" style="margin-top:10px;">
        <?= csrf_field() ?>
        <input type="hidden" name="intent" value="selftest">
        <button type="submit" class="btn btn-ghost btn-sm">Run signing self-test</button>
    </form>
    <?php endif; ?>
</div>

<div class="card animate-rise d3" style="max-width:720px;margin-bottom:20px;">
    <h3>2. Test On Your Phone</h3>
    <ol class="small" style="line-height:1.9;padding-left:20px;">
        <li>On the phone, install the app (Share → Add to Home Screen / Install).</li>
        <li>Open the app, tap <strong>“Turn On Notifications”</strong> and grant permission.</li>
        <li>Then press the button below <em>from this admin page</em> — minimize the app first to prove closed-app delivery.</li>
    </ol>
    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="intent" value="test">
        <button type="submit" class="btn btn-gold" <?= $keys ? '' : 'disabled title="Generate keys first"' ?>>Send test push to my device</button>
    </form>
</div>

<div class="card animate-rise d4" style="max-width:720px;margin-bottom:20px;">
    <h3>3. Keep The 7 AM Reminder Working When Nobody Is Online</h3>
    <p class="small text-muted">The daily reminder + push broadcast currently fires on first visit after 7 AM. For reliability, add a daily cron job (cPanel → Cron Jobs, once per day at 07:05 Africa/Lagos):</p>
    <p><code style="word-break:break-all;">curl -s "<?= htmlspecialchars($cron_url) ?>?force=1" &gt;/dev/null</code></p>
    <p class="small text-muted">Notes: iPhone requires iOS 16.4+, the app installed to Home Screen, and push permission granted. The icon badge (1, 2, 3…) shows on the installed PWA icon; in a plain browser tab only the in-app bell badge appears.</p>
</div>

<?php ui_page_end(); ?>
</body>
</html>
