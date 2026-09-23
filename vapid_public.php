<?php
/**
 * vapid_public.php — Returns the VAPID public key to the browser.
 * This is a public endpoint (no auth needed — the key is intentionally public).
 */
header('Content-Type: application/json');
header('Cache-Control: public, max-age=3600');

$vapidFile = __DIR__ . '/vapid.php';
if (!file_exists($vapidFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'VAPID config not found. Create vapid.php from vapid.php.example']);
    exit;
}

$config = require $vapidFile;

if (empty($config['publicKey'])) {
    http_response_code(500);
    echo json_encode(['error' => 'publicKey is empty in vapid.php']);
    exit;
}

echo json_encode(['publicKey' => $config['publicKey']]);
