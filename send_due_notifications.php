<?php
/**
 * send_due_notifications.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Sends Web Push notifications for tasks whose due datetime has arrived.
 *
 * TWO ways to run:
 *   1. CLI cron (recommended):
 *      * * * * *  php /path/to/taskboard/send_due_notifications.php >> /tmp/tb_notif.log 2>&1
 *
 *   2. HTTP endpoint — POST from a trusted source only (protect with token).
 *      Use this if your host does not allow cron jobs.
 *
 * Dependencies:
 *   composer require minishlink/web-push
 *   (or place the library in web-push-php-master/ as included in this zip)
 * ─────────────────────────────────────────────────────────────────────────────
 */

// ── Guard: CLI only (comment out if you want HTTP access) ─────────────────────
if (php_sapi_name() !== 'cli') {
    // Allow HTTP access with a secret token as a fallback
    $token = getenv('NOTIF_SECRET') ?: 'change-me-to-a-long-random-string';
    $provided = $_SERVER['HTTP_X_NOTIF_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!hash_equals($token, $provided)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit(1);
    }
}

require_once __DIR__ . '/db.php';

// ── Load VAPID config ─────────────────────────────────────────────────────────
if (!file_exists(__DIR__ . '/vapid.php')) {
    fwrite(STDERR, "ERROR: vapid.php not found.\n");
    exit(1);
}
$config = require __DIR__ . '/vapid.php';
$vapid  = [
    'subject'    => $config['subject'],
    'publicKey'  => $config['publicKey'],
    'privateKey' => $config['privateKey'],
];

// ── Load WebPush library ──────────────────────────────────────────────────────
$autoloads = [
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/web-push-php-master/web-push-php-master/vendor/autoload.php',
    __DIR__ . '/web-push-php-master/vendor/autoload.php',
];
$loaded = false;
foreach ($autoloads as $al) {
    if (file_exists($al)) { require_once $al; $loaded = true; break; }
}

// Fallback: manual PSR-4 autoloader for the bundled source
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

if (!$loaded) {
    fwrite(STDERR, "ERROR: Cannot find WebPush library. Run: composer require minishlink/web-push\n");
    exit(1);
}

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

// ── Find tasks due now (within the last minute, not yet notified) ─────────────
$pdo = DB::get();
$now = new DateTime('now');

$stmt = $pdo->prepare(
    "SELECT t.id        AS task_id,
            t.title,
            t.description,
            t.due_date,
            t.due_time,
            b.user_id
     FROM   tasks       t
     INNER JOIN board_lists l ON l.id = t.list_id
     INNER JOIN boards      b ON b.id = l.board_id
     WHERE  t.completed = 0
       AND  t.due_date IS NOT NULL
       AND  t.due_time IS NOT NULL
       AND  CONCAT(t.due_date, ' ', t.due_time) <= ?
       AND  t.id NOT IN (SELECT task_id FROM notifications_sent)"
);
$stmt->execute([$now->format('Y-m-d H:i:s')]);
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$tasks) {
    echo date('c') . " — No due tasks.\n";
    exit(0);
}

echo date('c') . " — Found " . count($tasks) . " task(s) to notify.\n";

// ── Send notifications ────────────────────────────────────────────────────────
foreach ($tasks as $task) {
    $subsStmt = $pdo->prepare(
        'SELECT endpoint, p256dh, auth FROM push_subscriptions WHERE user_id = ?'
    );
    $subsStmt->execute([(int) $task['user_id']]);
    $subs = $subsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$subs) {
        echo "  Task {$task['task_id']}: no subscriptions for user {$task['user_id']}, skipping.\n";
        // Still mark sent so we don't retry forever
        $pdo->prepare('INSERT IGNORE INTO notifications_sent (task_id) VALUES (?)')->execute([$task['task_id']]);
        continue;
    }

    $webPush = new WebPush(['VAPID' => $vapid]);

    $payload = json_encode([
        'title' => '⏰ Task Due: ' . $task['title'],
        'body'  => $task['description'] ?: 'This task is now due.',
        'tag'   => 'task-' . $task['task_id'],
        'url'   => './',
    ]);

    foreach ($subs as $row) {
        try {
            $subscription = Subscription::create([
                'endpoint' => $row['endpoint'],
                'keys'     => [
                    'p256dh' => $row['p256dh'],
                    'auth'   => $row['auth'],
                ],
            ]);
            $webPush->queueNotification($subscription, $payload);
        } catch (Exception $e) {
            echo "  Skipping invalid subscription for user {$task['user_id']}: " . $e->getMessage() . "\n";
        }
    }

    $allOk = true;
    foreach ($webPush->flush() as $report) {
        $ep = $report->getRequest()->getUri()->__toString();
        if ($report->isSuccess()) {
            echo "  ✓ Sent to " . substr($ep, 0, 60) . "...\n";
        } else {
            $reason = $report->getReason();
            echo "  ✗ Failed to " . substr($ep, 0, 60) . "... Reason: {$reason}\n";

            // Remove expired/invalid subscriptions automatically
            if (in_array($report->getResponse()->getStatusCode(), [404, 410])) {
                $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')->execute([$ep]);
                echo "    → Removed stale subscription.\n";
            }
            $allOk = false;
        }
    }

    // Mark as sent regardless (avoid repeated failed retries)
    $pdo->prepare('INSERT IGNORE INTO notifications_sent (task_id) VALUES (?)')->execute([$task['task_id']]);
}

echo date('c') . " — Done.\n";
