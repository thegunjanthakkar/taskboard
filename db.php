<?php
// Simple PDO DB connection helper. Edit credentials below.
class DB {
    private static $pdo = null;

    public static function get()
    {
        if (self::$pdo) return self::$pdo;

        $host = '127.0.0.1';
        $db   = 'taskboard';
        $user = 'root';
        $pass = '';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $opts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $opts);
            return self::$pdo;
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'DB connection failed', 'detail' => $e->getMessage()]);
            exit;
        }
    }
}
