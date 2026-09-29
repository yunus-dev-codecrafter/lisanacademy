<?php
// config/security/push_send.php — Dependency-free Web Push (VAPID) sender.
//
// Sends "tickle" pushes (empty body, no payload encryption needed): the push
// service wakes our service worker, which then fetches the latest
// notification from /api/notifications.php and shows it + sets the OS
// app-icon badge. This is what delivers notifications and the 1/2/3 count
// even when the app is closed.
//
// Requires: PHP with openssl + curl (standard on most shared hosts).
// Requires: VAPID keys in config/vapid_keys.json (generate via
//           admin/push_notifications.php). Without keys, all functions
//           safely no-op and return false.

if (!function_exists('push_b64url_encode')) {
    function push_b64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (!function_exists('push_b64url_decode')) {
    function push_b64url_decode($str) {
        $pad = strlen($str) % 4;
        if ($pad) $str .= str_repeat('=', 4 - $pad);
        $out = base64_decode(strtr($str, '-_', '+/'), true);
        return $out === false ? null : $out;
    }
}

if (!function_exists('push_vapid_keys_path')) {
    function push_vapid_keys_path() {
        return __DIR__ . '/../vapid_keys.json';
    }
}

if (!function_exists('push_get_vapid_keys')) {
    /**
     * Returns ['public'=>b64url, 'private'=>b64url, 'subject'=>mailto:]
     * or null when push is not configured.
     */
    function push_get_vapid_keys() {
        if (defined('PUSH_VAPID_PUBLIC') && defined('PUSH_VAPID_PRIVATE')) {
            return [
                'public'  => PUSH_VAPID_PUBLIC,
                'private' => PUSH_VAPID_PRIVATE,
                'subject' => defined('PUSH_VAPID_SUBJECT') ? PUSH_VAPID_SUBJECT : 'mailto:admin@localhost',
            ];
        }
        $path = push_vapid_keys_path();
        if (!is_readable($path)) return null;
        try {
            $raw = file_get_contents($path);
            $j = json_decode($raw, true);
            if (!is_array($j) || empty($j['public']) || empty($j['private'])) return null;
            return [
                'public'  => $j['public'],
                'private' => $j['private'],
                'subject' => !empty($j['subject']) ? $j['subject'] : 'mailto:admin@localhost',
            ];
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('push_get_public_key')) {
    function push_get_public_key() {
        $keys = push_get_vapid_keys();
        return $keys ? $keys['public'] : null;
    }
}

if (!function_exists('push_raw_private_to_pem')) {
    /**
     * Wrap a raw 32-byte P-256 private key (base64url) as an
     * "EC PRIVATE KEY" PEM so openssl can sign with it.
     */
    function push_raw_private_to_pem($privB64, $pubB64) {
        $d = push_b64url_decode($privB64);
        $pub = push_b64url_decode($pubB64);
        if ($d === null || strlen($d) !== 32) return null;
        // ECPrivateKey ::= SEQUENCE { version INTEGER(1), privateKey OCTET(32),
        //   [0] curve OID 1.2.840.10045.3.1.7, [1] publicKey BIT STRING }
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $d
             . "\xA0\x0A\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        if ($pub !== null && strlen($pub) === 65) {
            $der .= "\xA1\x44\x03\x42\x00" . $pub;
        } else {
            // Recompute lengths without the public-key section.
            $der = "\x30\x31\x02\x01\x01\x04\x20" . $d
                 . "\xA0\x0A\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        }
        return "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
    }
}

if (!function_exists('push_der_to_raw_sig')) {
    /** Convert DER-encoded ECDSA signature to raw R||S (64 bytes, P-256). */
    function push_der_to_raw_sig($der) {
        $p = 0;
        if (ord($der[$p++]) !== 0x30) return null;
        $len = ord($der[$p++]);
        if ($len & 0x80) $p += ($len & 0x7F); // long form (unlikely here)
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if (ord($der[$p++]) !== 0x02) return null;
            $l = ord($der[$p++]);
            $int = substr($der, $p, $l);
            $p += $l;
            $int = ltrim($int, "\x00");
            $out .= str_pad($int, 32, "\x00", STR_PAD_LEFT);
        }
        return strlen($out) === 64 ? $out : null;
    }
}

if (!function_exists('push_build_jwt')) {
    /**
     * Build a VAPID JWT (ES256) for a push endpoint's origin (aud).
     * Returns the compact JWT string or null on failure.
     */
    function push_build_jwt($audience, $subject, $privB64, $pubB64) {
        if (!function_exists('openssl_sign')) return null;
        $pem = push_raw_private_to_pem($privB64, $pubB64);
        if ($pem === null) return null;
        $key = openssl_pkey_get_private($pem);
        if ($key === false) return null;

        $header = push_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $payload = push_b64url_encode(json_encode([
            'aud' => $audience,
            'exp' => time() + 12 * 3600,
            'sub' => $subject,
        ]));
        $unsigned = $header . '.' . $payload;
        $der = '';
        if (!openssl_sign($unsigned, $der, $key, OPENSSL_ALGO_SHA256)) return null;
        if (PHP_MAJOR_VERSION >= 8) { try { openssl_pkey_free($key); } catch (Throwable $e) {} }
        $raw = push_der_to_raw_sig($der);
        if ($raw === null) return null;
        return $unsigned . '.' . push_b64url_encode($raw);
    }
}

if (!function_exists('push_make_handle')) {
    /** Build a configured curl handle for one tickle send. Returns the handle. */
    function push_make_handle($endpoint, $jwt, $pubKey, $topic) {
        $ch = curl_init($endpoint);
        $headers = [
            'TTL: 86400',
            'Urgency: normal',
            'Content-Length: 0',
            'Authorization: vapid t=' . $jwt . ', k=' . $pubKey,
        ];
        if ($topic !== '') $headers[] = 'Topic: ' . $topic;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);
        return $ch;
    }
}

if (!function_exists('push_send_tickle')) {
    /**
     * Send one tickle push. Returns ['ok'=>bool,'gone'=>bool,'code'=>int].
     * 'gone' means the subscription expired (410/404) and should be deleted.
     */
    function push_send_tickle($endpoint, $vapid, $topic = '') {
        if (!function_exists('curl_init')) {
            error_log('push_send: curl not available');
            return ['ok' => false, 'gone' => false, 'code' => 0];
        }
        $parts = parse_url($endpoint);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'gone' => false, 'code' => 0];
        }
        $aud = $parts['scheme'] . '://' . $parts['host']
             . (!empty($parts['port']) ? ':' . $parts['port'] : '');
        $jwt = push_build_jwt($aud, $vapid['subject'], $vapid['private'], $vapid['public']);
        if ($jwt === null) {
            error_log('push_send: failed to build VAPID JWT (openssl issue?)');
            return ['ok' => false, 'gone' => false, 'code' => 0];
        }
        $ch = push_make_handle($endpoint, $jwt, $vapid['public'], $topic);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp === false) {
            error_log('push_send: curl error: ' . $err);
            return ['ok' => false, 'gone' => false, 'code' => 0];
        }
        return ['ok' => ($code >= 200 && $code < 300), 'gone' => ($code === 404 || $code === 410), 'code' => $code];
    }
}

if (!function_exists('push_subscription_endpoint')) {
    function push_subscription_endpoint($subJson) {
        try {
            $sub = is_array($subJson) ? $subJson : json_decode((string)$subJson, true);
            return (!empty($sub['endpoint']) && is_string($sub['endpoint'])) ? $sub['endpoint'] : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('push_clear_subscription')) {
    function push_clear_subscription($conn, $user_id) {
        try {
            $user_id = (int)$user_id;
            $stmt = $conn->prepare("UPDATE user_notification_settings SET push_subscription = NULL WHERE user_id = ?");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
        } catch (Throwable $e) { /* ignore */ }
    }
}

if (!function_exists('push_notify_user')) {
    /**
     * Send a tickle push to one user if they opted in and have a subscription.
     * Returns true if a push was accepted by the push service.
     */
    function push_notify_user($conn, $user_id, $topic = 'general') {
        try {
            if (!isset($conn)) return false;
            $user_id = (int)$user_id;
            if ($user_id <= 0) return false;
            if (function_exists('ensure_notification_tables')) ensure_notification_tables($conn);

            $res = $conn->query("SELECT notifications_enabled, daily_reminder_enabled, push_subscription FROM user_notification_settings WHERE user_id = $user_id LIMIT 1");
            $row = $res ? $res->fetch_assoc() : null;
            if (!$row || empty($row['push_subscription'])) return false;
            if ((int)($row['notifications_enabled'] ?? 1) !== 1) return false;
            if ($topic === 'daily_virtue' && (int)($row['daily_reminder_enabled'] ?? 1) !== 1) return false;

            $endpoint = push_subscription_endpoint($row['push_subscription']);
            if ($endpoint === null) return false;

            $vapid = push_get_vapid_keys();
            if ($vapid === null) return false; // push not configured — silent no-op

            $r = push_send_tickle($endpoint, $vapid, 'lisanun-' . preg_replace('/[^a-z0-9_-]/i', '', $topic));
            if ($r['gone']) push_clear_subscription($conn, $user_id);
            if (!$r['ok']) error_log('push_send: user ' . $user_id . ' http=' . $r['code']);
            return $r['ok'];
        } catch (Throwable $e) {
            error_log('push_notify_user error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('push_notify_broadcast')) {
    /**
     * Parallel tickle push to every opted-in subscriber of a role.
     * Uses curl_multi with a bounded total budget so a daily page-load
     * trigger never hangs the site. Returns ['sent'=>n,'gone'=>n,'total'=>n].
     */
    function push_notify_broadcast($conn, $target_role = 'all', $topic = 'general') {
        $stats = ['sent' => 0, 'gone' => 0, 'total' => 0];
        try {
            if (!isset($conn) || !function_exists('curl_multi_init')) return $stats;
            if (function_exists('ensure_notification_tables')) ensure_notification_tables($conn);

            $vapid = push_get_vapid_keys();
            if ($vapid === null) return $stats;

            $target_role = in_array($target_role, ['all', 'student', 'admin'], true) ? $target_role : 'all';
            // Admins never receive the student daily virtue (wrong dashboard link).
            if ($topic === 'daily_virtue' && $target_role !== 'student') $target_role = 'student';

            // Subscribers = users with a saved subscription + notifications on.
            // Role filtering for broadcasts: resolved per-user via push_user_role().
            $sql = "SELECT s.user_id, s.push_subscription
                    FROM user_notification_settings s
                    WHERE s.push_subscription IS NOT NULL
                      AND s.push_subscription != ''
                      AND s.notifications_enabled = 1";
            if ($topic === 'daily_virtue') $sql .= " AND s.daily_reminder_enabled = 1";

            $res = $conn->query($sql);
            if (!$res) return $stats;

            $jobs = [];
            while ($row = $res->fetch_assoc()) {
                $uid = (int)$row['user_id'];
                if ($target_role !== 'all' && function_exists('push_user_role')) {
                    if (push_user_role($conn, $uid) !== $target_role) continue;
                }
                $endpoint = push_subscription_endpoint($row['push_subscription']);
                if ($endpoint === null) continue;
                $jobs[$uid] = $endpoint;
            }
            $stats['total'] = count($jobs);
            if (empty($jobs)) return $stats;

            $safeTopic = 'lisanun-' . preg_replace('/[^a-z0-9_-]/i', '', $topic);
            // Group endpoints by origin: one JWT per origin.
            $byOrigin = [];
            foreach ($jobs as $uid => $endpoint) {
                $parts = parse_url($endpoint);
                if (empty($parts['scheme']) || empty($parts['host'])) continue;
                $aud = $parts['scheme'] . '://' . $parts['host']
                     . (!empty($parts['port']) ? ':' . $parts['port'] : '');
                $byOrigin[$aud][$uid] = $endpoint;
            }

            if (function_exists('set_time_limit')) { try { @set_time_limit(60); } catch (Throwable $e) {} }
            $mh = curl_multi_init();
            $handles = [];
            foreach ($byOrigin as $aud => $group) {
                $jwt = push_build_jwt($aud, $vapid['subject'], $vapid['private'], $vapid['public']);
                if ($jwt === null) continue;
                foreach ($group as $uid => $endpoint) {
                    $ch = push_make_handle($endpoint, $jwt, $vapid['public'], $safeTopic);
                    curl_multi_add_handle($mh, $ch);
                    $handles[(int)$ch] = ['ch' => $ch, 'uid' => $uid];
                }
            }

            // Bounded execution: max ~25s total for the whole broadcast.
            $deadline = time() + 25;
            do {
                $mrc = curl_multi_exec($mh, $active);
                if ($active) curl_multi_select($mh, 1);
            } while ($active && $mrc === CURLM_OK && time() < $deadline);

            foreach ($handles as $h) {
                $code = (int)curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
                if ($code >= 200 && $code < 300) {
                    $stats['sent']++;
                } elseif ($code === 404 || $code === 410) {
                    $stats['gone']++;
                    push_clear_subscription($conn, $h['uid']);
                } elseif ($code !== 0) {
                    error_log('push_send: broadcast user ' . $h['uid'] . ' http=' . $code);
                }
                curl_multi_remove_handle($mh, $h['ch']);
                curl_close($h['ch']);
            }
            curl_multi_close($mh);
        } catch (Throwable $e) {
            error_log('push_notify_broadcast error: ' . $e->getMessage());
        }
        return $stats;
    }
}

if (!function_exists('push_user_role')) {
    /**
     * Best-effort role lookup: admins table vs students table.
     * Falls back to 'student' when unknown (student is the default role).
     */
    function push_user_role($conn, $user_id) {
        $user_id = (int)$user_id;
        try {
            foreach (['admins' => 'admin', 'students' => 'student', 'users' => null] as $table => $role) {
                try {
                    $chk = $conn->query("SELECT 1 FROM `$table` WHERE id = $user_id LIMIT 1");
                    if ($chk && $chk->num_rows > 0) {
                        if ($role !== null) return $role;
                        // Generic users table: look for a role column.
                        $cols = $conn->query("SHOW COLUMNS FROM `$table`");
                        $hasRole = false;
                        if ($cols) while ($c = $cols->fetch_assoc()) {
                            if (strtolower($c['Field']) === 'role') { $hasRole = true; break; }
                        }
                        if ($hasRole) {
                            $r = $conn->query("SELECT role FROM `$table` WHERE id = $user_id LIMIT 1")->fetch_assoc();
                            $rv = strtolower(trim((string)($r['role'] ?? 'student')));
                            return ($rv === 'admin') ? 'admin' : 'student';
                        }
                        return 'student';
                    }
                } catch (Throwable $e) { continue; }
            }
        } catch (Throwable $e) { /* ignore */ }
        return 'student';
    }
}

if (!function_exists('push_trigger_for_notification')) {
    /**
     * Best-effort entry point called right after a notification row is created.
     * Never throws — notification storage must never break because push failed.
     */
    function push_trigger_for_notification($conn, $user_id, $target_role, $type = 'general') {
        try {
            if (defined('LISANUN_SKIP_PUSH') && LISANUN_SKIP_PUSH) return;
            if (push_get_vapid_keys() === null) return; // not configured yet
            $topic = preg_replace('/[^a-z0-9_]/i', '', (string)$type);
            if ($topic === '') $topic = 'general';
            if ($user_id !== null && (int)$user_id > 0) {
                push_notify_user($conn, (int)$user_id, $topic);
            } else {
                push_notify_broadcast($conn, $target_role, $topic);
            }
        } catch (Throwable $e) {
            error_log('push_trigger error: ' . $e->getMessage());
        }
    }
}
