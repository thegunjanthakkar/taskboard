<?php
session_start();
$authUser = $_SESSION['auth_user'] ?? null;

if (!$authUser) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/db.php';

$theme = 'dark';
try {
    $pdo = DB::get();
    $stmt = $pdo->prepare('SELECT theme FROM user_settings WHERE user_id = ?');
    $stmt->execute([(int) $authUser['id']]);
    $theme = $stmt->fetchColumn() ?: 'dark';
} catch (Exception $e) {
    $theme = 'dark';
}

$userId = 'user_' . $authUser['id'];
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#1a1a2e">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TasksBoard">
    <meta name="description" content="Your personal task management board">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <title>TasksBoard</title>
    <link rel="shortcut icon" href="./icons/icon-192.png" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body class="theme-<?= $theme ?>">

<!-- Top Navigation -->
<header class="topnav" id="topnav">
    <div class="topnav-left">
        <button class="hamburger" id="menuToggle" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
        <div class="logo">
            <div class="logo-icon">
                <svg width="22" height="22" viewBox="0 0 22 22" fill="none">
                    <rect x="1" y="1" width="8" height="8" rx="2" fill="#4f8ef7"/>
                    <rect x="13" y="1" width="8" height="8" rx="2" fill="#4f8ef7" opacity=".6"/>
                    <rect x="1" y="13" width="8" height="8" rx="2" fill="#4f8ef7" opacity=".6"/>
                    <rect x="13" y="13" width="8" height="8" rx="2" fill="#4f8ef7" opacity=".3"/>
                </svg>
            </div>
            <span class="logo-text">TasksBoard</span>
        </div>
    </div>

    <div class="topnav-center">
        <div class="search-box">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <input type="text" id="searchInput" placeholder="Search tasks..." autocomplete="off">
        </div>
    </div>

    <div class="topnav-right">
        <button class="icon-btn" id="themeToggle" title="Toggle theme">
            <svg class="sun-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
            </svg>
            <svg class="moon-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>
        <button class="icon-btn notif-btn" id="enableNotif" title="Notifications">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <span class="notif-dot" id="notifDot"></span>
        </button>
        <?php if ($authUser): ?>
        <div class="user-avatar-wrapper">
            <div class="user-avatar" id="userAvatar" title="User: <?= htmlspecialchars($authUser['email']) ?>">
                <?= strtoupper(substr($authUser['email'], 0, 2)) ?>
            </div>
            <div class="user-menu" id="userMenu" aria-hidden="true">
                <div class="user-menu-item user-email"><?= htmlspecialchars($authUser['email']) ?></div>
                <button class="user-menu-item" id="logoutBtn">Logout</button>
            </div>
        </div>
        <?php else: ?>
        <button class="btn btn-primary" id="openAuthBtn">Login / Signup</button>
        <?php endif; ?>
    </div>
</header>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <nav class="sidebar-nav">
        <a class="nav-item" id="nav-due" href="#" data-view="due">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span>Due Today</span>
            <span class="badge" id="dueTodayCount">0</span>
        </a>

        <div class="nav-section">
            <div class="nav-section-header" id="boardsToggle">
                <div class="nav-section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    <span>Boards</span>
                </div>
                <svg class="chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </div>
            <div class="nav-submenu" id="boardsSubmenu">
                <div id="boardsList"></div>
                <a class="nav-item nav-item-add" href="#" id="addBoardBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>Add Board</span>
                </a>
            </div>
        </div>

        
    </nav>
</aside>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Main Content -->
<main class="main-content" id="mainContent">

    <!-- Due Today View -->
    <div class="view" id="view-due" style="display:none">
        <div class="view-header">
            <h1 class="view-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                Due Today
            </h1>
        </div>
        <div id="dueTodayList" class="due-today-list"></div>
    </div>

    <!-- Board View -->
    <div class="view" id="view-board">
        <div class="view-header">
            <h1 class="view-title board-title" id="currentBoardTitle">Main Board</h1>
            <div class="view-actions">
                <button class="btn-ghost btn-sm" id="renameBoardBtn" title="Rename board">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </button>
                <button class="btn-ghost btn-sm btn-danger" id="deleteBoardBtn" title="Delete board">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                    </svg>
                </button>
            </div>
        </div>
        <div class="board-container" id="boardContainer"></div>
        <button class="add-list-btn" id="addListBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add new list
        </button>
    </div>

</main>

<!-- Bottom Navigation (Mobile) -->
<nav class="bottom-nav" id="bottomNav">
    <a class="bottom-nav-item" href="#" data-view="due" id="bnav-due">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        <span>Due Today</span>
    </a>
    <a class="bottom-nav-item active" href="#" data-view="board" id="bnav-board">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
        </svg>
        <span>Boards</span>
    </a>
    <a class="bottom-nav-item" href="#" id="bnav-add">
        <div class="bnav-add-btn">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
        </div>
        <span>Add Task</span>
    </a>
    <a class="bottom-nav-item" href="#" id="bnav-search">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <span>Search</span>
    </a>
    <a class="bottom-nav-item" href="#" id="bnav-theme">
        <svg class="sun-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
        </svg>
        <svg class="moon-icon" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
        <span>Theme</span>
    </a>
</nav>

<!-- MODALS -->

<!-- Add/Edit Task Modal -->
<div class="modal-overlay" id="taskModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="taskModalTitle">Add Task</h2>
            <button class="modal-close" id="taskModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Task Name *</label>
                <input type="text" id="taskName" placeholder="Enter task name..." class="form-input" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="taskDesc" placeholder="Add a description..." class="form-input form-textarea" rows="3"></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Due Date</label>
                    <input type="date" id="taskDueDate" class="form-input">
                </div>
                <div class="form-group">
                    <label>Due Time</label>
                    <input type="time" id="taskDueTime" class="form-input">
                </div>
            </div>
            <div class="form-group">
                <label>Priority</label>
                <div class="priority-picker" id="priorityPicker">
                    <button class="priority-opt" data-priority="low">Low</button>
                    <button class="priority-opt" data-priority="medium">Medium</button>
                    <button class="priority-opt active" data-priority="high">High</button>
                </div>
            </div>
            <div class="form-group">
                <label>Board / List</label>
                <select id="taskBoardList" class="form-input form-select"></select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" id="taskModalCancel">Cancel</button>
            <button class="btn btn-primary" id="taskModalSave">Save Task</button>
        </div>
    </div>
</div>

<!-- Add Board Modal -->
<div class="modal-overlay" id="boardModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2 class="modal-title" id="boardModalTitle">New Board</h2>
            <button class="modal-close" id="boardModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Board Name *</label>
                <input type="text" id="boardName" placeholder="e.g. Work, Personal..." class="form-input" required>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" id="boardModalCancel">Cancel</button>
            <button class="btn btn-primary" id="boardModalSave">Create Board</button>
        </div>
    </div>
</div>

<!-- Add List Modal -->
<div class="modal-overlay" id="listModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2 class="modal-title">New List</h2>
            <button class="modal-close" id="listModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>List Name *</label>
                <input type="text" id="listName" placeholder="e.g. To Do, In Progress..." class="form-input" required>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" id="listModalCancel">Cancel</button>
            <button class="btn btn-primary" id="listModalSave">Create List</button>
        </div>
    </div>
</div>

<!-- Search Modal (mobile) -->
<div class="modal-overlay" id="searchModal">
    <div class="modal modal-search">
        <div class="modal-header">
            <h2 class="modal-title">Search Tasks</h2>
            <button class="modal-close" id="searchModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <input type="text" id="mobileSearchInput" placeholder="Search tasks..." class="form-input" autofocus>
            </div>
            <div id="searchResults" class="search-results"></div>
        </div>
    </div>
</div>

<!-- Toast Container -->
<div id="toastContainer"></div>

<!-- Hidden data -->
<!-- Auth Modal -->
<div class="modal-overlay" id="authModal" style="display:none">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2 class="modal-title" id="authModalTitle">Login or Signup</h2>
            <button class="modal-close" id="authModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Email</label>
                <input type="email" id="authEmail" class="form-input" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" id="authPassword" class="form-input" required>
            </div>
            <div style="display:flex;gap:8px;align-items:center;margin-top:8px">
                <button class="btn btn-primary" id="authLoginBtn">Login</button>
                <button class="btn btn-ghost" id="authSignupBtn">Signup</button>
                <div style="flex:1"></div>
            </div>
            <div style="margin-top:12px;text-align:center">
                <a href="google_login.php" class="btn" id="googleContinue">Continue with Google</a>
            </div>
            <div id="authMessage" style="margin-top:8px;color:#c00"></div>
        </div>
    </div>
</div>

<div id="userId" data-id="<?= htmlspecialchars($userId) ?>" style="display:none"></div>

<script src="app.js"></script>
<script src="auth-ui.js"></script>
</body>
</html>
