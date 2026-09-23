<?php
// Redirect to Google's OAuth 2.0 authorization endpoint.
// Set your client ID and redirect URI here.
$clientId = '33624325691-8v03ebu9mgntrvd02k4evlmqekvqfvv1.apps.googleusercontent.com';
// Normalize host (e.g., 127.0.0.1 -> localhost) for Google OAuth consistency
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (strpos($host, '127.0.0.1') === 0) {
    $host = preg_replace('/^127\.0\.0\.1/', 'localhost', $host);
}
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
$scheme = $isHttps ? 'https' : 'http';
$redirectUri = $scheme . '://' . $host . '/taskboard/google_callback.php';
$scope = urlencode('openid email profile');
$state = bin2hex(random_bytes(8));
session_start();
if (!empty($_GET['board'])) {
    $_SESSION['oauth_redirect_board'] = trim($_GET['board']);
}
$_SESSION['oauth_state'] = $state;

$authUrl = "https://accounts.google.com/o/oauth2/v2/auth?response_type=code&client_id={$clientId}&redirect_uri=" . urlencode($redirectUri) . "&scope={$scope}&state={$state}&access_type=offline&prompt=consent";
header('Location: ' . $authUrl);
exit;

