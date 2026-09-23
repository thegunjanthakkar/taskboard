<?php
// Handle Google OAuth callback: exchange code for tokens and get userinfo.
require_once __DIR__ . '/db.php';
session_start();

if (!isset($_GET['code']) || !isset($_GET['state']) || $_GET['state'] !== ($_SESSION['oauth_state'] ?? '')) {
    echo 'Invalid OAuth response.';
    exit;
}

$code = $_GET['code'];
$clientId = '33624325691-8v03ebu9mgntrvd02k4evlmqekvqfvv1.apps.googleusercontent.com';
$clientSecret = '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';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (strpos($host, '127.0.0.1') === 0) {
    $host = preg_replace('/^127\.0\.0\.1/', 'localhost', $host);
}
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$scheme = $isHttps ? 'https' : 'http';
$redirectUri = $scheme . '://' . $host . '/taskboard/google_callback.php';

$tokenUrl = 'https://oauth2.googleapis.com/token';
$post = http_build_query([
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code'
]);

$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
// Disable SSL verification on localhost/XAMPP if CA bundle is not configured
$isLocal = in_array(parse_url($redirectUri, PHP_URL_HOST), ['localhost', '127.0.0.1']);
if ($isLocal) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}
$resp = curl_exec($ch);
if ($resp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    echo 'cURL error during token exchange: ' . htmlspecialchars($err);
    exit;
}
curl_close($ch);

$data = json_decode($resp, true);
if (empty($data['access_token'])) {
    echo 'Token exchange failed.<br><pre>';
    var_dump($data);
    echo '</pre>';
    exit;
}

$userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
$ch = curl_init($userInfoUrl . '?access_token=' . urlencode($data['access_token']));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
if ($isLocal) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
}
$uResp = curl_exec($ch);
if ($uResp === false) {
    $err = curl_error($ch);
    curl_close($ch);
    echo 'cURL error fetching user info: ' . htmlspecialchars($err);
    exit;
}
curl_close($ch);
$user = json_decode($uResp, true);

if (empty($user['email'])) {
    echo 'Failed to fetch user info.';
    exit;
}

// Find or create user
try {
    $pdo = DB::get();
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$user['email']]);
    $row = $stmt->fetch();
    if ($row) {
        $userId = $row['id'];
    } else {
        $stmt = $pdo->prepare('INSERT INTO users (email, name, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$user['email'], $user['name'] ?? null]);
        $userId = $pdo->lastInsertId();
    }
    $_SESSION['auth_user'] = ['id' => $userId, 'email' => $user['email']];
    $_SESSION['user_id'] = 'user_' . $userId;
    header('Location: index.php');
    exit;
} catch (Exception $e) {
    echo 'Database error: ' . $e->getMessage();
    exit;
}
