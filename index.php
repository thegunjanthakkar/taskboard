<?php
session_start();
$authUser = $_SESSION['auth_user'] ?? null;

if (!$authUser) {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: login.php' . $qs);
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
$rawName = $authUser['name'] ?? null;
if (!$rawName && isset($pdo)) {
    try {
        $nStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
        $nStmt->execute([(int) $authUser['id']]);
        $rawName = $nStmt->fetchColumn() ?: null;
        if ($rawName) $_SESSION['auth_user']['name'] = $rawName;
    } catch (Exception $e) {}
}
$userName = htmlspecialchars($rawName ?: explode('@', $authUser['email'])[0]);
$userEmail = htmlspecialchars($authUser['email']);
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="theme-color" content="#141414">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="TasksBoard">
    <meta name="description" content="Modern task and project management application">
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <title>TasksBoard</title>
    <link rel="shortcut icon" href="./icons/icon-192.png" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body class="theme-<?= $theme ?>">

<!-- Top Navigation -->
<header class="topnav" id="topnav">
    <div class="topnav-left">
        <button class="hamburger" id="menuToggle" aria-label="Toggle navigation">
            <span></span><span></span><span></span>
        </button>
        <div class="logo" id="logoBtn" title="Go to Dashboard">
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
        <div class="search-box" id="searchBoxTrigger" title="Quick Search & Command Palette (Ctrl+K)">
            <svg class="search-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <input type="text" id="searchInput" placeholder="Search tasks, boards, or commands..." autocomplete="off">
            <span class="search-badge">Ctrl+K</span>
        </div>
    </div>

    <div class="topnav-right">
        <!-- New Task Quick Trigger -->
        <button class="btn btn-primary btn-sm topnav-create-btn" id="topCreateTaskBtn">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <span>Task</span>
        </button>

        <!-- Theme Toggle -->
        <button class="icon-btn" id="themeToggle" title="Toggle theme (Light / Dark)">
            <svg class="sun-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
            </svg>
            <svg class="moon-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
            </svg>
        </button>

        <!-- In-App Notification Center Bell -->
        <div class="notif-wrapper">
            <button class="icon-btn notif-btn" id="notifBellBtn" title="Notifications">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                </svg>
                <span class="notif-count-badge" id="notifBadge" style="display:none">0</span>
            </button>
            <!-- Notification Popover -->
            <div class="notif-popover" id="notifPopover">
                <div class="notif-popover-header">
                    <div class="notif-popover-title">Notifications</div>
                    <button class="btn-link btn-xs" id="markAllNotifsReadBtn">Mark all as read</button>
                </div>
                <div class="notif-list" id="notifList">
                    <div class="notif-empty">No notifications yet</div>
                </div>
                <div class="notif-popover-footer">
                    <button class="btn-link btn-xs" id="enablePushBtn">Enable browser push notifications</button>
                </div>
            </div>
        </div>

        <!-- User Menu -->
        <div class="user-avatar-wrapper">
            <div class="user-avatar" id="userAvatar" title="<?= $userName ?> (<?= $userEmail ?>)">
                <?= strtoupper(substr($userName, 0, 2)) ?>
            </div>
            <div class="user-menu" id="userMenu" aria-hidden="true">
                <div class="user-menu-header">
                    <div class="user-menu-name"><?= $userName ?></div>
                    <div class="user-menu-email"><?= $userEmail ?></div>
                </div>
                <div class="user-menu-divider"></div>
                <button class="user-menu-item" id="openSettingsBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                    Settings
                </button>
                <button class="user-menu-item" id="openShortcutsBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="2" y="4" width="20" height="16" rx="2"/><line x1="6" y1="8" x2="6" y2="8"/><line x1="10" y1="8" x2="10" y2="8"/><line x1="14" y1="8" x2="14" y2="8"/><line x1="18" y1="8" x2="18" y2="8"/><line x1="6" y1="12" x2="6" y2="12"/><line x1="18" y1="12" x2="18" y2="12"/><line x1="8" y1="16" x2="16" y2="16"/>
                    </svg>
                    Keyboard Shortcuts
                    <span class="user-menu-badge">?</span>
                </button>
                <div class="user-menu-divider"></div>
                <button class="user-menu-item user-menu-danger" id="logoutBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                    </svg>
                    Logout
                </button>
            </div>
        </div>
    </div>
</header>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <nav class="sidebar-nav">
        <!-- Main Core Views -->
        <a class="nav-item active" id="nav-dashboard" href="#" data-view="dashboard">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
            </svg>
            <span>Dashboard</span>
        </a>

        <a class="nav-item" id="nav-due" href="#" data-view="due">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            <span>Due Today</span>
            <span class="badge" id="dueTodayCount">0</span>
        </a>

        <a class="nav-item" id="nav-analytics" href="#" data-view="analytics">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
            </svg>
            <span>Analytics</span>
        </a>

        <div class="sidebar-divider"></div>

        <!-- My Boards Section -->
        <div class="nav-section">
            <div class="nav-section-header" id="boardsToggle">
                <div class="nav-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>
                    </svg>
                    <span>My Boards</span>
                </div>
                <svg class="chevron open" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </div>
            <div class="nav-submenu open" id="boardsSubmenu">
                <div id="boardsList"></div>
                <a class="nav-item nav-item-add" href="#" id="addBoardBtn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>New Board</span>
                </a>
            </div>
        </div>

        <!-- Shared Boards Section -->
        <div class="nav-section" id="sharedSection" style="display:none">
            <div class="nav-section-header" id="sharedToggle">
                <div class="nav-section-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                    <span>Shared with Me</span>
                </div>
                <svg class="chevron open" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
            </div>
            <div class="nav-submenu open" id="sharedBoardsSubmenu">
                <div id="sharedBoardsList"></div>
            </div>
        </div>
    </nav>
</aside>

<!-- Sidebar Overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Main Content Area -->
<main class="main-content" id="mainContent">

    <!-- ========================================== -->
    <!-- VIEW 1: DASHBOARD VIEW                    -->
    <!-- ========================================== -->
    <div class="view" id="view-dashboard">
        <div class="dash-hero">
            <div class="dash-hero-content">
                <div class="dash-greeting" id="dashGreeting">Good day, <?= $userName ?></div>
                <div class="dash-subtitle" id="dashDateSubtitle">Here is your daily agenda and progress overview.</div>
            </div>
            <div class="dash-hero-actions">
                <button class="btn btn-primary" id="dashCreateTaskBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>Create Task</span>
                </button>
                <button class="btn btn-secondary" id="dashCreateBoardBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span>New Board</span>
                </button>
            </div>
        </div>

        <!-- Metric Stat Cards -->
        <div class="stat-cards-grid">
            <div class="stat-card">
                <div class="stat-card-icon icon-blue">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value" id="dashStatActive">0</div>
                    <div class="stat-card-label">Active Tasks</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-icon icon-amber">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value" id="dashStatDue">0</div>
                    <div class="stat-card-label">Due Today</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-icon icon-green">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value" id="dashStatCompleted">0</div>
                    <div class="stat-card-label">Completed Today</div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-card-icon icon-red">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value" id="dashStatOverdue">0</div>
                    <div class="stat-card-label">Overdue Tasks</div>
                </div>
            </div>
        </div>

        <!-- Progress Banner -->
        <div class="dash-progress-card">
            <div class="dash-progress-header">
                <div class="dash-progress-title">Overall Task Completion</div>
                <div class="dash-progress-pct" id="dashProgressPct">0%</div>
            </div>
            <div class="dash-progress-track">
                <div class="dash-progress-fill" id="dashProgressFill" style="width: 0%"></div>
            </div>
        </div>

        <!-- Dashboard 2-Column Split: Tasks & Activity -->
        <div class="dash-split-grid">
            <!-- Left: Upcoming & Urgent Tasks -->
            <div class="dash-panel">
                <div class="dash-panel-header">
                    <div class="dash-panel-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        <span>Upcoming & Important</span>
                    </div>
                </div>
                <div class="dash-tasks-list" id="dashUpcomingTasksList">
                    <div class="empty-state-sm">No upcoming tasks for this week</div>
                </div>
            </div>

            <!-- Right: Live Activity Stream -->
            <div class="dash-panel">
                <div class="dash-panel-header">
                    <div class="dash-panel-title">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                        </svg>
                        <span>Recent Activity</span>
                    </div>
                    <button class="btn btn-ghost btn-xs" id="dashViewAllActivityBtn" title="View all activity logs">View All &rarr;</button>
                </div>
                <div class="dash-activity-list" id="dashActivityList">
                    <div class="empty-state-sm">No recent activity</div>
                </div>
                <div class="dash-panel-footer" id="dashActivityFooter" style="display:none">
                    <button class="btn-link-subtle" id="dashViewAllActivityFooterBtn">View all activity &rarr;</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- VIEW 2: BOARD VIEW (Kanban / List / Cal)  -->
    <!-- ========================================== -->
    <div class="view" id="view-board" style="display:none">
        <div class="view-header board-view-header">
            <div class="board-header-left">
                <div class="board-color-indicator" id="boardColorDot" style="background:#4f8ef7"></div>
                <h1 class="view-title board-title" id="currentBoardTitle">Board</h1>
                <span class="board-role-badge" id="boardRoleBadge" style="display:none">Shared</span>
            </div>

            <!-- View Switcher (Segmented Control) -->
            <div class="view-mode-switcher" id="viewModeSwitcher">
                <button class="view-mode-btn active" data-mode="kanban" id="btnModeKanban" title="Kanban Board (1)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="5" height="18" rx="1"/><rect x="10" y="3" width="5" height="11" rx="1"/><rect x="17" y="3" width="5" height="15" rx="1"/>
                    </svg>
                    <span>Kanban</span>
                </button>
                <button class="view-mode-btn" data-mode="list" id="btnModeList" title="List View (2)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>
                    </svg>
                    <span>List</span>
                </button>
                <button class="view-mode-btn" data-mode="calendar" id="btnModeCalendar" title="Calendar View (3)">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <span>Calendar</span>
                </button>
            </div>

            <!-- View Actions -->
            <div class="view-actions">
                <!-- Collaborators Avatar Stack & Share Button -->
                <div class="collaborators-group" id="collaboratorsGroup">
                    <div class="avatar-stack" id="boardMemberStack" title="Board collaborators"></div>
                    <button class="btn btn-share" id="shareBoardBtn" title="Share board with collaborators">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>
                        </svg>
                        <span>Share</span>
                    </button>
                </div>

                <!-- Board Options Menu -->
                <div class="board-menu-wrapper">
                    <button class="btn-ghost btn-sm" id="boardOptionsBtn" title="Board Options">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="12" cy="5" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="19" r="2"/>
                        </svg>
                    </button>
                    <div class="board-options-dropdown" id="boardOptionsDropdown">
                        <button class="board-opt-item" id="optRenameBoard">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                            </svg>
                            Rename Board
                        </button>
                        <button class="board-opt-item" id="optBoardMeta">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/><path d="m4.93 4.93 4.24 4.24"/><path d="m14.83 9.17 4.24-4.24"/><path d="m14.83 14.83 4.24 4.24"/><path d="m9.17 14.83-4.24 4.24"/>
                            </svg>
                            Color & Icon
                        </button>
                        <button class="board-opt-item" id="optDuplicateBoard">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                            </svg>
                            Duplicate Board
                        </button>
                        <button class="board-opt-item" id="optArchiveBoard">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>
                            </svg>
                            Archive Board
                        </button>
                        <div class="user-menu-divider"></div>
                        <button class="board-opt-item board-opt-danger" id="optDeleteBoard">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                            </svg>
                            Delete Board
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Board Filter Bar -->
        <div class="board-filter-bar">
            <div class="filter-pills" id="filterPills">
                <button class="filter-pill active" data-filter="all">All Tasks</button>
                <button class="filter-pill" data-filter="high"><span class="filter-dot high"></span>High Priority</button>
                <button class="filter-pill" data-filter="medium"><span class="filter-dot medium"></span>Medium</button>
                <button class="filter-pill" data-filter="low"><span class="filter-dot low"></span>Low</button>
                <button class="filter-pill" data-filter="today"><span class="filter-dot today"></span>Due Today</button>
                <button class="filter-pill" data-filter="my-tasks" id="filterMyTasks">Assigned to Me</button>
                <button class="filter-pill" data-filter="completed">Completed</button>
            </div>
        </div>

        <!-- Sub-View 1: Kanban Board -->
        <div class="board-container" id="boardContainer"></div>

        <!-- Sub-View 2: List View -->
        <div class="board-list-view" id="boardListView" style="display:none"></div>

        <!-- Sub-View 3: Calendar View -->
        <div class="board-calendar-view" id="boardCalendarView" style="display:none"></div>
    </div>

    <!-- ========================================== -->
    <!-- VIEW 3: DUE TODAY VIEW                    -->
    <!-- ========================================== -->
    <div class="view" id="view-due" style="display:none">
        <div class="view-header">
            <h1 class="view-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                Tasks Due Today
            </h1>
        </div>
        <div id="dueTodayList" class="due-today-list"></div>
    </div>

    <!-- ========================================== -->
    <!-- VIEW 4: ANALYTICS VIEW                    -->
    <!-- ========================================== -->
    <div class="view" id="view-analytics" style="display:none">
        <div class="view-header">
            <h1 class="view-title">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>
                </svg>
                Productivity & Analytics
            </h1>
        </div>
        <div class="analytics-container" id="analyticsContainer">
            <!-- Populated dynamically via JS -->
        </div>
    </div>

    <!-- ========================================== -->
    <!-- VIEW 5: ACTIVITY LOG VIEW                  -->
    <!-- ========================================== -->
    <div class="view" id="view-activity" style="display:none">
        <div class="view-header">
            <div class="view-header-left">
                <button class="btn btn-ghost btn-sm" id="activityBackBtn" style="margin-right:8px" title="Back to Dashboard">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
                    </svg>
                    Back to Dashboard
                </button>
                <h1 class="view-title">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                    </svg>
                    Activity Log
                </h1>
            </div>
        </div>
        <div class="activity-page-container">
            <div class="activity-page-card">
                <div class="activity-full-list" id="fullActivityList">
                    <div class="empty-state-sm">Loading activity...</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- VIEW 6: REQUEST ACCESS VIEW (Google Drive) -->
    <!-- ========================================== -->
    <div class="view" id="view-request-access" style="display:none">
        <div class="request-access-container">
            <div class="request-access-card">
                <div class="request-access-icon">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                    </svg>
                </div>
                <h1 class="request-access-title">You need access</h1>
                <p class="request-access-desc">
                    You are signed in as <strong id="reqAccUserEmail"><?= $userEmail ?></strong>.<br>
                    Ask the board owner for access:
                </p>
                <div class="request-board-badge">
                    <span class="board-nav-dot" id="reqAccBoardDot" style="background:#4f8ef7"></span>
                    <div class="req-badge-text">
                        <div class="req-board-name" id="reqAccBoardName">Board Name</div>
                        <div class="req-board-owner" id="reqAccOwnerName">Owned by Owner</div>
                    </div>
                </div>
                <div class="request-access-form" id="reqAccForm">
                    <label for="reqAccMessage" class="form-label" style="text-align:left; margin-bottom:6px">Message (optional)</label>
                    <textarea id="reqAccMessage" class="form-input form-textarea" rows="2" placeholder="Tell the owner why you need access..."></textarea>
                    <div class="req-actions">
                        <button class="btn btn-secondary" id="reqAccCancelBtn">Go to Dashboard</button>
                        <button class="btn btn-primary" id="reqAccSubmitBtn">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="22" y1="2" x2="11" y2="13"/>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                            </svg>
                            Request access
                        </button>
                    </div>
                </div>
                <div class="request-access-status" id="reqAccStatus" style="display:none">
                    <div class="req-status-icon">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                        </svg>
                    </div>
                    <div class="req-status-text">
                        <div class="req-status-title">Access requested</div>
                        <div class="req-status-desc">The board owner has been notified. You will get access once they approve your request.</div>
                    </div>
                    <button class="btn btn-secondary btn-sm" id="reqAccBackBtn" style="margin-top:16px">Return to Dashboard</button>
                </div>
            </div>
        </div>
    </div>

</main>

<!-- Bottom Navigation (Mobile) -->
<nav class="bottom-nav" id="bottomNav">
    <a class="bottom-nav-item active" href="#" data-view="dashboard" id="bnav-dash">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>
        </svg>
        <span>Dashboard</span>
    </a>
    <a class="bottom-nav-item" href="#" data-view="board" id="bnav-board">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>
        </svg>
        <span>Boards</span>
    </a>
    <a class="bottom-nav-item" href="#" id="bnav-add">
        <div class="bnav-add-btn">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
        </div>
        <span>New</span>
    </a>
    <a class="bottom-nav-item" href="#" data-view="due" id="bnav-due">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
        </svg>
        <span>Due</span>
    </a>
    <a class="bottom-nav-item" href="#" id="bnav-search">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
        </svg>
        <span>Search</span>
    </a>
</nav>

<!-- ========================================== -->
<!-- TASK DETAILS SLIDE-OVER DRAWER             -->
<!-- ========================================== -->
<div class="drawer-overlay" id="taskDrawerOverlay"></div>
<aside class="task-drawer" id="taskDrawer">
    <div class="drawer-header">
        <div class="drawer-header-left">
            <div class="drawer-task-checkbox" id="drawerTaskCheckbox" title="Toggle completed"></div>
            <div class="drawer-breadcrumb" id="drawerBreadcrumb">Board / List</div>
        </div>
        <div class="drawer-header-right">
            <button class="drawer-btn-danger" id="drawerDeleteTaskBtn" title="Delete task">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                </svg>
            </button>
            <button class="drawer-close-btn" id="taskDrawerClose" title="Close drawer (Esc)">&times;</button>
        </div>
    </div>

    <div class="drawer-body">
        <!-- Title Input -->
        <div class="drawer-title-box">
            <textarea id="drawerTaskTitle" class="drawer-title-input" rows="1" placeholder="Task title..."></textarea>
        </div>

        <!-- Meta Grid: Status, Priority, Assignee, Dates, Recurrence -->
        <div class="drawer-meta-grid">
            <div class="drawer-meta-row">
                <div class="drawer-meta-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/>
                    </svg>
                    Column
                </div>
                <div class="drawer-meta-val">
                    <select id="drawerListSelect" class="form-select form-select-sm"></select>
                </div>
            </div>

            <div class="drawer-meta-row">
                <div class="drawer-meta-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>
                    </svg>
                    Priority
                </div>
                <div class="drawer-meta-val">
                    <select id="drawerPrioritySelect" class="form-select form-select-sm">
                        <option value="low">Low</option>
                        <option value="medium">Medium</option>
                        <option value="high">High</option>
                    </select>
                </div>
            </div>

            <div class="drawer-meta-row">
                <div class="drawer-meta-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                    </svg>
                    Assignee
                </div>
                <div class="drawer-meta-val">
                    <select id="drawerAssigneeSelect" class="form-select form-select-sm">
                        <option value="">Unassigned</option>
                    </select>
                </div>
            </div>

            <div class="drawer-meta-row">
                <div class="drawer-meta-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    Due Date
                </div>
                <div class="drawer-meta-val drawer-date-group">
                    <input type="date" id="drawerDueDate" class="form-input form-input-sm">
                    <input type="time" id="drawerDueTime" class="form-input form-input-sm">
                </div>
            </div>

            <div class="drawer-meta-row">
                <div class="drawer-meta-label">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                    </svg>
                    Repeat
                </div>
                <div class="drawer-meta-val drawer-date-group">
                    <select id="drawerRecurrenceSelect" class="form-select form-select-sm">
                        <option value="none">Does not repeat</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                    <input type="number" id="drawerRecurrenceInterval" class="form-input form-input-sm" min="1" max="30" value="1" style="width:65px;display:none" title="Every X intervals">
                </div>
            </div>
        </div>

        <!-- Labels Section -->
        <div class="drawer-section drawer-section-labels">
            <div class="drawer-section-title">
                <span>Labels</span>
                <button class="btn-chip" id="drawerAddLabelBtn" type="button">+ Add Label</button>
            </div>
            <div class="drawer-labels-wrap">
                <div class="drawer-labels-list" id="drawerLabelsList"></div>
            </div>
            <!-- Quick Label Picker Dropdown / Inline Panel -->
            <div class="label-picker-dropdown" id="labelPickerDropdown" style="display:none">
                <div class="label-picker-header">
                    <span>Select or Create Label</span>
                    <button class="label-picker-close-btn" id="labelPickerCloseBtn" type="button" title="Close">&times;</button>
                </div>
                <div class="label-picker-items" id="labelPickerItems"></div>
                <div class="label-picker-create">
                    <div class="label-create-input-row">
                        <input type="text" id="newLabelInput" placeholder="New label name..." class="form-input form-input-sm" autocomplete="off">
                        <button class="btn btn-primary btn-sm" id="createLabelBtn" type="button">Add</button>
                    </div>
                    <div class="color-swatches" id="labelColorSwatches"></div>
                </div>
            </div>
        </div>

        <!-- Description Section -->
        <div class="drawer-section">
            <div class="drawer-section-title">Description</div>
            <textarea id="drawerTaskDesc" class="form-input drawer-desc-input" rows="3" placeholder="Add details or notes..."></textarea>
            <div class="drawer-desc-actions">
                <button class="btn btn-primary btn-xs" id="drawerSaveDescBtn">Save Description</button>
            </div>
        </div>

        <!-- Subtasks Checklist Section -->
        <div class="drawer-section">
            <div class="drawer-section-title">
                <span>Subtasks / Checklist</span>
                <span class="drawer-checklist-counter" id="drawerSubtaskCounter">0/0</span>
            </div>
            <div class="subtask-progress-bar">
                <div class="subtask-progress-fill" id="drawerSubtaskFill" style="width: 0%"></div>
            </div>
            <div class="subtasks-list" id="drawerSubtasksList"></div>
            <div class="subtask-add-row">
                <input type="text" id="drawerNewSubtaskInput" class="form-input form-input-sm" placeholder="Add an item...">
                <button class="btn btn-secondary btn-sm" id="drawerAddSubtaskBtn">Add</button>
            </div>
        </div>

        <!-- Attachments Section -->
        <div class="drawer-section">
            <div class="drawer-section-title">
                <span>Attachments</span>
                <button class="btn-chip" id="drawerUploadTriggerBtn">+ Add File</button>
            </div>
            <input type="file" id="drawerFileInput" style="display:none">
            <div class="drawer-attachments-list" id="drawerAttachmentsList">
                <div class="empty-state-sm">No attachments yet</div>
            </div>
        </div>

        <!-- Comments & Activity Tabs -->
        <div class="drawer-section">
            <div class="drawer-tabs">
                <button class="drawer-tab active" data-tab="comments" id="tabCommentsBtn">Comments</button>
                <button class="drawer-tab" data-tab="history" id="tabHistoryBtn">Activity Log</button>
            </div>

            <!-- Comments Panel -->
            <div class="drawer-tab-content" id="drawerCommentsPanel">
                <div class="drawer-comments-list" id="drawerCommentsList"></div>
                <div class="drawer-comment-input-box">
                    <textarea id="drawerNewCommentInput" class="form-input form-textarea" rows="2" placeholder="Write a comment..."></textarea>
                    <button class="btn btn-primary btn-sm" id="drawerSendCommentBtn">Comment</button>
                </div>
            </div>

            <!-- History Panel -->
            <div class="drawer-tab-content" id="drawerHistoryPanel" style="display:none">
                <div class="drawer-history-list" id="drawerHistoryList"></div>
            </div>
        </div>
    </div>
</aside>

<!-- ========================================== -->
<!-- MODALS                                     -->
<!-- ========================================== -->

<!-- Add / Edit Task Modal (Quick Create) -->
<div class="modal-overlay" id="taskModal">
    <div class="modal">
        <div class="modal-header">
            <h2 class="modal-title" id="taskModalTitle">Add Task</h2>
            <button class="modal-close" id="taskModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Task Name *</label>
                <input type="text" id="taskName" placeholder="What needs to be done?" class="form-input" required>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea id="taskDesc" placeholder="Add description..." class="form-input form-textarea" rows="3"></textarea>
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
            <div class="form-row">
                <div class="form-group">
                    <label>Priority</label>
                    <div class="priority-picker" id="priorityPicker">
                        <button class="priority-opt" data-priority="low">Low</button>
                        <button class="priority-opt active" data-priority="medium">Medium</button>
                        <button class="priority-opt" data-priority="high">High</button>
                    </div>
                </div>
                <div class="form-group">
                    <label>Repeat / Recurrence</label>
                    <select id="taskRecurrence" class="form-input form-select">
                        <option value="none">Does not repeat</option>
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Assign To</label>
                    <select id="taskAssignee" class="form-input form-select">
                        <option value="">Unassigned</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Board & Column</label>
                    <select id="taskBoardList" class="form-input form-select"></select>
                </div>
            </div>
            <div class="form-group">
                <label>Labels</label>
                <div class="modal-labels-picker" id="modalLabelsPicker"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" id="taskModalCancel">Cancel</button>
            <button class="btn btn-primary" id="taskModalSave">Save Task</button>
        </div>
    </div>
</div>

<!-- Share Board Modal (Google Drive Style) -->
<div class="modal-overlay" id="shareModal">
    <div class="modal modal-share">
        <div class="modal-header">
            <div>
                <h2 class="modal-title" id="shareModalTitle">Share Board</h2>
                <div class="modal-subtitle" id="shareModalSubtitle">Collaborate with your team in real time</div>
            </div>
            <button class="modal-close" id="shareModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <!-- 1. Add People & Groups / Invite by Email -->
            <div class="share-invite-form" id="shareInviteForm">
                <label class="form-label" style="margin-bottom: 8px">Add people and collaborators</label>
                <div class="invite-inputs-row">
                    <div class="invite-input-wrapper">
                        <svg class="invite-input-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/>
                            <polyline points="22,6 12,13 2,6"/>
                        </svg>
                        <input type="email" id="inviteEmail" placeholder="Add email to invite (e.g. alex@company.com)..." class="form-input invite-email-input" autocomplete="email">
                    </div>
                    <select id="inviteRole" class="form-input form-select invite-role-select">
                        <option value="editor">Editor</option>
                        <option value="viewer">Viewer</option>
                    </select>
                    <button class="btn btn-primary invite-btn" id="inviteBtn">Send</button>
                </div>
                <div id="inviteError" class="auth-message" style="display:none; margin-top:8px"></div>
            </div>

            <!-- 2. Pending Access Requests (Google Drive Style) -->
            <div class="share-requests-section" id="shareRequestsSection" style="display:none">
                <div class="share-section-heading">
                    <span class="form-label">Access Requests (<span id="shareRequestsCount">0</span>)</span>
                    <span class="req-pending-badge">Pending review</span>
                </div>
                <div class="share-requests-list" id="shareRequestsList"></div>
            </div>

            <!-- 3. People with access -->
            <div class="share-members-section">
                <div class="share-section-heading">
                    <span class="form-label">People with access</span>
                    <span class="share-member-count-badge" id="shareMemberCount">1</span>
                </div>
                <div class="share-members-list" id="shareMembersList"></div>
            </div>

            <!-- 4. General Access Section (Google Drive Style) -->
            <div class="share-general-section">
                <span class="form-label" style="margin-bottom: 8px">General access</span>
                <div class="general-access-card">
                    <div class="general-access-icon-col">
                        <div class="access-icon-circle" id="generalAccessIcon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="2" y1="12" x2="22" y2="12"/>
                                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                            </svg>
                        </div>
                    </div>
                    <div class="general-access-info-col">
                        <div class="general-access-selects">
                            <select id="generalAccessSelect" class="form-input form-select general-access-select">
                                <option value="anyone_with_link">Anyone with the link</option>
                                <option value="restricted">Restricted</option>
                            </select>
                            <select id="linkRoleSelect" class="form-input form-select link-role-select">
                                <option value="editor">Editor</option>
                                <option value="viewer">Viewer</option>
                            </select>
                        </div>
                        <div class="general-access-desc" id="generalAccessDesc">
                            Anyone on the internet with the link can join and collaborate.
                        </div>
                    </div>
                </div>

                <input type="text" id="shareLinkInput" class="form-input share-link-hidden-input" readonly>
            </div>
        </div>
        <div class="modal-footer share-modal-footer">
            <button class="btn btn-secondary copy-link-footer-btn" id="copyShareLinkBtn">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
                <span>Copy link</span>
            </button>
            <button class="btn btn-primary" id="shareModalDone">Done</button>
        </div>
    </div>
</div>

<!-- Add / Edit Board Modal -->
<div class="modal-overlay" id="boardModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2 class="modal-title" id="boardModalTitle">New Board</h2>
            <button class="modal-close" id="boardModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Board Name *</label>
                <input type="text" id="boardName" placeholder="e.g. Sprint, Product Launch, Personal..." class="form-input" required>
            </div>
            <div class="form-group">
                <label>Board Color Theme</label>
                <div class="color-palette-picker" id="boardColorPalette"></div>
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
            <h2 class="modal-title">New Column / List</h2>
            <button class="modal-close" id="listModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label>Column Name *</label>
                <input type="text" id="listName" placeholder="e.g. Backlog, In Review, QA..." class="form-input" required>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" id="listModalCancel">Cancel</button>
            <button class="btn btn-primary" id="listModalSave">Create Column</button>
        </div>
    </div>
</div>

<!-- Command Palette Modal (Ctrl+K) -->
<div class="modal-overlay" id="commandPalette">
    <div class="palette-modal">
        <div class="palette-search-wrap">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
            </svg>
            <input type="text" id="paletteInput" class="palette-input" placeholder="Type a command or search..." autocomplete="off">
            <span class="palette-esc-badge">ESC</span>
        </div>
        <div class="palette-list" id="paletteList"></div>
    </div>
</div>

<!-- Keyboard Shortcuts Modal (?) -->
<div class="modal-overlay" id="shortcutsModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2 class="modal-title">Keyboard Shortcuts</h2>
            <button class="modal-close" id="shortcutsModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="shortcuts-grid">
                <div class="shortcut-row"><kbd>Ctrl</kbd> + <kbd>K</kbd> <span>Open Command Palette</span></div>
                <div class="shortcut-row"><kbd>N</kbd> <span>Create new task</span></div>
                <div class="shortcut-row"><kbd>B</kbd> <span>Create new board</span></div>
                <div class="shortcut-row"><kbd>/</kbd> <span>Focus search</span></div>
                <div class="shortcut-row"><kbd>1</kbd> <span>Switch to Kanban View</span></div>
                <div class="shortcut-row"><kbd>2</kbd> <span>Switch to List View</span></div>
                <div class="shortcut-row"><kbd>3</kbd> <span>Switch to Calendar View</span></div>
                <div class="shortcut-row"><kbd>?</kbd> <span>Open this shortcuts cheatsheet</span></div>
                <div class="shortcut-row"><kbd>Esc</kbd> <span>Close drawer or modal</span></div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-primary" id="shortcutsModalDone">Got it</button>
        </div>
    </div>
</div>

<!-- User Settings Modal -->
<div class="modal-overlay" id="settingsModal">
    <div class="modal modal-sm">
        <div class="modal-header">
            <h2 class="modal-title">Settings</h2>
            <button class="modal-close" id="settingsModalClose">&times;</button>
        </div>
        <div class="modal-body">
            <div class="settings-section">
                <label class="form-label">Profile</label>
                <div class="form-group">
                    <label>Display Name</label>
                    <input type="text" id="settingsNameInput" class="form-input" value="<?= $userName ?>">
                </div>
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="text" class="form-input" value="<?= $userEmail ?>" readonly disabled>
                </div>
            </div>
            <div class="settings-section">
                <label class="form-label">Appearance</label>
                <div class="theme-select-row">
                    <button class="btn-theme-opt" id="themeOptDark" data-theme="dark">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                        </svg>
                        Dark Mode
                    </button>
                    <button class="btn-theme-opt" id="themeOptLight" data-theme="light">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
                        </svg>
                        Light Mode
                    </button>
                </div>
            </div>
            <div class="settings-section">
                <label class="form-label">Notifications</label>
                <div class="pref-toggle-row">
                    <span>Browser Web Push</span>
                    <button class="btn btn-secondary btn-xs" id="settingsPushToggle">Check Status</button>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" id="settingsModalCancel">Cancel</button>
            <button class="btn btn-primary" id="settingsModalSave">Save Changes</button>
        </div>
    </div>
</div>

<!-- Search Modal (Mobile) -->
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

<!-- Hidden context data -->
<div id="userId" data-id="<?= htmlspecialchars($userId) ?>" style="display:none"></div>

<script src="app.js?v=<?= filemtime(__DIR__ . '/app.js') ?>"></script>
<script src="auth-ui.js"></script>
</body>
</html>
