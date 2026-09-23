<?php
ini_set('display_errors', '0');
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
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? null;

if (!$action) {
    echo json_encode(['error' => 'no action']);
    exit;
}

try {
    $pdo = DB::get();
} catch (Exception $e) {
    echo json_encode(['error' => 'db error']);
    exit;
}

if ($action === 'signup') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid input']);
        exit;
    }

    // check exists
    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $existing = $stmt->fetch();
    if ($existing) {
        if (!empty($existing['password_hash'])) {
            http_response_code(409);
            echo json_encode(['error' => 'email_exists']);
            exit;
        }
        // User was pre-invited to a board! Activate their account by setting password
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $defaultName = explode('@', $email)[0];
        $uStmt = $pdo->prepare('UPDATE users SET password_hash = ?, name = COALESCE(NULLIF(name, ""), ?) WHERE id = ?');
        $uStmt->execute([$hash, $defaultName, (int)$existing['id']]);
        $userId = (int) $existing['id'];

        $_SESSION['auth_user'] = ['id' => $userId, 'email' => $email, 'name' => $defaultName];
        $_SESSION['user_id'] = 'user_' . $userId;
        session_regenerate_id(true);
        setcookie(session_name(), session_id(), [
            'expires'  => time() + $sevenDays,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        echo json_encode(['ok' => true, 'user' => $_SESSION['auth_user']]);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $defaultName = explode('@', $email)[0];
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name, created_at) VALUES (?, ?, ?, NOW())');
    $stmt->execute([$email, $hash, $defaultName]);
    $userId = (int) $pdo->lastInsertId();

    $_SESSION['auth_user'] = ['id' => $userId, 'email' => $email, 'name' => $defaultName];
    $_SESSION['user_id'] = 'user_' . $userId;
    session_regenerate_id(true);
    setcookie(session_name(), session_id(), [
        'expires'  => time() + $sevenDays,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    echo json_encode(['ok' => true, 'user' => $_SESSION['auth_user']]);
    exit;

} elseif ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        http_response_code(400);
        echo json_encode(['error' => 'invalid input']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT id, name, password_hash FROM users WHERE LOWER(email) = LOWER(?)');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_credentials']);
        exit;
    }

    $name = $row['name'] ?: explode('@', $email)[0];
    $_SESSION['auth_user'] = ['id' => (int) $row['id'], 'email' => $email, 'name' => $name];
    $_SESSION['user_id'] = 'user_' . $row['id'];
    session_regenerate_id(true);
    setcookie(session_name(), session_id(), [
        'expires'  => time() + $sevenDays,
        'path'     => '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    echo json_encode(['ok' => true, 'user' => $_SESSION['auth_user']]);
    exit;

} elseif ($action === 'logout') {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $params['path'] ?? '/',
            'domain'   => $params['domain'] ?? '',
            'secure'   => $params['secure'] ?? false,
            'httponly' => $params['httponly'] ?? true,
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
    echo json_encode(['ok' => true]);
    exit;

} else {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}
