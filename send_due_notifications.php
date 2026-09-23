<?php
/**
 * send_due_notifications.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Sends Web Push notifications for tasks whose due datetime has arrived.
 *
 * TWO ways to run:
 *   1. CLI cron (recommended):
 *      * * * * *  php /path/to/taskboard/send_due_notifications.php >> /tmp/tb_notif.log 2>&1
 *
 *   2. HTTP endpoint — POST from a trusted source only (protect with token).
 *      Use this if your host does not allow cron jobs.
 *
 * Dependencies:
 *   composer require minishlink/web-push
 *   (or place the library in web-push-php-master/ as included in this zip)
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Guard: CLI or protected HTTP Web Cron ────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    // Allow HTTP access with a secret token for web-cron services (e.g. cron-job.org / EasyCron)
    $token = getenv('NOTIF_SECRET') ?: 'taskboard_cron_secret';
    $provided = $_SERVER['HTTP_X_NOTIF_TOKEN'] ?? ($_GET['token'] ?? ($_GET['key'] ?? ''));
    if (!hash_equals($token, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Forbidden - provide valid ?token= or ?key=',
            'hint' => 'Access via: send_due_notifications.php?token=taskboard_cron_secret'
        ]);
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/db.php';

// ── Load VAPID config ─────────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/vapid.php')) {
    fwrite(STDERR, "ERROR: vapid.php not found.\n");
    exit(1);
}
$config = require __DIR__ . '/vapid.php';
$vapid  = [
    'subject'    => $config['subject'],
    'publicKey'  => $config['publicKey'],
    'privateKey' => $config['privateKey'],
];

function tb_base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function tb_base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

function tb_der_to_raw_sig(string $der): string {
    $pos = 2;
    if (ord($der[1]) & 0x80) $pos += (ord($der[1]) & 0x7f);
    $pos++;
    $rLen = ord($der[$pos++]);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;
    $pos++;
    $sLen = ord($der[$pos++]);
    $s = substr($der, $pos, $sLen);
    return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT) . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
}

function getOpensslConfigPath(): ?string {
    $candidates = [
        __DIR__ . '/openssl.cnf',
        getenv('OPENSSL_CONF') ?: '',
        'C:/xampp/apache/bin/openssl.cnf',
        'C:/xampp/php/extras/ssl/openssl.cnf',
        'C:/xampp/apache/conf/openssl.cnf',
        '/etc/ssl/openssl.cnf',
        '/usr/lib/ssl/openssl.cnf',
        '/usr/local/ssl/openssl.cnf',
    ];
    foreach ($candidates as $c) {
        if ($c && file_exists($c)) {
            putenv("OPENSSL_CONF={$c}");
            $_ENV['OPENSSL_CONF'] = $c;
            return $c;
        }
    }
    $local = __DIR__ . '/openssl.cnf';
    if (!file_exists($local)) {
        @file_put_contents($local, "[req]\ndefault_bits = 2048\ndefault_md = sha256\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\ncommonName = TaskBoard\n");
    }
    if (file_exists($local)) {
        putenv("OPENSSL_CONF={$local}");
        $_ENV['OPENSSL_CONF'] = $local;
        return $local;
    }
    return null;
}

function sendWebPushNative(string $endpoint, ?string $userP256dh, ?string $userAuth, array $vapid, string $payload): array
{
    if (!$userP256dh || !$userAuth) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'Missing subscriber keys'];
    }
    if (!extension_loaded('openssl')) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'OpenSSL extension not loaded in PHP'];
    }
    if (!extension_loaded('curl')) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'cURL extension not loaded in PHP'];
    }

    $pubRaw = tb_base64url_decode($vapid['publicKey']);
    $privRaw = tb_base64url_decode($vapid['privateKey']);
    if (strlen($pubRaw) !== 65 || strlen($privRaw) !== 32) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'Invalid VAPID key lengths in vapid.php'];
    }

    $urlParts = parse_url($endpoint);
    $audience = ($urlParts['scheme'] ?? 'https') . '://' . ($urlParts['host'] ?? '');
    if (!empty($urlParts['port'])) {
        $audience .= ':' . $urlParts['port'];
    }

    $jwtHeader = tb_base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $jwtPayload = tb_base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => $vapid['subject'],
    ]));
    $jwtUnsigned = $jwtHeader . '.' . $jwtPayload;

    $privDer = pack('H*', '30770201010420') . $privRaw . pack('H*', 'a00a06082a8648ce3d030107a144034200') . $pubRaw;
    $privPem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($privDer), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    $privKeyRes = openssl_pkey_get_private($privPem);
    if (!$privKeyRes) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'Unable to load VAPID private key: ' . openssl_error_string()];
    }

    $derSig = '';
    if (!openssl_sign($jwtUnsigned, $derSig, $privKeyRes, OPENSSL_ALGO_SHA256)) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'Unable to sign VAPID JWT: ' . openssl_error_string()];
    }

    $rawSig = tb_der_to_raw_sig($derSig);
    $jwt = $jwtUnsigned . '.' . tb_base64url_encode($rawSig);

    $clientPubRaw = tb_base64url_decode($userP256dh);
    $clientAuthRaw = tb_base64url_decode($userAuth);
    if (strlen($clientPubRaw) !== 65 || strlen($clientAuthRaw) !== 16) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'Invalid client subscription key lengths'];
    }

    $cnf = getOpensslConfigPath();
    $ecArgs = [
        'curve_name' => 'prime256v1',
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'private_key_bits' => 2048,
    ];
    if ($cnf) {
        $ecArgs['config'] = $cnf;
    }
    $ephemeral = @openssl_pkey_new($ecArgs);
    if (!$ephemeral) {
        $ephemeral = @openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'private_key_bits' => 2048,
        ]);
    }
    if (!$ephemeral) {
        $errs = [];
        while ($msg = openssl_error_string()) { $errs[] = $msg; }
        return ['success' => false, 'statusCode' => 0, 'error' => 'Could not generate ephemeral EC key: ' . (implode('; ', $errs) ?: 'Check OpenSSL config')];
    }
    $details = openssl_pkey_get_details($ephemeral);
    $localPubRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];

    $clientPubDer = pack('H*', '3059301306072a8648ce3d020106082a8648ce3d030107034200') . $clientPubRaw;
    $clientPubPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($clientPubDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $clientKeyRes = openssl_pkey_get_public($clientPubPem);
    if (!$clientKeyRes) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'Could not load client public key'];
    }

    $sharedSecret = openssl_pkey_derive($clientKeyRes, $ephemeral, 32);
    if (!$sharedSecret) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'ECDH derivation failed'];
    }

    $keyInfo = "WebPush: info\x00" . $clientPubRaw . $localPubRaw;
    $ikm = hash_hkdf('sha256', $sharedSecret, 32, $keyInfo, $clientAuthRaw);
    $salt = random_bytes(16);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    $paddedPayload = $payload . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($paddedPayload, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        return ['success' => false, 'statusCode' => 0, 'error' => 'Payload encryption failed'];
    }

    $recordSize = pack('N', 4096);
    $idLen = chr(65);
    $body = $salt . $recordSize . $idLen . $localPubRaw . $ciphertext . $tag;

    $ch = curl_init($endpoint);
    $headers = [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 86400',
        'Authorization: vapid t=' . $jwt . ', k=' . $vapid['publicKey'],
    ];
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $isSuccess = ($httpCode >= 200 && $httpCode < 300);
    $errorMsg = null;
    if (!$isSuccess) {
        if ($curlErr) {
            $errorMsg = 'cURL error: ' . $curlErr;
        } else {
            $errorMsg = "Push service responded with HTTP {$httpCode}";
            if ($response && strlen(trim($response)) > 0) {
                $trimmedResp = trim(strip_tags($response));
                if (strlen($trimmedResp) < 200) {
                    $errorMsg .= ': ' . $trimmedResp;
                }
            }
        }
    }
    return [
        'success' => $isSuccess,
        'statusCode' => $httpCode,
        'response' => $response,
        'error' => $errorMsg,
    ];
}

// ── Find tasks due now (within the last minute, not yet notified) ─────────────
$pdo = DB::get();
$now = new DateTime('now');

$stmt = $pdo->prepare(
    "SELECT t.id        AS task_id,
            t.title,
            t.description,
            t.due_date,
            t.due_time,
            b.user_id
     FROM   tasks       t
     INNER JOIN board_lists l ON l.id = t.list_id
     INNER JOIN boards      b ON b.id = l.board_id
     WHERE  t.completed = 0
       AND  t.due_date IS NOT NULL
       AND  t.due_time IS NOT NULL
       AND  CONCAT(t.due_date, ' ', t.due_time) <= ?
       AND  t.id NOT IN (SELECT task_id FROM notifications_sent)"
);
$stmt->execute([$now->format('Y-m-d H:i:s')]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$tasks) {
    echo date('c') . " — No due tasks.\n";
    exit(0);
}

echo date('c') . " — Found " . count($tasks) . " task(s) to notify.\n";

// ── Send notifications ────────────────────────────────────────────────────────
foreach ($tasks as $task) {
    $subsStmt = $pdo->prepare(
        'SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?'
    );
    $subsStmt->execute([(int) $task['user_id']]);
    $subs = $subsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Insert in-app notification
    try {
        $notifId = 'notif_' . uniqid() . '_' . bin2hex(random_bytes(4));
        $pdo->prepare('INSERT INTO notifications (id, user_id, actor_id, type, title, message, entity_type, entity_id, is_read, created_at) VALUES (?, ?, NULL, "due", ?, ?, "task", ?, 0, NOW())')
            ->execute([$notifId, (int) $task['user_id'], '⏰ Task Due: ' . $task['title'], $task['description'] ?: 'This task is now due.', $task['task_id']]);
    } catch (Exception $e) {}

    if (!$subs) {
        echo "  Task {$task['task_id']}: no subscriptions for user {$task['user_id']}, skipping.\n";
        // Still mark sent so we don't retry forever
        $pdo->prepare('INSERT IGNORE INTO notifications_sent (task_id) VALUES (?)')
            ->execute([$task['task_id']]);
        continue;
    }

    $payload = json_encode([
        'title' => '⏰ Task Due: ' . $task['title'],
        'body'  => $task['description'] ?: 'This task is now due.',
        'tag'   => 'task-' . $task['task_id'],
        'url'   => './',
    ]);

    foreach ($subs as $row) {
        $res = sendWebPushNative($row['endpoint'], $row['p256dh'], $row['auth'], $vapid, $payload);
        if ($res['success']) {
            echo "  ✓ Sent to " . substr($row['endpoint'], 0, 60) . "...\n";
        } else {
            echo "  ✗ Failed to " . substr($row['endpoint'], 0, 60) . "... Reason: " . ($res['error'] ?? 'Unknown') . "\n";
            if (in_array($res['statusCode'], [404, 410])) {
                $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$row['endpoint']]);
                echo "    → Removed stale subscription.\n";
            }
        }
    }

    // Mark as sent regardless (avoid repeated failed retries)
    $pdo->prepare('INSERT IGNORE INTO notifications_sent (task_id) VALUES (?)')
        ->execute([$task['task_id']]);
}

echo date('c') . " — Done.\n";
