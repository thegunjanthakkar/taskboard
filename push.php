<?php
/**
 * push.php — Save / update a browser push subscription for the logged-in user.
 * Called by app.js after Notification.requestPermission() is granted.
 */
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}
$userId = (int) $_SESSION['auth_user']['id'];

// ── Parse body ────────────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty body']);
    exit;
}

$body = json_decode($raw, true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Accept both formats:
//   { subscription: { endpoint, keys: { p256dh, auth } } }   (old)
//   { endpoint, keys: { p256dh, auth } }                      (direct PushSubscription.toJSON())
$sub = $body['subscription'] ?? $body;

$endpoint = trim($sub['endpoint'] ?? '');
$p256dh   = $sub['keys']['p256dh'] ?? null;
$auth     = $sub['keys']['auth']   ?? null;

if (!$endpoint) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint']);
    exit;
}

// ── Upsert subscription ───────────────────────────────────────────────────────
try {
    $pdo = DB::get();

    // Check if this exact endpoint already exists for the user
    $stmt = $pdo->prepare(
        'SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?'
    );
    $stmt->execute([$userId, $endpoint]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Update keys (they can rotate)
        $stmt = $pdo->prepare(
            'UPDATE push_subscriptions SET p256dh = ?, auth = ?, created_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$p256dh, $auth, $existing['id']]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $endpoint, $p256dh, $auth]);
    }

    echo json_encode(['ok' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'DB error', 'detail' => $e->getMessage()]);
}
