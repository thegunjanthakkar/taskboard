'use strict';

// ============================
// State
// ============================
let state = {
    boards: [],
    activeBoardId: null,
    currentView: 'board', // 'board' | 'due'
    theme: document.documentElement.getAttribute('data-theme') || 'dark',
    notifPermission: 'default',
    scheduledNotifs: {}, // taskId -> timeoutId
    editingTaskId: null,
    editingListId: null,
    editingBoardId: null,
};

// ============================
// API helpers
// ============================
async function apiCall(action, data = {}) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action, ...data })
    });
    if (res.status === 401) {
        window.location.href = 'login.php';
        return { error: 'unauthorized' };
    }
    return res.json();
}

// ============================
// Init
// ============================
async function init() {
    await loadBoards();
    setupEventListeners();
    await registerServiceWorker();   // must await — SW must be active before push subscribe
    checkNotifPermission();
    setView('board');
    renderSidebar();
    updateDueTodayCount();
    scheduleAllNotifications();
}

async function loadBoards() {
    const data = await apiCall('getBoards');
    state.boards = data.boards || [];
    if (state.boards.length > 0) {
        state.activeBoardId = state.boards[0].id;
    }
}

// ============================
// Service Worker
// ============================
// Service Worker
// ============================

// Stored SW registration so push subscription can reuse it
let _swReg = null;

async function registerServiceWorker() {
    if (!('serviceWorker' in navigator)) return;
    try {
        _swReg = await navigator.serviceWorker.register('sw.js');
        // Wait until the SW is fully active before we try push subscribe
        if (_swReg.installing || _swReg.waiting) {
            await new Promise(resolve => {
                const sw = _swReg.installing || _swReg.waiting;
                sw.addEventListener('statechange', function handler() {
                    if (sw.state === 'activated') { sw.removeEventListener('statechange', handler); resolve(); }
                });
            });
        }
    } catch (err) {
        console.warn('SW registration failed:', err);
    }
}

// ============================
// Notifications
// ============================
function checkNotifPermission() {
    if (!('Notification' in window)) return;
    state.notifPermission = Notification.permission;
    updateNotifUI();
    // If already granted on page reload, re-subscribe silently
    if (Notification.permission === 'granted') {
        subscribePush().catch(() => {});
    }
}

function updateNotifUI() {
    const dot = document.getElementById('notifDot');
    if (state.notifPermission !== 'granted') {
        dot.classList.add('active');
    } else {
        dot.classList.remove('active');
    }
}

/**
 * Subscribe this browser to Web Push and send the subscription to push.php.
 * Returns true on success, false on any failure.
 */
async function subscribePush() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return false;
    try {
        // Get (or reuse) the SW registration
        const reg = _swReg || await navigator.serviceWorker.ready;

        // Fetch VAPID public key
        const vkRes = await fetch('vapid_public.php');
        if (!vkRes.ok) { console.warn('vapid_public.php returned', vkRes.status); return false; }
        const { publicKey } = await vkRes.json();
        if (!publicKey) { console.warn('No VAPID public key returned'); return false; }

        // Check for existing subscription first (avoid duplicate)
        let sub = await reg.pushManager.getSubscription();
        if (!sub) {
            sub = await reg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(publicKey)
            });
        }

        // Save to server
        const res = await fetch('push.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sub.toJSON())   // send PushSubscription directly, no wrapper
        });
        if (!res.ok) {
            const err = await res.json().catch(() => ({}));
            console.warn('push.php error:', err);
            return false;
        }
        return true;
    } catch (err) {
        console.warn('Push subscription failed:', err);
        return false;
    }
}

async function requestNotifPermission() {
    if (!('Notification' in window)) {
        showToast('Notifications not supported in this browser', 'error');
        return;
    }
    if (Notification.permission === 'granted') {
        // Already granted — make sure we have a push subscription
        const ok = await subscribePush();
        showToast(ok ? 'Notifications already enabled!' : 'Notifications enabled (push setup failed — check console)', ok ? 'success' : 'info');
        return;
    }
    const perm = await Notification.requestPermission();
    state.notifPermission = perm;
    updateNotifUI();
    if (perm === 'granted') {
        const ok = await subscribePush();
        showToast(ok ? 'Notifications enabled!' : 'Notifications enabled (push setup failed — check console)', ok ? 'success' : 'info');
        scheduleAllNotifications();
    } else {
        showToast('Notifications blocked. Please enable in browser settings.', 'error');
    }
}

/** Convert URL-safe base64 VAPID key to Uint8Array */
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

function scheduleAllNotifications() {
    if (Notification.permission !== 'granted') return;
    // Clear old
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
                body: `${boardName} — ${task.description || 'No description'}`,
                icon: 'icons/icon-192.png',
                badge: 'icons/icon-192.png',
                tag: task.id,
                requireInteraction: true
            });
            n.onclick = () => { window.focus(); n.close(); };
        }
    }, msFromNow);
    state.scheduledNotifs[task.id] = id;
}

// ============================
// Views
// ============================
function setView(view, boardId = null) {
    state.currentView = view;
    document.querySelectorAll('.view').forEach(v => v.style.display = 'none');

    document.querySelectorAll('.nav-item[data-view]').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.bottom-nav-item[data-view]').forEach(el => el.classList.remove('active'));

    if (view === 'due') {
        document.getElementById('view-due').style.display = '';
        document.querySelectorAll('[data-view="due"]').forEach(el => el.classList.add('active'));
        renderDueToday();
    } else {
        document.getElementById('view-board').style.display = '';
        if (boardId) state.activeBoardId = boardId;
        document.querySelectorAll('[data-view="board"]').forEach(el => el.classList.add('active'));
        renderBoard();
        renderSidebar();
    }

    // Close sidebar on mobile
    if (window.innerWidth <= 768) closeSidebar();
}

// ============================
// Render Sidebar
// ============================
function renderSidebar() {
    const list = document.getElementById('boardsList');
    list.innerHTML = '';
    state.boards.forEach(board => {
        const a = document.createElement('a');
        a.className = 'nav-item' + (board.id === state.activeBoardId && state.currentView === 'board' ? ' active' : '');
        a.href = '#';
        a.innerHTML = `
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
            </svg>
            <span>${escHtml(board.name)}</span>
        `;
        a.addEventListener('click', e => { e.preventDefault(); setView('board', board.id); });
        list.appendChild(a);
    });
}

// ============================
// Render Board
// ============================
function renderBoard() {
    const board = getActiveBoard();
    if (!board) {
        document.getElementById('boardContainer').innerHTML = `<div class="empty-state"><p>No board selected.</p></div>`;
        document.getElementById('currentBoardTitle').textContent = 'TasksBoard';
        return;
    }
    document.getElementById('currentBoardTitle').textContent = board.name;
    const container = document.getElementById('boardContainer');
    container.innerHTML = '';

    board.lists.forEach(list => {
        container.appendChild(renderList(board, list));
    });
}

function renderList(board, list) {
    const col = document.createElement('div');
    col.className = 'list-column';
    col.dataset.listId = list.id;

    const completedCount = list.tasks.filter(t => t.completed).length;
    const total = list.tasks.length;

    col.innerHTML = `
        <div class="list-header">
            <div class="list-title-group">
                <span class="list-title">${escHtml(list.name)}</span>
                <span class="list-count">${total}</span>
            </div>
            <button class="list-menu-btn" data-list-id="${list.id}" title="List options">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/>
                </svg>
            </button>
        </div>
        <div class="tasks-wrapper" id="tasks-${list.id}">
            ${total === 0 ? renderEmptyState() : list.tasks.map(t => renderTaskCard(t)).join('')}
        </div>
        <button class="add-task-btn" data-list-id="${list.id}" data-board-id="${board.id}">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            Add a task
        </button>
    `;

    // List menu
    col.querySelector('.list-menu-btn').addEventListener('click', e => {
        e.stopPropagation();
        showListContextMenu(e, board.id, list);
    });

    // Add task buttons
    col.querySelector('.add-task-btn').addEventListener('click', () => {
        openTaskModal(null, list.id, board.id);
    });

    // Task card events
    col.querySelectorAll('.task-card').forEach(card => {
        const taskId = card.dataset.taskId;
        const task = list.tasks.find(t => t.id === taskId);
        if (!task) return;

        card.addEventListener('click', e => {
            if (e.target.closest('.task-action-btn') || e.target.closest('.task-checkbox')) return;
            openTaskModal(task, list.id, board.id);
        });

        card.querySelector('.task-checkbox')?.addEventListener('click', e => {
            e.stopPropagation();
            toggleTask(board.id, list.id, taskId);
        });

        card.querySelector('.edit-btn')?.addEventListener('click', e => {
            e.stopPropagation();
            openTaskModal(task, list.id, board.id);
        });

        card.querySelector('.delete-btn')?.addEventListener('click', e => {
            e.stopPropagation();
            deleteTask(board.id, list.id, taskId);
        });
    });

    return col;
}

function renderEmptyState() {
    return `
        <div class="empty-state">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
            </svg>
            <p>No tasks yet</p>
            <small>Click "+" above to add a new task</small>
        </div>
    `;
}

function renderTaskCard(task) {
    const dueInfo = getDueInfo(task);
    const dueClass = dueInfo.isOverdue ? 'overdue' : (dueInfo.isToday ? 'today' : '');
    const dueLabel = dueInfo.label;

    return `
        <div class="task-card ${task.completed ? 'completed' : ''}" data-task-id="${task.id}">
            <div class="task-card-top">
                <div class="task-checkbox ${task.completed ? 'checked' : ''}"></div>
                <div class="task-body">
                    <div class="task-title">${escHtml(task.title)}</div>
                    ${task.description ? `<div class="task-desc">${escHtml(task.description)}</div>` : ''}
                    <div class="task-meta">
                        ${dueLabel ? `<span class="task-due ${dueClass}">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>
                            </svg>
                            ${dueLabel}
                        </span>` : ''}
                        ${task.priority ? `<span class="priority-badge ${task.priority}">${task.priority}</span>` : ''}
                    </div>
                </div>
            </div>
            <div class="task-actions">
                <button class="task-action-btn edit-btn" title="Edit">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                    </svg>
                </button>
                <button class="task-action-btn delete-btn delete" title="Delete">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                    </svg>
                </button>
            </div>
        </div>
    `;
}

// ============================
// Due Today
// ============================
function renderDueToday() {
    const today = new Date().toISOString().split('T')[0];
    const container = document.getElementById('dueTodayList');
    const dueTasks = [];

    state.boards.forEach(board => {
        board.lists.forEach(list => {
            list.tasks.forEach(task => {
                if (!task.completed && task.due_date === today) {
                    dueTasks.push({ task, board, list });
                }
            });
        });
    });

    if (dueTasks.length === 0) {
        container.innerHTML = `
            <div class="due-today-empty">
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                    <path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
                </svg>
                <h3>All caught up!</h3>
                <p>No tasks due today. Great work!</p>
            </div>
        `;
        return;
    }

    container.innerHTML = dueTasks.map(({ task, board, list }) => `
        <div class="due-task-card" data-task-id="${task.id}" data-board-id="${board.id}">
            <div class="task-checkbox ${task.completed ? 'checked' : ''}" data-task-id="${task.id}" data-board-id="${board.id}" data-list-id="${list.id}"></div>
            <div class="due-task-info">
                <div class="due-task-name">${escHtml(task.title)}</div>
                <div class="due-task-board">${escHtml(board.name)} › ${escHtml(list.name)}</div>
            </div>
            ${task.due_time ? `<div class="due-task-time">${formatTime(task.due_time)}</div>` : ''}
            ${task.priority ? `<span class="priority-badge ${task.priority}">${task.priority}</span>` : ''}
        </div>
    `).join('');

    // Checkbox events
    container.querySelectorAll('.task-checkbox').forEach(cb => {
        cb.addEventListener('click', () => {
            toggleTask(cb.dataset.boardId, cb.dataset.listId, cb.dataset.taskId);
        });
    });
}

function updateDueTodayCount() {
    const today = new Date().toISOString().split('T')[0];
    let count = 0;
    state.boards.forEach(b => b.lists.forEach(l => l.tasks.forEach(t => {
        if (!t.completed && t.due_date === today) count++;
    })));
    const badge = document.getElementById('dueTodayCount');
    badge.textContent = count > 0 ? count : '';
    badge.style.display = count > 0 ? '' : 'none';
}

// ============================
// Task Modal
// ============================
function openTaskModal(task = null, listId = null, boardId = null) {
    state.editingTaskId = task?.id || null;
    state.editingListId = listId;
    state.editingBoardId = boardId;

    const modal = document.getElementById('taskModal');
    document.getElementById('taskModalTitle').textContent = task ? 'Edit Task' : 'Add Task';

    document.getElementById('taskName').value = task?.title || '';
    document.getElementById('taskDesc').value = task?.description || '';
    document.getElementById('taskDueDate').value = task?.due_date || '';
    document.getElementById('taskDueTime').value = task?.due_time || '';

    // Priority
    const priority = task?.priority || 'medium';
    document.querySelectorAll('.priority-opt').forEach(btn => {
        btn.classList.toggle('active', btn.dataset.priority === priority);
    });

    // Board/List selector
    const select = document.getElementById('taskBoardList');
    select.innerHTML = '';
    state.boards.forEach(board => {
        board.lists.forEach(list => {
            const opt = document.createElement('option');
            opt.value = `${board.id}::${list.id}`;
            opt.textContent = `${board.name} / ${list.name}`;
            if (board.id === boardId && list.id === listId) opt.selected = true;
            select.appendChild(opt);
        });
    });

    modal.classList.add('open');
    setTimeout(() => document.getElementById('taskName').focus(), 100);
}

function closeTaskModal() {
    document.getElementById('taskModal').classList.remove('open');
    state.editingTaskId = null;
    state.editingListId = null;
    state.editingBoardId = null;
}

async function saveTask() {
    const title = document.getElementById('taskName').value.trim();
    if (!title) { shake(document.getElementById('taskName')); return; }

    const desc = document.getElementById('taskDesc').value.trim();
    const dueDate = document.getElementById('taskDueDate').value;
    const dueTime = document.getElementById('taskDueTime').value;
    const priority = document.querySelector('.priority-opt.active')?.dataset.priority || 'medium';

    const [boardId, listId] = document.getElementById('taskBoardList').value.split('::');

    const taskData = { title, description: desc, due_date: dueDate, due_time: dueTime, priority };

    let result;
    if (state.editingTaskId) {
        result = await apiCall('updateTask', {
            boardId, listId, taskId: state.editingTaskId, taskData,
            oldBoardId: state.editingBoardId, oldListId: state.editingListId
        });
    } else {
        result = await apiCall('addTask', { boardId, listId, taskData });
    }

    if (result.success) {
        state.boards = result.boards;
        closeTaskModal();
        renderBoard();
        renderSidebar();
        updateDueTodayCount();
        // If this task has a due date/time, ensure notifications are enabled and schedule reminders
        if (dueDate && dueTime && 'Notification' in window) {
            if (Notification.permission === 'granted') {
                scheduleAllNotifications();
            } else if (Notification.permission !== 'denied') {
                try {
                    const perm = await Notification.requestPermission();
                    if (perm === 'granted') {
                        scheduleAllNotifications();
                        showToast('Notifications enabled — we will remind you', 'success');
                    } else {
                        showToast('Enable notifications to receive due reminders', 'info');
                    }
                } catch (err) {
                    console.warn('Notification permission request failed', err);
                }
            } else {
                showToast('Notifications are blocked in your browser settings', 'error');
            }
        } else {
            scheduleAllNotifications();
        }

        showToast(state.editingTaskId ? 'Task updated!' : 'Task added!', 'success');
    } else {
        showToast('Error saving task', 'error');
    }
}

async function deleteTask(boardId, listId, taskId) {
    if (!confirm('Delete this task?')) return;
    const result = await apiCall('deleteTask', { boardId, listId, taskId });
    if (result.success) {
        state.boards = result.boards;
        renderBoard();
        updateDueTodayCount();
        scheduleAllNotifications();
        showToast('Task deleted', 'info');
    }
}

async function toggleTask(boardId, listId, taskId) {
    const result = await apiCall('toggleTask', { boardId, listId, taskId });
    if (result.success) {
        state.boards = result.boards;
        if (state.currentView === 'due') {
            renderDueToday();
        } else {
            renderBoard();
        }
        updateDueTodayCount();
    }
}

// ============================
// Board CRUD
// ============================
function openBoardModal(board = null) {
    state.editingBoardId = board?.id || null;
    document.getElementById('boardModalTitle').textContent = board ? 'Rename Board' : 'New Board';
    document.getElementById('boardName').value = board?.name || '';
    document.getElementById('boardModal').classList.add('open');
    setTimeout(() => document.getElementById('boardName').focus(), 100);
}

function closeBoardModal() {
    document.getElementById('boardModal').classList.remove('open');
}

async function saveBoard() {
    const name = document.getElementById('boardName').value.trim();
    if (!name) { shake(document.getElementById('boardName')); return; }

    let result;
    if (state.editingBoardId) {
        result = await apiCall('renameBoard', { boardId: state.editingBoardId, name });
    } else {
        result = await apiCall('addBoard', { name });
    }

    if (result.success) {
        state.boards = result.boards;
        if (!state.editingBoardId) state.activeBoardId = result.newBoardId;
        closeBoardModal();
        renderSidebar();
        if (state.currentView === 'board') renderBoard();
        showToast(state.editingBoardId ? 'Board renamed!' : 'Board created!', 'success');
    }
}

async function deleteBoard(boardId) {
    if (state.boards.length <= 1) { showToast('Cannot delete the last board', 'error'); return; }
    if (!confirm('Delete this board and all its tasks?')) return;

    const result = await apiCall('deleteBoard', { boardId });
    if (result.success) {
        state.boards = result.boards;
        state.activeBoardId = state.boards[0]?.id;
        renderSidebar();
        renderBoard();
        showToast('Board deleted', 'info');
    }
}

// ============================
// List CRUD
// ============================
function openListModal() {
    document.getElementById('listName').value = '';
    document.getElementById('listModal').classList.add('open');
    setTimeout(() => document.getElementById('listName').focus(), 100);
}

function closeListModal() {
    document.getElementById('listModal').classList.remove('open');
}

async function saveList() {
    const name = document.getElementById('listName').value.trim();
    if (!name) { shake(document.getElementById('listName')); return; }

    const result = await apiCall('addList', { boardId: state.activeBoardId, name });
    if (result.success) {
        state.boards = result.boards;
        closeListModal();
        renderBoard();
        showToast('List created!', 'success');
    }
}

async function deleteList(boardId, listId) {
    const board = state.boards.find(b => b.id === boardId);
    if (board && board.lists.length <= 1) {
        showToast('Cannot delete the last list', 'error');
        return;
    }
    if (!confirm('Delete this list and all its tasks?')) return;
    const result = await apiCall('deleteList', { boardId, listId });
    if (result.success) {
        state.boards = result.boards;
        renderBoard();
        showToast('List deleted', 'info');
    }
}

async function renameList(boardId, listId) {
    const board = state.boards.find(b => b.id === boardId);
    const list = board?.lists.find(l => l.id === listId);
    if (!list) return;
    const name = prompt('Rename list:', list.name);
    if (!name || !name.trim()) return;
    const result = await apiCall('renameList', { boardId, listId, name: name.trim() });
    if (result.success) {
        state.boards = result.boards;
        renderBoard();
    }
}

// ============================
// Context Menus
// ============================
function showListContextMenu(e, boardId, list) {
    removeContextMenu();
    const menu = document.createElement('div');
    menu.className = 'context-menu';
    menu.innerHTML = `
        <div class="ctx-item" id="ctx-rename">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Rename List
        </div>
        <div class="ctx-divider"></div>
        <div class="ctx-item danger" id="ctx-delete">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
            Delete List
        </div>
    `;
    document.body.appendChild(menu);

    const rect = e.target.getBoundingClientRect();
    menu.style.top = (rect.bottom + 4) + 'px';
    menu.style.left = Math.min(rect.left, window.innerWidth - 180) + 'px';

    menu.querySelector('#ctx-rename').addEventListener('click', () => { removeContextMenu(); renameList(boardId, list.id); });
    menu.querySelector('#ctx-delete').addEventListener('click', () => { removeContextMenu(); deleteList(boardId, list.id); });

    setTimeout(() => document.addEventListener('click', removeContextMenu, { once: true }), 10);
}

function removeContextMenu() {
    document.querySelectorAll('.context-menu').forEach(el => el.remove());
}

// ============================
// Theme
// ============================
async function toggleTheme() {
    state.theme = state.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', state.theme);
    document.body.className = 'theme-' + state.theme;
    await apiCall('setTheme', { theme: state.theme });
}

// ============================
// Search
// ============================
function searchTasks(query) {
    if (!query.trim()) return [];
    const q = query.toLowerCase();
    const results = [];
    state.boards.forEach(board => {
        board.lists.forEach(list => {
            list.tasks.forEach(task => {
                if (task.title.toLowerCase().includes(q) || (task.description || '').toLowerCase().includes(q)) {
                    results.push({ task, board, list });
                }
            });
        });
    });
    return results;
}

function renderSearchResults(query, container) {
    const results = searchTasks(query);
    if (!query.trim()) { container.innerHTML = ''; return; }
    if (results.length === 0) {
        container.innerHTML = `<div class="search-empty">No tasks found for "${escHtml(query)}"</div>`;
        return;
    }
    container.innerHTML = results.map(({ task, board, list }) => `
        <div class="search-result-item" data-board-id="${board.id}" data-list-id="${list.id}" data-task-id="${task.id}">
            <div class="task-title">${highlight(escHtml(task.title), query)}</div>
            <div style="font-size:.76rem;color:var(--text-muted);margin-top:3px">${escHtml(board.name)} › ${escHtml(list.name)}</div>
        </div>
    `).join('');

    container.querySelectorAll('.search-result-item').forEach(el => {
        el.addEventListener('click', () => {
            const boardId = el.dataset.boardId;
            const listId = el.dataset.listId;
            const taskId = el.dataset.taskId;
            const board = state.boards.find(b => b.id === boardId);
            const list = board?.lists.find(l => l.id === listId);
            const task = list?.tasks.find(t => t.id === taskId);
            if (task) {
                document.getElementById('searchModal').classList.remove('open');
                setView('board', boardId);
                openTaskModal(task, listId, boardId);
            }
        });
    });
}

// ============================
// Utils
// ============================
function getActiveBoard() {
    return state.boards.find(b => b.id === state.activeBoardId) || null;
}

function getDueInfo(task) {
    if (!task.due_date) return { label: null, isOverdue: false, isToday: false };
    const today = new Date().toISOString().split('T')[0];
    const isToday = task.due_date === today;
    const isOverdue = task.due_date < today;
    let label = '';
    if (isToday) {
        label = task.due_time ? 'Today ' + formatTime(task.due_time) : 'Today';
    } else if (isOverdue) {
        label = formatDate(task.due_date);
    } else {
        label = formatDate(task.due_date);
    }
    return { label, isToday, isOverdue };
}

function formatDate(dateStr) {
    const d = new Date(dateStr + 'T00:00:00');
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
}

function formatTime(timeStr) {
    if (!timeStr) return '';
    const [h, m] = timeStr.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return `${h12}:${String(m).padStart(2, '0')} ${ampm}`;
}

function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function highlight(text, query) {
    const re = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g,'\\$&') + ')', 'gi');
    return text.replace(re, '<mark style="background:rgba(79,142,247,.3);border-radius:2px">$1</mark>');
}

function shake(el) {
    el.style.animation = '';
    requestAnimationFrame(() => {
        el.style.animation = 'shake 0.3s ease';
    });
}

// Add shake animation dynamically
const shakeStyle = document.createElement('style');
shakeStyle.textContent = `@keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)} }`;
document.head.appendChild(shakeStyle);

// ============================
// Toast
// ============================
function showToast(msg, type = 'info') {
    const icons = {
        success: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>`,
        error: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>`,
        info: `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`
    };
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `<span class="toast-icon">${icons[type] || icons.info}</span> ${escHtml(msg)}`;
    document.getElementById('toastContainer').appendChild(toast);
    setTimeout(() => {
        toast.classList.add('fadeOut');
        setTimeout(() => toast.remove(), 250);
    }, 3000);
}

// ============================
// Event Listeners
// ============================
function setupEventListeners() {

    // Hamburger
    document.getElementById('menuToggle').addEventListener('click', toggleSidebar);
    document.getElementById('sidebarOverlay').addEventListener('click', closeSidebar);

    // Theme
    document.getElementById('themeToggle').addEventListener('click', toggleTheme);
    document.getElementById('bnav-theme').addEventListener('click', e => { e.preventDefault(); toggleTheme(); });

    // Notifications
    document.getElementById('enableNotif').addEventListener('click', requestNotifPermission);

    // Nav items
    document.getElementById('nav-due').addEventListener('click', e => { e.preventDefault(); setView('due'); });
    document.getElementById('bnav-due').addEventListener('click', e => { e.preventDefault(); setView('due'); });
    document.getElementById('bnav-board').addEventListener('click', e => { e.preventDefault(); setView('board'); });

    // Boards submenu
    document.getElementById('boardsToggle').addEventListener('click', () => {
        const submenu = document.getElementById('boardsSubmenu');
        const chevron = document.querySelector('#boardsToggle .chevron');
        submenu.classList.toggle('open');
        chevron.classList.toggle('open');
    });
    // Open by default
    document.getElementById('boardsSubmenu').classList.add('open');
    document.querySelector('#boardsToggle .chevron').classList.add('open');

    // Add Board
    document.getElementById('addBoardBtn').addEventListener('click', e => { e.preventDefault(); openBoardModal(); });

    // Add List
    document.getElementById('addListBtn').addEventListener('click', openListModal);

    // Rename / Delete Board
    document.getElementById('renameBoardBtn').addEventListener('click', () => {
        const board = getActiveBoard();
        if (board) openBoardModal(board);
    });
    document.getElementById('deleteBoardBtn').addEventListener('click', () => {
        if (state.activeBoardId) deleteBoard(state.activeBoardId);
    });

    // Task modal
    document.getElementById('taskModalClose').addEventListener('click', closeTaskModal);
    document.getElementById('taskModalCancel').addEventListener('click', closeTaskModal);
    document.getElementById('taskModalSave').addEventListener('click', saveTask);
    document.getElementById('taskName').addEventListener('keydown', e => { if (e.key === 'Enter') saveTask(); });

    // Board modal
    document.getElementById('boardModalClose').addEventListener('click', closeBoardModal);
    document.getElementById('boardModalCancel').addEventListener('click', closeBoardModal);
    document.getElementById('boardModalSave').addEventListener('click', saveBoard);
    document.getElementById('boardName').addEventListener('keydown', e => { if (e.key === 'Enter') saveBoard(); });

    // List modal
    document.getElementById('listModalClose').addEventListener('click', closeListModal);
    document.getElementById('listModalCancel').addEventListener('click', closeListModal);
    document.getElementById('listModalSave').addEventListener('click', saveList);
    document.getElementById('listName').addEventListener('keydown', e => { if (e.key === 'Enter') saveList(); });

    // Modal overlay close on backdrop click
    ['taskModal', 'boardModal', 'listModal', 'searchModal'].forEach(id => {
        document.getElementById(id).addEventListener('click', e => {
            if (e.target === document.getElementById(id)) {
                document.getElementById(id).classList.remove('open');
            }
        });
    });

    // Priority picker
    document.querySelectorAll('.priority-opt').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.priority-opt').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
        });
    });

    // Desktop Search
    document.getElementById('searchInput').addEventListener('input', e => {
        const q = e.target.value;
        // For simplicity, open search modal on desktop too
        if (q.length > 0) {
            const modal = document.getElementById('searchModal');
            modal.classList.add('open');
            document.getElementById('mobileSearchInput').value = q;
            renderSearchResults(q, document.getElementById('searchResults'));
        }
    });

    // Mobile Search
    document.getElementById('bnav-search').addEventListener('click', e => {
        e.preventDefault();
        document.getElementById('searchModal').classList.add('open');
        setTimeout(() => document.getElementById('mobileSearchInput').focus(), 100);
    });
    document.getElementById('searchModalClose').addEventListener('click', () => {
        document.getElementById('searchModal').classList.remove('open');
        document.getElementById('searchInput').value = '';
    });
    document.getElementById('mobileSearchInput').addEventListener('input', e => {
        renderSearchResults(e.target.value, document.getElementById('searchResults'));
    });

    // Mobile Add Task
    document.getElementById('bnav-add').addEventListener('click', e => {
        e.preventDefault();
        const board = getActiveBoard();
        const list = board?.lists[0];
        openTaskModal(null, list?.id, board?.id);
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
            removeContextMenu();
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            document.getElementById('searchModal').classList.add('open');
            document.getElementById('mobileSearchInput').focus();
        }
    });

    // User avatar menu (logout)
    const userAvatar = document.getElementById('userAvatar');
    const userMenu = document.getElementById('userMenu');
    const logoutBtn = document.getElementById('logoutBtn');
    if (userAvatar && userMenu) {
        userAvatar.addEventListener('click', e => {
            e.stopPropagation();
            userMenu.classList.toggle('open');
            userMenu.setAttribute('aria-hidden', userMenu.classList.contains('open') ? 'false' : 'true');
        });

        // Close menu when clicking outside
        document.addEventListener('click', e => {
            if (!userMenu.contains(e.target) && e.target !== userAvatar) {
                userMenu.classList.remove('open');
                userMenu.setAttribute('aria-hidden', 'true');
            }
        });
    }

    if (logoutBtn) {
        logoutBtn.addEventListener('click', async e => {
            e.preventDefault();
            try {
                const form = new URLSearchParams();
                form.append('action', 'logout');
                const res = await fetch('auth.php', { method: 'POST', body: form });
                // ignore body, just redirect to login
            } catch (err) {
                console.warn('Logout request failed', err);
            }
            window.location.href = 'login.php';
        });
    }
}

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}

// ============================
// Boot
// ============================
document.addEventListener('DOMContentLoaded', init);
