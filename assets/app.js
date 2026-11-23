(() => {
    const csrf = document.querySelector('meta[name="csrf"]')?.content || '';
    const toastEl = document.getElementById('toast');
    let toastTimer = null;

    function toast(message, url = null) {
        if (!toastEl) return;
        toastEl.textContent = '';
        if (url) {
            const link = document.createElement('a');
            link.href = url;
            link.textContent = message;
            toastEl.appendChild(link);
        } else {
            toastEl.textContent = message;
        }
        toastEl.hidden = false;
        toastEl.classList.add('is-visible');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => {
            toastEl.classList.remove('is-visible');
            setTimeout(() => { toastEl.hidden = true; }, 200);
        }, 6000);
    }

    // --- Уведомления ---
    const notif = document.getElementById('notif');
    const notifBtn = document.getElementById('notifBtn');
    const notifPop = document.getElementById('notifPop');
    const notifCount = document.getElementById('notifCount');
    const notifList = document.getElementById('notifList');

    notifBtn?.addEventListener('click', (event) => {
        event.stopPropagation();
        notifPop.hidden = !notifPop.hidden;
    });

    document.addEventListener('click', (event) => {
        if (notif && !notif.contains(event.target)) {
            notifPop.hidden = true;
        }
    });

    document.getElementById('notifReadAll')?.addEventListener('click', async () => {
        const body = new FormData();
        body.append('action', 'read_notifications');
        body.append('_token', csrf);
        try {
            await fetch('actions.php', { method: 'POST', body });
            if (notifCount) {
                notifCount.textContent = '0';
                notifCount.classList.add('is-hidden');
            }
            document.querySelectorAll('.notif-item.is-unread').forEach((el) => el.classList.remove('is-unread'));
        } catch (e) {
            toast('Не удалось отметить уведомления');
        }
    });

    function addNotification(data) {
        if (!notifList) return;
        notifList.querySelector('.notif-empty')?.remove();

        const item = document.createElement('li');
        item.className = 'notif-item is-unread';

        const text = document.createElement('a');
        if (data.request_id) {
            text.href = 'request.php?id=' + data.request_id;
        }
        text.textContent = data.message;

        const time = document.createElement('time');
        time.textContent = data.created_at;

        item.append(text, time);
        notifList.prepend(item);

        if (notifCount) {
            const current = parseInt(notifCount.textContent || '0', 10) || 0;
            notifCount.textContent = String(current + 1);
            notifCount.classList.remove('is-hidden');
        }

        toast(data.message, data.request_id ? 'request.php?id=' + data.request_id : null);
    }

    if (window.EventSource) {
        const source = new EventSource('sse.php');
        source.addEventListener('notification', (event) => {
            try {
                addNotification(JSON.parse(event.data));
            } catch (e) {
                // ignore broken payload
            }
        });
    }
})();
