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

function genId($prefix = 'id') {
    return $prefix . '_' . uniqid() . '_' . bin2hex(random_bytes(4));
}

function ensureDefaultBoard(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM boards WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }

    $boardId = genId('board');
    $listId = genId('list');

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO boards (id, user_id, name, position, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$boardId, $userId, 'Main Board', 1]);

        $stmt = $pdo->prepare('INSERT INTO board_lists (id, board_id, name, position, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$listId, $boardId, 'Tasks', 1]);

        $pdo->commit();
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function fetchBoards(PDO $pdo, int $userId): array
{
    ensureDefaultBoard($pdo, $userId);

    $boardsStmt = $pdo->prepare('SELECT id, name, created_at FROM boards WHERE user_id = ? ORDER BY position ASC, created_at ASC, id ASC');
    $boardsStmt->execute([$userId]);
    $boards = [];

    $listStmt = $pdo->prepare('SELECT id, board_id, name, created_at FROM board_lists WHERE board_id IN (SELECT id FROM boards WHERE user_id = ?) ORDER BY position ASC, created_at ASC, id ASC');
    $listStmt->execute([$userId]);
    $listsByBoard = [];
    foreach ($listStmt->fetchAll() as $listRow) {
        $listsByBoard[$listRow['board_id']][] = [
            'id' => $listRow['id'],
            'name' => $listRow['name'],
            'tasks' => []
        ];
    }

    $taskStmt = $pdo->prepare(
        'SELECT t.id, t.list_id, t.title, t.description, t.due_date, t.due_time, t.priority, t.completed, t.created_at
         FROM tasks t
         INNER JOIN board_lists l ON l.id = t.list_id
         INNER JOIN boards b ON b.id = l.board_id
         WHERE b.user_id = ?
         ORDER BY t.position ASC, t.created_at ASC, t.id ASC'
    );
    $taskStmt->execute([$userId]);
    $tasksByList = [];
    foreach ($taskStmt->fetchAll() as $taskRow) {
        $tasksByList[$taskRow['list_id']][] = [
            'id' => $taskRow['id'],
            'title' => $taskRow['title'],
            'description' => $taskRow['description'] ?? '',
            'due_date' => $taskRow['due_date'],
            'due_time' => $taskRow['due_time'],
            'priority' => $taskRow['priority'],
            'completed' => (bool) $taskRow['completed'],
            'created_at' => $taskRow['created_at'],
        ];
    }

    foreach ($boardsStmt->fetchAll() as $boardRow) {
        $boardLists = [];
        foreach ($listsByBoard[$boardRow['id']] ?? [] as $listRow) {
            $listRow['tasks'] = $tasksByList[$listRow['id']] ?? [];
            $boardLists[] = $listRow;
        }

        $boards[] = [
            'id' => $boardRow['id'],
            'name' => $boardRow['name'],
            'created_at' => $boardRow['created_at'],
            'lists' => $boardLists,
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

function findTaskOwnership(PDO $pdo, int $userId, string $taskId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT t.id AS task_id, t.list_id, l.board_id
         FROM tasks t
         INNER JOIN board_lists l ON l.id = t.list_id
         INNER JOIN boards b ON b.id = l.board_id
         WHERE t.id = ? AND b.user_id = ?'
    );
    $stmt->execute([$taskId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$body = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';

try {
    $pdo = DB::get();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'db error']);
    exit;
}

switch ($action) {

    case 'getBoards':
        echo json_encode(['boards' => fetchBoards($pdo, $userId)]);
        break;

    case 'addBoard': {
        $name = trim($body['name'] ?? '');
        if (!$name) { echo json_encode(['success' => false, 'error' => 'No name']); break; }
        $newId = genId('board');
        $listId = genId('list');
        $pdo->beginTransaction();
        try {
            $boardPos = nextPosition($pdo, 'boards', 'user_id', 'user_id', (string) $userId);
            $stmt = $pdo->prepare('INSERT INTO boards (id, user_id, name, position, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$newId, $userId, $name, $boardPos]);

            $stmt = $pdo->prepare('INSERT INTO board_lists (id, board_id, name, position, created_at) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$listId, $newId, 'Tasks', 1]);

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId), 'newBoardId' => $newId]);
        break;
    }

    case 'renameBoard': {
        $boardId = $body['boardId'] ?? '';
        $name = trim($body['name'] ?? '');
        if (!$name) { echo json_encode(['success' => false]); break; }
        $stmt = $pdo->prepare('UPDATE boards SET name = ? WHERE id = ? AND user_id = ?');
        $stmt->execute([$name, $boardId, $userId]);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'deleteBoard': {
        $boardId = $body['boardId'] ?? '';
        $stmt = $pdo->prepare('DELETE FROM boards WHERE id = ? AND user_id = ?');
        $stmt->execute([$boardId, $userId]);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'addList': {
        $boardId = $body['boardId'] ?? '';
        $name = trim($body['name'] ?? '');
        if (!$name) { echo json_encode(['success' => false]); break; }
        $stmt = $pdo->prepare('SELECT id FROM boards WHERE id = ? AND user_id = ?');
        $stmt->execute([$boardId, $userId]);
        if (!$stmt->fetch()) { echo json_encode(['success' => false]); break; }

        $listId = genId('list');
        $position = nextPosition($pdo, 'board_lists', 'board_id', 'board_id', $boardId);
        $stmt = $pdo->prepare('INSERT INTO board_lists (id, board_id, name, position, created_at) VALUES (?, ?, ?, ?, NOW())');
        $stmt->execute([$listId, $boardId, $name, $position]);

        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'renameList': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $name = trim($body['name'] ?? '');
        $stmt = $pdo->prepare(
            'UPDATE board_lists l
             INNER JOIN boards b ON b.id = l.board_id
             SET l.name = ?
             WHERE l.id = ? AND l.board_id = ? AND b.user_id = ?'
        );
        $stmt->execute([$name, $listId, $boardId, $userId]);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'deleteList': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $stmt = $pdo->prepare(
            'DELETE l FROM board_lists l
             INNER JOIN boards b ON b.id = l.board_id
             WHERE l.id = ? AND l.board_id = ? AND b.user_id = ?'
        );
        $stmt->execute([$listId, $boardId, $userId]);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'addTask': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $taskData = $body['taskData'] ?? [];
        $title = trim($taskData['title'] ?? '');
        if ($title === '') { echo json_encode(['success' => false]); break; }

        $stmt = $pdo->prepare(
            'SELECT l.id
             FROM board_lists l
             INNER JOIN boards b ON b.id = l.board_id
             WHERE l.id = ? AND l.board_id = ? AND b.user_id = ?'
        );
        $stmt->execute([$listId, $boardId, $userId]);
        if (!$stmt->fetch()) { echo json_encode(['success' => false]); break; }

        $task = [
            'id' => genId('task'),
            'title' => $title,
            'description' => trim($taskData['description'] ?? ''),
            'due_date' => $taskData['due_date'] ?: null,
            'due_time' => $taskData['due_time'] ?: null,
            'priority' => in_array(($taskData['priority'] ?? 'medium'), ['low', 'medium', 'high'], true) ? $taskData['priority'] : 'medium',
            'completed' => 0,
            'created_at' => date('Y-m-d H:i:s')
        ];

        $position = nextPosition($pdo, 'tasks', 'list_id', 'list_id', $listId);
        $stmt = $pdo->prepare('INSERT INTO tasks (id, list_id, title, description, due_date, due_time, priority, completed, position, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
        $stmt->execute([
            $task['id'],
            $listId,
            $task['title'],
            $task['description'],
            $task['due_date'],
            $task['due_time'],
            $task['priority'],
            $task['completed'],
            $position,
        ]);

        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'updateTask': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $taskId = $body['taskId'] ?? '';
        $oldBoardId = $body['oldBoardId'] ?? $boardId;
        $oldListId = $body['oldListId'] ?? $listId;
        $taskData = $body['taskData'] ?? [];
        $ownership = findTaskOwnership($pdo, $userId, $taskId);
        if (!$ownership) { echo json_encode(['success' => false]); break; }

        $stmt = $pdo->prepare(
            'SELECT l.id
             FROM board_lists l
             INNER JOIN boards b ON b.id = l.board_id
             WHERE l.id = ? AND l.board_id = ? AND b.user_id = ?'
        );
        $stmt->execute([$listId, $boardId, $userId]);
        if (!$stmt->fetch()) { echo json_encode(['success' => false]); break; }

        $title = trim($taskData['title'] ?? '');
        if ($title === '') { echo json_encode(['success' => false]); break; }

        $priority = $taskData['priority'] ?? 'medium';
        if (!in_array($priority, ['low', 'medium', 'high'], true)) {
            $priority = 'medium';
        }

        $stmt = $pdo->prepare(
            'UPDATE tasks t
             INNER JOIN board_lists l ON l.id = t.list_id
             INNER JOIN boards b ON b.id = l.board_id
             SET t.list_id = ?, t.title = ?, t.description = ?, t.due_date = ?, t.due_time = ?, t.priority = ?
             WHERE t.id = ? AND b.user_id = ?'
        );
        $stmt->execute([
            $listId,
            $title,
            trim($taskData['description'] ?? ''),
            $taskData['due_date'] ?: null,
            $taskData['due_time'] ?: null,
            $priority,
            $taskId,
            $userId,
        ]);

        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'deleteTask': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $taskId = $body['taskId'] ?? '';
        $stmt = $pdo->prepare(
            'DELETE t FROM tasks t
             INNER JOIN board_lists l ON l.id = t.list_id
             INNER JOIN boards b ON b.id = l.board_id
             WHERE t.id = ? AND l.id = ? AND l.board_id = ? AND b.user_id = ?'
        );
        $stmt->execute([$taskId, $listId, $boardId, $userId]);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'toggleTask': {
        $boardId = $body['boardId'] ?? '';
        $listId = $body['listId'] ?? '';
        $taskId = $body['taskId'] ?? '';
        $stmt = $pdo->prepare(
            'UPDATE tasks t
             INNER JOIN board_lists l ON l.id = t.list_id
             INNER JOIN boards b ON b.id = l.board_id
             SET t.completed = CASE WHEN t.completed = 1 THEN 0 ELSE 1 END
             WHERE t.id = ? AND l.id = ? AND l.board_id = ? AND b.user_id = ?'
        );
        $stmt->execute([$taskId, $listId, $boardId, $userId]);
        echo json_encode(['success' => true, 'boards' => fetchBoards($pdo, $userId)]);
        break;
    }

    case 'setTheme': {
        $theme = $body['theme'] === 'light' ? 'light' : 'dark';
        $stmt = $pdo->prepare('INSERT INTO user_settings (user_id, theme) VALUES (?, ?) ON DUPLICATE KEY UPDATE theme = VALUES(theme)');
        $stmt->execute([$userId, $theme]);
        echo json_encode(['success' => true]);
        break;
    }

    default:
        echo json_encode(['error' => 'Unknown action']);
}
