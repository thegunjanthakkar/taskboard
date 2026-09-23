<?php
/**
 * vapid_public.php — Returns the VAPID public key to the browser.
 * This is a public endpoint (no auth needed — the key is intentionally public).
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

$vapidFile = __DIR__ . '/vapid.php';
if (!file_exists($vapidFile)) {
    // Attempt auto-generation if OpenSSL is enabled
    if (extension_loaded('openssl')) {
        $cnf = file_exists(__DIR__ . '/openssl.cnf') ? __DIR__ . '/openssl.cnf' : (getenv('OPENSSL_CONF') ?: null);
        $args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 2048];
        if ($cnf) $args['config'] = $cnf;
        $res = @openssl_pkey_new($args);
        if (!$res) {
            $res = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC, 'private_key_bits' => 2048]);
        }
        if ($res) {
            $details = openssl_pkey_get_details($res);
            if (!empty($details['ec']['x']) && !empty($details['ec']['y']) && !empty($details['ec']['d'])) {
                $pubRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
                $privRaw = $details['ec']['d'];
                $pubB64 = rtrim(strtr(base64_encode($pubRaw), '+/', '-_'), '=');
                $privB64 = rtrim(strtr(base64_encode($privRaw), '+/', '-_'), '=');
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $content = "<?php\n// Auto-generated VAPID keys for Web Push\nreturn [\n\t'publicKey'  => '{$pubB64}',\n\t'privateKey' => '{$privB64}',\n\t'subject'    => 'mailto:admin@{$host}',\n];\n";
                @file_put_contents($vapidFile, $content);
            }
        }
    }
}

if (!file_exists($vapidFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'VAPID config not found. Please upload vapid.php or enable OpenSSL.']);
    exit;
}

$config = require $vapidFile;

if (empty($config['publicKey'])) {
    http_response_code(500);
    echo json_encode(['error' => 'publicKey is empty in vapid.php']);
    exit;
}

echo json_encode(['publicKey' => $config['publicKey']]);
