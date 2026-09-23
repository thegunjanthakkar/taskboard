document.addEventListener('DOMContentLoaded', function () {
    const tabs = document.querySelectorAll('[data-auth-tab]');
    const panels = document.querySelectorAll('[data-auth-panel]');
    const loginBtn = document.getElementById('loginBtn');
    const signupBtn = document.getElementById('signupBtn');
    const loginEmail = document.getElementById('loginEmail');
    const loginPassword = document.getElementById('loginPassword');
    const signupEmail = document.getElementById('signupEmail');
    const signupPassword = document.getElementById('signupPassword');
    const msg = document.getElementById('authMessage');

    function activate(tabName) {
        tabs.forEach(tab => tab.classList.toggle('active', tab.dataset.authTab === tabName));
        panels.forEach(panel => panel.classList.toggle('active', panel.dataset.authPanel === tabName));
        if (msg) msg.textContent = '';
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => activate(tab.dataset.authTab));
    });

    async function postAction(action) {
        if (msg) msg.textContent = '';
        const form = new FormData();
        form.append('action', action);
        form.append('email', action === 'login' ? loginEmail.value : signupEmail.value);
        form.append('password', action === 'login' ? loginPassword.value : signupPassword.value);

        try {
            const res = await fetch('auth.php', { method: 'POST', body: form });
            const data = await res.json();
            if (!res.ok) throw data;
            window.location.href = document.body.dataset.authRedirect || 'index.php';
        } catch (err) {
            if (msg) msg.textContent = err.error || JSON.stringify(err);
        }
    }

    if (loginBtn) loginBtn.addEventListener('click', () => postAction('login'));
    if (signupBtn) signupBtn.addEventListener('click', () => postAction('signup'));
});
