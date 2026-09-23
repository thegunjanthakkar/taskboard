'use strict';

// ============================
// Application State
// ============================
let state = {
    boards: [],
    activeBoardId: null,
    currentView: 'dashboard',    // 'dashboard' | 'board' | 'due' | 'analytics'
    boardViewMode: 'kanban',      // 'kanban' | 'list' | 'calendar'
    activeFilter: 'all',         // 'all' | 'high' | 'medium' | 'low' | 'today' | 'my-tasks' | 'completed'
    currentUserId: null,
    theme: document.documentElement.getAttribute('data-theme') || 'dark',
    notifPermission: 'default',
    scheduledNotifs: {},         // taskId -> timeoutId
    activeTaskDetails: null,     // Currently loaded task in drawer
    calendarMonth: new Date(),   // Current viewed month in calendar view
    dashboardData: null,
    notifications: [],
    unreadNotifCount: 0,
    editingTaskId: null,
    editingListId: null,
    editingBoardId: null,
    draggedTaskId: null,
    dragSourceListId: null,
    draggedListColId: null,
};

// ============================
// API Helpers
// ============================
async function apiCall(action, data = {}) {
    try {
        const res = await fetch('api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...data })
        });
        if (res.status === 401) {
            window.location.href = 'login.php';
            return { error: 'unauthorized' };
        }
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (parseErr) {
            console.error('API non-JSON response for action:', action, text);
            const cleanText = text.replace(/<[^>]*>?/gm, '').trim();
            return { success: false, error: cleanText && cleanText.length < 150 ? cleanText : 'Server error: Invalid response format' };
        }
    } catch (err) {
        console.error('API call failed:', action, err);
        return { success: false, error: 'Network error: ' + (err.message || 'Unable to reach server') };
    }
}

// ============================
// Initialization
// ============================
async function init() {
    detectCurrentUserId();
    await loadBoards();
    setupEventListeners();
    await registerServiceWorker();
    checkNotifPermission();

    // Support URL direct board links & auto-joining shared boards
    const urlParams = new URLSearchParams(window.location.search);
    const requestedBoard = urlParams.get('board');
    if (requestedBoard) {
        const existing = state.boards.find(b => b.id === requestedBoard);
        if (existing) {
            setView('board', requestedBoard);
        } else {
            // Attempt to join / load board via shared link
            const joinRes = await apiCall('joinBoardByLink', { boardId: requestedBoard });
            if (joinRes && joinRes.restricted) {
                // Restricted mode (Google Drive style): Show request access view
                showRequestAccessView(joinRes);
            } else if (joinRes && joinRes.success && joinRes.boards) {
                state.boards = joinRes.boards;
                state.activeBoardId = requestedBoard;
                renderSidebar();
                setView('board', requestedBoard);
                if (!joinRes.alreadyMember) {
                    showToast(`Joined "${joinRes.boardName || 'shared'}" board! 🎉`, 'success');
                }
            } else {
                showToast(joinRes?.error || 'Board not found or no longer available', 'error');
                setView('dashboard');
            }
        }
    } else {
        setView('dashboard');
    }

    renderSidebar();
    loadNotifications(true);
    // Poll for collaborative updates and notifications every 5 seconds
    setInterval(() => {
        loadNotifications(false);
    }, 5000);
    scheduleAllNotifications();
}

function showRequestAccessView(info) {
    state.pendingRequestBoardId = info.boardId;
    const nameEl = document.getElementById('reqAccBoardName');
    const ownerEl = document.getElementById('reqAccOwnerName');
    if (nameEl) nameEl.textContent = info.boardName || 'Board';
    if (ownerEl) ownerEl.textContent = `Owned by ${info.ownerName || info.ownerEmail || 'Owner'}`;
    
    const form = document.getElementById('reqAccForm');
    const status = document.getElementById('reqAccStatus');
    const msgInput = document.getElementById('reqAccMessage');
    if (msgInput) msgInput.value = '';

    if (info.hasRequested && info.requestStatus === 'pending') {
        if (form) form.style.display = 'none';
        if (status) status.style.display = '';
    } else {
        if (form) form.style.display = '';
        if (status) status.style.display = 'none';
    }

    setView('request-access');
}

function detectCurrentUserId() {
    const userIdEl = document.getElementById('userId');
    if (userIdEl && userIdEl.dataset.id) {
        const m = userIdEl.dataset.id.match(/\d+/);
        if (m) state.currentUserId = parseInt(m[0], 10);
    }
}

async function loadBoards() {
    const data = await apiCall('getBoards');
    state.boards = data.boards || [];
    if (!state.activeBoardId && state.boards.length > 0) {
        state.activeBoardId = state.boards[0].id;
    }
    updateDueTodayCount();
}

function getActiveBoard() {
    return state.boards.find(b => b.id === state.activeBoardId) || state.boards[0] || null;
}

// ============================
// View Navigation Controller
// ============================
function setView(view, boardId = null) {
    state.currentView = view;

    // Hide all views
    document.querySelectorAll('.view').forEach(v => v.style.display = 'none');
    document.querySelectorAll('.sidebar-nav .nav-item').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.bottom-nav-item').forEach(el => el.classList.remove('active'));

    if (view === 'dashboard') {
        const dashEl = document.getElementById('view-dashboard');
        if (dashEl) dashEl.style.display = '';
        document.getElementById('nav-dashboard')?.classList.add('active');
        document.getElementById('bnav-dash')?.classList.add('active');
        loadDashboard();
    } else if (view === 'due') {
        const dueEl = document.getElementById('view-due');
        if (dueEl) dueEl.style.display = '';
        document.getElementById('nav-due')?.classList.add('active');
        document.getElementById('bnav-due')?.classList.add('active');
        renderDueToday();
    } else if (view === 'analytics') {
        const anaEl = document.getElementById('view-analytics');
        if (anaEl) anaEl.style.display = '';
        document.getElementById('nav-analytics')?.classList.add('active');
        loadAnalytics();
    } else if (view === 'activity') {
        const actEl = document.getElementById('view-activity');
        if (actEl) actEl.style.display = '';
        loadFullActivity();
    } else if (view === 'request-access') {
        const reqEl = document.getElementById('view-request-access');
        if (reqEl) reqEl.style.display = '';
    } else if (view === 'board') {
        const boardEl = document.getElementById('view-board');
        if (boardEl) boardEl.style.display = '';
        document.getElementById('bnav-board')?.classList.add('active');
        if (boardId) {
            state.activeBoardId = boardId;
            if (!state.boards.some(b => b.id === boardId)) {
                loadBoards().then(() => {
                    renderSidebar();
                    renderBoard();
                });
            }
        }
        renderBoard();
    }

    renderSidebar();
    if (window.innerWidth <= 768) closeSidebar();
}

function setBoardViewMode(mode) {
    state.boardViewMode = mode;
    document.querySelectorAll('.view-mode-btn').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.mode === mode);
    });

    const kanbanEl = document.getElementById('boardContainer');
    const listEl = document.getElementById('boardListView');
    const calEl = document.getElementById('boardCalendarView');

    if (kanbanEl) kanbanEl.style.display = (mode === 'kanban') ? '' : 'none';
    if (listEl) listEl.style.display = (mode === 'list') ? '' : 'none';
    if (calEl) calEl.style.display = (mode === 'calendar') ? '' : 'none';

    renderBoard();
}

// ============================
// Dashboard View
// ============================
async function loadDashboard() {
    updateGreeting();
    const data = await apiCall('getDashboard');
    if (!data || !data.stats) return;

    state.dashboardData = data;
    renderDashboard(data);
}

function updateGreeting() {
    const greetingEl = document.getElementById('dashGreeting');
    const subEl = document.getElementById('dashDateSubtitle');
    if (!greetingEl) return;

    const hour = new Date().getHours();
    let timeOfDay = 'Good evening';
    if (hour < 12) timeOfDay = 'Good morning';
    else if (hour < 17) timeOfDay = 'Good afternoon';

    const userEl = document.getElementById('settingsNameInput');
    const name = userEl ? userEl.value.trim() : 'there';
    greetingEl.textContent = `${timeOfDay}, ${name}`;

    const dateOptions = { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' };
    const dateStr = new Date().toLocaleDateString(undefined, dateOptions);
    if (subEl) subEl.textContent = `${dateStr} — here is your progress and priority agenda.`;
}

function renderDashboard(data) {
    const s = data.stats || {};
    const elActive = document.getElementById('dashStatActive');
    const elDue = document.getElementById('dashStatDue');
    const elCompleted = document.getElementById('dashStatCompleted');
    const elOverdue = document.getElementById('dashStatOverdue');
    const elProgressPct = document.getElementById('dashProgressPct');
    const elProgressFill = document.getElementById('dashProgressFill');

    if (elActive) elActive.textContent = s.active || 0;
    if (elDue) elDue.textContent = s.dueToday || 0;
    if (elCompleted) elCompleted.textContent = s.completedToday || 0;
    if (elOverdue) elOverdue.textContent = s.overdue || 0;
    if (elProgressPct) elProgressPct.textContent = `${s.progressPercent || 0}%`;
    if (elProgressFill) elProgressFill.style.width = `${s.progressPercent || 0}%`;

    // Render Upcoming Tasks
    const upcomingContainer = document.getElementById('dashUpcomingTasksList');
    if (upcomingContainer) {
        upcomingContainer.innerHTML = '';
        const tasks = data.upcomingTasks || [];
        if (tasks.length === 0) {
            upcomingContainer.innerHTML = `<div class="empty-state-sm">No upcoming tasks scheduled for this week. Enjoy your day!</div>`;
        } else {
            tasks.forEach(task => {
                const item = document.createElement('div');
                item.className = `dash-task-item priority-${task.priority} ${task.completed ? 'completed' : ''}`;
                
                const dueInfo = getDueInfo(task);
                const dueClass = dueInfo.isOverdue ? 'overdue' : (dueInfo.isToday ? 'today' : '');

                item.innerHTML = `
                    <div class="dash-task-check ${task.completed ? 'checked' : ''}" data-task-id="${task.id}" data-board-id="${task.board_id}"></div>
                    <div class="dash-task-content">
                        <div class="dash-task-title">${escHtml(task.title)}</div>
                        <div class="dash-task-meta">
                            <span class="board-mini-tag" style="--tag-color: ${task.board_color || '#4f8ef7'}">
                                ${escHtml(task.board_name)} / ${escHtml(task.list_name)}
                            </span>
                            ${dueInfo.label ? `<span class="task-due ${dueClass}">${dueInfo.label}</span>` : ''}
                            <span class="priority-badge ${task.priority}">${task.priority}</span>
                        </div>
                    </div>
                `;

                item.addEventListener('click', e => {
                    if (e.target.closest('.dash-task-check')) return;
                    openTaskDrawer(task.id);
                });

                item.querySelector('.dash-task-check').addEventListener('click', async e => {
                    e.stopPropagation();
                    await toggleTask(task.board_id, null, task.id);
                    await loadDashboard();
                });

                upcomingContainer.appendChild(item);
            });
        }
    }

    // Render Activity Feed (Only 5 shown on dashboard)
    const activityContainer = document.getElementById('dashActivityList');
    const activityFooter = document.getElementById('dashActivityFooter');
    if (activityContainer) {
        activityContainer.innerHTML = '';
        const acts = data.recentActivity || [];
        const top5 = acts.slice(0, 5); // Show only 5 activities in dashboard

        if (acts.length === 0) {
            activityContainer.innerHTML = `<div class="empty-state-sm">No recent activity logged yet.</div>`;
            if (activityFooter) activityFooter.style.display = 'none';
        } else {
            top5.forEach(act => {
                const item = document.createElement('div');
                item.className = 'dash-activity-item';
                const timeAgo = formatTimeAgo(act.created_at);
                const initials = getInitials(act.user_name || act.user_email || 'U');

                item.innerHTML = `
                    <div class="activity-avatar">${initials}</div>
                    <div class="activity-body">
                        <div class="activity-line">
                            <strong>${escHtml(act.user_name || act.user_email)}</strong>
                            <span>${escHtml(act.details || act.action)}</span>
                        </div>
                        <div class="activity-time">${timeAgo}</div>
                    </div>
                `;
                activityContainer.appendChild(item);
            });

            if (activityFooter) {
                activityFooter.style.display = acts.length > 5 ? 'flex' : 'none';
            }
        }
    }
}

// ============================
// Render Sidebar
// ============================
function renderSidebar() {
    const myList = document.getElementById('boardsList');
    const sharedList = document.getElementById('sharedBoardsList');
    const sharedSection = document.getElementById('sharedSection');

    if (myList) myList.innerHTML = '';
    if (sharedList) sharedList.innerHTML = '';

    const myBoards = state.boards.filter(b => b.is_owner && !b.is_archived);
    const sharedBoards = state.boards.filter(b => !b.is_owner && !b.is_archived);

    myBoards.forEach(board => {
        const a = document.createElement('a');
        a.className = 'nav-item' + (board.id === state.activeBoardId && state.currentView === 'board' ? ' active' : '');
        a.href = '#';
        const memberCount = board.members ? board.members.length : 1;
        a.innerHTML = `
            <span class="board-nav-dot" style="background: ${board.color || '#4f8ef7'}"></span>
            <span class="board-item-name">${escHtml(board.name)}</span>
            ${memberCount > 1 ? `<span class="collab-badge" title="${memberCount} collaborators">${memberCount}</span>` : ''}
        `;
        a.addEventListener('click', e => {
            e.preventDefault();
            setView('board', board.id);
        });
        if (myList) myList.appendChild(a);
    });

    if (sharedBoards.length > 0) {
        if (sharedSection) sharedSection.style.display = '';
        document.getElementById('sharedBoardsSubmenu')?.classList.add('open');
        document.querySelector('#sharedToggle .chevron')?.classList.add('open');
        sharedBoards.forEach(board => {
            const a = document.createElement('a');
            a.className = 'nav-item' + (board.id === state.activeBoardId && state.currentView === 'board' ? ' active' : '');
            a.href = '#';
            const ownerBadge = board.owner_name ? `<span class="shared-by-badge">by ${escHtml(board.owner_name)}</span>` : '';
            a.innerHTML = `
                <span class="board-nav-dot" style="background: ${board.color || '#4f8ef7'}"></span>
                <span class="board-item-name" title="${escHtml(board.name)}${board.owner_name ? ' (by ' + escHtml(board.owner_name) + ')' : ''}">${escHtml(board.name)}</span>
                ${ownerBadge}
                <span class="shared-role-pill">${escHtml(board.user_role || 'shared')}</span>
            `;
            a.addEventListener('click', e => {
                e.preventDefault();
                setView('board', board.id);
            });
            if (sharedList) sharedList.appendChild(a);
        });
    } else {
        if (sharedSection) sharedSection.style.display = 'none';
    }
}

// ============================
// Render Board Dispatcher
// ============================
function renderBoard() {
    const board = getActiveBoard();
    const container = document.getElementById('boardContainer');
    const memberStack = document.getElementById('boardMemberStack');
    const roleBadge = document.getElementById('boardRoleBadge');
    const colorDot = document.getElementById('boardColorDot');

    if (!board) {
        if (container) container.innerHTML = `<div class="empty-state"><p>No board selected. Create one to get started.</p></div>`;
        document.getElementById('currentBoardTitle').textContent = 'TasksBoard';
        if (memberStack) memberStack.innerHTML = '';
        if (roleBadge) roleBadge.style.display = 'none';
        return;
    }

    document.getElementById('currentBoardTitle').textContent = board.name;
    if (colorDot) colorDot.style.background = board.color || '#4f8ef7';

    if (!board.is_owner) {
        if (roleBadge) {
            roleBadge.style.display = '';
            roleBadge.textContent = (board.user_role === 'viewer') ? 'Viewer' : 'Shared';
        }
    } else {
        if (roleBadge) roleBadge.style.display = 'none';
    }

    renderMemberStack(board.members || []);

    if (state.boardViewMode === 'kanban') {
        renderKanban(board);
    } else if (state.boardViewMode === 'list') {
        renderListView(board);
    } else if (state.boardViewMode === 'calendar') {
        renderCalendarView(board);
    }
}

function renderMemberStack(members) {
    const stack = document.getElementById('boardMemberStack');
    if (!stack) return;
    stack.innerHTML = '';
    const maxVisible = 3;
    const visible = members.slice(0, maxVisible);
    const remaining = members.length - maxVisible;

    visible.forEach(m => {
        const item = document.createElement('div');
        item.className = 'avatar-sm' + (m.is_owner ? ' is-owner' : '');
        item.title = `${m.name || m.email} (${m.role || (m.is_owner ? 'Owner' : 'Member')})`;
        item.textContent = getInitials(m.name || m.email);
        stack.appendChild(item);
    });

    if (remaining > 0) {
        const count = document.createElement('div');
        count.className = 'avatar-sm avatar-more';
        count.textContent = `+${remaining}`;
        count.title = `${remaining} more collaborators`;
        stack.appendChild(count);
    }
}

// ============================
// Sub-View 1: Kanban Board
// ============================
function renderKanban(board) {
    const container = document.getElementById('boardContainer');
    if (!container) return;
    container.innerHTML = '';

    board.lists.forEach(list => {
        container.appendChild(renderListColumn(board, list));
    });

    // Inline "+ Add list" column
    container.appendChild(renderAddListColumn(board));
}

function renderListColumn(board, list) {
    const col = document.createElement('div');
    col.className = 'list-column';
    col.dataset.listId = list.id;
    col.dataset.boardId = board.id;

    const todayStr = getTodayStr();
    const activeTasks = list.tasks.filter(t => !isFutureRecurringTask(t, todayStr));
    const completedCount = activeTasks.filter(t => t.completed && !isPastCompletedRecurringTask(t, todayStr)).length;
    const total = activeTasks.filter(t => !isPastCompletedRecurringTask(t, todayStr)).length;
    const pct = total > 0 ? Math.round((completedCount / total) * 100) : 0;

    col.innerHTML = `
        <div class="list-header" data-list-id="${list.id}">
            <div class="list-drag-indicator" title="Drag to reorder column">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                    <circle cx="8" cy="6" r="1.5"/><circle cx="16" cy="6" r="1.5"/>
                    <circle cx="8" cy="12" r="1.5"/><circle cx="16" cy="12" r="1.5"/>
                    <circle cx="8" cy="18" r="1.5"/><circle cx="16" cy="18" r="1.5"/>
                </svg>
            </div>
            <div class="list-title-group">
                <span class="list-title">${escHtml(list.name)}</span>
                <span class="list-count">${completedCount}/${total}</span>
            </div>
            <button class="list-menu-btn" data-list-id="${list.id}" title="Column options">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>
                </svg>
            </button>
        </div>
        <div class="list-progress-bar">
            <div class="list-progress-fill" style="width: ${pct}%"></div>
        </div>
        <div class="tasks-wrapper" id="tasks-${list.id}" data-list-id="${list.id}"></div>
        <button class="add-task-btn" data-list-id="${list.id}" data-board-id="${board.id}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add a task
        </button>
    `;

    const tasksWrapper = col.querySelector('.tasks-wrapper');
    const filteredTasks = getFilteredTasks(list.tasks);

    if (filteredTasks.length === 0) {
        if (total === 0) {
            tasksWrapper.innerHTML = renderEmptyState();
        } else {
            tasksWrapper.innerHTML = `<div class="empty-state-filter"><p>No tasks matching filter</p></div>`;
        }
    } else {
        filteredTasks.forEach(task => {
            tasksWrapper.appendChild(renderTaskCard(task, list, board));
        });
    }

    makeListHeaderDraggable(col, col.querySelector('.list-header'), list, board);

    col.querySelector('.list-menu-btn').addEventListener('click', e => {
        e.stopPropagation();
        showListContextMenu(e, board.id, list);
    });

    col.querySelector('.add-task-btn').addEventListener('click', () => {
        openTaskModal(null, list.id, board.id);
    });

    setupTaskDropZone(col, tasksWrapper, list, board);
    return col;
}

function renderTaskCard(task, list, board) {
    const dueInfo = getDueInfo(task);
    const dueClass = dueInfo.isOverdue ? 'overdue' : (dueInfo.isToday ? 'today' : '');
    const priorityClass = task.priority || 'medium';

    const card = document.createElement('div');
    card.className = `task-card priority-${priorityClass} ${task.completed ? 'completed' : ''}`;
    card.dataset.taskId = task.id;
    card.dataset.listId = list.id;
    card.dataset.boardId = board.id;
    card.draggable = true;

    // Badges & Pills
    let labelsHtml = '';
    if (task.labels && task.labels.length > 0) {
        labelsHtml = `<div class="card-labels-row">` + task.labels.map(l =>
            `<span class="label-pill" style="--lbl-bg: ${l.color || '#4f8ef7'}">${escHtml(l.name)}</span>`
        ).join('') + `</div>`;
    }

    let checklistBadge = '';
    if (task.subtask_count > 0) {
        checklistBadge = `
            <span class="card-pill ${task.subtask_done_count === task.subtask_count ? 'pill-done' : ''}" title="Subtasks completed">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                </svg>
                ${task.subtask_done_count}/${task.subtask_count}
            </span>
        `;
    }

    let commentsBadge = '';
    if (task.comment_count > 0) {
        commentsBadge = `
            <span class="card-pill" title="${task.comment_count} comments">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                </svg>
                ${task.comment_count}
            </span>
        `;
    }

    let attachmentsBadge = '';
    if (task.attachment_count > 0) {
        attachmentsBadge = `
            <span class="card-pill" title="${task.attachment_count} attachments">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                </svg>
                ${task.attachment_count}
            </span>
        `;
    }

    let recurrenceBadge = '';
    if (task.recurrence && task.recurrence !== 'none') {
        recurrenceBadge = `
            <span class="card-pill" title="Repeats ${task.recurrence}">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>
                </svg>
            </span>
        `;
    }

    let assigneeHtml = '';
    if (task.assigned_to) {
        const initials = getInitials(task.assigned_name || task.assigned_email || 'U');
        assigneeHtml = `
            <div class="task-assignee-pill" title="Assigned to ${escHtml(task.assigned_name || task.assigned_email)}">
                <span class="assignee-avatar-mini">${initials}</span>
            </div>
        `;
    }

    card.innerHTML = `
        <div class="task-drag-handle" title="Drag to move">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor">
                <circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/>
                <circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/>
                <circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/>
            </svg>
        </div>
        <div class="task-card-top">
            <div class="task-checkbox ${task.completed ? 'checked' : ''}"></div>
            <div class="task-body">
                ${labelsHtml}
                <div class="task-title">${escHtml(task.title)}</div>
                ${task.description ? `<div class="task-desc">${escHtml(task.description)}</div>` : ''}
                <div class="task-meta">
                    ${dueInfo.label ? `<span class="task-due ${dueClass}">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        ${dueInfo.label}
                    </span>` : ''}
                    <span class="priority-badge ${task.priority}">${task.priority}</span>
                    ${checklistBadge}
                    ${attachmentsBadge}
                    ${commentsBadge}
                    ${recurrenceBadge}
                    <div style="flex:1"></div>
                    ${assigneeHtml}
                </div>
            </div>
        </div>
    `;

    // Drag events
    card.addEventListener('dragstart', handleTaskDragStart);
    card.addEventListener('dragend', handleTaskDragEnd);

    // Click events
    card.addEventListener('click', e => {
        if (e.target.closest('.task-checkbox') || e.target.closest('.task-drag-handle')) return;
        openTaskDrawer(task.id);
    });

    card.querySelector('.task-checkbox')?.addEventListener('click', e => {
        e.stopPropagation();
        toggleTask(board.id, list.id, task.id);
    });

    return card;
}

function renderAddListColumn(board) {
    const col = document.createElement('div');
    col.className = 'add-list-column';
    col.innerHTML = `
        <button class="add-list-btn add-list-btn-card" id="inlineAddListBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            <span>Add Column</span>
        </button>
    `;
    col.querySelector('#inlineAddListBtn').addEventListener('click', () => {
        openListModal(board.id);
    });
    return col;
}

// ============================
// Sub-View 2: List View
// ============================
function renderListView(board) {
    const listContainer = document.getElementById('boardListView');
    if (!listContainer) return;
    listContainer.innerHTML = '';

    board.lists.forEach(list => {
        const group = document.createElement('div');
        group.className = 'list-view-group';

        const filtered = getFilteredTasks(list.tasks);

        group.innerHTML = `
            <div class="list-view-group-header">
                <div class="list-view-group-title">
                    <span class="group-dot" style="background: ${list.color || '#4f8ef7'}"></span>
                    <span>${escHtml(list.name)}</span>
                    <span class="group-count">(${filtered.length})</span>
                </div>
                <button class="btn btn-ghost btn-xs btn-add-in-group">+ Add Task</button>
            </div>
            <div class="list-view-table-wrapper">
                <table class="list-view-table">
                    <thead>
                        <tr>
                            <th style="width:36px"></th>
                            <th>Task Title</th>
                            <th style="width:140px">Labels</th>
                            <th style="width:90px">Priority</th>
                            <th style="width:120px">Due Date</th>
                            <th style="width:120px">Assignee</th>
                            <th style="width:100px">Metrics</th>
                        </tr>
                    </thead>
                    <tbody class="list-view-tbody"></tbody>
                </table>
            </div>
        `;

        const tbody = group.querySelector('.list-view-tbody');
        if (filtered.length === 0) {
            tbody.innerHTML = `<tr><td colspan="7" class="list-empty-row">No tasks in this column</td></tr>`;
        } else {
            filtered.forEach(task => {
                const tr = document.createElement('tr');
                tr.className = `list-row ${task.completed ? 'completed' : ''}`;
                
                const dueInfo = getDueInfo(task);
                const dueClass = dueInfo.isOverdue ? 'overdue' : (dueInfo.isToday ? 'today' : '');

                const labelsStr = (task.labels || []).map(l =>
                    `<span class="label-pill-sm" style="--lbl-bg: ${l.color}">${escHtml(l.name)}</span>`
                ).join(' ');

                const assigneeStr = task.assigned_name || task.assigned_email || '—';

                tr.innerHTML = `
                    <td><div class="task-checkbox ${task.completed ? 'checked' : ''}"></div></td>
                    <td>
                        <div class="list-row-title">${escHtml(task.title)}</div>
                        ${task.description ? `<div class="list-row-desc">${escHtml(task.description)}</div>` : ''}
                    </td>
                    <td>${labelsStr || '—'}</td>
                    <td><span class="priority-badge ${task.priority}">${task.priority}</span></td>
                    <td><span class="task-due ${dueClass}">${dueInfo.label || '—'}</span></td>
                    <td><span class="list-assignee">${escHtml(assigneeStr)}</span></td>
                    <td>
                        <div class="list-metrics-row">
                            ${task.subtask_count > 0 ? `<span title="Subtasks">✓ ${task.subtask_done_count}/${task.subtask_count}</span>` : ''}
                            ${task.attachment_count > 0 ? `<span title="Files">📎 ${task.attachment_count}</span>` : ''}
                            ${task.comment_count > 0 ? `<span title="Comments">💬 ${task.comment_count}</span>` : ''}
                        </div>
                    </td>
                `;

                tr.addEventListener('click', e => {
                    if (e.target.closest('.task-checkbox')) return;
                    openTaskDrawer(task.id);
                });

                tr.querySelector('.task-checkbox').addEventListener('click', e => {
                    e.stopPropagation();
                    toggleTask(board.id, list.id, task.id);
                });

                tbody.appendChild(tr);
            });
        }

        group.querySelector('.btn-add-in-group').addEventListener('click', () => {
            openTaskModal(null, list.id, board.id);
        });

        listContainer.appendChild(group);
    });
}

// ============================
// Sub-View 3: Calendar View
// ============================
function renderCalendarView(board) {
    const calContainer = document.getElementById('boardCalendarView');
    if (!calContainer) return;
    calContainer.innerHTML = '';

    const currentYear = state.calendarMonth.getFullYear();
    const currentMonth = state.calendarMonth.getMonth(); // 0-indexed

    const monthNames = ['January','February','March','April','May','June','July','August','September','October','November','December'];

    // Collect all tasks with due dates in this board
    const tasksWithDates = [];
    board.lists.forEach(l => {
        l.tasks.forEach(t => {
            if (t.due_date) tasksWithDates.push(t);
        });
    });

    // Calendar Header
    const calHeader = document.createElement('div');
    calHeader.className = 'calendar-header';
    calHeader.innerHTML = `
        <div class="calendar-title">${monthNames[currentMonth]} ${currentYear}</div>
        <div class="calendar-nav-btns">
            <button class="btn btn-secondary btn-sm" id="calPrevMonthBtn">&larr; Prev</button>
            <button class="btn btn-secondary btn-sm" id="calTodayBtn">Today</button>
            <button class="btn btn-secondary btn-sm" id="calNextMonthBtn">Next &rarr;</button>
        </div>
    `;

    calHeader.querySelector('#calPrevMonthBtn').addEventListener('click', () => {
        state.calendarMonth.setMonth(state.calendarMonth.getMonth() - 1);
        renderCalendarView(board);
    });

    calHeader.querySelector('#calTodayBtn').addEventListener('click', () => {
        state.calendarMonth = new Date();
        renderCalendarView(board);
    });

    calHeader.querySelector('#calNextMonthBtn').addEventListener('click', () => {
        state.calendarMonth.setMonth(state.calendarMonth.getMonth() + 1);
        renderCalendarView(board);
    });

    calContainer.appendChild(calHeader);

    // Days Grid
    const grid = document.createElement('div');
    grid.className = 'calendar-grid';

    // Weekday labels
    const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    weekdays.forEach(day => {
        const dEl = document.createElement('div');
        dEl.className = 'calendar-weekday';
        dEl.textContent = day;
        grid.appendChild(dEl);
    });

    // Calculation of days
    const firstDay = new Date(currentYear, currentMonth, 1);
    let startDayOfWeek = firstDay.getDay() - 1; // Mon = 0
    if (startDayOfWeek === -1) startDayOfWeek = 6; // Sunday = 6

    const daysInMonth = new Date(currentYear, currentMonth + 1, 0).getDate();
    const daysInPrevMonth = new Date(currentYear, currentMonth, 0).getDate();

    const todayStr = new Date().toISOString().split('T')[0];

    // Leading days from previous month
    for (let i = startDayOfWeek - 1; i >= 0; i--) {
        const cell = document.createElement('div');
        cell.className = 'calendar-cell other-month';
        cell.innerHTML = `<span class="cell-day-num">${daysInPrevMonth - i}</span>`;
        grid.appendChild(cell);
    }

    // Days in current month
    for (let day = 1; day <= daysInMonth; day++) {
        const cell = document.createElement('div');
        const dayStr = `${currentYear}-${String(currentMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const isToday = (dayStr === todayStr);

        cell.className = 'calendar-cell' + (isToday ? ' is-today' : '');
        cell.innerHTML = `
            <div class="cell-header">
                <span class="cell-day-num">${day}</span>
                <button class="cell-add-btn" title="Add task on this day">+</button>
            </div>
            <div class="cell-tasks-list"></div>
        `;

        // Add task button on day hover
        cell.querySelector('.cell-add-btn').addEventListener('click', e => {
            e.stopPropagation();
            openTaskModal(null, board.lists[0]?.id, board.id);
            const dueInput = document.getElementById('taskDueDate');
            if (dueInput) dueInput.value = dayStr;
        });

        // Populate tasks matching this day
        const dayTasks = tasksWithDates.filter(t => t.due_date === dayStr);
        const cellTasksWrap = cell.querySelector('.cell-tasks-list');

        dayTasks.forEach(task => {
            const chip = document.createElement('div');
            chip.className = `cal-task-chip priority-${task.priority} ${task.completed ? 'completed' : ''}`;
            chip.innerHTML = `
                <span class="chip-status-dot"></span>
                <span class="chip-title">${escHtml(task.title)}</span>
            `;
            chip.addEventListener('click', e => {
                e.stopPropagation();
                openTaskDrawer(task.id);
            });
            cellTasksWrap.appendChild(chip);
        });

        grid.appendChild(cell);
    }

    // Trailing days to round out 35 or 42 grid cells
    const totalCells = startDayOfWeek + daysInMonth;
    const remaining = (7 - (totalCells % 7)) % 7;
    for (let i = 1; i <= remaining; i++) {
        const cell = document.createElement('div');
        cell.className = 'calendar-cell other-month';
        cell.innerHTML = `<span class="cell-day-num">${i}</span>`;
        grid.appendChild(cell);
    }

    calContainer.appendChild(grid);
}

// ============================
// Drag and Drop (Kanban Tasks)
// ============================
function handleTaskDragStart(e) {
    state.draggedTaskId = this.dataset.taskId;
    state.dragSourceListId = this.dataset.listId;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', this.dataset.taskId);
    setTimeout(() => {
        this.classList.add('dragging');
    }, 0);
}

function handleTaskDragEnd() {
    this.classList.remove('dragging');
    document.querySelectorAll('.task-card.dragging').forEach(el => el.classList.remove('dragging'));
    document.querySelectorAll('.list-column.drag-over').forEach(el => el.classList.remove('drag-over'));
    removeDropPlaceholder();
    state.draggedTaskId = null;
    state.dragSourceListId = null;
}

function setupTaskDropZone(col, tasksWrapper, list, board) {
    col.addEventListener('dragover', e => {
        if (!state.draggedTaskId) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        col.classList.add('drag-over');

        const afterElement = getDragAfterElement(tasksWrapper, e.clientY);
        const placeholder = getOrCreateDropPlaceholder();
        if (afterElement == null) {
            tasksWrapper.appendChild(placeholder);
        } else {
            tasksWrapper.insertBefore(placeholder, afterElement);
        }
    });

    col.addEventListener('dragleave', e => {
        if (!col.contains(e.relatedTarget)) {
            col.classList.remove('drag-over');
        }
    });

    col.addEventListener('drop', async e => {
        if (!state.draggedTaskId) return;
        e.preventDefault();
        col.classList.remove('drag-over');

        const placeholder = document.getElementById('dropPlaceholder');
        let targetIndex = 0;
        if (placeholder && placeholder.parentNode === tasksWrapper) {
            const allElements = Array.from(tasksWrapper.children);
            targetIndex = allElements.indexOf(placeholder);
        }
        removeDropPlaceholder();

        const taskId = state.draggedTaskId;
        const targetListId = list.id;

        await onTaskMoved(board.id, taskId, targetListId, targetIndex);
    });
}

function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.task-card:not(.dragging)')];
    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

function getOrCreateDropPlaceholder() {
    let p = document.getElementById('dropPlaceholder');
    if (!p) {
        p = document.createElement('div');
        p.id = 'dropPlaceholder';
        p.className = 'drop-placeholder';
    }
    return p;
}

function removeDropPlaceholder() {
    const p = document.getElementById('dropPlaceholder');
    if (p && p.parentNode) p.parentNode.removeChild(p);
}

async function onTaskMoved(boardId, taskId, targetListId, targetIndex) {
    const res = await apiCall('moveTask', { boardId, taskId, targetListId, targetIndex });
    if (res.success) {
        state.boards = res.boards;
        renderBoard();
        scheduleAllNotifications();
    } else {
        showToast(res.error || 'Failed to move task', 'error');
        renderBoard();
    }
}

// Drag & drop columns
function makeListHeaderDraggable(col, header, list, board) {
    header.setAttribute('draggable', 'true');
    header.addEventListener('dragstart', e => {
        state.draggedListColId = list.id;
        e.dataTransfer.effectAllowed = 'move';
        col.classList.add('column-dragging');
    });

    header.addEventListener('dragend', () => {
        col.classList.remove('column-dragging');
        document.querySelectorAll('.list-column.column-dragging').forEach(c => c.classList.remove('column-dragging'));
        state.draggedListColId = null;
    });

    col.addEventListener('dragover', e => {
        if (!state.draggedListColId || state.draggedListColId === list.id) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
    });

    col.addEventListener('drop', async e => {
        if (!state.draggedListColId || state.draggedListColId === list.id) return;
        e.preventDefault();

        const boardEl = document.getElementById('boardContainer');
        const cols = Array.from(boardEl.querySelectorAll('.list-column'));
        const targetIndex = cols.indexOf(col);

        const res = await apiCall('moveList', { boardId: board.id, listId: state.draggedListColId, targetIndex });
        if (res.success) {
            state.boards = res.boards;
            renderBoard();
        }
    });
}

// ============================
// TASK DETAILS SLIDE-OVER DRAWER
// ============================
async function openTaskDrawer(taskId) {
    const drawer = document.getElementById('taskDrawer');
    const overlay = document.getElementById('taskDrawerOverlay');
    if (!drawer || !overlay) return;

    drawer.classList.add('open');
    overlay.classList.add('active');

    const res = await apiCall('getTaskDetails', { taskId });
    if (!res.success || !res.task) {
        showToast(res.error || 'Could not load task details', 'error');
        closeTaskDrawer();
        return;
    }

    state.activeTaskDetails = res;
    renderTaskDrawerContent(res);
}

function closeTaskDrawer() {
    const drawer = document.getElementById('taskDrawer');
    const overlay = document.getElementById('taskDrawerOverlay');
    if (drawer) drawer.classList.remove('open');
    if (overlay) overlay.classList.remove('active');
    document.getElementById('labelPickerDropdown').style.display = 'none';
    state.activeTaskDetails = null;
}

function renderTaskDrawerContent(data) {
    const task = data.task;
    const board = state.boards.find(b => b.id === task.board_id);

    // Breadcrumb
    const bc = document.getElementById('drawerBreadcrumb');
    if (bc) bc.textContent = `${task.board_name} / ${task.list_name}`;

    // Checkbox
    const cb = document.getElementById('drawerTaskCheckbox');
    if (cb) {
        cb.className = `drawer-task-checkbox ${task.completed ? 'checked' : ''}`;
        cb.onclick = async () => {
            await toggleTask(task.board_id, task.list_id, task.id);
            openTaskDrawer(task.id);
        };
    }

    // Title & Description
    const titleInput = document.getElementById('drawerTaskTitle');
    const descInput = document.getElementById('drawerTaskDesc');
    if (titleInput) titleInput.value = task.title;
    if (descInput) descInput.value = task.description || '';

    // Lists Select
    const listSelect = document.getElementById('drawerListSelect');
    if (listSelect && board) {
        listSelect.innerHTML = board.lists.map(l =>
            `<option value="${l.id}" ${l.id === task.list_id ? 'selected' : ''}>${escHtml(l.name)}</option>`
        ).join('');
    }

    // Priority
    const prioSelect = document.getElementById('drawerPrioritySelect');
    if (prioSelect) prioSelect.value = task.priority || 'medium';

    // Assignee
    const assSelect = document.getElementById('drawerAssigneeSelect');
    if (assSelect && board) {
        assSelect.innerHTML = `<option value="">Unassigned</option>` + (board.members || []).map(m =>
            `<option value="${m.id}" ${m.id === task.assigned_to ? 'selected' : ''}>${escHtml(m.name || m.email)}</option>`
        ).join('');
    }

    // Due Date & Time
    const dueD = document.getElementById('drawerDueDate');
    const dueT = document.getElementById('drawerDueTime');
    if (dueD) dueD.value = task.due_date || '';
    if (dueT) dueT.value = task.due_time || '';

    // Recurrence
    const recSelect = document.getElementById('drawerRecurrenceSelect');
    const recInt = document.getElementById('drawerRecurrenceInterval');
    if (recSelect) recSelect.value = task.recurrence || 'none';
    if (recInt) {
        recInt.value = task.recurrence_interval || 1;
        recInt.style.display = (task.recurrence && task.recurrence !== 'none') ? '' : 'none';
    }

    // Render Subtasks
    renderDrawerSubtasks(data.subtasks || []);

    // Render Labels
    renderDrawerLabels(data.assignedLabels || [], data.allLabels || []);

    // Render Attachments
    renderDrawerAttachments(data.attachments || []);

    // Render Comments
    renderDrawerComments(data.comments || []);

    // Render Activity History
    renderDrawerHistory(data.activity || []);

    // Attach real-time autosave handlers
    setupDrawerFieldAutosave(task);
}

function setupDrawerFieldAutosave(task) {
    const titleInput = document.getElementById('drawerTaskTitle');
    const listSelect = document.getElementById('drawerListSelect');
    const prioSelect = document.getElementById('drawerPrioritySelect');
    const assSelect = document.getElementById('drawerAssigneeSelect');
    const dueD = document.getElementById('drawerDueDate');
    const dueT = document.getElementById('drawerDueTime');
    const recSelect = document.getElementById('drawerRecurrenceSelect');
    const recInt = document.getElementById('drawerRecurrenceInterval');
    const descBtn = document.getElementById('drawerSaveDescBtn');
    const deleteBtn = document.getElementById('drawerDeleteTaskBtn');

    const saveChanges = async () => {
        const payload = {
            title: titleInput.value.trim() || task.title,
            description: document.getElementById('drawerTaskDesc').value,
            priority: prioSelect.value,
            assigned_to: assSelect.value ? parseInt(assSelect.value, 10) : null,
            due_date: dueD.value || null,
            due_time: dueT.value || null,
            recurrence: recSelect.value,
            recurrence_interval: parseInt(recInt.value, 10) || 1
        };

        const res = await apiCall('updateTask', {
            boardId: task.board_id,
            listId: listSelect.value,
            taskId: task.id,
            taskData: payload
        });

        if (res.success) {
            state.boards = res.boards;
            renderBoard();
        }
    };

    titleInput.onblur = saveChanges;
    listSelect.onchange = saveChanges;
    prioSelect.onchange = saveChanges;
    assSelect.onchange = saveChanges;
    dueD.onchange = saveChanges;
    dueT.onchange = saveChanges;
    recSelect.onchange = () => {
        recInt.style.display = (recSelect.value !== 'none') ? '' : 'none';
        saveChanges();
    };
    recInt.onchange = saveChanges;

    if (descBtn) {
        descBtn.onclick = async () => {
            await saveChanges();
            showToast('Description saved', 'success');
        };
    }

    if (deleteBtn) {
        deleteBtn.onclick = async () => {
            if (confirm('Delete this task?')) {
                await deleteTask(task.board_id, task.list_id, task.id);
                closeTaskDrawer();
            }
        };
    }
}

// Drawer: Subtasks
function renderDrawerSubtasks(subtasks) {
    const listEl = document.getElementById('drawerSubtasksList');
    const counterEl = document.getElementById('drawerSubtaskCounter');
    const fillEl = document.getElementById('drawerSubtaskFill');
    if (!listEl) return;

    listEl.innerHTML = '';
    const completed = subtasks.filter(s => s.completed).length;
    const total = subtasks.length;
    const pct = total > 0 ? Math.round((completed / total) * 100) : 0;

    if (counterEl) counterEl.textContent = `${completed}/${total}`;
    if (fillEl) fillEl.style.width = `${pct}%`;

    subtasks.forEach(s => {
        const row = document.createElement('div');
        row.className = `subtask-row ${s.completed ? 'completed' : ''}`;
        row.innerHTML = `
            <div class="subtask-checkbox ${s.completed ? 'checked' : ''}"></div>
            <span class="subtask-title">${escHtml(s.title)}</span>
            <button class="subtask-del-btn" title="Delete item">&times;</button>
        `;

        row.querySelector('.subtask-checkbox').addEventListener('click', async () => {
            const res = await apiCall('toggleSubtask', { subtaskId: s.id });
            if (res.success) {
                renderDrawerSubtasks(res.subtasks);
                await loadBoards();
                renderBoard();
            }
        });

        row.querySelector('.subtask-del-btn').addEventListener('click', async () => {
            const res = await apiCall('deleteSubtask', { subtaskId: s.id });
            if (res.success) {
                renderDrawerSubtasks(res.subtasks);
                await loadBoards();
                renderBoard();
            }
        });

        listEl.appendChild(row);
    });

    const addBtn = document.getElementById('drawerAddSubtaskBtn');
    const addInput = document.getElementById('drawerNewSubtaskInput');

    const submitSubtask = async () => {
        const val = addInput.value.trim();
        if (!val || !state.activeTaskDetails) return;
        const res = await apiCall('addSubtask', { taskId: state.activeTaskDetails.task.id, title: val });
        if (res.success) {
            addInput.value = '';
            renderDrawerSubtasks(res.subtasks);
            await loadBoards();
            renderBoard();
        }
    };

    if (addBtn) addBtn.onclick = submitSubtask;
    if (addInput) addInput.onkeydown = e => { if (e.key === 'Enter') submitSubtask(); };
}

// Drawer: Labels
function renderDrawerLabels(assignedLabels, allBoardLabels) {
    const wrap = document.getElementById('drawerLabelsList');
    if (!wrap) return;
    wrap.innerHTML = '';

    const currentAssigned = state.activeTaskDetails?.assignedLabels || assignedLabels || [];

    if (currentAssigned.length === 0) {
        wrap.innerHTML = '<span class="empty-labels-hint">No labels yet. Click "+ Add Label" to tag this task.</span>';
    } else {
        currentAssigned.forEach(l => {
            const pill = document.createElement('span');
            pill.className = 'label-pill-interactive';
            pill.style.setProperty('--lbl-bg', l.color || '#4f8ef7');
            pill.innerHTML = `
                <span>${escHtml(l.name)}</span>
                <button class="label-remove-btn" title="Remove label">&times;</button>
            `;
            pill.querySelector('.label-remove-btn').addEventListener('click', async e => {
                e.stopPropagation();
                if (!state.activeTaskDetails) return;
                const res = await apiCall('toggleTaskLabel', { taskId: state.activeTaskDetails.task.id, labelId: l.id });
                if (res.success) {
                    state.activeTaskDetails.assignedLabels = res.assignedLabels;
                    renderDrawerLabels(res.assignedLabels, allBoardLabels);
                    populateLabelPicker(res.assignedLabels, allBoardLabels);
                    await loadBoards();
                    renderBoard();
                } else {
                    showToast(res.error || 'Failed to remove label', 'error');
                }
            });
            wrap.appendChild(pill);
        });
    }

    // Label Picker Popup Trigger
    const addBtn = document.getElementById('drawerAddLabelBtn');
    const picker = document.getElementById('labelPickerDropdown');
    const closeBtn = document.getElementById('labelPickerCloseBtn');

    if (closeBtn && picker) {
        closeBtn.onclick = () => {
            picker.style.display = 'none';
        };
    }

    if (addBtn && picker) {
        addBtn.onclick = e => {
            e.stopPropagation();
            const isOpen = picker.style.display !== 'none';
            picker.style.display = isOpen ? 'none' : 'block';
            if (!isOpen) {
                populateLabelPicker(state.activeTaskDetails?.assignedLabels || assignedLabels, allBoardLabels);
                setTimeout(() => document.getElementById('newLabelInput')?.focus(), 50);
            }
        };
    }
}

function populateLabelPicker(assignedLabels, allBoardLabels) {
    const itemsEl = document.getElementById('labelPickerItems');
    if (!itemsEl) return;
    itemsEl.innerHTML = '';

    const currentAssigned = state.activeTaskDetails?.assignedLabels || assignedLabels || [];
    const assignedIds = new Set(currentAssigned.map(l => l.id));

    if (!allBoardLabels || allBoardLabels.length === 0) {
        itemsEl.innerHTML = '<div class="empty-state-sm" style="padding:10px 4px;font-size:0.8rem;color:var(--text-tertiary)">No labels available. Create one below:</div>';
    } else {
        allBoardLabels.forEach(l => {
            const item = document.createElement('div');
            const isActive = assignedIds.has(l.id);
            item.className = 'label-picker-row' + (isActive ? ' is-active' : '');
            item.innerHTML = `
                <span class="label-picker-dot" style="background:${l.color || '#4f8ef7'}"></span>
                <span class="label-picker-name">${escHtml(l.name)}</span>
                ${isActive ? '<span class="label-check">✓</span>' : ''}
            `;
            item.onclick = async e => {
                e.stopPropagation();
                if (!state.activeTaskDetails) return;
                const res = await apiCall('toggleTaskLabel', { taskId: state.activeTaskDetails.task.id, labelId: l.id });
                if (res.success) {
                    state.activeTaskDetails.assignedLabels = res.assignedLabels;
                    renderDrawerLabels(res.assignedLabels, allBoardLabels);
                    populateLabelPicker(res.assignedLabels, allBoardLabels);
                    await loadBoards();
                    renderBoard();
                } else {
                    showToast(res.error || 'Failed to update label', 'error');
                }
            };
            itemsEl.appendChild(item);
        });
    }

    // Swatches for new label creation
    const swatchesEl = document.getElementById('labelColorSwatches');
    if (swatchesEl) {
        swatchesEl.innerHTML = '';
        const colors = ['#ef4444', '#f59e0b', '#10b981', '#4f8ef7', '#a855f7', '#ec4899', '#64748b'];
        let selectedColor = colors[3];

        colors.forEach(c => {
            const sw = document.createElement('div');
            sw.className = 'color-swatch' + (c === selectedColor ? ' active' : '');
            sw.style.background = c;
            sw.onclick = () => {
                swatchesEl.querySelectorAll('.color-swatch').forEach(s => s.classList.remove('active'));
                sw.classList.add('active');
                selectedColor = c;
            };
            swatchesEl.appendChild(sw);
        });

        const createBtn = document.getElementById('createLabelBtn');
        const input = document.getElementById('newLabelInput');

        const handleCreate = async () => {
            const name = input.value.trim();
            if (!name || !state.activeTaskDetails) return;
            const res = await apiCall('createLabel', {
                boardId: state.activeTaskDetails.task.board_id,
                name,
                color: selectedColor
            });
            if (res.success) {
                input.value = '';
                const newLabel = { id: res.newLabelId, name, color: selectedColor };
                if (allBoardLabels && !allBoardLabels.some(x => x.id === newLabel.id)) {
                    allBoardLabels.push(newLabel);
                }
                // Automatically assign newly created label to current task
                const toggleRes = await apiCall('toggleTaskLabel', { taskId: state.activeTaskDetails.task.id, labelId: res.newLabelId });
                const updatedAssigned = toggleRes.success ? toggleRes.assignedLabels : [...currentAssigned, newLabel];
                state.activeTaskDetails.assignedLabels = updatedAssigned;
                renderDrawerLabels(updatedAssigned, allBoardLabels);
                populateLabelPicker(updatedAssigned, allBoardLabels);
                await loadBoards();
                renderBoard();
                showToast(`Label "${name}" created and added!`, 'success');
            } else {
                showToast(res.error || 'Failed to create label', 'error');
            }
        };

        if (createBtn) createBtn.onclick = handleCreate;
        if (input) {
            input.onkeydown = e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    handleCreate();
                }
            };
        }
    }
}

// Drawer: Attachments
function renderDrawerAttachments(attachments) {
    const listEl = document.getElementById('drawerAttachmentsList');
    if (!listEl) return;
    listEl.innerHTML = '';

    if (attachments.length === 0) {
        listEl.innerHTML = `<div class="empty-state-sm">No files attached yet.</div>`;
        return;
    }

    attachments.forEach(att => {
        const item = document.createElement('div');
        item.className = 'attachment-item';
        const formattedSize = formatBytes(att.file_size);

        item.innerHTML = `
            <div class="att-icon">📎</div>
            <div class="att-info">
                <a href="${att.url}" target="_blank" class="att-name" download="${escHtml(att.original_name)}">${escHtml(att.original_name)}</a>
                <div class="att-meta">${formattedSize} • ${escHtml(att.user_name)}</div>
            </div>
            ${att.can_delete ? `<button class="att-del-btn" title="Delete attachment">&times;</button>` : ''}
        `;

        if (att.can_delete) {
            item.querySelector('.att-del-btn').addEventListener('click', async () => {
                if (confirm(`Remove file "${att.original_name}"?`)) {
                    const res = await apiCall('deleteAttachment', { attachmentId: att.id });
                    if (res.success) {
                        renderDrawerAttachments(res.attachments);
                        await loadBoards();
                        renderBoard();
                    }
                }
            });
        }

        listEl.appendChild(item);
    });
}

// Drawer: Comments
function renderDrawerComments(comments) {
    const listEl = document.getElementById('drawerCommentsList');
    if (!listEl) return;
    listEl.innerHTML = '';

    if (comments.length === 0) {
        listEl.innerHTML = `<div class="empty-state-sm">No comments yet. Start the conversation!</div>`;
    } else {
        comments.forEach(c => {
            const item = document.createElement('div');
            item.className = 'comment-card';
            const initials = getInitials(c.user_name || c.user_email);
            const timeAgo = formatTimeAgo(c.created_at);

            item.innerHTML = `
                <div class="comment-avatar">${initials}</div>
                <div class="comment-content-wrap">
                    <div class="comment-header">
                        <span class="comment-author">${escHtml(c.user_name)}</span>
                        <span class="comment-time">${timeAgo}</span>
                        ${c.can_delete ? `<button class="comment-del-btn" title="Delete comment">&times;</button>` : ''}
                    </div>
                    <div class="comment-body">${escHtml(c.content)}</div>
                </div>
            `;

            if (c.can_delete) {
                item.querySelector('.comment-del-btn').addEventListener('click', async () => {
                    const res = await apiCall('deleteComment', { commentId: c.id });
                    if (res.success) {
                        renderDrawerComments(res.comments);
                        await loadBoards();
                        renderBoard();
                    }
                });
            }

            listEl.appendChild(item);
        });
    }

    const sendBtn = document.getElementById('drawerSendCommentBtn');
    const input = document.getElementById('drawerNewCommentInput');

    const submitComment = async () => {
        const val = input.value.trim();
        if (!val || !state.activeTaskDetails) return;
        const res = await apiCall('addComment', { taskId: state.activeTaskDetails.task.id, content: val });
        if (res.success) {
            input.value = '';
            renderDrawerComments(res.comments);
            await loadBoards();
            renderBoard();
        }
    };

    if (sendBtn) sendBtn.onclick = submitComment;
}

// Drawer: History
function renderDrawerHistory(history) {
    const listEl = document.getElementById('drawerHistoryList');
    if (!listEl) return;
    listEl.innerHTML = '';

    if (history.length === 0) {
        listEl.innerHTML = `<div class="empty-state-sm">No activity recorded for this task.</div>`;
        return;
    }

    history.forEach(h => {
        const item = document.createElement('div');
        item.className = 'history-item';
        item.innerHTML = `
            <div class="history-dot"></div>
            <div class="history-content">
                <div class="history-line"><strong>${escHtml(h.user_name || h.user_email)}</strong> ${escHtml(h.details || h.action)}</div>
                <div class="history-time">${formatTimeAgo(h.created_at)}</div>
            </div>
        `;
        listEl.appendChild(item);
    });
}

let _lastSeenNotifId = null;
let _isLiveSyncing = false;

async function loadNotifications(isInitial = false) {
    if (_isLiveSyncing) return;
    _isLiveSyncing = true;
    try {
        const res = await apiCall('getNotifications');
        if (!res || !res.success) return;

        const notifs = res.notifications || [];
        const unreadCount = res.unreadCount || 0;

        state.notifications = notifs;
        state.unreadNotifCount = unreadCount;

        const badge = document.getElementById('notifBadge');
        if (badge) {
            if (state.unreadNotifCount > 0) {
                badge.style.display = '';
                badge.textContent = state.unreadNotifCount > 9 ? '9+' : state.unreadNotifCount;
            } else {
                badge.style.display = 'none';
            }
        }

        renderNotificationList();

        if (notifs.length > 0) {
            const newest = notifs[0];

            // If not initial load and there are new unread notifications that arrived from other people
            if (!isInitial && _lastSeenNotifId && newest.id !== _lastSeenNotifId) {
                const incoming = [];
                for (const n of notifs) {
                    if (n.id === _lastSeenNotifId) break;
                    if (!n.is_read) incoming.push(n);
                }

                if (incoming.length > 0) {
                    const topN = incoming[0];
                    showToast(`🔔 ${topN.title} — ${topN.message || ''}`, 'info');

                    // If tab is in background and desktop notification permitted, show browser notification
                    if (Notification.permission === 'granted' && document.hidden) {
                        try {
                            new Notification(topN.title, {
                                body: topN.message || '',
                                icon: 'icons/icon-192.png',
                                tag: topN.id
                            });
                        } catch (err) {}
                    }

                    // Live auto-refresh of board / dashboard when changes occur
                    await loadBoards();
                    if (state.currentView === 'board') {
                        renderBoard();
                    } else if (state.currentView === 'dashboard') {
                        loadDashboard();
                    } else if (state.currentView === 'due') {
                        renderDueToday();
                    }
                    updateDueTodayCount();
                }
            }

            _lastSeenNotifId = newest.id;
        }
    } catch (e) {
        // Non-fatal
    } finally {
        _isLiveSyncing = false;
    }
}

function renderNotificationList() {
    const listEl = document.getElementById('notifList');
    if (!listEl) return;
    listEl.innerHTML = '';

    if (state.notifications.length === 0) {
        listEl.innerHTML = `<div class="notif-empty">All caught up! No notifications.</div>`;
        return;
    }

    state.notifications.forEach(n => {
        const item = document.createElement('div');
        item.className = `notif-item ${n.is_read ? 'read' : 'unread'}`;
        const timeAgo = formatTimeAgo(n.created_at);

        item.innerHTML = `
            <div class="notif-item-title">${escHtml(n.title)}</div>
            ${n.message ? `<div class="notif-item-msg">${escHtml(n.message)}</div>` : ''}
            <div class="notif-item-time">${timeAgo}</div>
        `;

        item.onclick = async () => {
            if (!n.is_read) {
                await apiCall('markNotificationRead', { notificationId: n.id });
                n.is_read = true;
                loadNotifications();
            }
            if (n.entity_type === 'task' && n.entity_id) {
                document.getElementById('notifPopover').classList.remove('open');
                openTaskDrawer(n.entity_id);
            } else if (n.entity_type === 'board' && n.entity_id) {
                document.getElementById('notifPopover').classList.remove('open');
                setView('board', n.entity_id);
            }
        };

        listEl.appendChild(item);
    });
}

// ============================
// Analytics View
// ============================
async function loadAnalytics() {
    const container = document.getElementById('analyticsContainer');
    if (!container) return;
    container.innerHTML = `<div class="empty-state"><p>Loading productivity analytics...</p></div>`;

    const res = await apiCall('getAnalytics');
    if (!res) return;

    renderAnalytics(res);
}

function renderAnalytics(data) {
    const container = document.getElementById('analyticsContainer');
    if (!container) return;
    container.innerHTML = '';

    const rate = data.completionRate || 0;

    // Stat Cards
    const statsHtml = `
        <div class="stat-cards-grid">
            <div class="stat-card">
                <div class="stat-card-icon icon-blue">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                    </svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value">${data.completedTasks || 0}</div>
                    <div class="stat-card-label">Completed Tasks</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon icon-amber">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                    </svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value">${data.activeTasks || 0}</div>
                    <div class="stat-card-label">Pending Tasks</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon icon-green">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value">${rate}%</div>
                    <div class="stat-card-label">Completion Rate</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon icon-red">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value">${data.overdueTasks || 0}</div>
                    <div class="stat-card-label">Overdue Tasks</div>
                </div>
            </div>
        </div>
    `;

    // Weekly Productivity Bars
    const weekly = data.weekly || [];
    const maxVal = Math.max(...weekly.map(w => w.count), 1);

    const weeklyBarsHtml = weekly.map(w => {
        const heightPct = Math.round((w.count / maxVal) * 100);
        return `
            <div class="chart-bar-col">
                <div class="chart-bar-count">${w.count}</div>
                <div class="chart-bar-track">
                    <div class="chart-bar-fill" style="height:${heightPct}%"></div>
                </div>
                <div class="chart-bar-label">${w.day}</div>
            </div>
        `;
    }).join('');

    // Priority Distribution
    const prio = data.priority || { high: 0, medium: 0, low: 0 };
    const prioTotal = (prio.high + prio.medium + prio.low) || 1;
    const highPct = Math.round((prio.high / prioTotal) * 100);
    const medPct = Math.round((prio.medium / prioTotal) * 100);
    const lowPct = Math.round((prio.low / prioTotal) * 100);

    // Board Breakdown
    const boardRowsHtml = (data.boards || []).map(b => `
        <div class="analytics-board-row">
            <div class="analytics-board-name">
                <span class="board-nav-dot" style="background:${b.color || '#4f8ef7'}"></span>
                <span>${escHtml(b.name)}</span>
            </div>
            <div class="analytics-board-stat">${b.completed} / ${b.total} done</div>
            <div class="analytics-board-bar">
                <div class="analytics-board-bar-fill" style="width:${b.rate}%;background:${b.color || '#4f8ef7'}"></div>
            </div>
            <div class="analytics-board-pct">${b.rate}%</div>
        </div>
    `).join('');

    container.innerHTML = `
        ${statsHtml}
        <div class="analytics-split-grid">
            <!-- Weekly Chart -->
            <div class="dash-panel">
                <div class="dash-panel-header">
                    <div class="dash-panel-title">Tasks Completed (Last 7 Days)</div>
                </div>
                <div class="weekly-chart-wrap">
                    ${weeklyBarsHtml}
                </div>
            </div>

            <!-- Priority Breakdown -->
            <div class="dash-panel">
                <div class="dash-panel-header">
                    <div class="dash-panel-title">Priority Breakdown</div>
                </div>
                <div class="prio-breakdown-wrap">
                    <div class="prio-row">
                        <span class="priority-badge high">High (${prio.high})</span>
                        <div class="prio-bar"><div class="prio-bar-fill prio-high-fill" style="width:${highPct}%"></div></div>
                        <span class="prio-pct">${highPct}%</span>
                    </div>
                    <div class="prio-row">
                        <span class="priority-badge medium">Medium (${prio.medium})</span>
                        <div class="prio-bar"><div class="prio-bar-fill prio-med-fill" style="width:${medPct}%"></div></div>
                        <span class="prio-pct">${medPct}%</span>
                    </div>
                    <div class="prio-row">
                        <span class="priority-badge low">Low (${prio.low})</span>
                        <div class="prio-bar"><div class="prio-bar-fill prio-low-fill" style="width:${lowPct}%"></div></div>
                        <span class="prio-pct">${lowPct}%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Board Progress -->
        <div class="dash-panel" style="margin-top:20px">
            <div class="dash-panel-header">
                <div class="dash-panel-title">Board Completion Rates</div>
            </div>
            <div class="analytics-boards-list">
                ${boardRowsHtml || '<div class="empty-state-sm">No boards created yet.</div>'}
            </div>
        </div>
    `;
}

// ============================
// Full Activity Log View
// ============================
async function loadFullActivity() {
    const listEl = document.getElementById('fullActivityList');
    if (!listEl) return;
    listEl.innerHTML = `<div class="empty-state-sm">Loading activity logs...</div>`;

    const res = await apiCall('getAllActivity');
    const acts = (res && res.activity) ? res.activity : [];

    if (acts.length === 0) {
        listEl.innerHTML = `
            <div class="empty-state">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
                </svg>
                <p>No activity logs recorded yet.</p>
            </div>
        `;
        return;
    }

    listEl.innerHTML = '';
    acts.forEach(act => {
        const item = document.createElement('div');
        item.className = 'activity-full-item';
        const timeAgo = formatTimeAgo(act.created_at);
        const initials = getInitials(act.user_name || act.user_email || 'U');

        let taskLink = '';
        if (act.task_id && act.task_title) {
            taskLink = `<span class="activity-task-link" data-task-id="${act.task_id}">📋 ${escHtml(act.task_title)}</span>`;
        }

        let boardTag = '';
        if (act.board_name) {
            boardTag = `<span class="board-mini-tag" style="--tag-color:${act.board_color || '#4f8ef7'}">${escHtml(act.board_name)}</span>`;
        }

        item.innerHTML = `
            <div class="activity-full-avatar">${initials}</div>
            <div class="activity-full-content">
                <div class="activity-full-header">
                    <span class="activity-full-user">${escHtml(act.user_name || act.user_email)}</span>
                    <span class="activity-full-time" title="${act.created_at}">${timeAgo}</span>
                </div>
                <div class="activity-full-details">${escHtml(act.details || act.action)}</div>
                <div class="activity-full-meta">
                    ${boardTag}
                    ${taskLink}
                </div>
            </div>
        `;

        item.querySelector('.activity-task-link')?.addEventListener('click', (e) => {
            e.stopPropagation();
            openTaskDrawer(act.task_id);
        });

        listEl.appendChild(item);
    });
}

// ============================
// Command Palette (Ctrl+K)
// ============================
function openCommandPalette() {
    const modal = document.getElementById('commandPalette');
    const input = document.getElementById('paletteInput');
    if (!modal || !input) return;

    modal.classList.add('open');
    input.value = '';
    renderPaletteResults('');
    setTimeout(() => input.focus(), 50);
}

function closeCommandPalette() {
    const modal = document.getElementById('commandPalette');
    if (modal) modal.classList.remove('open');
}

function renderPaletteResults(query) {
    const list = document.getElementById('paletteList');
    if (!list) return;
    list.innerHTML = '';
    const q = query.toLowerCase().trim();

    const staticCommands = [
        { label: 'Go to Dashboard', icon: '🏠', action: () => setView('dashboard') },
        { label: 'Go to Due Today', icon: '⏰', action: () => setView('due') },
        { label: 'Go to Analytics', icon: '📊', action: () => setView('analytics') },
        { label: 'Go to Activity Log', icon: '⚡', action: () => setView('activity') },
        { label: 'Create New Task', icon: '➕', action: () => openTaskModal() },
        { label: 'Create New Board', icon: '📁', action: () => openBoardModal() },
        { label: 'Switch to Kanban View', icon: '☷', action: () => { setView('board'); setBoardViewMode('kanban'); } },
        { label: 'Switch to List View', icon: '☰', action: () => { setView('board'); setBoardViewMode('list'); } },
        { label: 'Switch to Calendar View', icon: '📅', action: () => { setView('board'); setBoardViewMode('calendar'); } },
        { label: 'Toggle Light / Dark Theme', icon: '🌓', action: () => toggleTheme() },
        { label: 'Open Keyboard Shortcuts', icon: '⌨️', action: () => openShortcutsModal() },
        { label: 'Open Settings', icon: '⚙️', action: () => openSettingsModal() },
    ];

    // Add board shortcuts
    state.boards.forEach(b => {
        staticCommands.push({
            label: `Open Board: ${b.name}`,
            icon: '📋',
            action: () => setView('board', b.id)
        });
    });

    const filtered = staticCommands.filter(c => c.label.toLowerCase().includes(q));

    // Also match task titles
    const matchedTasks = [];
    if (q.length > 1) {
        state.boards.forEach(b => {
            b.lists.forEach(l => {
                l.tasks.forEach(t => {
                    if (t.title.toLowerCase().includes(q)) {
                        matchedTasks.push({ task: t, boardName: b.name });
                    }
                });
            });
        });
    }

    if (filtered.length === 0 && matchedTasks.length === 0) {
        list.innerHTML = `<div class="palette-empty">No commands or tasks found matching "${escHtml(q)}"</div>`;
        return;
    }

    filtered.forEach((cmd, idx) => {
        const item = document.createElement('div');
        item.className = 'palette-item' + (idx === 0 && matchedTasks.length === 0 ? ' selected' : '');
        item.innerHTML = `<span class="palette-icon">${cmd.icon}</span><span>${escHtml(cmd.label)}</span>`;
        item.onclick = () => {
            closeCommandPalette();
            cmd.action();
        };
        list.appendChild(item);
    });

    if (matchedTasks.length > 0) {
        const divider = document.createElement('div');
        divider.className = 'palette-divider-title';
        divider.textContent = 'Tasks Matching Search';
        list.appendChild(divider);

        matchedTasks.slice(0, 8).forEach(m => {
            const item = document.createElement('div');
            item.className = 'palette-item';
            item.innerHTML = `
                <span class="palette-icon">✓</span>
                <div class="palette-task-row">
                    <span class="palette-task-title">${escHtml(m.task.title)}</span>
                    <span class="palette-task-meta">${escHtml(m.boardName)}</span>
                </div>
            `;
            item.onclick = () => {
                closeCommandPalette();
                openTaskDrawer(m.task.id);
            };
            list.appendChild(item);
        });
    }
}

// ============================
// Keyboard Shortcuts Modal
// ============================
function openShortcutsModal() {
    document.getElementById('shortcutsModal')?.classList.add('open');
}
function closeShortcutsModal() {
    document.getElementById('shortcutsModal')?.classList.remove('open');
}

// ============================
// Settings Modal
// ============================
function openSettingsModal() {
    const modal = document.getElementById('settingsModal');
    if (!modal) return;
    modal.classList.add('open');

    // Highlight current theme
    const isDark = state.theme === 'dark';
    document.getElementById('themeOptDark')?.classList.toggle('active', isDark);
    document.getElementById('themeOptLight')?.classList.toggle('active', !isDark);
}
function closeSettingsModal() {
    document.getElementById('settingsModal')?.classList.remove('open');
}

// ============================
// Core Task / Board CRUD Operations
// ============================
async function saveTask() {
    const title = document.getElementById('taskName').value.trim();
    if (!title) {
        showToast('Please enter a task name', 'error');
        return;
    }

    const desc = document.getElementById('taskDesc').value.trim();
    const dueDate = document.getElementById('taskDueDate').value || null;
    const dueTime = document.getElementById('taskDueTime').value || null;
    const activePrio = document.querySelector('.priority-opt.active')?.dataset.priority || 'medium';
    const recurrence = document.getElementById('taskRecurrence')?.value || 'none';
    const assignedTo = document.getElementById('taskAssignee')?.value || null;

    const boardListVal = document.getElementById('taskBoardList').value;
    const [boardId, listId] = boardListVal.split(':');

    const taskData = {
        title,
        description: desc,
        due_date: dueDate,
        due_time: dueTime,
        priority: activePrio,
        recurrence: recurrence,
        assigned_to: assignedTo ? parseInt(assignedTo, 10) : null,
        label_ids: Array.from(state.modalSelectedLabelIds || [])
    };

    let res;
    if (state.editingTaskId) {
        res = await apiCall('updateTask', { boardId, listId, taskId: state.editingTaskId, taskData });
    } else {
        res = await apiCall('addTask', { boardId, listId, taskData });
    }

    if (res.success) {
        state.boards = res.boards;
        closeTaskModal();
        if (state.currentView === 'board') renderBoard();
        else if (state.currentView === 'dashboard') loadDashboard();
        updateDueTodayCount();
        scheduleAllNotifications();
        showToast(state.editingTaskId ? 'Task updated!' : 'Task created!', 'success');
    } else {
        showToast(res.error || 'Failed to save task', 'error');
    }
}

async function toggleTask(boardId, listId, taskId) {
    const res = await apiCall('toggleTask', { boardId, taskId });
    if (res.success) {
        state.boards = res.boards;
        if (state.currentView === 'board') renderBoard();
        else if (state.currentView === 'dashboard') loadDashboard();
        else if (state.currentView === 'due') renderDueToday();
        updateDueTodayCount();
        scheduleAllNotifications();
    }
}

async function deleteTask(boardId, listId, taskId) {
    const res = await apiCall('deleteTask', { boardId, taskId });
    if (res.success) {
        state.boards = res.boards;
        if (state.currentView === 'board') renderBoard();
        else if (state.currentView === 'dashboard') loadDashboard();
        else if (state.currentView === 'due') renderDueToday();
        updateDueTodayCount();
        showToast('Task deleted', 'info');
    }
}

async function saveBoard() {
    const name = document.getElementById('boardName').value.trim();
    if (!name) {
        showToast('Please enter a board name', 'error');
        return;
    }
    const color = document.querySelector('.color-palette-opt.active')?.dataset.color || '#4f8ef7';

    let res;
    if (state.editingBoardId) {
        res = await apiCall('updateBoardMeta', { boardId: state.editingBoardId, name, color });
    } else {
        res = await apiCall('addBoard', { name, color });
    }

    if (res.success) {
        state.boards = res.boards;
        if (res.newBoardId) state.activeBoardId = res.newBoardId;
        closeBoardModal();
        setView('board', state.activeBoardId);
        showToast(state.editingBoardId ? 'Board updated!' : 'Board created!', 'success');
    } else {
        showToast(res.error || 'Failed to save board', 'error');
    }
}

async function deleteBoard(boardId) {
    const board = state.boards.find(b => b.id === boardId);
    const confirmMsg = board?.is_owner
        ? `Are you sure you want to delete board "${board?.name}"? All tasks inside will be deleted.`
        : `Are you sure you want to leave board "${board?.name}"?`;

    if (!confirm(confirmMsg)) return;

    const res = await apiCall('deleteBoard', { boardId });
    if (res.success) {
        state.boards = res.boards;
        state.activeBoardId = state.boards[0]?.id || null;
        setView('dashboard');
        showToast('Board removed', 'info');
    }
}

async function saveList() {
    const name = document.getElementById('listName').value.trim();
    if (!name) {
        showToast('Please enter a column name', 'error');
        return;
    }

    let res;
    if (state.editingListId) {
        res = await apiCall('renameList', { boardId: state.activeBoardId, listId: state.editingListId, name });
    } else {
        res = await apiCall('addList', { boardId: state.activeBoardId, name });
    }

    if (res.success) {
        state.boards = res.boards;
        closeListModal();
        renderBoard();
        showToast(state.editingListId ? 'Column renamed!' : 'Column added!', 'success');
    } else {
        showToast(res.error || 'Failed to save column', 'error');
    }
}

// ============================
// Modals Open / Close Helpers
// ============================
function openTaskModal(task = null, defaultListId = null, defaultBoardId = null) {
    state.editingTaskId = task ? task.id : null;
    const modal = document.getElementById('taskModal');
    const titleEl = document.getElementById('taskModalTitle');
    const nameInput = document.getElementById('taskName');
    const descInput = document.getElementById('taskDesc');
    const dueDateInput = document.getElementById('taskDueDate');
    const dueTimeInput = document.getElementById('taskDueTime');
    const recurrenceSelect = document.getElementById('taskRecurrence');
    const assigneeSelect = document.getElementById('taskAssignee');
    const boardListSelect = document.getElementById('taskBoardList');

    titleEl.textContent = task ? 'Edit Task' : 'Add Task';
    nameInput.value = task ? task.title : '';
    descInput.value = task ? (task.description || '') : '';
    dueDateInput.value = task ? (task.due_date || '') : '';
    dueTimeInput.value = task ? (task.due_time || '') : '';

    if (recurrenceSelect) recurrenceSelect.value = task ? (task.recurrence || 'none') : 'none';

    // Populate board lists select
    boardListSelect.innerHTML = '';
    state.boards.forEach(b => {
        const optgroup = document.createElement('optgroup');
        optgroup.label = b.name;
        b.lists.forEach(l => {
            const opt = document.createElement('option');
            opt.value = `${b.id}:${l.id}`;
            opt.textContent = `${b.name} › ${l.name}`;
            if (task && task.list_id === l.id) opt.selected = true;
            else if (!task && b.id === (defaultBoardId || state.activeBoardId) && l.id === defaultListId) opt.selected = true;
            optgroup.appendChild(opt);
        });
        boardListSelect.appendChild(optgroup);
    });

    // Populate assignee select based on active board
    const currentBoard = getActiveBoard();
    if (assigneeSelect && currentBoard) {
        assigneeSelect.innerHTML = `<option value="">Unassigned</option>` + (currentBoard.members || []).map(m =>
            `<option value="${m.id}" ${task && task.assigned_to === m.id ? 'selected' : ''}>${escHtml(m.name || m.email)}</option>`
        ).join('');
    }

    // Priority picker
    const prio = task ? task.priority : 'medium';
    document.querySelectorAll('.priority-opt').forEach(b => {
        b.classList.toggle('active', b.dataset.priority === prio);
    });

    // Populate modal labels picker
    const modalLabelsEl = document.getElementById('modalLabelsPicker');
    state.modalSelectedLabelIds = new Set((task && task.labels) ? task.labels.map(l => l.id) : []);

    const updateModalLabels = () => {
        if (!modalLabelsEl) return;
        modalLabelsEl.innerHTML = '';
        const currentSelectedBoardId = (boardListSelect.value ? boardListSelect.value.split(':')[0] : null) || (task ? task.board_id : state.activeBoardId);
        const boardObj = state.boards.find(b => b.id === currentSelectedBoardId) || getActiveBoard();
        const availableLabels = boardObj?.labels || [];

        if (availableLabels.length === 0) {
            modalLabelsEl.innerHTML = '<span style="font-size:0.78rem;color:var(--text-muted);font-style:italic">No labels on this board</span>';
            return;
        }

        availableLabels.forEach(lbl => {
            const chip = document.createElement('div');
            const isActive = state.modalSelectedLabelIds.has(lbl.id);
            chip.className = 'modal-label-chip' + (isActive ? ' active' : '');
            chip.style.setProperty('--lbl-bg', lbl.color || '#4f8ef7');
            chip.innerHTML = `
                <span class="modal-label-dot" style="background:${lbl.color || '#4f8ef7'}"></span>
                <span>${escHtml(lbl.name)}</span>
                ${isActive ? '<span>✓</span>' : ''}
            `;
            chip.onclick = () => {
                if (state.modalSelectedLabelIds.has(lbl.id)) {
                    state.modalSelectedLabelIds.delete(lbl.id);
                } else {
                    state.modalSelectedLabelIds.add(lbl.id);
                }
                updateModalLabels();
            };
            modalLabelsEl.appendChild(chip);
        });
    };

    updateModalLabels();
    boardListSelect.onchange = updateModalLabels;

    modal.classList.add('open');
    setTimeout(() => nameInput.focus(), 100);
}

function closeTaskModal() {
    document.getElementById('taskModal')?.classList.remove('open');
    state.editingTaskId = null;
    state.modalSelectedLabelIds = new Set();
}

function openBoardModal(board = null) {
    state.editingBoardId = board ? board.id : null;
    const modal = document.getElementById('boardModal');
    const titleEl = document.getElementById('boardModalTitle');
    const nameInput = document.getElementById('boardName');
    const paletteEl = document.getElementById('boardColorPalette');

    titleEl.textContent = board ? 'Edit Board' : 'New Board';
    nameInput.value = board ? board.name : '';

    // Color swatches
    if (paletteEl) {
        paletteEl.innerHTML = '';
        const colors = ['#4f8ef7', '#10b981', '#f59e0b', '#ef4444', '#a855f7', '#06b6d4', '#ec4899', '#64748b'];
        const activeColor = board ? board.color : '#4f8ef7';

        colors.forEach(c => {
            const opt = document.createElement('button');
            opt.className = 'color-palette-opt' + (c === activeColor ? ' active' : '');
            opt.dataset.color = c;
            opt.style.background = c;
            opt.onclick = () => {
                paletteEl.querySelectorAll('.color-palette-opt').forEach(b => b.classList.remove('active'));
                opt.classList.add('active');
            };
            paletteEl.appendChild(opt);
        });
    }

    modal.classList.add('open');
    setTimeout(() => nameInput.focus(), 100);
}

function closeBoardModal() {
    document.getElementById('boardModal')?.classList.remove('open');
    state.editingBoardId = null;
}

function openListModal(boardId, list = null) {
    state.editingListId = list ? list.id : null;
    const modal = document.getElementById('listModal');
    const nameInput = document.getElementById('listName');
    nameInput.value = list ? list.name : '';
    modal.classList.add('open');
    setTimeout(() => nameInput.focus(), 100);
}

function closeListModal() {
    document.getElementById('listModal')?.classList.remove('open');
    state.editingListId = null;
}

// ============================
// Context Menus & Options
// ============================
function showListContextMenu(e, boardId, list) {
    removeContextMenu();
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.id = 'activeContextMenu';
    menu.style.top = `${e.clientY + 5}px`;
    menu.style.left = `${Math.min(e.clientX, window.innerWidth - 180)}px`;

    menu.innerHTML = `
        <div class="ctx-item" id="ctxRenameList">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
            </svg>
            Rename Column
        </div>
        <div class="ctx-item" id="ctxAddTaskInCol">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add Task
        </div>
        <div class="ctx-divider"></div>
        <div class="ctx-item danger" id="ctxDeleteList">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
            </svg>
            Delete Column
        </div>
    `;

    document.body.appendChild(menu);

    menu.querySelector('#ctxRenameList').addEventListener('click', () => {
        removeContextMenu();
        openListModal(boardId, list);
    });

    menu.querySelector('#ctxAddTaskInCol').addEventListener('click', () => {
        removeContextMenu();
        openTaskModal(null, list.id, boardId);
    });

    menu.querySelector('#ctxDeleteList').addEventListener('click', async () => {
        removeContextMenu();
        if (confirm(`Delete column "${list.name}" and all its tasks?`)) {
            const res = await apiCall('deleteList', { boardId, listId: list.id });
            if (res.success) {
                state.boards = res.boards;
                renderBoard();
            }
        }
    });

    setTimeout(() => {
        document.addEventListener('click', removeContextMenu, { once: true });
    }, 10);
}

function removeContextMenu() {
    const m = document.getElementById('activeContextMenu');
    if (m && m.parentNode) m.parentNode.removeChild(m);
}

// ============================
// Due Today View
// ============================
function renderDueToday() {
    const listEl = document.getElementById('dueTodayList');
    if (!listEl) return;
    listEl.innerHTML = '';

    const todayStr = getTodayStr();
    const dueTasks = [];

    state.boards.forEach(b => {
        b.lists.forEach(l => {
            l.tasks.forEach(t => {
                if (t.due_date && (t.due_date <= todayStr || (!t.completed && t.due_date === todayStr))) {
                    dueTasks.push({ task: t, list: l, board: b });
                }
            });
        });
    });

    if (dueTasks.length === 0) {
        listEl.innerHTML = `
            <div class="empty-state">
                <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 14 14"/>
                </svg>
                <p>No tasks due today. You are all caught up!</p>
            </div>
        `;
        return;
    }

    dueTasks.forEach(({ task, list, board }) => {
        const item = document.createElement('div');
        item.className = `due-today-item due-task-card priority-${task.priority} ${task.completed ? 'completed' : ''}`;
        const dueInfo = getDueInfo(task);
        const dueClass = dueInfo.isOverdue ? 'overdue' : (dueInfo.isToday ? 'today' : '');

        const labelsHtml = (task.labels || []).map(l =>
            `<span class="label-pill-sm" style="--lbl-bg:${l.color || '#4f8ef7'}">${escHtml(l.name)}</span>`
        ).join(' ');

        item.innerHTML = `
            <div class="task-checkbox ${task.completed ? 'checked' : ''}" title="Toggle complete"></div>
            <div class="due-item-body">
                <div class="due-item-title">${escHtml(task.title)}</div>
                <div class="due-item-meta">
                    <span class="board-mini-tag" style="--tag-color:${board.color || '#4f8ef7'}">${escHtml(board.name)} › ${escHtml(list.name)}</span>
                    <span class="task-due ${dueClass}">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                        </svg>
                        ${dueInfo.label}
                    </span>
                    <span class="priority-badge ${task.priority}">${task.priority}</span>
                    ${labelsHtml}
                    ${task.subtask_count > 0 ? `<span class="card-pill" title="Subtasks">✓ ${task.subtask_done_count || 0}/${task.subtask_count}</span>` : ''}
                    ${task.attachment_count > 0 ? `<span class="card-pill" title="Attachments">📎 ${task.attachment_count}</span>` : ''}
                </div>
            </div>
        `;

        item.addEventListener('click', e => {
            if (e.target.closest('.task-checkbox')) return;
            openTaskDrawer(task.id);
        });

        item.querySelector('.task-checkbox').addEventListener('click', async e => {
            e.stopPropagation();
            await toggleTask(board.id, list.id, task.id);
            renderDueToday();
        });

        listEl.appendChild(item);
    });
}

function updateDueTodayCount() {
    const today = getTodayStr();
    let count = 0;
    state.boards.forEach(b => {
        b.lists.forEach(l => {
            l.tasks.forEach(t => {
                if (!t.completed && t.due_date && t.due_date <= today) count++;
            });
        });
    });
    const badge = document.getElementById('dueTodayCount');
    if (badge) badge.textContent = count;
}

// ============================
// Service Worker & Notifications
// ============================
let _swReg = null;

async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    try {
        _swReg = await navigator.serviceWorker.register('sw.js');
        if (_swReg) {
            _swReg.update().catch(() => {});
        }
    } catch (err) {
        console.warn('SW registration failed:', err);
    }
}

function checkNotifPermission() {
    if (!('Notification' in window)) return;
    state.notifPermission = Notification.permission;
    updatePushButtons();
}

async function updatePushButtons() {
    const enableBtn = document.getElementById('enablePushBtn');
    const testBtn = document.getElementById('testPushBtn');
    if (!enableBtn) return;

    if (!('Notification' in window) || !('serviceWorker' in navigator)) {
        enableBtn.style.display = 'none';
        if (testBtn) testBtn.style.display = 'none';
        return;
    }

    const isLocal = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if (location.protocol !== 'https:' && !isLocal) {
        enableBtn.textContent = '⚠️ Push requires HTTPS on live server';
        enableBtn.title = 'Web Push notifications require an SSL certificate (https://) on public domains';
        enableBtn.style.color = '#eab308';
        if (testBtn) testBtn.style.display = 'none';
        return;
    }

    if (Notification.permission === 'granted') {
        enableBtn.textContent = '✓ Push notifications enabled';
        enableBtn.style.color = '#10b981';
        enableBtn.disabled = true;
        if (testBtn) testBtn.style.display = '';
    } else if (Notification.permission === 'denied') {
        enableBtn.textContent = '❌ Push notifications blocked';
        enableBtn.title = 'Please reset notification permission in browser site settings';
        enableBtn.disabled = true;
        if (testBtn) testBtn.style.display = 'none';
    } else {
        enableBtn.textContent = 'Enable browser push notifications';
        enableBtn.style.color = '';
        enableBtn.disabled = false;
        if (testBtn) testBtn.style.display = 'none';
    }
}

async function subscribePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        showToast('Push notifications not supported in this browser.', 'error');
        return false;
    }

    const isLocal = location.hostname === 'localhost' || location.hostname === '127.0.0.1';
    if (location.protocol !== 'https:' && !isLocal) {
        showToast('Push notifications require HTTPS on live servers. Please access via https://', 'error');
        return false;
    }

    try {
        const reg = _swReg || await navigator.serviceWorker.ready;
        const vkRes = await fetch('vapid_public.php');
        if (!vkRes.ok) {
            const errData = await vkRes.json().catch(() => ({}));
            showToast('VAPID error: ' + (errData.error || 'vapid.php not found'), 'error');
            return false;
        }
        const { publicKey } = await vkRes.json();
        if (!publicKey) {
            showToast('VAPID public key is missing on server.', 'error');
            return false;
        }

        let sub = await reg.pushManager.getSubscription();
        if (sub) {
            // Verify if subscription key matches current server VAPID key
            const currentKeyBytes = urlBase64ToUint8Array(publicKey);
            const subKey = sub.options?.applicationServerKey;
            let keyMatches = false;
            if (subKey) {
                const subKeyBytes = new Uint8Array(subKey);
                if (subKeyBytes.length === currentKeyBytes.length) {
                    keyMatches = subKeyBytes.every((v, i) => v === currentKeyBytes[i]);
                }
            }
            if (!keyMatches) {
                await sub.unsubscribe();
                sub = null;
            }
        }

        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey)
            });
        }

        const res = await fetch('push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub.toJSON())
        });
        if (!res.ok) {
            const errJson = await res.json().catch(() => ({}));
            showToast('Failed to save subscription: ' + (errJson.error || errJson.detail || 'Server error'), 'error');
            return false;
        }
        return true;
    } catch (err) {
        console.warn('Push subscription failed:', err);
        showToast('Push setup error: ' + (err.message || 'Check browser permissions'), 'error');
        return false;
    }
}

async function requestNotifPermission() {
    if (!('Notification' in window)) {
        showToast('Notifications not supported in this browser', 'error');
        return;
    }
    const perm = await Notification.requestPermission();
    state.notifPermission = perm;
    if (perm === 'granted') {
        const ok = await subscribePush();
        if (ok) {
            showToast('Push notifications enabled! 🎉', 'success');
        }
        updatePushButtons();
        scheduleAllNotifications();
    } else {
        showToast('Notification permission was not granted', 'info');
        updatePushButtons();
    }
}

function scheduleAllNotifications() {
    if (Notification.permission !== 'granted') return;
    Object.values(state.scheduledNotifs).forEach(clearTimeout);
    state.scheduledNotifs = {};

    const now = Date.now();
    state.boards.forEach(board => {
        board.lists.forEach(list => {
            list.tasks.forEach(task => {
                if (task.completed) return;
                if (task.due_date && task.due_time) {
                    const due = new Date(`${task.due_date}T${task.due_time}`).getTime();
                    const diff = due - now;
                    if (diff > 0 && diff < 7 * 24 * 60 * 60 * 1000) {
                        scheduleTaskNotification(task, board.name, diff);
                    }
                }
            });
        });
    });
}

function scheduleTaskNotification(task, boardName, msFromNow) {
    const id = setTimeout(() => {
        if (Notification.permission === 'granted') {
            const n = new Notification('Task Due: ' + task.title, {
                body: `${boardName} — ${task.description || 'This task is now due.'}`,
                icon: 'icons/icon-192.png',
                badge: 'icons/icon-192.png',
                tag: task.id,
                requireInteraction: true
            });
            n.onclick = () => { window.focus(); n.close(); openTaskDrawer(task.id); };
        }
    }, msFromNow);
    state.scheduledNotifs[task.id] = id;
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// ============================
// Collaboration & Sharing
// ============================
function openShareModal() {
    const board = getActiveBoard();
    if (!board) return;

    const modal = document.getElementById('shareModal');
    const title = document.getElementById('shareModalTitle');
    const linkInput = document.getElementById('shareLinkInput');
    const countEl = document.getElementById('shareMemberCount');
    const listEl = document.getElementById('shareMembersList');
    const inviteForm = document.getElementById('shareInviteForm');
    const requestsSection = document.getElementById('shareRequestsSection');
    const requestsList = document.getElementById('shareRequestsList');
    const requestsCount = document.getElementById('shareRequestsCount');
    const generalSelect = document.getElementById('generalAccessSelect');
    const linkRoleSelect = document.getElementById('linkRoleSelect');

    title.textContent = `Share "${board.name}"`;
    if (linkInput) {
        linkInput.value = `${window.location.origin}${window.location.pathname}?board=${encodeURIComponent(board.id)}`;
    }

    if (!board.is_owner) {
        if (inviteForm) inviteForm.style.display = 'none';
        if (requestsSection) requestsSection.style.display = 'none';
        if (generalSelect) generalSelect.disabled = true;
        if (linkRoleSelect) linkRoleSelect.disabled = true;
    } else {
        if (inviteForm) inviteForm.style.display = '';
        if (generalSelect) generalSelect.disabled = false;
        if (linkRoleSelect) linkRoleSelect.disabled = false;
    }

    // Set General Access Dropdown and Link Role Dropdown
    const curGeneralAccess = board.general_access || 'anyone_with_link';
    const curLinkRole = board.link_role || 'editor';
    if (generalSelect) generalSelect.value = curGeneralAccess;
    if (linkRoleSelect) linkRoleSelect.value = curLinkRole;
    updateGeneralAccessUI(curGeneralAccess, curLinkRole);

    // Pending Access Requests (Google Drive style)
    const accessReqs = board.access_requests || [];
    if (board.is_owner && accessReqs.length > 0) {
        if (requestsSection) requestsSection.style.display = '';
        if (requestsCount) requestsCount.textContent = accessReqs.length;
        if (requestsList) {
            requestsList.innerHTML = '';
            accessReqs.forEach(req => {
                const reqItem = document.createElement('div');
                reqItem.className = 'share-request-item';
                reqItem.innerHTML = `
                    <div class="req-user-info">
                        <div class="req-user-name">${escHtml(req.user_name || req.user_email)}</div>
                        <div class="req-user-email">${escHtml(req.user_email)}</div>
                        ${req.message ? `<div class="req-user-msg">"${escHtml(req.message)}"</div>` : ''}
                    </div>
                    <div class="req-actions-group">
                        <button class="btn btn-primary btn-xs req-approve-btn" data-req-id="${req.id}">Approve</button>
                        <button class="btn btn-ghost btn-xs req-decline-btn" data-req-id="${req.id}">Decline</button>
                    </div>
                `;

                reqItem.querySelector('.req-approve-btn')?.addEventListener('click', async () => {
                    const res = await apiCall('handleBoardAccessRequest', {
                        boardId: board.id,
                        requestId: req.id,
                        action: 'approve',
                        role: 'editor'
                    });
                    if (res.success) {
                        state.boards = res.boards;
                        renderBoard();
                        openShareModal();
                        showToast(`Approved access for ${req.user_name || req.user_email}`, 'success');
                    }
                });

                reqItem.querySelector('.req-decline-btn')?.addEventListener('click', async () => {
                    const res = await apiCall('handleBoardAccessRequest', {
                        boardId: board.id,
                        requestId: req.id,
                        action: 'decline'
                    });
                    if (res.success) {
                        state.boards = res.boards;
                        renderBoard();
                        openShareModal();
                        showToast('Request declined', 'info');
                    }
                });

                requestsList.appendChild(reqItem);
            });
        }
    } else {
        if (requestsSection) requestsSection.style.display = 'none';
    }

    // Members list
    const members = board.members || [];
    if (countEl) countEl.textContent = members.length;
    if (listEl) {
        listEl.innerHTML = '';
        members.forEach(m => {
            const item = document.createElement('div');
            item.className = 'share-member-item';
            const isSelf = m.id === state.currentUserId;
            const initials = getInitials(m.name || m.email);

            item.innerHTML = `
                <div class="share-member-avatar">${initials}</div>
                <div class="share-member-info">
                    <div class="share-member-name">${escHtml(m.name || m.email)} ${isSelf ? '<span class="you-badge">(You)</span>' : ''}</div>
                    <div class="share-member-email">${escHtml(m.email)}</div>
                </div>
                <div class="share-member-action">
                    ${m.is_owner ? `<span class="owner-pill">Owner</span>` : (
                        board.is_owner ? `
                            <select class="form-select form-select-xs role-change-select" data-user-id="${m.id}">
                                <option value="editor" ${m.role === 'editor' ? 'selected' : ''}>Editor</option>
                                <option value="viewer" ${m.role === 'viewer' ? 'selected' : ''}>Viewer</option>
                            </select>
                            <button class="btn-ghost btn-xs remove-member-btn" data-user-id="${m.id}" title="Remove access">&times;</button>
                        ` : `<span class="role-pill">${escHtml(m.role || 'Member')}</span>`
                    )}
                </div>
            `;

            if (board.is_owner && !m.is_owner) {
                item.querySelector('.role-change-select')?.addEventListener('change', async e => {
                    const res = await apiCall('updateMemberRole', {
                        boardId: board.id,
                        memberUserId: m.id,
                        role: e.target.value
                    });
                    if (res.success) {
                        state.boards = res.boards;
                        renderBoard();
                    }
                });

                item.querySelector('.remove-member-btn')?.addEventListener('click', async () => {
                    if (confirm(`Remove ${m.name || m.email} from this board?`)) {
                        const res = await apiCall('removeBoardMember', {
                            boardId: board.id,
                            memberUserId: m.id
                        });
                        if (res.success) {
                            state.boards = res.boards;
                            renderBoard();
                            openShareModal();
                        }
                    }
                });
            }

            listEl.appendChild(item);
        });
    }

    modal.classList.add('open');
}

function updateGeneralAccessUI(mode, role) {
    const iconEl = document.getElementById('generalAccessIcon');
    const descEl = document.getElementById('generalAccessDesc');
    const roleSelect = document.getElementById('linkRoleSelect');

    if (mode === 'restricted') {
        if (iconEl) {
            iconEl.className = 'access-icon-circle restricted';
            iconEl.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                </svg>
            `;
        }
        if (descEl) descEl.textContent = 'Only people with access can open with the link.';
        if (roleSelect) roleSelect.style.display = 'none';
    } else {
        if (iconEl) {
            iconEl.className = 'access-icon-circle';
            iconEl.innerHTML = `
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="2" y1="12" x2="22" y2="12"/>
                    <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
                </svg>
            `;
        }
        if (descEl) descEl.textContent = role === 'viewer' ? 'Anyone on the internet with the link can view.' : 'Anyone on the internet with the link can join and collaborate.';
        if (roleSelect) roleSelect.style.display = '';
    }
}

function closeShareModal() {
    document.getElementById('shareModal')?.classList.remove('open');
}

async function inviteMember() {
    const board = getActiveBoard();
    if (!board) return;
    const emailInput = document.getElementById('inviteEmail');
    const roleSelect = document.getElementById('inviteRole');
    const errorEl = document.getElementById('inviteError');
    const email = emailInput.value.trim();

    if (!email) return;

    errorEl.style.display = 'none';
    const res = await apiCall('addBoardMember', {
        boardId: board.id,
        email: email,
        role: roleSelect.value
    });

    if (res.success) {
        state.boards = res.boards;
        emailInput.value = '';
        renderBoard();
        openShareModal();
        showToast(`Invited ${email}`, 'success');
    } else {
        errorEl.textContent = res.error || 'Failed to invite';
        errorEl.style.display = 'block';
    }
}

function copyShareLink() {
    const input = document.getElementById('shareLinkInput');
    const board = getActiveBoard();
    if (board && input) {
        input.value = `${window.location.origin}${window.location.pathname}?board=${encodeURIComponent(board.id)}`;
    }
    if (!input) return;
    input.select();
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(input.value).then(() => {
            showToast('Board link copied to clipboard! 📋', 'success');
        }).catch(() => {
            document.execCommand('copy');
            showToast('Board link copied to clipboard! 📋', 'success');
        });
    } else {
        document.execCommand('copy');
        showToast('Board link copied to clipboard! 📋', 'success');
    }
}

// ============================
// Theme & Utilities
// ============================
async function toggleTheme(forced = null) {
    const newTheme = forced || (state.theme === 'dark' ? 'light' : 'dark');
    state.theme = newTheme;
    document.documentElement.setAttribute('data-theme', newTheme);
    document.body.className = `theme-${newTheme}`;
    await apiCall('setTheme', { theme: newTheme });
}

function getTodayStr() {
    const d = new Date();
    const year = d.getFullYear();
    const month = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

function isFutureRecurringTask(task, todayStr = getTodayStr()) {
    if (!task || !task.recurrence || task.recurrence === 'none') return false;
    if (!task.due_date) return false;
    return task.due_date > todayStr;
}

function isPastCompletedRecurringTask(task, todayStr = getTodayStr()) {
    if (!task || !task.recurrence || task.recurrence === 'none') return false;
    if (!task.due_date) return false;
    return Boolean(task.completed) && task.due_date < todayStr;
}

function getDueInfo(task) {
    if (!task.due_date) return { label: null, isToday: false, isOverdue: false };
    const today = getTodayStr();
    const isToday = task.due_date === today;
    const isOverdue = !task.completed && task.due_date < today;

    let label = task.due_date;
    if (isToday) {
        label = task.due_time ? `Today, ${formatTime(task.due_time)}` : 'Today';
    } else if (isOverdue) {
        label = `Overdue (${task.due_date})`;
    } else {
        const d = new Date(task.due_date);
        label = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        if (task.due_time) label += ` ${formatTime(task.due_time)}`;
    }
    return { label, isToday, isOverdue };
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    const [h, m] = timeStr.split(':');
    let hr = parseInt(h, 10);
    const ampm = hr >= 12 ? 'PM' : 'AM';
    hr = hr % 12 || 12;
    return `${hr}:${m} ${ampm}`;
}

function formatTimeAgo(dateStr) {
    if (!dateStr) return '';
    const diff = (Date.now() - new Date(dateStr).getTime()) / 1000;
    if (diff < 60) return 'Just now';
    if (diff < 3600) return `${Math.floor(diff / 60)}m ago`;
    if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`;
    if (diff < 604800) return `${Math.floor(diff / 86400)}d ago`;
    return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function getInitials(nameOrEmail) {
    if (!nameOrEmail) return '?';
    const parts = nameOrEmail.split('@')[0].split(/[ ._-]/);
    if (parts.length >= 2) return (parts[0][0] + parts[1][0]).toUpperCase();
    return nameOrEmail.substring(0, 2).toUpperCase();
}

function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function showToast(msg, type = 'info') {
    const c = document.getElementById('toastContainer');
    if (!c) return;
    const t = document.createElement('div');
    t.className = `toast ${type}`;
    t.innerHTML = `
        <span class="toast-icon">${type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ'}</span>
        <span>${escHtml(msg)}</span>
    `;
    c.appendChild(t);
    setTimeout(() => {
        t.classList.add('fadeOut');
        setTimeout(() => t.remove(), 250);
    }, 3200);
}

function getFilteredTasks(tasks) {
    const today = getTodayStr();

    // Future recurring tasks should not be shown on today's active board - they will show when their due date arrives
    const nonFuture = tasks.filter(t => !isFutureRecurringTask(t, today));

    if (state.activeFilter === 'all') {
        return nonFuture.filter(t => !isPastCompletedRecurringTask(t, today));
    } else if (state.activeFilter === 'high') {
        return nonFuture.filter(t => t.priority === 'high' && !isPastCompletedRecurringTask(t, today));
    } else if (state.activeFilter === 'medium') {
        return nonFuture.filter(t => t.priority === 'medium' && !isPastCompletedRecurringTask(t, today));
    } else if (state.activeFilter === 'low') {
        return nonFuture.filter(t => t.priority === 'low' && !isPastCompletedRecurringTask(t, today));
    } else if (state.activeFilter === 'today') {
        return tasks.filter(t => t.due_date === today);
    } else if (state.activeFilter === 'my-tasks') {
        return nonFuture.filter(t => t.assigned_to && t.assigned_to === state.currentUserId && !isPastCompletedRecurringTask(t, today));
    } else if (state.activeFilter === 'completed') {
        return tasks.filter(t => t.completed);
    }
    return nonFuture;
}

function renderEmptyState() {
    return `
        <div class="empty-state">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
            </svg>
            <p>No tasks yet</p>
        </div>
    `;
}

// ============================
// Event Listeners Registration
// ============================
function setupEventListeners() {
    // Logo -> Dashboard
    document.getElementById('logoBtn')?.addEventListener('click', () => setView('dashboard'));

    // Sidebar Mobile Toggle
    document.getElementById('menuToggle')?.addEventListener('click', toggleSidebar);
    document.getElementById('sidebarOverlay')?.addEventListener('click', closeSidebar);

    // Core Nav
    document.getElementById('nav-dashboard')?.addEventListener('click', e => { e.preventDefault(); setView('dashboard'); });
    document.getElementById('nav-due')?.addEventListener('click', e => { e.preventDefault(); setView('due'); });
    document.getElementById('nav-analytics')?.addEventListener('click', e => { e.preventDefault(); setView('analytics'); });

    // Mobile Bottom Nav
    document.getElementById('bnav-dash')?.addEventListener('click', e => { e.preventDefault(); setView('dashboard'); });
    document.getElementById('bnav-board')?.addEventListener('click', e => { e.preventDefault(); setView('board'); });
    document.getElementById('bnav-due')?.addEventListener('click', e => { e.preventDefault(); setView('due'); });
    document.getElementById('bnav-add')?.addEventListener('click', e => {
        e.preventDefault();
        openTaskModal();
    });

    // Top Header Create Task
    document.getElementById('topCreateTaskBtn')?.addEventListener('click', () => openTaskModal());
    document.getElementById('dashCreateTaskBtn')?.addEventListener('click', () => openTaskModal());
    document.getElementById('dashCreateBoardBtn')?.addEventListener('click', () => openBoardModal());

    // Theme toggle
    document.getElementById('themeToggle')?.addEventListener('click', () => toggleTheme());

    // Notification Center Popover
    const notifBell = document.getElementById('notifBellBtn');
    const notifPop = document.getElementById('notifPopover');
    if (notifBell && notifPop) {
        notifBell.addEventListener('click', e => {
            e.stopPropagation();
            notifPop.classList.toggle('open');
            if (notifPop.classList.contains('open')) {
                updatePushButtons();
            }
        });
        document.addEventListener('click', e => {
            if (!notifPop.contains(e.target) && e.target !== notifBell) {
                notifPop.classList.remove('open');
            }
        });
    }

    document.getElementById('markAllNotifsReadBtn')?.addEventListener('click', async () => {
        await apiCall('markAllNotificationsRead');
        loadNotifications();
    });

    document.getElementById('enablePushBtn')?.addEventListener('click', requestNotifPermission);
    document.getElementById('testPushBtn')?.addEventListener('click', async () => {
        showToast('Sending test push notification...', 'info');
        const res = await apiCall('testPushNotification');
        if (res && res.success) {
            showToast('Test push notification delivered! 🔔', 'success');
        } else {
            showToast(res?.error || 'Test push failed. Ensure push is enabled and server is on HTTPS.', 'error');
        }
    });

    // View Switcher (Kanban, List, Calendar)
    document.querySelectorAll('.view-mode-btn').forEach(btn => {
        btn.addEventListener('click', () => setBoardViewMode(btn.dataset.mode));
    });

    // Board Options Menu Dropdown
    const boardOptBtn = document.getElementById('boardOptionsBtn');
    const boardOptDrop = document.getElementById('boardOptionsDropdown');
    if (boardOptBtn && boardOptDrop) {
        boardOptBtn.addEventListener('click', e => {
            e.stopPropagation();
            boardOptDrop.classList.toggle('open');
        });
        document.addEventListener('click', e => {
            if (!boardOptDrop.contains(e.target) && e.target !== boardOptBtn) {
                boardOptDrop.classList.remove('open');
            }
        });
    }

    document.getElementById('optRenameBoard')?.addEventListener('click', () => {
        boardOptDrop?.classList.remove('open');
        const b = getActiveBoard();
        if (b) openBoardModal(b);
    });

    document.getElementById('optBoardMeta')?.addEventListener('click', () => {
        boardOptDrop?.classList.remove('open');
        const b = getActiveBoard();
        if (b) openBoardModal(b);
    });

    document.getElementById('optDuplicateBoard')?.addEventListener('click', async () => {
        boardOptDrop?.classList.remove('open');
        const b = getActiveBoard();
        if (!b) return;
        const res = await apiCall('duplicateBoard', { boardId: b.id });
        if (res.success) {
            state.boards = res.boards;
            state.activeBoardId = res.newBoardId;
            setView('board', state.activeBoardId);
            showToast('Board duplicated!', 'success');
        }
    });

    document.getElementById('optArchiveBoard')?.addEventListener('click', async () => {
        boardOptDrop?.classList.remove('open');
        const b = getActiveBoard();
        if (!b) return;
        const res = await apiCall('archiveBoard', { boardId: b.id });
        if (res.success) {
            state.boards = res.boards;
            state.activeBoardId = state.boards[0]?.id || null;
            setView('dashboard');
            showToast('Board archived', 'info');
        }
    });

    document.getElementById('optDeleteBoard')?.addEventListener('click', () => {
        boardOptDrop?.classList.remove('open');
        if (state.activeBoardId) deleteBoard(state.activeBoardId);
    });

    // Share Board & General Access (Google Drive style)
    document.getElementById('shareBoardBtn')?.addEventListener('click', openShareModal);
    document.getElementById('boardMemberStack')?.addEventListener('click', openShareModal);
    document.getElementById('shareModalClose')?.addEventListener('click', closeShareModal);
    document.getElementById('shareModalDone')?.addEventListener('click', closeShareModal);
    document.getElementById('copyShareLinkBtn')?.addEventListener('click', copyShareLink);
    document.getElementById('inviteBtn')?.addEventListener('click', inviteMember);
    document.getElementById('inviteEmail')?.addEventListener('keydown', e => { if (e.key === 'Enter') inviteMember(); });

    document.getElementById('generalAccessSelect')?.addEventListener('change', async e => {
        const board = getActiveBoard();
        if (!board || !board.is_owner) return;
        const generalAccess = e.target.value;
        const linkRole = document.getElementById('linkRoleSelect')?.value || 'editor';
        updateGeneralAccessUI(generalAccess, linkRole);
        const res = await apiCall('updateBoardGeneralAccess', { boardId: board.id, generalAccess, linkRole });
        if (res.success) {
            state.boards = res.boards;
            renderBoard();
            showToast('Updated general access settings', 'success');
        }
    });

    document.getElementById('linkRoleSelect')?.addEventListener('change', async e => {
        const board = getActiveBoard();
        if (!board || !board.is_owner) return;
        const linkRole = e.target.value;
        const generalAccess = document.getElementById('generalAccessSelect')?.value || 'anyone_with_link';
        updateGeneralAccessUI(generalAccess, linkRole);
        const res = await apiCall('updateBoardGeneralAccess', { boardId: board.id, generalAccess, linkRole });
        if (res.success) {
            state.boards = res.boards;
            renderBoard();
            showToast(`Link permission updated to ${linkRole}`, 'success');
        }
    });

    // Request Access Screen actions
    document.getElementById('reqAccSubmitBtn')?.addEventListener('click', async () => {
        const boardId = state.pendingRequestBoardId;
        if (!boardId) return;
        const message = document.getElementById('reqAccMessage')?.value.trim() || '';
        const btn = document.getElementById('reqAccSubmitBtn');
        btn.disabled = true;
        btn.textContent = 'Sending request...';
        const res = await apiCall('requestBoardAccess', { boardId, message });
        btn.disabled = false;
        btn.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Request access`;
        if (res.success) {
            document.getElementById('reqAccForm').style.display = 'none';
            document.getElementById('reqAccStatus').style.display = '';
            showToast('Access request sent to the board owner! 📬', 'success');
        } else {
            showToast(res.error || 'Failed to send request', 'error');
        }
    });

    document.getElementById('reqAccCancelBtn')?.addEventListener('click', () => setView('dashboard'));
    document.getElementById('reqAccBackBtn')?.addEventListener('click', () => setView('dashboard'));

    // Boards Accordion
    document.getElementById('boardsToggle')?.addEventListener('click', () => {
        document.getElementById('boardsSubmenu')?.classList.toggle('open');
        document.querySelector('#boardsToggle .chevron')?.classList.toggle('open');
    });

    document.getElementById('sharedToggle')?.addEventListener('click', () => {
        document.getElementById('sharedBoardsSubmenu')?.classList.toggle('open');
        document.querySelector('#sharedToggle .chevron')?.classList.toggle('open');
    });

    document.getElementById('addBoardBtn')?.addEventListener('click', e => {
        e.preventDefault();
        openBoardModal();
    });

    // Filter pills
    document.querySelectorAll('.filter-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            state.activeFilter = btn.dataset.filter;
            renderBoard();
        });
    });

    // Modals Close & Save
    document.getElementById('taskModalClose')?.addEventListener('click', closeTaskModal);
    document.getElementById('taskModalCancel')?.addEventListener('click', closeTaskModal);
    document.getElementById('taskModalSave')?.addEventListener('click', saveTask);
    document.getElementById('taskName')?.addEventListener('keydown', e => { if (e.key === 'Enter') saveTask(); });

    document.getElementById('boardModalClose')?.addEventListener('click', closeBoardModal);
    document.getElementById('boardModalCancel')?.addEventListener('click', closeBoardModal);
    document.getElementById('boardModalSave')?.addEventListener('click', saveBoard);
    document.getElementById('boardName')?.addEventListener('keydown', e => { if (e.key === 'Enter') saveBoard(); });

    document.getElementById('listModalClose')?.addEventListener('click', closeListModal);
    document.getElementById('listModalCancel')?.addEventListener('click', closeListModal);
    document.getElementById('listModalSave')?.addEventListener('click', saveList);
    document.getElementById('listName')?.addEventListener('keydown', e => { if (e.key === 'Enter') saveList(); });

    // Drawer Close
    document.getElementById('taskDrawerClose')?.addEventListener('click', closeTaskDrawer);
    document.getElementById('taskDrawerOverlay')?.addEventListener('click', closeTaskDrawer);

    // Drawer Tabs (Comments / History)
    document.getElementById('tabCommentsBtn')?.addEventListener('click', () => {
        document.getElementById('tabCommentsBtn').classList.add('active');
        document.getElementById('tabHistoryBtn').classList.remove('active');
        document.getElementById('drawerCommentsPanel').style.display = '';
        document.getElementById('drawerHistoryPanel').style.display = 'none';
    });

    document.getElementById('tabHistoryBtn')?.addEventListener('click', () => {
        document.getElementById('tabHistoryBtn').classList.add('active');
        document.getElementById('tabCommentsBtn').classList.remove('active');
        document.getElementById('drawerHistoryPanel').style.display = '';
        document.getElementById('drawerCommentsPanel').style.display = 'none';
    });

    // File Upload handling in Drawer
    const fileTrigger = document.getElementById('drawerUploadTriggerBtn');
    const fileInput = document.getElementById('drawerFileInput');
    if (fileTrigger && fileInput) {
        fileTrigger.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', async () => {
            if (!fileInput.files || fileInput.files.length === 0 || !state.activeTaskDetails) return;
            const file = fileInput.files[0];
            const formData = new FormData();
            formData.append('action', 'uploadAttachment');
            formData.append('taskId', state.activeTaskDetails.task.id);
            formData.append('file', file);

            showToast('Uploading attachment...', 'info');
            try {
                const res = await fetch('api.php', { method: 'POST', body: formData });
                const json = await res.json();
                if (json.success) {
                    fileInput.value = '';
                    renderDrawerAttachments(json.attachments);
                    await loadBoards();
                    renderBoard();
                    showToast('File attached!', 'success');
                } else {
                    showToast(json.error || 'Upload failed', 'error');
                }
            } catch (err) {
                showToast('Upload failed', 'error');
            }
        });
    }

    // Command Palette Trigger (Top Search Input)
    const searchBox = document.getElementById('searchBoxTrigger');
    if (searchBox) {
        searchBox.addEventListener('click', openCommandPalette);
    }
    document.getElementById('paletteInput')?.addEventListener('input', e => {
        renderPaletteResults(e.target.value);
    });

    // Keyboard Shortcuts
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            closeTaskDrawer();
            closeCommandPalette();
            closeShortcutsModal();
            closeSettingsModal();
            closeShareModal();
            closeTaskModal();
            closeBoardModal();
            closeListModal();
            removeContextMenu();
        }

        // Ctrl+K -> Command Palette
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
            e.preventDefault();
            const palette = document.getElementById('commandPalette');
            if (palette?.classList.contains('open')) closeCommandPalette();
            else openCommandPalette();
            return;
        }

        // Ignore single-key shortcuts when typing in inputs/textareas
        const activeTag = document.activeElement?.tagName?.toLowerCase();
        if (activeTag === 'input' || activeTag === 'textarea' || activeTag === 'select' || document.activeElement?.isContentEditable) {
            return;
        }

        if (e.key === 'n' || e.key === 'N') {
            e.preventDefault();
            openTaskModal();
        } else if (e.key === 'b' || e.key === 'B') {
            e.preventDefault();
            openBoardModal();
        } else if (e.key === '/') {
            e.preventDefault();
            openCommandPalette();
        } else if (e.key === '1') {
            e.preventDefault();
            if (state.currentView !== 'board') setView('board');
            setBoardViewMode('kanban');
        } else if (e.key === '2') {
            e.preventDefault();
            if (state.currentView !== 'board') setView('board');
            setBoardViewMode('list');
        } else if (e.key === '3') {
            e.preventDefault();
            if (state.currentView !== 'board') setView('board');
            setBoardViewMode('calendar');
        } else if (e.key === '?') {
            e.preventDefault();
            openShortcutsModal();
        }
    });

    // Shortcuts modal
    document.getElementById('shortcutsModalClose')?.addEventListener('click', closeShortcutsModal);
    document.getElementById('shortcutsModalDone')?.addEventListener('click', closeShortcutsModal);
    document.getElementById('openShortcutsBtn')?.addEventListener('click', () => {
        document.getElementById('userMenu')?.classList.remove('open');
        openShortcutsModal();
    });

    // Settings modal
    document.getElementById('openSettingsBtn')?.addEventListener('click', () => {
        document.getElementById('userMenu')?.classList.remove('open');
        openSettingsModal();
    });
    document.getElementById('settingsModalClose')?.addEventListener('click', closeSettingsModal);
    document.getElementById('settingsModalCancel')?.addEventListener('click', closeSettingsModal);
    document.getElementById('settingsModalSave')?.addEventListener('click', async () => {
        const name = document.getElementById('settingsNameInput')?.value.trim();
        if (name) {
            await apiCall('updateProfile', { name });
            updateGreeting();
            showToast('Profile updated!', 'success');
        }
        closeSettingsModal();
    });

    document.getElementById('themeOptDark')?.addEventListener('click', () => {
        toggleTheme('dark');
        document.getElementById('themeOptDark').classList.add('active');
        document.getElementById('themeOptLight').classList.remove('active');
    });

    document.getElementById('themeOptLight')?.addEventListener('click', () => {
        toggleTheme('light');
        document.getElementById('themeOptLight').classList.add('active');
        document.getElementById('themeOptDark').classList.remove('active');
    });

    document.getElementById('settingsPushToggle')?.addEventListener('click', requestNotifPermission);

    // User Avatar Menu
    const userAvatar = document.getElementById('userAvatar');
    const userMenu = document.getElementById('userMenu');
    if (userAvatar && userMenu) {
        userAvatar.addEventListener('click', e => {
            e.stopPropagation();
            userMenu.classList.toggle('open');
            userMenu.setAttribute('aria-hidden', userMenu.classList.contains('open') ? 'false' : 'true');
        });
        document.addEventListener('click', e => {
            if (!userMenu.contains(e.target) && e.target !== userAvatar) {
                userMenu.classList.remove('open');
                userMenu.setAttribute('aria-hidden', 'true');
            }
        });
    }

    document.getElementById('logoutBtn')?.addEventListener('click', async e => {
        e.preventDefault();
        try {
            const form = new URLSearchParams();
            form.append('action', 'logout');
            await fetch('auth.php', { method: 'POST', body: form });
        } catch (err) {}
        window.location.href = 'login.php';
    });

    // Mobile Search modal
    document.getElementById('bnav-search')?.addEventListener('click', e => {
        e.preventDefault();
        openCommandPalette();
    });

    // Activity Log view navigation triggers
    document.getElementById('dashViewAllActivityBtn')?.addEventListener('click', () => setView('activity'));
    document.getElementById('dashViewAllActivityFooterBtn')?.addEventListener('click', () => setView('activity'));
    document.getElementById('activityBackBtn')?.addEventListener('click', () => setView('dashboard'));

    // Modal background click dismiss
    ['taskModal', 'boardModal', 'listModal', 'shareModal', 'commandPalette', 'shortcutsModal', 'settingsModal'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('click', e => {
                if (e.target === el) el.classList.remove('open');
            });
        }
    });
}

function toggleSidebar() {
    document.getElementById('sidebar')?.classList.toggle('open');
    document.getElementById('sidebarOverlay')?.classList.toggle('active');
}

function closeSidebar() {
    document.getElementById('sidebar')?.classList.remove('open');
    document.getElementById('sidebarOverlay')?.classList.remove('active');
}

// Boot application
document.addEventListener('DOMContentLoaded', init);
