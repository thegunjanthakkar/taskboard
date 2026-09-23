<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['auth_user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No session']);
    exit;
}

$authUser = $_SESSION['auth_user'];
$userId = (int) $authUser['id'];

function genId(string $prefix = 'id'): string {
    return $prefix . '_' . uniqid() . '_' . bin2hex(random_bytes(4));
}

function ensureSchema(PDO $pdo): void
{
    // 0. Base tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `email` VARCHAR(255) NOT NULL,
          `name` VARCHAR(255) NULL,
          `password` VARCHAR(255) NULL,
          `google_id` VARCHAR(255) NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_users_email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `boards` (
          `id` VARCHAR(64) NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `name` VARCHAR(255) NOT NULL,
          `color` VARCHAR(20) NOT NULL DEFAULT '#4f8ef7',
          `icon` VARCHAR(50) NOT NULL DEFAULT 'grid',
          `position` INT NOT NULL DEFAULT 0,
          `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_boards_user_id` (`user_id`),
          KEY `idx_boards_archived` (`is_archived`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `board_lists` (
          `id` VARCHAR(64) NOT NULL,
          `board_id` VARCHAR(64) NOT NULL,
          `name` VARCHAR(255) NOT NULL,
          `color` VARCHAR(20) NULL,
          `position` INT NOT NULL DEFAULT 0,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_board_lists_board_id` (`board_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `tasks` (
          `id` VARCHAR(64) NOT NULL,
          `list_id` VARCHAR(64) NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `description` TEXT NULL,
          `start_date` DATE NULL,
          `due_date` DATE NULL,
          `due_time` TIME NULL,
          `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
          `completed` TINYINT(1) NOT NULL DEFAULT 0,
          `position` INT NOT NULL DEFAULT 0,
          `assigned_to` INT UNSIGNED NULL DEFAULT NULL,
          `recurrence` ENUM('none','daily','weekly','monthly','custom') NOT NULL DEFAULT 'none',
          `recurrence_interval` INT NOT NULL DEFAULT 1,
          `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
          `completed_at` DATETIME NULL DEFAULT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_tasks_list_id` (`list_id`),
          KEY `idx_tasks_assigned_to` (`assigned_to`),
          KEY `idx_tasks_archived` (`is_archived`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 1. board_members
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `board_members` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `board_id` VARCHAR(64) NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `role` ENUM('owner','editor','viewer') NOT NULL DEFAULT 'editor',
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_board_user` (`board_id`, `user_id`),
          KEY `idx_bm_board` (`board_id`),
          KEY `idx_bm_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 2. subtasks
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `subtasks` (
          `id` VARCHAR(64) NOT NULL,
          `task_id` VARCHAR(64) NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `completed` TINYINT(1) NOT NULL DEFAULT 0,
          `position` INT NOT NULL DEFAULT 0,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_subtasks_task` (`task_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 3. labels
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `labels` (
          `id` VARCHAR(64) NOT NULL,
          `board_id` VARCHAR(64) NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `name` VARCHAR(50) NOT NULL,
          `color` VARCHAR(20) NOT NULL DEFAULT '#4f8ef7',
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_labels_board` (`board_id`),
          KEY `idx_labels_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 4. task_labels
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `task_labels` (
          `task_id` VARCHAR(64) NOT NULL,
          `label_id` VARCHAR(64) NOT NULL,
          PRIMARY KEY (`task_id`, `label_id`),
          KEY `idx_tl_label` (`label_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 5. comments
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `comments` (
          `id` VARCHAR(64) NOT NULL,
          `task_id` VARCHAR(64) NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `content` TEXT NOT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_comments_task` (`task_id`),
          KEY `idx_comments_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 6. attachments
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `attachments` (
          `id` VARCHAR(64) NOT NULL,
          `task_id` VARCHAR(64) NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `filename` VARCHAR(255) NOT NULL,
          `original_name` VARCHAR(255) NOT NULL,
          `file_size` INT UNSIGNED NOT NULL,
          `file_type` VARCHAR(100) NOT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_attachments_task` (`task_id`),
          KEY `idx_attachments_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 7. notifications
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `notifications` (
          `id` VARCHAR(64) NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `actor_id` INT UNSIGNED NULL,
          `type` VARCHAR(50) NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `message` TEXT NULL,
          `entity_type` VARCHAR(50) NULL,
          `entity_id` VARCHAR(64) NULL,
          `is_read` TINYINT(1) NOT NULL DEFAULT 0,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_notifications_user` (`user_id`),
          KEY `idx_notifications_read` (`is_read`),
          KEY `idx_notifications_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 8. activity_logs
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `activity_logs` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `board_id` VARCHAR(64) NULL,
          `task_id` VARCHAR(64) NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `action` VARCHAR(50) NOT NULL,
          `details` TEXT NULL,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_activity_board` (`board_id`),
          KEY `idx_activity_task` (`task_id`),
          KEY `idx_activity_user` (`user_id`),
          KEY `idx_activity_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 9. user_settings
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `user_settings` (
          `user_id` INT UNSIGNED NOT NULL,
          `theme` VARCHAR(10) NOT NULL DEFAULT 'dark',
          `notification_preferences` TEXT NULL,
          PRIMARY KEY (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 10. board_access_requests
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `board_access_requests` (
          `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `board_id` VARCHAR(64) NOT NULL,
          `user_id` INT UNSIGNED NOT NULL,
          `message` TEXT NULL,
          `status` ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_bar_board_user` (`board_id`, `user_id`),
          KEY `idx_bar_board` (`board_id`),
          KEY `idx_bar_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // Ensure columns in `boards`
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `boards` LIKE 'color'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `boards` ADD COLUMN `color` VARCHAR(20) NOT NULL DEFAULT '#4f8ef7'");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `boards` LIKE 'icon'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `boards` ADD COLUMN `icon` VARCHAR(50) NOT NULL DEFAULT 'grid'");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `boards` LIKE 'is_archived'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `boards` ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0, ADD KEY `idx_boards_archived` (`is_archived`)");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `boards` LIKE 'general_access'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `boards` ADD COLUMN `general_access` VARCHAR(32) NOT NULL DEFAULT 'anyone_with_link'");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `boards` LIKE 'link_role'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `boards` ADD COLUMN `link_role` VARCHAR(20) NOT NULL DEFAULT 'editor'");
    } catch (Exception $e) {}

    // Ensure columns in `board_lists`
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `board_lists` LIKE 'color'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `board_lists` ADD COLUMN `color` VARCHAR(20) NULL DEFAULT NULL");
    } catch (Exception $e) {}

    // Ensure columns in `tasks`
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `tasks` LIKE 'assigned_to'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `assigned_to` INT UNSIGNED NULL DEFAULT NULL AFTER `position`, ADD KEY `idx_tasks_assigned_to` (`assigned_to`)");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `tasks` LIKE 'start_date'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `start_date` DATE NULL DEFAULT NULL AFTER `description`");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `tasks` LIKE 'recurrence'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `recurrence` ENUM('none','daily','weekly','monthly','custom') NOT NULL DEFAULT 'none' AFTER `assigned_to`");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `tasks` LIKE 'recurrence_interval'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `recurrence_interval` INT NOT NULL DEFAULT 1 AFTER `recurrence`");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `tasks` LIKE 'is_archived'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `is_archived` TINYINT(1) NOT NULL DEFAULT 0, ADD KEY `idx_tasks_archived` (`is_archived`)");
    } catch (Exception $e) {}
    try {
        $c = $pdo->query("SHOW COLUMNS FROM `tasks` LIKE 'completed_at'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE `tasks` ADD COLUMN `completed_at` DATETIME NULL DEFAULT NULL");
    } catch (Exception $e) {}

    // Ensure uploads directory exists
    $uploadDir = __DIR__ . '/uploads/tasks';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }

    // Self-healing: Clean up runaway test chains of recurring tasks with future due dates
    try {
        // Reset any future recurring task that was prematurely marked completed to uncompleted if its due_date is tomorrow
        $pdo->exec("
            UPDATE tasks
            SET completed = 0, completed_at = NULL
            WHERE recurrence != 'none'
              AND due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND completed = 1
        ");

        // Remove redundant chained duplicates that were spawned beyond tomorrow for the same list & title
        $pdo->exec("
            DELETE t1 FROM tasks t1
            INNER JOIN tasks t2 ON t1.list_id = t2.list_id
                                AND t1.title = t2.title
                                AND t1.recurrence = t2.recurrence
                                AND t1.recurrence != 'none'
            WHERE t1.due_date > DATE_ADD(CURDATE(), INTERVAL 1 DAY)
              AND t2.due_date = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        ");
    } catch (Exception $e) {}
}

function logActivity(PDO $pdo, ?string $boardId, ?string $taskId, int $userId, string $action, ?string $details = null): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO activity_logs (board_id, task_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$boardId, $taskId, $userId, $action, $details]);
    } catch (Exception $e) {
        // Non-fatal logging
    }
}

function getActorName(PDO $pdo, int $actorId): string
{
    static $cache = [];
    if (isset($cache[$actorId])) return $cache[$actorId];
    try {
        $stmt = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
        $stmt->execute([$actorId]);
        $u = $stmt->fetch();
        $name = $u ? ($u['name'] ?: explode('@', $u['email'])[0]) : 'A collaborator';
        $cache[$actorId] = $name;
        return $name;
    } catch (Exception $e) {
        return 'A collaborator';
    }
}

function getBoardName(PDO $pdo, string $boardId): string
{
    static $cache = [];
    if (isset($cache[$boardId])) return $cache[$boardId];
    try {
        $stmt = $pdo->prepare('SELECT name FROM boards WHERE id = ?');
        $stmt->execute([$boardId]);
        $name = $stmt->fetchColumn() ?: 'Board';
        $cache[$boardId] = $name;
        return $name;
    } catch (Exception $e) {
        return 'Board';
    }
}

function sendPushNotification(PDO $pdo, int $targetUserId, string $title, ?string $message = null, ?string $url = null): void
{
    try {
        if (!file_exists(__DIR__ . '/vapid.php')) return;
        $config = require __DIR__ . '/vapid.php';
        if (empty($config['publicKey']) || empty($config['privateKey'])) return;

        $autoloads = [
            __DIR__ . '/vendor/autoload.php',
            __DIR__ . '/web-push-php-master/web-push-php-master/vendor/autoload.php',
            __DIR__ . '/web-push-php-master/vendor/autoload.php',
        ];
        $loaded = false;
        foreach ($autoloads as $al) {
            if (file_exists($al)) { require_once $al; $loaded = true; break; }
        }
        if (!$loaded) {
            $srcDirs = [
                __DIR__ . '/web-push-php-master/web-push-php-master/src',
                __DIR__ . '/web-push-php-master/src',
            ];
            foreach ($srcDirs as $src) {
                if (is_dir($src)) {
                    spl_autoload_register(function (string $class) use ($src) {
                        $prefix = 'Minishlink\\WebPush\\';
                        if (strpos($class, $prefix) !== 0) return;
                        $rel  = str_replace('\\', '/', substr($class, strlen($prefix)));
                        $file = $src . '/' . $rel . '.php';
                        if (file_exists($file)) require_once $file;
                    });
                    $loaded = true;
                    break;
                }
            }
        }
        if (!$loaded || !class_exists('Minishlink\\WebPush\\WebPush')) return;

        $subsStmt = $pdo->prepare('SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?');
        $subsStmt->execute([$targetUserId]);
        $subs = $subsStmt->fetchAll();
        if (empty($subs)) return;

        $webPush = new \Minishlink\WebPush\WebPush([
            'VAPID' => [
                'subject' => $config['subject'],
                'publicKey' => $config['publicKey'],
                'privateKey' => $config['privateKey'],
            ]
        ]);

        $payload = json_encode([
            'title' => $title,
            'body' => $message ?: '',
            'tag' => 'tb-' . time(),
            'url' => $url ?: './',
        ]);

        foreach ($subs as $s) {
            try {
                $sub = \Minishlink\WebPush\Subscription::create([
                    'endpoint' => $s['endpoint'],
                    'keys' => [
                        'p256dh' => $s['p256dh'],
                        'auth' => $s['auth'],
                    ]
                ]);
                $webPush->queueNotification($sub, $payload);
            } catch (Exception $e) {}
        }

        foreach ($webPush->flush() as $report) {
            if (!$report->isSuccess() && $report->getResponse() && in_array($report->getResponse()->getStatusCode(), [404, 410])) {
                $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$report->getRequest()->getUri()->__toString()]);
            }
        }
    } catch (Throwable $e) {
        // Non-fatal push failure
    }
}

function createNotification(PDO $pdo, int $targetUserId, ?int $actorId, string $type, string $title, ?string $message = null, ?string $entityType = null, ?string $entityId = null): void
{
    try {
        // Don't notify oneself
        if ($actorId && $targetUserId === $actorId) {
            return;
        }
        $id = genId('notif');
        $stmt = $pdo->prepare('INSERT INTO notifications (id, user_id, actor_id, type, title, message, entity_type, entity_id, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())');
        $stmt->execute([$id, $targetUserId, $actorId, $type, $title, $message, $entityType, $entityId]);

        // Background Web Push to recipient if subscribed
        sendPushNotification($pdo, $targetUserId, $title, $message);
    } catch (Exception $e) {
        // Non-fatal
    }
}

function notifyBoardMembers(PDO $pdo, string $boardId, int $actorId, string $type, string $title, ?string $message = null, ?string $entityType = null, ?string $entityId = null): void
{
    try {
        $stmtOwner = $pdo->prepare('SELECT user_id FROM boards WHERE id = ?');
        $stmtOwner->execute([$boardId]);
        $ownerId = (int) $stmtOwner->fetchColumn();

        $targetUserIds = [];
        if ($ownerId && $ownerId !== $actorId) {
            $targetUserIds[] = $ownerId;
        }

        $stmtMembers = $pdo->prepare('SELECT user_id FROM board_members WHERE board_id = ?');
        $stmtMembers->execute([$boardId]);
        foreach ($stmtMembers->fetchAll() as $row) {
            $mId = (int) $row['user_id'];
            if ($mId && $mId !== $actorId && !in_array($mId, $targetUserIds, true)) {
                $targetUserIds[] = $mId;
            }
        }

        if (empty($targetUserIds)) return;

        foreach ($targetUserIds as $targetUserId) {
            createNotification($pdo, $targetUserId, $actorId, $type, $title, $message, $entityType, $entityId);
        }
    } catch (Exception $e) {
        // Non-fatal
    }
}

function ensureDefaultBoard(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM boards WHERE user_id = ?');
    $stmt->execute([$userId]);
    $ownedCount = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM board_members WHERE user_id = ?');
    $stmt->execute([$userId]);
    $memberCount = (int) $stmt->fetchColumn();

    if ($ownedCount > 0 || $memberCount > 0) {
        return;
    }

    $boardId = genId('board');
    $defaultColumns = [
        ['name' => 'Backlog', 'color' => '#8c959f'],
        ['name' => 'To Do', 'color' => '#4f8ef7'],
        ['name' => 'In Progress', 'color' => '#f59e0b'],
        ['name' => 'Review', 'color' => '#a855f7'],
        ['name' => 'Done', 'color' => '#22c55e'],
    ];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO boards (id, user_id, name, color, icon, position, is_archived, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())');
        $stmt->execute([$boardId, $userId, 'Main Board', '#4f8ef7', 'grid', 1]);

        $bmStmt = $pdo->prepare('INSERT INTO board_members (board_id, user_id, role, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE role = ?');
        $bmStmt->execute([$boardId, $userId, 'owner', 'owner']);

        $listStmt = $pdo->prepare('INSERT INTO board_lists (id, board_id, name, color, position, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        foreach ($defaultColumns as $idx => $col) {
            $listStmt->execute([genId('list'), $boardId, $col['name'], $col['color'], $idx + 1]);
        }

        // Seed default labels for this user
        $labelStmt = $pdo->prepare('INSERT INTO labels (id, board_id, user_id, name, color, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $defaultLabels = [
            ['Bug', '#ef4444'],
            ['Feature', '#4f8ef7'],
            ['Urgent', '#f59e0b'],
            ['Design', '#a855f7'],
            ['Ops', '#10b981'],
        ];
        foreach ($defaultLabels as $lbl) {
            $labelStmt->execute([genId('lbl'), $boardId, $userId, $lbl[0], $lbl[1]]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('ensureDefaultBoard error: ' . $e->getMessage());
    }
}

function getBoardAccess(PDO $pdo, int $userId, string $boardId): ?string
{
    $stmt = $pdo->prepare('SELECT user_id FROM boards WHERE id = ?');
    $stmt->execute([$boardId]);
    $ownerId = $stmt->fetchColumn();
    if ($ownerId !== false) {
        if ((int)$ownerId === $userId) {
            return 'owner';
        }
    } else {
        return null;
    }

    $stmt = $pdo->prepare('SELECT role FROM board_members WHERE board_id = ? AND user_id = ?');
    $stmt->execute([$boardId, $userId]);
    $role = $stmt->fetchColumn();
    return $role ?: null;
}

function checkBoardWritePermission(PDO $pdo, int $userId, string $boardId): bool
{
    $role = getBoardAccess($pdo, $userId, $boardId);
    return in_array($role, ['owner', 'editor'], true);
}

function fetchBoards(PDO $pdo, int $userId, bool $includeArchived = false): array
{
    ensureSchema($pdo);
    ensureDefaultBoard($pdo, $userId);

    $archiveFilter = $includeArchived ? '' : 'AND b.is_archived = 0';

    $boardsStmt = $pdo->prepare("
        SELECT DISTINCT b.id, b.name, b.color, b.icon, b.position, b.is_archived, b.created_at,
               COALESCE(b.general_access, 'anyone_with_link') AS general_access,
               COALESCE(b.link_role, 'editor') AS link_role,
               b.user_id AS owner_id, u.email AS owner_email, u.name AS owner_name,
               CASE WHEN b.user_id = ? THEN 'owner' ELSE COALESCE(bm.role, 'editor') END AS user_role
        FROM boards b
        LEFT JOIN board_members bm ON bm.board_id = b.id AND bm.user_id = ?
        LEFT JOIN users u ON u.id = b.user_id
        WHERE (b.user_id = ? OR bm.user_id = ?) {$archiveFilter}
        ORDER BY b.position ASC, b.created_at ASC, b.id ASC
    ");
    $boardsStmt->execute([$userId, $userId, $userId, $userId]);
    $boardsData = $boardsStmt->fetchAll();

    if (empty($boardsData)) {
        return [];
    }

    $boardIds = array_column($boardsData, 'id');
    $inPlaceholder = implode(',', array_fill(0, count($boardIds), '?'));

    // Fetch pending access requests for owned boards
    $requestsByBoard = [];
    try {
        $reqStmt = $pdo->prepare("
            SELECT bar.id, bar.board_id, bar.user_id, bar.message, bar.created_at,
                   u.name AS user_name, u.email AS user_email
            FROM board_access_requests bar
            INNER JOIN users u ON u.id = bar.user_id
            WHERE bar.board_id IN ($inPlaceholder) AND bar.status = 'pending'
            ORDER BY bar.created_at DESC
        ");
        $reqStmt->execute($boardIds);
        foreach ($reqStmt->fetchAll() as $reqRow) {
            $requestsByBoard[$reqRow['board_id']][] = [
                'id' => (int) $reqRow['id'],
                'user_id' => (int) $reqRow['user_id'],
                'user_name' => $reqRow['user_name'] ?: explode('@', $reqRow['user_email'])[0],
                'user_email' => $reqRow['user_email'],
                'message' => $reqRow['message'],
                'created_at' => $reqRow['created_at']
            ];
        }
    } catch (Exception $e) {}

    // Fetch members for each board
    $memStmt = $pdo->prepare("
        SELECT bm.board_id, u.id, u.email, u.name, bm.role
        FROM board_members bm
        INNER JOIN users u ON u.id = bm.user_id
        WHERE bm.board_id IN ($inPlaceholder)
        ORDER BY bm.created_at ASC
    ");
    $memStmt->execute($boardIds);
    $membersByBoard = [];
    foreach ($memStmt->fetchAll() as $mRow) {
        $membersByBoard[$mRow['board_id']][] = [
            'id' => (int) $mRow['id'],
            'email' => $mRow['email'],
            'name' => $mRow['name'] ?: explode('@', $mRow['email'])[0],
            'role' => $mRow['role'],
            'is_owner' => false
        ];
    }

    // Fetch lists
    $listStmt = $pdo->prepare("
        SELECT id, board_id, name, color, position, created_at
        FROM board_lists
        WHERE board_id IN ($inPlaceholder)
        ORDER BY position ASC, created_at ASC, id ASC
    ");
    $listStmt->execute($boardIds);
    $listsByBoard = [];
    foreach ($listStmt->fetchAll() as $listRow) {
        $listsByBoard[$listRow['board_id']][] = [
            'id' => $listRow['id'],
            'name' => $listRow['name'],
            'color' => $listRow['color'] ?? null,
            'position' => (int) $listRow['position'],
            'tasks' => []
        ];
    }

    // Fetch tasks with subtask counts, comment counts, attachment counts
    $taskStmt = $pdo->prepare("
        SELECT t.id, t.list_id, t.title, t.description, t.start_date, t.due_date, t.due_time,
               t.priority, t.completed, t.position, t.assigned_to, t.recurrence, t.recurrence_interval,
               t.is_archived, t.completed_at, t.created_at,
               au.email AS assigned_email, au.name AS assigned_name,
               (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id) AS subtask_count,
               (SELECT COUNT(*) FROM subtasks WHERE task_id = t.id AND completed = 1) AS subtask_done_count,
               (SELECT COUNT(*) FROM comments WHERE task_id = t.id) AS comment_count,
               (SELECT COUNT(*) FROM attachments WHERE task_id = t.id) AS attachment_count
        FROM tasks t
        INNER JOIN board_lists l ON l.id = t.list_id
        LEFT JOIN users au ON au.id = t.assigned_to
        WHERE l.board_id IN ($inPlaceholder) AND t.is_archived = 0
        ORDER BY t.position ASC, t.created_at ASC, t.id ASC
    ");
    $taskStmt->execute($boardIds);
    $allTasks = $taskStmt->fetchAll();

    // Fetch all task_labels in bulk
    $taskIds = array_column($allTasks, 'id');
    $labelsByTask = [];
    if (!empty($taskIds)) {
        $taskPlaceholder = implode(',', array_fill(0, count($taskIds), '?'));
        $tlStmt = $pdo->prepare("
            SELECT tl.task_id, l.id, l.name, l.color
            FROM task_labels tl
            INNER JOIN labels l ON l.id = tl.label_id
            WHERE tl.task_id IN ($taskPlaceholder)
            ORDER BY l.name ASC
        ");
        $tlStmt->execute($taskIds);
        foreach ($tlStmt->fetchAll() as $tl) {
            $labelsByTask[$tl['task_id']][] = [
                'id' => $tl['id'],
                'name' => $tl['name'],
                'color' => $tl['color']
            ];
        }
    }

    $tasksByList = [];
    foreach ($allTasks as $taskRow) {
        $tasksByList[$taskRow['list_id']][] = [
            'id' => $taskRow['id'],
            'title' => $taskRow['title'],
            'description' => $taskRow['description'] ?? '',
            'start_date' => $taskRow['start_date'],
            'due_date' => $taskRow['due_date'],
            'due_time' => $taskRow['due_time'],
            'priority' => $taskRow['priority'],
            'completed' => (bool) $taskRow['completed'],
            'position' => (int) $taskRow['position'],
            'assigned_to' => $taskRow['assigned_to'] ? (int)$taskRow['assigned_to'] : null,
            'assigned_name' => $taskRow['assigned_name'] ?: ($taskRow['assigned_email'] ? explode('@', $taskRow['assigned_email'])[0] : null),
            'assigned_email' => $taskRow['assigned_email'] ?: null,
            'recurrence' => $taskRow['recurrence'] ?? 'none',
            'recurrence_interval' => (int) ($taskRow['recurrence_interval'] ?? 1),
            'subtask_count' => (int) $taskRow['subtask_count'],
            'subtask_done_count' => (int) $taskRow['subtask_done_count'],
            'comment_count' => (int) $taskRow['comment_count'],
            'attachment_count' => (int) $taskRow['attachment_count'],
            'labels' => $labelsByTask[$taskRow['id']] ?? [],
            'created_at' => $taskRow['created_at'],
            'completed_at' => $taskRow['completed_at'],
        ];
    }

    // Fetch board-level labels
    $boardLabelsStmt = $pdo->prepare("
        SELECT id, board_id, name, color
        FROM labels
        WHERE board_id IN ($inPlaceholder) OR (board_id IS NULL AND user_id = ?)
        ORDER BY name ASC
    ");
    $boardLabelsStmt->execute(array_merge($boardIds, [$userId]));
    $labelsByBoard = [];
    foreach ($boardLabelsStmt->fetchAll() as $lbl) {
        $labelsByBoard[$lbl['board_id'] ?? 'global'][] = [
            'id' => $lbl['id'],
            'name' => $lbl['name'],
            'color' => $lbl['color']
        ];
    }

    $boards = [];
    foreach ($boardsData as $boardRow) {
        $boardLists = [];
        foreach ($listsByBoard[$boardRow['id']] ?? [] as $listRow) {
            $listRow['tasks'] = $tasksByList[$listRow['id']] ?? [];
            $boardLists[] = $listRow;
        }

        $boardMembers = [];
        $ownerId = (int) $boardRow['owner_id'];
        $boardMembers[] = [
            'id' => $ownerId,
            'email' => $boardRow['owner_email'],
            'name' => $boardRow['owner_name'] ?: explode('@', $boardRow['owner_email'])[0],
            'role' => 'owner',
            'is_owner' => true
        ];

        foreach ($membersByBoard[$boardRow['id']] ?? [] as $m) {
            if ($m['id'] !== $ownerId) {
                $boardMembers[] = $m;
            }
        }

        $bLabels = array_merge(
            $labelsByBoard[$boardRow['id']] ?? [],
            $labelsByBoard['global'] ?? []
        );

        $boards[] = [
            'id' => $boardRow['id'],
            'name' => $boardRow['name'],
            'color' => $boardRow['color'] ?? '#4f8ef7',
            'icon' => $boardRow['icon'] ?? 'grid',
            'is_archived' => (bool) $boardRow['is_archived'],
            'general_access' => $boardRow['general_access'] ?? 'anyone_with_link',
            'link_role' => $boardRow['link_role'] ?? 'editor',
            'access_requests' => $requestsByBoard[$boardRow['id']] ?? [],
            'owner_id' => $ownerId,
            'owner_email' => $boardRow['owner_email'],
            'owner_name' => $boardRow['owner_name'] ?: explode('@', $boardRow['owner_email'])[0],
            'is_owner' => ($ownerId === $userId),
            'user_role' => $boardRow['user_role'],
            'created_at' => $boardRow['created_at'],
            'members' => $boardMembers,
            'lists' => $boardLists,
            'labels' => $bLabels,
        ];
    }

    return $boards;
}

function nextPosition(PDO $pdo, string $table, string $column, string $whereColumn, string $whereValue): int
{
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(position), 0) + 1 FROM {$table} WHERE {$whereColumn} = ?");
    $stmt->execute([$whereValue]);
    return (int) $stmt->fetchColumn();
}

// Support both JSON input and multipart/form-data
$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?: [];
$action = $_POST['action'] ?? ($body['action'] ?? '');

try {
    $pdo = DB::get();
    ensureSchema($pdo);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db error', 'detail' => $e->getMessage()]);
    exit;
}

try {
    switch ($action) {

    case 'getBoards': {
        $includeArchived = !empty($body['includeArchived']);
        echo json_encode(['boards' => fetchBoards($pdo, $userId, $includeArchived)]);
        break;
    }

    case 'getDashboard': {
        // Collect dashboard metrics across user's accessible boards
        $boards = fetchBoards($pdo, $userId, false);
        $boardIds = array_column($boards, 'id');

        $activeTasksCount = 0;
        $dueTodayCount = 0;
        $completedTodayCount = 0;
        $overdueCount = 0;
        $totalTasks = 0;
        $completedTotal = 0;

        $upcomingTasks = [];
        $today = date('Y-m-d');
        $sevenDays = date('Y-m-d', strtotime('+7 days'));

        if (!empty($boardIds)) {
            $inPlaceholder = implode(',', array_fill(0, count($boardIds), '?'));

            // 1. Overall counts
            $stmt = $pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN t.completed = 0 AND (t.recurrence = 'none' OR t.due_date IS NULL OR t.due_date <= CURDATE()) THEN 1 END) AS active_count,
                    COUNT(CASE WHEN t.completed = 0 AND t.due_date = CURDATE() THEN 1 END) AS due_today_count,
                    COUNT(CASE WHEN t.completed = 1 AND DATE(t.completed_at) = CURDATE() THEN 1 END) AS completed_today_count,
                    COUNT(CASE WHEN t.completed = 0 AND t.due_date < CURDATE() THEN 1 END) AS overdue_count,
                    COUNT(CASE WHEN t.recurrence = 'none' OR t.due_date IS NULL OR t.due_date <= CURDATE() THEN 1 END) AS total_count,
                    COUNT(CASE WHEN t.completed = 1 THEN 1 END) AS completed_count
                FROM tasks t
                INNER JOIN board_lists l ON l.id = t.list_id
                WHERE l.board_id IN ($inPlaceholder) AND t.is_archived = 0
            ");
            $stmt->execute($boardIds);
            $counts = $stmt->fetch();
            if ($counts) {
                $activeTasksCount = (int) $counts['active_count'];
                $dueTodayCount = (int) $counts['due_today_count'];
                $completedTodayCount = (int) $counts['completed_today_count'];
                $overdueCount = (int) $counts['overdue_count'];
                $totalTasks = (int) $counts['total_count'];
                $completedTotal = (int) $counts['completed_count'];
            }

            // 2. Upcoming tasks
            $stmtUpcoming = $pdo->prepare("
                SELECT t.id, t.title, t.due_date, t.due_time, t.priority, t.completed,
                       l.name AS list_name, b.id AS board_id, b.name AS board_name, b.color AS board_color,
                       au.name AS assigned_name, au.email AS assigned_email
                FROM tasks t
                INNER JOIN board_lists l ON l.id = t.list_id
                INNER JOIN boards b ON b.id = l.board_id
                LEFT JOIN users au ON au.id = t.assigned_to
                WHERE l.board_id IN ($inPlaceholder) AND t.is_archived = 0
                  AND (
                    (t.completed = 0 AND t.due_date IS NOT NULL AND t.due_date <= ?)
                    OR (t.completed = 0 AND t.due_date < CURDATE())
                  )
                ORDER BY t.due_date ASC, t.due_time ASC, t.priority DESC
                LIMIT 15
            ");
            $stmtUpcoming->execute(array_merge($boardIds, [$sevenDays]));
            $upcomingTasks = $stmtUpcoming->fetchAll();

            // 3. Recent activity feed (strictly for this user - other users cannot see each other's activity)
            $stmtAct = $pdo->prepare("
                SELECT a.id, a.board_id, a.task_id, a.action, a.details, a.created_at,
                       u.name AS user_name, u.email AS user_email,
                       b.name AS board_name, t.title AS task_title
                FROM activity_logs a
                LEFT JOIN users u ON u.id = a.user_id
                LEFT JOIN boards b ON b.id = a.board_id
                LEFT JOIN tasks t ON t.id = a.task_id
                WHERE a.user_id = ? AND (a.board_id IN ($inPlaceholder) OR a.board_id IS NULL)
                ORDER BY a.created_at DESC
                LIMIT 15
            ");
            $stmtAct->execute(array_merge([$userId], $boardIds));
            $recentActivity = $stmtAct->fetchAll();
        } else {
            $recentActivity = [];
        }

        $progressPercent = $totalTasks > 0 ? round(($completedTotal / $totalTasks) * 100) : 0;

        echo json_encode([
            'stats' => [
                'active' => $activeTasksCount,
                'dueToday' => $dueTodayCount,
                'completedToday' => $completedTodayCount,
                'overdue' => $overdueCount,
                'total' => $totalTasks,
                'progressPercent' => $progressPercent
            ],
            'upcomingTasks' => $upcomingTasks,
            'recentActivity' => $recentActivity,
            'user' => [
                'id' => $userId,
                'email' => $authUser['email'],
                'name' => $authUser['name'] ?? explode('@', $authUser['email'])[0]
            ]
        ]);
        break;
    }

    case 'getAllActivity': {
        $boards = fetchBoards($pdo, $userId, false);
        $boardIds = array_column($boards, 'id');

        if (empty($boardIds)) {
            $stmtAct = $pdo->prepare("
                SELECT a.id, a.board_id, a.task_id, a.action, a.details, a.created_at,
                       u.name AS user_name, u.email AS user_email,
                       b.name AS board_name, b.color AS board_color, t.title AS task_title
                FROM activity_logs a
                LEFT JOIN users u ON u.id = a.user_id
                LEFT JOIN boards b ON b.id = a.board_id
                LEFT JOIN tasks t ON t.id = a.task_id
                WHERE a.user_id = ?
                ORDER BY a.created_at DESC
                LIMIT 100
            ");
            $stmtAct->execute([$userId]);
            $activity = $stmtAct->fetchAll();
            echo json_encode(['success' => true, 'activity' => $activity]);
            break;
        }

        $inPlaceholder = implode(',', array_fill(0, count($boardIds), '?'));
        $stmtAct = $pdo->prepare("
            SELECT a.id, a.board_id, a.task_id, a.action, a.details, a.created_at,
                   u.name AS user_name, u.email AS user_email,
                   b.name AS board_name, b.color AS board_color, t.title AS task_title
            FROM activity_logs a
            LEFT JOIN users u ON u.id = a.user_id
            LEFT JOIN boards b ON b.id = a.board_id
            LEFT JOIN tasks t ON t.id = a.task_id
            WHERE a.user_id = ? AND (a.board_id IN ($inPlaceholder) OR a.board_id IS NULL)
            ORDER BY a.created_at DESC
            LIMIT 100
        ");
        $stmtAct->execute(array_merge([$userId], $boardIds));
        $activity = $stmtAct->fetchAll();

        echo json_encode(['success' => true, 'activity' => $activity]);
        break;
    }

    case 'getAnalytics': {
        $boards = fetchBoards($pdo, $userId, false);
        $boardIds = array_column($boards, 'id');

        if (empty($boardIds)) {
            echo json_encode([
                'totalTasks' => 0,
                'completedTasks' => 0,
                'activeTasks' => 0,
                'overdueTasks' => 0,
                'completionRate' => 0,
                'priority' => ['high' => 0, 'medium' => 0, 'low' => 0],
                'weekly' => [],
                'boards' => []
            ]);
            break;
        }

        $inPlaceholder = implode(',', array_fill(0, count($boardIds), '?'));

        // General counts
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total,
                COUNT(CASE WHEN completed = 1 THEN 1 END) AS completed,
                COUNT(CASE WHEN completed = 0 THEN 1 END) AS active,
                COUNT(CASE WHEN completed = 0 AND due_date < CURDATE() THEN 1 END) AS overdue,
                COUNT(CASE WHEN priority = 'high' THEN 1 END) AS prio_high,
                COUNT(CASE WHEN priority = 'medium' THEN 1 END) AS prio_med,
                COUNT(CASE WHEN priority = 'low' THEN 1 END) AS prio_low
            FROM tasks t
            INNER JOIN board_lists l ON l.id = t.list_id
            WHERE l.board_id IN ($inPlaceholder) AND t.is_archived = 0
        ");
        $stmt->execute($boardIds);
        $row = $stmt->fetch();

        // Weekly completions (last 7 days)
        $weekly = [];
        for ($i = 6; $i >= 0; $i--) {
            $dayStr = date('Y-m-d', strtotime("-$i days"));
            $dayLabel = date('D', strtotime("-$i days"));
            $weekly[$dayStr] = ['date' => $dayStr, 'day' => $dayLabel, 'count' => 0];
        }

        $wStmt = $pdo->prepare("
            SELECT DATE(t.completed_at) AS d, COUNT(*) AS cnt
            FROM tasks t
            INNER JOIN board_lists l ON l.id = t.list_id
            WHERE l.board_id IN ($inPlaceholder) AND t.completed = 1 AND t.completed_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(t.completed_at)
        ");
        $wStmt->execute($boardIds);
        foreach ($wStmt->fetchAll() as $wRow) {
            if (isset($weekly[$wRow['d']])) {
                $weekly[$wRow['d']]['count'] = (int) $wRow['cnt'];
            }
        }

        // Board breakdown
        $bStats = [];
        foreach ($boards as $b) {
            $bTotal = 0;
            $bDone = 0;
            foreach ($b['lists'] as $l) {
                foreach ($l['tasks'] as $t) {
                    $bTotal++;
                    if ($t['completed']) $bDone++;
                }
            }
            $bStats[] = [
                'id' => $b['id'],
                'name' => $b['name'],
                'color' => $b['color'],
                'total' => $bTotal,
                'completed' => $bDone,
                'rate' => $bTotal > 0 ? round(($bDone / $bTotal) * 100) : 0
            ];
        }

        $total = (int) ($row['total'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);
        $rate = $total > 0 ? round(($completed / $total) * 100) : 0;

        echo json_encode([
            'totalTasks' => $total,
            'completedTasks' => $completed,
            'activeTasks' => (int) ($row['active'] ?? 0),
            'overdueTasks' => (int) ($row['overdue'] ?? 0),
            'completionRate' => $rate,
            'priority' => [
                'high' => (int) ($row['prio_high'] ?? 0),
                'medium' => (int) ($row['prio_med'] ?? 0),
                'low' => (int) ($row['prio_low'] ?? 0)
            ],
            'weekly' => array_values($weekly),
            'boards' => $bStats
        ]);
        break;
    }

    case 'addBoard': {
        $name = trim($body['name'] ?? '');
        if (!$name) { echo json_encode(['success' => false, 'error' => 'Board name is required']); break; }
        $color = $body['color'] ?? '#4f8ef7';
        $icon = $body['icon'] ?? 'grid';
        $newId = genId('board');

        $defaultColumns = [
            ['name' => 'Backlog', 'color' => '#8c959f'],
            ['name' => 'To Do', 'color' => '#4f8ef7'],
            ['name' => 'In Progress', 'color' => '#f59e0b'],
            ['name' => 'Review', 'color' => '#a855f7'],
            ['name' => 'Done', 'color' => '#22c55e'],
        ];

        $pdo->beginTransaction();
        try {
            $boardPos = nextPosition($pdo, 'boards', 'position', 'user_id', (string) $userId);
            $stmt = $pdo->prepare('INSERT INTO boards (id, user_id, name, color, icon, position, is_archived, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())');
            $stmt->execute([$newId, $userId, $name, $color, $icon, $boardPos]);

            // Add owner to board_members table
            $bmStmt = $pdo->prepare('INSERT INTO board_members (board_id, user_id, role, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE role = ?');
            $bmStmt->execute([$newId, $userId, 'owner', 'owner']);

            $listStmt = $pdo->prepare('INSERT INTO board_lists (id, board_id, name, color, position, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($defaultColumns as $idx => $col) {
                $listStmt->execute([genId('list'), $newId, $col['name'], $col['color'], $idx + 1]);
            }

            // Seed default board labels
            $labelStmt = $pdo->prepare('INSERT INTO labels (id, board_id, user_id, name, color, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $defaultLabels = [
                ['Bug', '#ef4444'],
                ['Feature', '#4f8ef7'],
                ['Urgent', '#f59e0b'],
                ['Design', '#a855f7'],
            ];
            foreach ($defaultLabels as $lbl) {
                $labelStmt->execute([genId('lbl'), $newId, $userId, $lbl[0], $lbl[1]]);
            }

            logActivity($pdo, $newId, null, $userId, 'board_created', "Created board '{$name}'");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Database error creating board: ' . $e->getMessage()]);
            break;
        }

        try {
            $boards = fetchBoards($pdo, $userId);
            echo json_encode(['success' => true, 'boards' => $boards, 'newBoardId' => $newId]);
        } catch (Throwable $e) {
            echo json_encode(['success' => false, 'error' => 'Failed to load boards: ' . $e->getMessage()]);
        }
        break;
    }

    case 'renameBoard':
    case 'updateBoardMeta': {
        $boardId = $body['boardId'] ?? '';
        $name = trim($body['name'] ?? '');
        $color = $body['color'] ?? null;
        $icon = $body['icon'] ?? null;

        if (!$name) { echo json_encode(['success' => false, 'error' => 'Name cannot be empty']); break; }
        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $fields = ['name = ?'];
        $params = [$name];
        if ($color) { $fields[] = 'color = ?'; $params[] = $color; }
        if ($icon) { $fields[] = 'icon = ?'; $params[] = $icon; }
        $params[] = $boardId;

        $stmt = $pdo->prepare('UPDATE boards SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);

        logActivity($pdo, $boardId, null, $userId, 'board_updated', "Updated board details");
        $actorName = getActorName($pdo, $userId);
        notifyBoardMembers($pdo, $boardId, $userId, 'board_updated', "Board updated: '{$name}'", "{$actorName} updated board details", 'board', $boardId);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'duplicateBoard': {
        $boardId = $body['boardId'] ?? '';
        if (!getBoardAccess($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }

        $pdo->beginTransaction();
        try {
            // 1. Get original board
            $stmt = $pdo->prepare('SELECT * FROM boards WHERE id = ?');
            $stmt->execute([$boardId]);
            $origBoard = $stmt->fetch();
            if (!$origBoard) throw new Exception('Board not found');

            $newBoardId = genId('board');
            $newName = $origBoard['name'] . ' (Copy)';
            $boardPos = nextPosition($pdo, 'boards', 'position', 'user_id', (string) $userId);

            $stmt = $pdo->prepare('INSERT INTO boards (id, user_id, name, color, icon, position, is_archived, created_at) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())');
            $stmt->execute([$newBoardId, $userId, $newName, $origBoard['color'], $origBoard['icon'], $boardPos]);

            // 2. Clone labels and map old label ID -> new label ID
            $lblMap = [];
            $lblStmt = $pdo->prepare('SELECT * FROM labels WHERE board_id = ?');
            $lblStmt->execute([$boardId]);
            $insLbl = $pdo->prepare('INSERT INTO labels (id, board_id, user_id, name, color, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($lblStmt->fetchAll() as $oldLbl) {
                $newLblId = genId('lbl');
                $insLbl->execute([$newLblId, $newBoardId, $userId, $oldLbl['name'], $oldLbl['color']]);
                $lblMap[$oldLbl['id']] = $newLblId;
            }

            // 3. Clone lists & tasks
            $listStmt = $pdo->prepare('SELECT * FROM board_lists WHERE board_id = ? ORDER BY position ASC');
            $listStmt->execute([$boardId]);
            $insList = $pdo->prepare('INSERT INTO board_lists (id, board_id, name, color, position, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $insTask = $pdo->prepare('INSERT INTO tasks (id, list_id, title, description, start_date, due_date, due_time, priority, completed, position, assigned_to, recurrence, recurrence_interval, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $insSubtask = $pdo->prepare('INSERT INTO subtasks (id, task_id, title, completed, position, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $insTaskLbl = $pdo->prepare('INSERT INTO task_labels (task_id, label_id) VALUES (?, ?)');

            foreach ($listStmt->fetchAll() as $origList) {
                $newListId = genId('list');
                $insList->execute([$newListId, $newBoardId, $origList['name'], $origList['color'], $origList['position']]);

                $taskStmt = $pdo->prepare('SELECT * FROM tasks WHERE list_id = ? AND is_archived = 0 ORDER BY position ASC');
                $taskStmt->execute([$origList['id']]);
                foreach ($taskStmt->fetchAll() as $origTask) {
                    $newTaskId = genId('task');
                    $insTask->execute([
                        $newTaskId, $newListId, $origTask['title'], $origTask['description'],
                        $origTask['start_date'], $origTask['due_date'], $origTask['due_time'],
                        $origTask['priority'], $origTask['completed'], $origTask['position'],
                        $origTask['assigned_to'], $origTask['recurrence'], $origTask['recurrence_interval']
                    ]);

                    // Clone subtasks
                    $subStmt = $pdo->prepare('SELECT * FROM subtasks WHERE task_id = ? ORDER BY position ASC');
                    $subStmt->execute([$origTask['id']]);
                    foreach ($subStmt->fetchAll() as $origSub) {
                        $insSubtask->execute([genId('sub'), $newTaskId, $origSub['title'], $origSub['completed'], $origSub['position']]);
                    }

                    // Clone task labels
                    $tlStmt = $pdo->prepare('SELECT label_id FROM task_labels WHERE task_id = ?');
                    $tlStmt->execute([$origTask['id']]);
                    foreach ($tlStmt->fetchAll() as $origTl) {
                        if (isset($lblMap[$origTl['label_id']])) {
                            $insTaskLbl->execute([$newTaskId, $lblMap[$origTl['label_id']]]);
                        }
                    }
                }
            }

            logActivity($pdo, $newBoardId, null, $userId, 'board_duplicated', "Duplicated from '{$origBoard['name']}'");
            $pdo->commit();
            echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId), 'newBoardId' => $newBoardId]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    }

    case 'archiveBoard': {
        $boardId = $body['boardId'] ?? '';
        $role = getBoardAccess($pdo, $userId, $boardId);
        if ($role !== 'owner') {
            echo json_encode(['success' => false, 'error' => 'Only the owner can archive this board']);
            break;
        }

        $stmt = $pdo->prepare('UPDATE boards SET is_archived = CASE WHEN is_archived = 1 THEN 0 ELSE 1 END WHERE id = ?');
        $stmt->execute([$boardId]);

        logActivity($pdo, $boardId, null, $userId, 'board_archived', "Toggled board archived state");
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'deleteBoard': {
        $boardId = $body['boardId'] ?? '';
        $role = getBoardAccess($pdo, $userId, $boardId);
        if (!$role) { echo json_encode(['success' => false, 'error' => 'Board not found']); break; }

        if ($role === 'owner') {
            $stmt = $pdo->prepare('DELETE FROM boards WHERE id = ?');
            $stmt->execute([$boardId]);
        } else {
            $stmt = $pdo->prepare('DELETE FROM board_members WHERE board_id = ? AND user_id = ?');
            $stmt->execute([$boardId, $userId]);
        }
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'addList': {
        $boardId = $body['boardId'] ?? '';
        $name = trim($body['name'] ?? '');
        $color = $body['color'] ?? null;
        if (!$name) { echo json_encode(['success' => false, 'error' => 'List name required']); break; }
        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $listId = genId('list');
        $position = nextPosition($pdo, 'board_lists', 'position', 'board_id', $boardId);
        $stmt = $pdo->prepare('INSERT INTO board_lists (id, board_id, name, color, position, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$listId, $boardId, $name, $color, $position]);

        logActivity($pdo, $boardId, null, $userId, 'list_created', "Created list '{$name}'");
        $actorName = getActorName($pdo, $userId);
        $boardName = getBoardName($pdo, $boardId);
        notifyBoardMembers($pdo, $boardId, $userId, 'list_created', "New column in '{$boardName}'", "{$actorName} added column '{$name}'", 'board', $boardId);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'renameList': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $name = trim($body['name'] ?? '');
        $color = $body['color'] ?? null;
        if (!$name) { echo json_encode(['success' => false]); break; }
        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $stmt = $pdo->prepare('UPDATE board_lists SET name = ?, color = COALESCE(?, color) WHERE id = ? AND board_id = ?');
        $stmt->execute([$name, $color, $listId, $boardId]);

        logActivity($pdo, $boardId, null, $userId, 'list_renamed', "Renamed column to '{$name}'");
        $actorName = getActorName($pdo, $userId);
        $boardName = getBoardName($pdo, $boardId);
        notifyBoardMembers($pdo, $boardId, $userId, 'list_renamed', "Column renamed in '{$boardName}'", "{$actorName} renamed column to '{$name}'", 'board', $boardId);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'deleteList': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $colNameStmt = $pdo->prepare('SELECT name FROM board_lists WHERE id = ? AND board_id = ?');
        $colNameStmt->execute([$listId, $boardId]);
        $listName = $colNameStmt->fetchColumn() ?: 'column';

        $stmt = $pdo->prepare('DELETE FROM board_lists WHERE id = ? AND board_id = ?');
        $stmt->execute([$listId, $boardId]);

        logActivity($pdo, $boardId, null, $userId, 'list_deleted', "Deleted column '{$listName}'");
        $actorName = getActorName($pdo, $userId);
        $boardName = getBoardName($pdo, $boardId);
        notifyBoardMembers($pdo, $boardId, $userId, 'list_deleted', "Column removed from '{$boardName}'", "{$actorName} deleted column '{$listName}'", 'board', $boardId);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'addTask': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $taskData = $body['taskData'] ?? [];
        $title = trim($taskData['title'] ?? '');
        if ($title === '') { echo json_encode(['success' => false, 'error' => 'Title is required']); break; }
        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $assignedTo = !empty($taskData['assigned_to']) ? (int) $taskData['assigned_to'] : null;
        $recurrence = in_array(($taskData['recurrence'] ?? 'none'), ['none','daily','weekly','monthly','custom'], true) ? $taskData['recurrence'] : 'none';
        $recurrenceInterval = max(1, (int) ($taskData['recurrence_interval'] ?? 1));
        $priority = in_array(($taskData['priority'] ?? 'medium'), ['low', 'medium', 'high'], true) ? $taskData['priority'] : 'medium';
        $newTaskId = genId('task');
        $position = nextPosition($pdo, 'tasks', 'position', 'list_id', $listId);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO tasks (id, list_id, title, description, start_date, due_date, due_time, priority, completed, position, assigned_to, recurrence, recurrence_interval, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, NOW())');
            $stmt->execute([
                $newTaskId,
                $listId,
                $title,
                trim($taskData['description'] ?? ''),
                $taskData['start_date'] ?: null,
                $taskData['due_date'] ?: null,
                $taskData['due_time'] ?: null,
                $priority,
                $position,
                $assignedTo,
                $recurrence,
                $recurrenceInterval
            ]);

            // Save labels if provided
            if (!empty($taskData['label_ids']) && is_array($taskData['label_ids'])) {
                $lblIns = $pdo->prepare('INSERT IGNORE INTO task_labels (task_id, label_id) VALUES (?, ?)');
                foreach ($taskData['label_ids'] as $lid) {
                    $lblIns->execute([$newTaskId, $lid]);
                }
            }

            // Save subtasks if provided
            if (!empty($taskData['subtasks']) && is_array($taskData['subtasks'])) {
                $subIns = $pdo->prepare('INSERT INTO subtasks (id, task_id, title, completed, position, created_at) VALUES (?, ?, ?, 0, ?, NOW())');
                foreach ($taskData['subtasks'] as $idx => $st) {
                    $stTitle = trim(is_array($st) ? ($st['title'] ?? '') : (string)$st);
                    if ($stTitle) {
                        $subIns->execute([genId('sub'), $newTaskId, $stTitle, $idx + 1]);
                    }
                }
            }

            logActivity($pdo, $boardId, $newTaskId, $userId, 'task_created', "Created task '{$title}'");

            if ($assignedTo && $assignedTo !== $userId) {
                createNotification($pdo, $assignedTo, $userId, 'assigned', "Assigned to '{$title}'", "You were assigned to task '{$title}'", 'task', $newTaskId);
            }

            $actorName = getActorName($pdo, $userId);
            $boardName = getBoardName($pdo, $boardId);
            notifyBoardMembers($pdo, $boardId, $userId, 'task_created', "New task in '{$boardName}'", "{$actorName} added task '{$title}'", 'task', $newTaskId);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Failed to create task: ' . $e->getMessage()]);
            break;
        }

        echo json_encode(['success' => true, 'newTaskId' => $newTaskId, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'updateTask': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $taskId = $body['taskId'] ?? '';
        $taskData = $body['taskData'] ?? [];
        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $title = trim($taskData['title'] ?? '');
        if ($title === '') { echo json_encode(['success' => false, 'error' => 'Title required']); break; }

        $priority = in_array(($taskData['priority'] ?? 'medium'), ['low', 'medium', 'high'], true) ? $taskData['priority'] : 'medium';
        $recurrence = in_array(($taskData['recurrence'] ?? 'none'), ['none','daily','weekly','monthly','custom'], true) ? $taskData['recurrence'] : 'none';
        $recurrenceInterval = max(1, (int) ($taskData['recurrence_interval'] ?? 1));
        $assignedTo = !empty($taskData['assigned_to']) ? (int) $taskData['assigned_to'] : null;

        // Fetch old assigned_to to check if changed
        $stmtOld = $pdo->prepare('SELECT assigned_to, title FROM tasks WHERE id = ?');
        $stmtOld->execute([$taskId]);
        $oldRow = $stmtOld->fetch();
        $oldAssignee = $oldRow ? (int)$oldRow['assigned_to'] : null;

        $stmt = $pdo->prepare(
            'UPDATE tasks t
             INNER JOIN board_lists l ON l.id = t.list_id
             SET t.list_id = ?, t.title = ?, t.description = ?, t.start_date = ?, t.due_date = ?, t.due_time = ?,
                 t.priority = ?, t.assigned_to = ?, t.recurrence = ?, t.recurrence_interval = ?
             WHERE t.id = ? AND l.board_id = ?'
        );
        $stmt->execute([
            $listId,
            $title,
            trim($taskData['description'] ?? ''),
            $taskData['start_date'] ?: null,
            $taskData['due_date'] ?: null,
            $taskData['due_time'] ?: null,
            $priority,
            $assignedTo,
            $recurrence,
            $recurrenceInterval,
            $taskId,
            $boardId,
        ]);

        if (isset($taskData['label_ids']) && is_array($taskData['label_ids'])) {
            $pdo->prepare('DELETE FROM task_labels WHERE task_id = ?')->execute([$taskId]);
            $lblIns = $pdo->prepare('INSERT INTO task_labels (task_id, label_id) VALUES (?, ?)');
            foreach ($taskData['label_ids'] as $lid) {
                $lblIns->execute([$taskId, $lid]);
            }
        }

        if ($assignedTo && $assignedTo !== $oldAssignee && $assignedTo !== $userId) {
            createNotification($pdo, $assignedTo, $userId, 'assigned', "Assigned to '{$title}'", "You were assigned to task '{$title}'", 'task', $taskId);
        }

        logActivity($pdo, $boardId, $taskId, $userId, 'task_updated', "Updated task '{$title}'");
        $actorName = getActorName($pdo, $userId);
        $boardName = getBoardName($pdo, $boardId);
        notifyBoardMembers($pdo, $boardId, $userId, 'task_updated', "Task updated in '{$boardName}'", "{$actorName} updated '{$title}'", 'task', $taskId);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'deleteTask': {
        $boardId = $body['boardId'] ?? '';
        $taskId = $body['taskId'] ?? '';
        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $taskTitleStmt = $pdo->prepare('SELECT title FROM tasks WHERE id = ?');
        $taskTitleStmt->execute([$taskId]);
        $taskTitle = $taskTitleStmt->fetchColumn() ?: 'task';

        // Delete any attachment files physically
        $attStmt = $pdo->prepare('SELECT filename FROM attachments WHERE task_id = ?');
        $attStmt->execute([$taskId]);
        foreach ($attStmt->fetchAll() as $att) {
            $path = __DIR__ . '/uploads/tasks/' . $att['filename'];
            if (file_exists($path)) @unlink($path);
        }

        $stmt = $pdo->prepare(
            'DELETE t FROM tasks t
             INNER JOIN board_lists l ON l.id = t.list_id
             WHERE t.id = ? AND l.board_id = ?'
        );
        $stmt->execute([$taskId, $boardId]);

        logActivity($pdo, $boardId, null, $userId, 'task_deleted', "Deleted task");
        $actorName = getActorName($pdo, $userId);
        $boardName = getBoardName($pdo, $boardId);
        notifyBoardMembers($pdo, $boardId, $userId, 'task_deleted', "Task deleted from '{$boardName}'", "{$actorName} deleted task '{$taskTitle}'", 'board', $boardId);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'toggleTask': {
        $boardId = $body['boardId'] ?? '';
        $taskId = $body['taskId'] ?? '';
        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        // Fetch task info
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) { echo json_encode(['success' => false, 'error' => 'Task not found']); break; }

        $newCompleted = $task['completed'] ? 0 : 1;
        $completedAt = $newCompleted ? date('Y-m-d H:i:s') : null;

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare('UPDATE tasks SET completed = ?, completed_at = ? WHERE id = ?');
            $upd->execute([$newCompleted, $completedAt, $taskId]);

            // Recurring task logic: if completing a task with recurrence, generate next occurrence
            if ($newCompleted === 1 && $task['recurrence'] && $task['recurrence'] !== 'none') {
                $currentDue = $task['due_date'] ? new DateTime($task['due_date']) : new DateTime('today');
                $interval = max(1, (int) $task['recurrence_interval']);

                switch ($task['recurrence']) {
                    case 'daily':
                        $currentDue->modify("+{$interval} days");
                        break;
                    case 'weekly':
                        $currentDue->modify("+{$interval} weeks");
                        break;
                    case 'monthly':
                        $currentDue->modify("+{$interval} months");
                        break;
                    case 'custom':
                        $currentDue->modify("+{$interval} days");
                        break;
                }
                $nextDueDate = $currentDue->format('Y-m-d');

                // Check if an uncompleted next occurrence already exists on/after nextDueDate
                $chkNext = $pdo->prepare('SELECT id FROM tasks WHERE list_id = ? AND title = ? AND recurrence = ? AND completed = 0 AND due_date >= ? AND is_archived = 0 LIMIT 1');
                $chkNext->execute([$task['list_id'], $task['title'], $task['recurrence'], $nextDueDate]);
                $existingNextId = $chkNext->fetchColumn();

                if (!$existingNextId) {
                    $nextTaskId = genId('task');
                    $nextPos = nextPosition($pdo, 'tasks', 'position', 'list_id', $task['list_id']);

                    // Insert recurring next occurrence
                    $insRec = $pdo->prepare('INSERT INTO tasks (id, list_id, title, description, start_date, due_date, due_time, priority, completed, position, assigned_to, recurrence, recurrence_interval, created_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?, 0, ?, ?, ?, ?, NOW())');
                    $insRec->execute([
                        $nextTaskId,
                        $task['list_id'],
                        $task['title'],
                        $task['description'],
                        $nextDueDate,
                        $task['due_time'],
                        $task['priority'],
                        $nextPos,
                        $task['assigned_to'],
                        $task['recurrence'],
                        $interval
                    ]);

                    // Copy labels
                    $tlStmt = $pdo->prepare('SELECT label_id FROM task_labels WHERE task_id = ?');
                    $tlStmt->execute([$taskId]);
                    $insTl = $pdo->prepare('INSERT INTO task_labels (task_id, label_id) VALUES (?, ?)');
                    foreach ($tlStmt->fetchAll() as $tl) {
                        $insTl->execute([$nextTaskId, $tl['label_id']]);
                    }

                    // Copy subtasks (reset completed = 0)
                    $subStmt = $pdo->prepare('SELECT title, position FROM subtasks WHERE task_id = ? ORDER BY position ASC');
                    $subStmt->execute([$taskId]);
                    $insSub = $pdo->prepare('INSERT INTO subtasks (id, task_id, title, completed, position, created_at) VALUES (?, ?, ?, 0, ?, NOW())');
                    foreach ($subStmt->fetchAll() as $s) {
                        $insSub->execute([genId('sub'), $nextTaskId, $s['title'], $s['position']]);
                    }

                    logActivity($pdo, $boardId, $nextTaskId, $userId, 'recurring_renewed', "Next occurrence scheduled for {$nextDueDate}");
                }
            } elseif ($newCompleted === 0 && $task['recurrence'] && $task['recurrence'] !== 'none') {
                // If reopening a recurring task, remove any uncompleted future occurrence spawned from it
                $cleanDate = $task['due_date'] ?: date('Y-m-d');
                $delFuture = $pdo->prepare('DELETE FROM tasks WHERE list_id = ? AND title = ? AND recurrence = ? AND completed = 0 AND due_date > ? AND is_archived = 0');
                $delFuture->execute([$task['list_id'], $task['title'], $task['recurrence'], $cleanDate]);
            }

            logActivity($pdo, $boardId, $taskId, $userId, $newCompleted ? 'task_completed' : 'task_reopened', $task['title']);
            $actorName = getActorName($pdo, $userId);
            $boardName = getBoardName($pdo, $boardId);
            $statusStr = $newCompleted ? 'completed' : 'reopened';
            notifyBoardMembers($pdo, $boardId, $userId, $newCompleted ? 'task_completed' : 'task_reopened', "Task {$statusStr} in '{$boardName}'", "{$actorName} marked '{$task['title']}' as {$statusStr}", 'task', $taskId);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Failed to update task: ' . $e->getMessage()]);
            break;
        }

        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'moveTask': {
        $boardId = $body['boardId'] ?? '';
        $taskId = $body['taskId'] ?? '';
        $targetListId = $body['targetListId'] ?? '';
        $targetIndex = isset($body['targetIndex']) ? (int) $body['targetIndex'] : 0;

        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $stmt = $pdo->prepare('SELECT id FROM board_lists WHERE id = ? AND board_id = ?');
        $stmt->execute([$targetListId, $boardId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Invalid target list']);
            break;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM tasks WHERE list_id = ? AND id != ? ORDER BY position ASC, id ASC');
            $stmt->execute([$targetListId, $taskId]);
            $siblingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($targetIndex < 0) $targetIndex = 0;
            if ($targetIndex > count($siblingIds)) $targetIndex = count($siblingIds);
            array_splice($siblingIds, $targetIndex, 0, [$taskId]);

            $updStmt = $pdo->prepare('UPDATE tasks SET list_id = ?, position = ? WHERE id = ?');
            foreach ($siblingIds as $pos => $sId) {
                $updStmt->execute([$targetListId, $pos + 1, $sId]);
            }

            $stmtInfo = $pdo->prepare('SELECT t.title, l.name AS list_name, b.name AS board_name FROM tasks t INNER JOIN board_lists l ON l.id = ? INNER JOIN boards b ON b.id = ? WHERE t.id = ?');
            $stmtInfo->execute([$targetListId, $boardId, $taskId]);
            $rowInfo = $stmtInfo->fetch();
            $tTitle = $rowInfo['title'] ?? 'Task';
            $lName = $rowInfo['list_name'] ?? 'column';
            $bName = $rowInfo['board_name'] ?? 'board';
            $actorName = getActorName($pdo, $userId);

            logActivity($pdo, $boardId, $taskId, $userId, 'task_moved', "Moved '{$tTitle}' to '{$lName}'");
            notifyBoardMembers($pdo, $boardId, $userId, 'task_moved', "Task moved in '{$bName}'", "{$actorName} moved '{$tTitle}' to {$lName}", 'task', $taskId);

            $pdo->commit();
            echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    }

    case 'moveList': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $targetIndex = isset($body['targetIndex']) ? (int) $body['targetIndex'] : 0;

        if (!checkBoardWritePermission($pdo, $userId, $boardId)) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT id FROM board_lists WHERE board_id = ? AND id != ? ORDER BY position ASC, id ASC');
            $stmt->execute([$boardId, $listId]);
            $listIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if ($targetIndex < 0) $targetIndex = 0;
            if ($targetIndex > count($listIds)) $targetIndex = count($listIds);
            array_splice($listIds, $targetIndex, 0, [$listId]);

            $updStmt = $pdo->prepare('UPDATE board_lists SET position = ? WHERE id = ?');
            foreach ($listIds as $pos => $lId) {
                $updStmt->execute([$pos + 1, $lId]);
            }

            $colNameStmt = $pdo->prepare('SELECT name FROM board_lists WHERE id = ?');
            $colNameStmt->execute([$listId]);
            $lName = $colNameStmt->fetchColumn() ?: 'column';
            $actorName = getActorName($pdo, $userId);
            $boardName = getBoardName($pdo, $boardId);

            logActivity($pdo, $boardId, null, $userId, 'list_moved', "Reordered columns in '{$boardName}'");
            notifyBoardMembers($pdo, $boardId, $userId, 'list_moved', "Columns reordered in '{$boardName}'", "{$actorName} moved column '{$lName}'", 'board', $boardId);

            $pdo->commit();
            echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        break;
    }

    // ============================
    // Task Details & Drawer APIs
    // ============================
    case 'getTaskDetails': {
        $taskId = $body['taskId'] ?? '';
        $stmt = $pdo->prepare("
            SELECT t.*, l.name AS list_name, b.id AS board_id, b.name AS board_name, b.color AS board_color,
                   au.name AS assigned_name, au.email AS assigned_email
            FROM tasks t
            INNER JOIN board_lists l ON l.id = t.list_id
            INNER JOIN boards b ON b.id = l.board_id
            LEFT JOIN users au ON au.id = t.assigned_to
            WHERE t.id = ?
        ");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) {
            echo json_encode(['success' => false, 'error' => 'Task not found']);
            break;
        }

        if (!getBoardAccess($pdo, $userId, $task['board_id'])) {
            echo json_encode(['success' => false, 'error' => 'Access denied']);
            break;
        }

        // Subtasks
        $subStmt = $pdo->prepare('SELECT * FROM subtasks WHERE task_id = ? ORDER BY position ASC, created_at ASC');
        $subStmt->execute([$taskId]);
        $subtasks = $subStmt->fetchAll();

        // Labels
        $tlStmt = $pdo->prepare('SELECT l.id, l.name, l.color FROM task_labels tl INNER JOIN labels l ON l.id = tl.label_id WHERE tl.task_id = ?');
        $tlStmt->execute([$taskId]);
        $assignedLabels = $tlStmt->fetchAll();

        $bLabelsStmt = $pdo->prepare('SELECT id, name, color FROM labels WHERE board_id = ? OR (board_id IS NULL AND user_id = ?) ORDER BY name ASC');
        $bLabelsStmt->execute([$task['board_id'], $userId]);
        $allBoardLabels = $bLabelsStmt->fetchAll();

        if (empty($allBoardLabels)) {
            $defaultLabels = [
                ['Bug', '#ef4444'],
                ['Feature', '#4f8ef7'],
                ['Urgent', '#f59e0b'],
                ['Design', '#a855f7'],
                ['Task', '#10b981'],
            ];
            $insLbl = $pdo->prepare('INSERT INTO labels (id, board_id, user_id, name, color, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            foreach ($defaultLabels as $dl) {
                $insLbl->execute([genId('lbl'), $task['board_id'], $userId, $dl[0], $dl[1]]);
            }
            $bLabelsStmt->execute([$task['board_id'], $userId]);
            $allBoardLabels = $bLabelsStmt->fetchAll();
        }

        // Comments
        $cmtStmt = $pdo->prepare("
            SELECT c.id, c.content, c.created_at, c.user_id,
                   u.name AS user_name, u.email AS user_email
            FROM comments c
            INNER JOIN users u ON u.id = c.user_id
            WHERE c.task_id = ?
            ORDER BY c.created_at ASC
        ");
        $cmtStmt->execute([$taskId]);
        $comments = [];
        foreach ($cmtStmt->fetchAll() as $c) {
            $comments[] = [
                'id' => $c['id'],
                'content' => $c['content'],
                'created_at' => $c['created_at'],
                'user_id' => (int) $c['user_id'],
                'user_name' => $c['user_name'] ?: explode('@', $c['user_email'])[0],
                'user_email' => $c['user_email'],
                'can_delete' => ((int)$c['user_id'] === $userId || getBoardAccess($pdo, $userId, $task['board_id']) === 'owner')
            ];
        }

        // Attachments
        $attStmt = $pdo->prepare("
            SELECT a.id, a.filename, a.original_name, a.file_size, a.file_type, a.created_at, a.user_id,
                   u.name AS user_name, u.email AS user_email
            FROM attachments a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.task_id = ?
            ORDER BY a.created_at DESC
        ");
        $attStmt->execute([$taskId]);
        $attachments = [];
        foreach ($attStmt->fetchAll() as $att) {
            $attachments[] = [
                'id' => $att['id'],
                'filename' => $att['filename'],
                'original_name' => $att['original_name'],
                'file_size' => (int) $att['file_size'],
                'file_type' => $att['file_type'],
                'url' => 'uploads/tasks/' . rawurlencode($att['filename']),
                'created_at' => $att['created_at'],
                'user_name' => $att['user_name'] ?: explode('@', $att['user_email'])[0],
                'can_delete' => ((int)$att['user_id'] === $userId || getBoardAccess($pdo, $userId, $task['board_id']) === 'owner')
            ];
        }

        // Activity log
        $actStmt = $pdo->prepare("
            SELECT a.id, a.action, a.details, a.created_at,
                   u.name AS user_name, u.email AS user_email
            FROM activity_logs a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.task_id = ?
            ORDER BY a.created_at DESC
            LIMIT 20
        ");
        $actStmt->execute([$taskId]);
        $activity = $actStmt->fetchAll();

        echo json_encode([
            'success' => true,
            'task' => [
                'id' => $task['id'],
                'board_id' => $task['board_id'],
                'board_name' => $task['board_name'],
                'board_color' => $task['board_color'],
                'list_id' => $task['list_id'],
                'list_name' => $task['list_name'],
                'title' => $task['title'],
                'description' => $task['description'] ?? '',
                'start_date' => $task['start_date'],
                'due_date' => $task['due_date'],
                'due_time' => $task['due_time'],
                'priority' => $task['priority'],
                'completed' => (bool) $task['completed'],
                'assigned_to' => $task['assigned_to'] ? (int)$task['assigned_to'] : null,
                'assigned_name' => $task['assigned_name'] ?: ($task['assigned_email'] ? explode('@', $task['assigned_email'])[0] : null),
                'assigned_email' => $task['assigned_email'],
                'recurrence' => $task['recurrence'] ?? 'none',
                'recurrence_interval' => (int) ($task['recurrence_interval'] ?? 1),
                'created_at' => $task['created_at'],
                'completed_at' => $task['completed_at']
            ],
            'subtasks' => $subtasks,
            'assignedLabels' => $assignedLabels,
            'allLabels' => $allBoardLabels,
            'comments' => $comments,
            'attachments' => $attachments,
            'activity' => $activity
        ]);
        break;
    }

    // Subtasks
    case 'addSubtask': {
        $taskId = $body['taskId'] ?? '';
        $title = trim($body['title'] ?? '');
        if (!$title) { echo json_encode(['success' => false, 'error' => 'Title required']); break; }

        $subId = genId('sub');
        $pos = nextPosition($pdo, 'subtasks', 'position', 'task_id', $taskId);
        $stmt = $pdo->prepare('INSERT INTO subtasks (id, task_id, title, completed, position, created_at) VALUES (?, ?, ?, 0, ?, NOW())');
        $stmt->execute([$subId, $taskId, $title, $pos]);

        // Get board id for activity
        $bStmt = $pdo->prepare('SELECT l.board_id, t.title FROM tasks t INNER JOIN board_lists l ON l.id = t.list_id WHERE t.id = ?');
        $bStmt->execute([$taskId]);
        $tRow = $bStmt->fetch();
        $bId = $tRow['board_id'] ?? null;
        logActivity($pdo, $bId, $taskId, $userId, 'subtask_added', "Added checklist item '{$title}'");

        if ($bId && $tRow) {
            $actorName = getActorName($pdo, $userId);
            $bName = getBoardName($pdo, $bId);
            notifyBoardMembers($pdo, $bId, $userId, 'subtask_added', "Checklist updated in '{$bName}'", "{$actorName} added item '{$title}' to '{$tRow['title']}'", 'task', $taskId);
        }

        $subs = $pdo->prepare('SELECT * FROM subtasks WHERE task_id = ? ORDER BY position ASC, created_at ASC');
        $subs->execute([$taskId]);
        echo json_encode(['success' => true, 'subtasks' => $subs->fetchAll()]);
        break;
    }

    case 'toggleSubtask': {
        $subtaskId = $body['subtaskId'] ?? '';
        $stmt = $pdo->prepare('UPDATE subtasks SET completed = CASE WHEN completed = 1 THEN 0 ELSE 1 END WHERE id = ?');
        $stmt->execute([$subtaskId]);

        // Return updated subtasks for this task
        $tStmt = $pdo->prepare('SELECT task_id, completed, title FROM subtasks WHERE id = ?');
        $tStmt->execute([$subtaskId]);
        $sub = $tStmt->fetch();

        if ($sub) {
            $bStmt = $pdo->prepare('SELECT l.board_id, t.title FROM tasks t INNER JOIN board_lists l ON l.id = t.list_id WHERE t.id = ?');
            $bStmt->execute([$sub['task_id']]);
            $tRow = $bStmt->fetch();
            if ($tRow) {
                $actorName = getActorName($pdo, $userId);
                $bName = getBoardName($pdo, $tRow['board_id']);
                $subStatus = $sub['completed'] ? 'completed' : 'reopened';
                logActivity($pdo, $tRow['board_id'], $sub['task_id'], $userId, 'subtask_toggled', "Marked '{$sub['title']}' {$subStatus}");
                notifyBoardMembers($pdo, $tRow['board_id'], $userId, 'subtask_toggled', "Checklist updated in '{$bName}'", "{$actorName} marked '{$sub['title']}' as {$subStatus}", 'task', $sub['task_id']);
            }

            $subs = $pdo->prepare('SELECT * FROM subtasks WHERE task_id = ? ORDER BY position ASC, created_at ASC');
            $subs->execute([$sub['task_id']]);
            echo json_encode(['success' => true, 'subtasks' => $subs->fetchAll()]);
        } else {
            echo json_encode(['success' => false]);
        }
        break;
    }

    case 'deleteSubtask': {
        $subtaskId = $body['subtaskId'] ?? '';
        $tStmt = $pdo->prepare('SELECT task_id FROM subtasks WHERE id = ?');
        $tStmt->execute([$subtaskId]);
        $taskId = $tStmt->fetchColumn();

        $stmt = $pdo->prepare('DELETE FROM subtasks WHERE id = ?');
        $stmt->execute([$subtaskId]);

        $subs = $pdo->prepare('SELECT * FROM subtasks WHERE task_id = ? ORDER BY position ASC, created_at ASC');
        $subs->execute([$taskId]);
        echo json_encode(['success' => true, 'subtasks' => $subs->fetchAll()]);
        break;
    }

    // Labels
    case 'createLabel': {
        $boardId = $body['boardId'] ?? null;
        $name = trim($body['name'] ?? '');
        $color = $body['color'] ?? '#4f8ef7';
        if (!$name) { echo json_encode(['success' => false, 'error' => 'Label name required']); break; }

        $lblId = genId('lbl');
        $stmt = $pdo->prepare('INSERT INTO labels (id, board_id, user_id, name, color, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
        $stmt->execute([$lblId, $boardId, $userId, $name, $color]);

        $bLabelsStmt = $pdo->prepare('SELECT id, name, color FROM labels WHERE board_id = ? OR (board_id IS NULL AND user_id = ?) ORDER BY name ASC');
        $bLabelsStmt->execute([$boardId, $userId]);
        echo json_encode(['success' => true, 'labels' => $bLabelsStmt->fetchAll(), 'newLabelId' => $lblId]);
        break;
    }

    case 'toggleTaskLabel': {
        $taskId = trim($body['taskId'] ?? '');
        $labelId = trim($body['labelId'] ?? '');

        if (!$taskId || !$labelId) {
            echo json_encode(['success' => false, 'error' => 'Missing task or label ID']);
            break;
        }

        $check = $pdo->prepare('SELECT 1 FROM task_labels WHERE task_id = ? AND label_id = ?');
        $check->execute([$taskId, $labelId]);
        if ($check->fetch()) {
            $pdo->prepare('DELETE FROM task_labels WHERE task_id = ? AND label_id = ?')->execute([$taskId, $labelId]);
        } else {
            $pdo->prepare('INSERT IGNORE INTO task_labels (task_id, label_id) VALUES (?, ?)')->execute([$taskId, $labelId]);
        }

        $tlStmt = $pdo->prepare('SELECT l.id, l.name, l.color FROM task_labels tl INNER JOIN labels l ON l.id = tl.label_id WHERE tl.task_id = ?');
        $tlStmt->execute([$taskId]);
        echo json_encode(['success' => true, 'assignedLabels' => $tlStmt->fetchAll()]);
        break;
    }

    case 'deleteLabel': {
        $labelId = $body['labelId'] ?? '';
        $stmt = $pdo->prepare('DELETE FROM labels WHERE id = ? AND (user_id = ? OR board_id IN (SELECT id FROM boards WHERE user_id = ?))');
        $stmt->execute([$labelId, $userId, $userId]);
        echo json_encode(['success' => true]);
        break;
    }

    // Comments
    case 'addComment': {
        $taskId = $body['taskId'] ?? '';
        $content = trim($body['content'] ?? '');
        if (!$content) { echo json_encode(['success' => false, 'error' => 'Comment cannot be empty']); break; }

        $stmtTask = $pdo->prepare('SELECT t.title, t.assigned_to, l.board_id FROM tasks t INNER JOIN board_lists l ON l.id = t.list_id WHERE t.id = ?');
        $stmtTask->execute([$taskId]);
        $task = $stmtTask->fetch();
        if (!$task) { echo json_encode(['success' => false, 'error' => 'Task not found']); break; }

        $cmtId = genId('cmt');
        $stmt = $pdo->prepare('INSERT INTO comments (id, task_id, user_id, content, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$cmtId, $taskId, $userId, $content]);

        logActivity($pdo, $task['board_id'], $taskId, $userId, 'comment_added', "Commented on '{$task['title']}'");

        $actorName = getActorName($pdo, $userId);
        $bName = getBoardName($pdo, $task['board_id']);
        $preview = mb_substr($content, 0, 75);
        notifyBoardMembers($pdo, $task['board_id'], $userId, 'comment_added', "New comment in '{$bName}'", "{$actorName} on '{$task['title']}': \"{$preview}\"", 'task', $taskId);

        // Return updated comments
        $cmtStmt = $pdo->prepare("
            SELECT c.id, c.content, c.created_at, c.user_id,
                   u.name AS user_name, u.email AS user_email
            FROM comments c
            INNER JOIN users u ON u.id = c.user_id
            WHERE c.task_id = ?
            ORDER BY c.created_at ASC
        ");
        $cmtStmt->execute([$taskId]);
        $comments = [];
        foreach ($cmtStmt->fetchAll() as $c) {
            $comments[] = [
                'id' => $c['id'],
                'content' => $c['content'],
                'created_at' => $c['created_at'],
                'user_id' => (int) $c['user_id'],
                'user_name' => $c['user_name'] ?: explode('@', $c['user_email'])[0],
                'user_email' => $c['user_email'],
                'can_delete' => ((int)$c['user_id'] === $userId || getBoardAccess($pdo, $userId, $task['board_id']) === 'owner')
            ];
        }

        echo json_encode(['success' => true, 'comments' => $comments]);
        break;
    }

    case 'deleteComment': {
        $commentId = $body['commentId'] ?? '';
        $cStmt = $pdo->prepare('SELECT c.task_id, c.user_id, l.board_id FROM comments c INNER JOIN tasks t ON t.id = c.task_id INNER JOIN board_lists l ON l.id = t.list_id WHERE c.id = ?');
        $cStmt->execute([$commentId]);
        $cmt = $cStmt->fetch();
        if (!$cmt) { echo json_encode(['success' => false, 'error' => 'Comment not found']); break; }

        $role = getBoardAccess($pdo, $userId, $cmt['board_id']);
        if ((int)$cmt['user_id'] !== $userId && $role !== 'owner') {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $pdo->prepare('DELETE FROM comments WHERE id = ?')->execute([$commentId]);

        // Return refreshed comments
        $cmtStmt = $pdo->prepare("
            SELECT c.id, c.content, c.created_at, c.user_id,
                   u.name AS user_name, u.email AS user_email
            FROM comments c
            INNER JOIN users u ON u.id = c.user_id
            WHERE c.task_id = ?
            ORDER BY c.created_at ASC
        ");
        $cmtStmt->execute([$cmt['task_id']]);
        $comments = [];
        foreach ($cmtStmt->fetchAll() as $c) {
            $comments[] = [
                'id' => $c['id'],
                'content' => $c['content'],
                'created_at' => $c['created_at'],
                'user_id' => (int) $c['user_id'],
                'user_name' => $c['user_name'] ?: explode('@', $c['user_email'])[0],
                'user_email' => $c['user_email'],
                'can_delete' => ((int)$c['user_id'] === $userId || $role === 'owner')
            ];
        }

        echo json_encode(['success' => true, 'comments' => $comments]);
        break;
    }

    // Attachments
    case 'uploadAttachment': {
        $taskId = $_POST['taskId'] ?? ($body['taskId'] ?? '');
        if (!$taskId) { echo json_encode(['success' => false, 'error' => 'Task ID required']); break; }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'File upload error or no file sent']);
            break;
        }

        $file = $_FILES['file'];
        if ($file['size'] > 15 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'File exceeds 15MB limit']);
            break;
        }

        $origName = basename($file['name']);
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $disallowed = ['php', 'phtml', 'exe', 'bat', 'cmd', 'sh', 'js', 'py', 'pl'];
        if (in_array($ext, $disallowed, true)) {
            echo json_encode(['success' => false, 'error' => 'Executable file types not allowed']);
            break;
        }

        $safeName = genId('att') . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $origName);
        $targetDir = __DIR__ . '/uploads/tasks';
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
        $targetPath = $targetDir . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            echo json_encode(['success' => false, 'error' => 'Failed to save file']);
            break;
        }

        $attId = genId('att');
        $stmt = $pdo->prepare('INSERT INTO attachments (id, task_id, user_id, filename, original_name, file_size, file_type, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $attId,
            $taskId,
            $userId,
            $safeName,
            $origName,
            $file['size'],
            $file['type'] ?: 'application/octet-stream'
        ]);

        $bStmt = $pdo->prepare('SELECT l.board_id, t.title FROM tasks t INNER JOIN board_lists l ON l.id = t.list_id WHERE t.id = ?');
        $bStmt->execute([$taskId]);
        $tRow = $bStmt->fetch();
        $bId = $tRow['board_id'] ?? null;
        logActivity($pdo, $bId, $taskId, $userId, 'attachment_uploaded', "Attached '{$origName}'");

        if ($bId && $tRow) {
            $actorName = getActorName($pdo, $userId);
            $bName = getBoardName($pdo, $bId);
            notifyBoardMembers($pdo, $bId, $userId, 'attachment_uploaded', "New file in '{$bName}'", "{$actorName} attached '{$origName}' to '{$tRow['title']}'", 'task', $taskId);
        }

        // Return updated attachments list
        $attStmt = $pdo->prepare("
            SELECT a.id, a.filename, a.original_name, a.file_size, a.file_type, a.created_at, a.user_id,
                   u.name AS user_name, u.email AS user_email
            FROM attachments a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.task_id = ?
            ORDER BY a.created_at DESC
        ");
        $attStmt->execute([$taskId]);
        $attachments = [];
        foreach ($attStmt->fetchAll() as $att) {
            $attachments[] = [
                'id' => $att['id'],
                'filename' => $att['filename'],
                'original_name' => $att['original_name'],
                'file_size' => (int) $att['file_size'],
                'file_type' => $att['file_type'],
                'url' => 'uploads/tasks/' . rawurlencode($att['filename']),
                'created_at' => $att['created_at'],
                'user_name' => $att['user_name'] ?: explode('@', $att['user_email'])[0],
                'can_delete' => true
            ];
        }

        echo json_encode(['success' => true, 'attachments' => $attachments]);
        break;
    }

    case 'deleteAttachment': {
        $attachmentId = $body['attachmentId'] ?? '';
        $stmt = $pdo->prepare('SELECT a.*, l.board_id FROM attachments a INNER JOIN tasks t ON t.id = a.task_id INNER JOIN board_lists l ON l.id = t.list_id WHERE a.id = ?');
        $stmt->execute([$attachmentId]);
        $att = $stmt->fetch();
        if (!$att) { echo json_encode(['success' => false, 'error' => 'Attachment not found']); break; }

        $role = getBoardAccess($pdo, $userId, $att['board_id']);
        if ((int)$att['user_id'] !== $userId && $role !== 'owner') {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $filePath = __DIR__ . '/uploads/tasks/' . $att['filename'];
        if (file_exists($filePath)) @unlink($filePath);

        $pdo->prepare('DELETE FROM attachments WHERE id = ?')->execute([$attachmentId]);

        // Return updated attachments
        $attStmt = $pdo->prepare("
            SELECT a.id, a.filename, a.original_name, a.file_size, a.file_type, a.created_at, a.user_id,
                   u.name AS user_name, u.email AS user_email
            FROM attachments a
            INNER JOIN users u ON u.id = a.user_id
            WHERE a.task_id = ?
            ORDER BY a.created_at DESC
        ");
        $attStmt->execute([$att['task_id']]);
        $attachments = [];
        foreach ($attStmt->fetchAll() as $a) {
            $attachments[] = [
                'id' => $a['id'],
                'filename' => $a['filename'],
                'original_name' => $a['original_name'],
                'file_size' => (int) $a['file_size'],
                'file_type' => $a['file_type'],
                'url' => 'uploads/tasks/' . rawurlencode($a['filename']),
                'created_at' => $a['created_at'],
                'user_name' => $a['user_name'] ?: explode('@', $a['user_email'])[0],
                'can_delete' => ((int)$a['user_id'] === $userId || $role === 'owner')
            ];
        }

        echo json_encode(['success' => true, 'attachments' => $attachments]);
        break;
    }

    // In-app Notifications
    case 'getNotifications': {
        $stmt = $pdo->prepare("
            SELECT n.id, n.type, n.title, n.message, n.entity_type, n.entity_id, n.is_read, n.created_at,
                   u.name AS actor_name, u.email AS actor_email
            FROM notifications n
            LEFT JOIN users u ON u.id = n.actor_id
            WHERE n.user_id = ?
            ORDER BY n.created_at DESC
            LIMIT 30
        ");
        $stmt->execute([$userId]);
        $notifs = $stmt->fetchAll();

        $unreadCount = 0;
        foreach ($notifs as &$n) {
            $n['is_read'] = (bool) $n['is_read'];
            if (!$n['is_read']) $unreadCount++;
        }

        echo json_encode(['success' => true, 'notifications' => $notifs, 'unreadCount' => $unreadCount]);
        break;
    }

    case 'markNotificationRead': {
        $notifId = $body['notificationId'] ?? '';
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        $stmt->execute([$notifId, $userId]);
        echo json_encode(['success' => true]);
        break;
    }

    case 'markAllNotificationsRead': {
        $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->execute([$userId]);
        echo json_encode(['success' => true]);
        break;
    }

    // Collaboration: Join board via direct share link
    case 'joinBoardByLink': {
        $boardId = trim($body['boardId'] ?? '');
        if (!$boardId) {
            echo json_encode(['success' => false, 'error' => 'Board ID is required']);
            break;
        }

        $bStmt = $pdo->prepare('SELECT b.id, b.name, b.user_id AS owner_id, b.is_archived, COALESCE(b.general_access, "anyone_with_link") AS general_access, COALESCE(b.link_role, "editor") AS link_role, u.name AS owner_name, u.email AS owner_email FROM boards b LEFT JOIN users u ON u.id = b.user_id WHERE b.id = ?');
        $bStmt->execute([$boardId]);
        $board = $bStmt->fetch();

        if (!$board) {
            echo json_encode(['success' => false, 'error' => 'Board not found or link is invalid']);
            break;
        }

        if ((int)$board['is_archived'] === 1) {
            echo json_encode(['success' => false, 'error' => 'This board has been archived']);
            break;
        }

        $ownerId = (int) $board['owner_id'];
        if ($ownerId === $userId) {
            echo json_encode([
                'success' => true,
                'alreadyMember' => true,
                'role' => 'owner',
                'boardName' => $board['name'],
                'boards' => fetchBoards($pdo, $userId)
            ]);
            break;
        }

        // Check if already a member
        $mStmt = $pdo->prepare('SELECT role FROM board_members WHERE board_id = ? AND user_id = ?');
        $mStmt->execute([$boardId, $userId]);
        $existingRole = $mStmt->fetchColumn();

        if ($existingRole) {
            echo json_encode([
                'success' => true,
                'alreadyMember' => true,
                'role' => $existingRole,
                'boardName' => $board['name'],
                'boards' => fetchBoards($pdo, $userId)
            ]);
            break;
        }

        $generalAccess = $board['general_access'] ?? 'anyone_with_link';
        $linkRole = in_array(($board['link_role'] ?? 'editor'), ['editor', 'viewer'], true) ? $board['link_role'] : 'editor';

        // Restricted mode: Require access approval like Google Drive
        if ($generalAccess === 'restricted') {
            $reqCheck = $pdo->prepare('SELECT status, created_at FROM board_access_requests WHERE board_id = ? AND user_id = ?');
            $reqCheck->execute([$boardId, $userId]);
            $req = $reqCheck->fetch();

            echo json_encode([
                'success' => false,
                'restricted' => true,
                'boardId' => $boardId,
                'boardName' => $board['name'],
                'ownerName' => $board['owner_name'] ?: explode('@', $board['owner_email'])[0],
                'ownerEmail' => $board['owner_email'],
                'hasRequested' => (bool) $req,
                'requestStatus' => $req ? $req['status'] : null,
                'error' => 'You need permission to access this board'
            ]);
            break;
        }

        // Anyone with link mode: auto-join
        $insStmt = $pdo->prepare('INSERT INTO board_members (board_id, user_id, role, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE role = role');
        $insStmt->execute([$boardId, $userId, $linkRole]);

        $joinerName = $authUser['name'] ?: explode('@', $authUser['email'])[0];
        logActivity($pdo, $boardId, null, $userId, 'member_joined', "{$joinerName} joined via shared link ({$linkRole})");
        createNotification($pdo, $ownerId, $userId, 'board_joined', "New Collaborator", "{$joinerName} joined your board '{$board['name']}' via shared link", 'board', $boardId);

        echo json_encode([
            'success' => true,
            'alreadyMember' => false,
            'role' => $linkRole,
            'boardName' => $board['name'],
            'boards' => fetchBoards($pdo, $userId)
        ]);
        break;
    }

    // Collaboration: Request access to a restricted board
    case 'requestBoardAccess': {
        $boardId = trim($body['boardId'] ?? '');
        $message = trim($body['message'] ?? '');

        $bStmt = $pdo->prepare('SELECT b.id, b.name, b.user_id AS owner_id, u.name AS owner_name, u.email AS owner_email FROM boards b LEFT JOIN users u ON u.id = b.user_id WHERE b.id = ?');
        $bStmt->execute([$boardId]);
        $board = $bStmt->fetch();

        if (!$board) {
            echo json_encode(['success' => false, 'error' => 'Board not found']);
            break;
        }

        $ownerId = (int) $board['owner_id'];
        if ($ownerId === $userId) {
            echo json_encode(['success' => true, 'alreadyMember' => true]);
            break;
        }

        $ins = $pdo->prepare('
            INSERT INTO board_access_requests (board_id, user_id, message, status, created_at, updated_at)
            VALUES (?, ?, ?, "pending", NOW(), NOW())
            ON DUPLICATE KEY UPDATE message = VALUES(message), status = "pending", updated_at = NOW()
        ');
        $ins->execute([$boardId, $userId, $message]);

        $requesterName = $authUser['name'] ?: explode('@', $authUser['email'])[0];
        createNotification(
            $pdo,
            $ownerId,
            $userId,
            'access_request',
            "Access Request for '{$board['name']}'",
            "{$requesterName} requested access to board '{$board['name']}'" . ($message ? ": \"{$message}\"" : ""),
            'board',
            $boardId
        );
        logActivity($pdo, $boardId, null, $userId, 'access_requested', "{$requesterName} requested access");

        echo json_encode(['success' => true, 'message' => 'Access request sent to board owner']);
        break;
    }

    // Collaboration: Update general access and link role (Google Drive style)
    case 'updateBoardGeneralAccess': {
        $boardId = trim($body['boardId'] ?? '');
        $generalAccess = in_array($body['generalAccess'] ?? '', ['anyone_with_link', 'restricted'], true) ? $body['generalAccess'] : 'anyone_with_link';
        $linkRole = in_array($body['linkRole'] ?? '', ['editor', 'viewer'], true) ? $body['linkRole'] : 'editor';

        $userRole = getBoardAccess($pdo, $userId, $boardId);
        if ($userRole !== 'owner') {
            echo json_encode(['success' => false, 'error' => 'Only the board owner can change access settings']);
            break;
        }

        $stmt = $pdo->prepare('UPDATE boards SET general_access = ?, link_role = ? WHERE id = ?');
        $stmt->execute([$generalAccess, $linkRole, $boardId]);

        $desc = ($generalAccess === 'anyone_with_link') ? "Changed to Anyone with link ({$linkRole})" : "Changed to Restricted";
        logActivity($pdo, $boardId, null, $userId, 'access_settings_changed', $desc);

        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    // Collaboration: Owner approves or declines access request
    case 'handleBoardAccessRequest': {
        $boardId = trim($body['boardId'] ?? '');
        $requestId = (int) ($body['requestId'] ?? 0);
        $decision = ($body['action'] ?? '') === 'approve' ? 'approve' : 'decline';
        $role = in_array($body['role'] ?? '', ['editor', 'viewer'], true) ? $body['role'] : 'editor';

        $userRole = getBoardAccess($pdo, $userId, $boardId);
        if ($userRole !== 'owner') {
            echo json_encode(['success' => false, 'error' => 'Only the board owner can manage access requests']);
            break;
        }

        $rStmt = $pdo->prepare('SELECT user_id FROM board_access_requests WHERE id = ? AND board_id = ?');
        $rStmt->execute([$requestId, $boardId]);
        $targetUserId = (int) $rStmt->fetchColumn();

        if (!$targetUserId) {
            echo json_encode(['success' => false, 'error' => 'Request not found']);
            break;
        }

        $bNameStmt = $pdo->prepare('SELECT name FROM boards WHERE id = ?');
        $bNameStmt->execute([$boardId]);
        $bName = $bNameStmt->fetchColumn() ?: 'board';

        if ($decision === 'approve') {
            $upd = $pdo->prepare('UPDATE board_access_requests SET status = "approved", updated_at = NOW() WHERE id = ?');
            $upd->execute([$requestId]);

            $bmStmt = $pdo->prepare('INSERT INTO board_members (board_id, user_id, role, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE role = VALUES(role)');
            $bmStmt->execute([$boardId, $targetUserId, $role]);

            createNotification($pdo, $targetUserId, $userId, 'access_approved', "Access Granted to '{$bName}'", "Your access request to '{$bName}' was approved as {$role}!", 'board', $boardId);
            logActivity($pdo, $boardId, null, $userId, 'request_approved', "Approved access request as {$role}");
        } else {
            $upd = $pdo->prepare('UPDATE board_access_requests SET status = "declined", updated_at = NOW() WHERE id = ?');
            $upd->execute([$requestId]);

            createNotification($pdo, $targetUserId, $userId, 'access_declined', "Access Declined for '{$bName}'", "Your access request to '{$bName}' was declined.", 'board', $boardId);
            logActivity($pdo, $boardId, null, $userId, 'request_declined', "Declined access request");
        }

        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    // Collaboration: Add collaborator by email
    case 'addBoardMember': {
        $boardId = $body['boardId'] ?? '';
        $email = strtolower(trim($body['email'] ?? ''));
        $role = in_array($body['role'] ?? '', ['editor', 'viewer'], true) ? $body['role'] : 'editor';

        $userRole = getBoardAccess($pdo, $userId, $boardId);
        if ($userRole !== 'owner') {
            echo json_encode(['success' => false, 'error' => 'Only the board owner can invite members']);
            break;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'error' => 'Please enter a valid email address']);
            break;
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = LOWER(?)');
        $stmt->execute([$email]);
        $targetUserId = $stmt->fetchColumn();

        if (!$targetUserId) {
            $stmt = $pdo->prepare('INSERT INTO users (email, name, created_at) VALUES (?, ?, NOW())');
            $stmt->execute([$email, explode('@', $email)[0]]);
            $targetUserId = (int) $pdo->lastInsertId();
        } else {
            $targetUserId = (int) $targetUserId;
        }

        if ($targetUserId === $userId) {
            echo json_encode(['success' => false, 'error' => 'You are already the owner of this board']);
            break;
        }

        $stmt = $pdo->prepare('INSERT INTO board_members (board_id, user_id, role, created_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE role = VALUES(role)');
        $stmt->execute([$boardId, $targetUserId, $role]);

        // Get board name for notification
        $bNameStmt = $pdo->prepare('SELECT name FROM boards WHERE id = ?');
        $bNameStmt->execute([$boardId]);
        $bName = $bNameStmt->fetchColumn() ?: 'a board';

        createNotification($pdo, $targetUserId, $userId, 'board_invite', "Invited to '{$bName}'", "You were added as {$role} to board '{$bName}'", 'board', $boardId);
        logActivity($pdo, $boardId, null, $userId, 'member_invited', "Invited {$email} as {$role}");

        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    // Collaboration: Remove collaborator
    case 'removeBoardMember': {
        $boardId = $body['boardId'] ?? '';
        $targetUserId = (int) ($body['memberUserId'] ?? 0);

        $userRole = getBoardAccess($pdo, $userId, $boardId);
        if ($userRole !== 'owner' && $targetUserId !== $userId) {
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            break;
        }

        $stmt = $pdo->prepare('DELETE FROM board_members WHERE board_id = ? AND user_id = ?');
        $stmt->execute([$boardId, $targetUserId]);

        $stmt = $pdo->prepare('
            UPDATE tasks t
            INNER JOIN board_lists l ON l.id = t.list_id
            SET t.assigned_to = NULL
            WHERE l.board_id = ? AND t.assigned_to = ?
        ');
        $stmt->execute([$boardId, $targetUserId]);

        logActivity($pdo, $boardId, null, $userId, 'member_removed', "Removed collaborator");
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'updateMemberRole': {
        $boardId = $body['boardId'] ?? '';
        $targetUserId = (int) ($body['memberUserId'] ?? 0);
        $role = in_array($body['role'] ?? '', ['editor', 'viewer'], true) ? $body['role'] : 'editor';

        $userRole = getBoardAccess($pdo, $userId, $boardId);
        if ($userRole !== 'owner') {
            echo json_encode(['success' => false, 'error' => 'Only the board owner can change member roles']);
            break;
        }

        $stmt = $pdo->prepare('UPDATE board_members SET role = ? WHERE board_id = ? AND user_id = ?');
        $stmt->execute([$role, $boardId, $targetUserId]);

        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'setTheme': {
        $theme = ($body['theme'] ?? '') === 'light' ? 'light' : 'dark';
        $stmt = $pdo->prepare('INSERT INTO user_settings (user_id, theme) VALUES (?, ?) ON DUPLICATE KEY UPDATE theme = VALUES(theme)');
        $stmt->execute([$userId, $theme]);
        echo json_encode(['success' => true]);
        break;
    }

    case 'updateProfile': {
        $name = trim($body['name'] ?? '');
        if ($name) {
            $stmt = $pdo->prepare('UPDATE users SET name = ? WHERE id = ?');
            $stmt->execute([$name, $userId]);
            $_SESSION['auth_user']['name'] = $name;
        }
        echo json_encode(['success' => true]);
        break;
    }

    default:
        echo json_encode(['error' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

