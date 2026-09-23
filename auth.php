<?php
session_start();
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
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'email_exists']);
        exit;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$email, $hash]);
    $userId = $pdo->lastInsertId();

    $_SESSION['auth_user'] = ['id' => $userId, 'email' => $email];
    $_SESSION['user_id'] = 'user_' . $userId;
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

    $stmt = $pdo->prepare('SELECT id, password_hash FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'invalid_credentials']);
        exit;
    }

    $_SESSION['auth_user'] = ['id' => $row['id'], 'email' => $email];
    $_SESSION['user_id'] = 'user_' . $row['id'];
    echo json_encode(['ok' => true, 'user' => $_SESSION['auth_user']]);
    exit;

} elseif ($action === 'logout') {
    unset($_SESSION['auth_user']);
    unset($_SESSION['user_id']);
    echo json_encode(['ok' => true]);
    exit;

} else {
    http_response_code(400);
    echo json_encode(['error' => 'unknown action']);
    exit;
}
