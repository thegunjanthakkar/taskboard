<?php
$sevenDays = 7 * 86400; // 7 days (604800 seconds)
ini_set('session.gc_maxlifetime', (string) $sevenDays);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
session_set_cookie_params([
    'lifetime' => $sevenDays,
    'path'     => '/',
    'secure'   => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$targetBoard = !empty($_GET['board']) ? trim($_GET['board']) : '';
$redirectUrl = 'index.php' . ($targetBoard ? '?board=' . urlencode($targetBoard) : '');

if (isset($_SESSION['auth_user'])) {
    header('Location: ' . $redirectUrl);
    exit;
}

$theme = 'dark';
$googleHref = 'google_login.php' . ($targetBoard ? '?board=' . urlencode($targetBoard) : '');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="description" content="Login to TasksBoard">
    <title>TasksBoard Login</title>
    <link rel="shortcut icon" href="./icons/icon-192.png" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="auth-page" data-auth-redirect="<?= htmlspecialchars($redirectUrl) ?>">
    <main class="auth-shell">
        <section class="auth-card" aria-label="Login form">
            <div class="auth-brand">
                <div class="auth-logo" aria-hidden="true">
                    <svg width="26" height="26" viewBox="0 0 22 22" fill="none">
                        <rect x="1" y="1" width="8" height="8" rx="2" fill="#4f8ef7"/>
                        <rect x="13" y="1" width="8" height="8" rx="2" fill="#4f8ef7" opacity=".6"/>
                        <rect x="1" y="13" width="8" height="8" rx="2" fill="#4f8ef7" opacity=".6"/>
                        <rect x="13" y="13" width="8" height="8" rx="2" fill="#4f8ef7" opacity=".3"/>
                    </svg>
                </div>
                <div>
                    <div class="auth-kicker">Welcome back</div>
                    <h1 class="auth-title">TasksBoard</h1>
                </div>
            </div>
            <p class="auth-copy">Sign in to access your boards, tasks, and reminders.</p>

            <div class="auth-tabs">
                <button type="button" class="auth-tab active" data-auth-tab="login">Login</button>
                <button type="button" class="auth-tab" data-auth-tab="signup">Signup</button>
            </div>

            <div class="auth-panel active" data-auth-panel="login">
                <div class="form-group">
                    <label for="loginEmail">Email</label>
                    <input type="email" id="loginEmail" class="form-input" autocomplete="email" required>
                </div>
                <div class="form-group">
                    <label for="loginPassword">Password</label>
                    <input type="password" id="loginPassword" class="form-input" autocomplete="current-password" required>
                </div>
                <button type="button" class="btn btn-primary auth-submit" id="loginBtn">Login</button>
            </div>

            <div class="auth-panel" data-auth-panel="signup">
                <div class="form-group">
                    <label for="signupEmail">Email</label>
                    <input type="email" id="signupEmail" class="form-input" autocomplete="email" required>
                </div>
                <div class="form-group">
                    <label for="signupPassword">Password</label>
                    <input type="password" id="signupPassword" class="form-input" autocomplete="new-password" required>
                </div>
                <button type="button" class="btn btn-primary auth-submit" id="signupBtn">Create account</button>
            </div>

            <div class="auth-divider">or</div>
            <a href="<?= htmlspecialchars($googleHref) ?>" class="btn auth-google">Continue with Google</a>
            <div id="authMessage" class="auth-message"></div>
        </section>
    </main>
    <script src="auth-ui.js"></script>
</body>
</html>