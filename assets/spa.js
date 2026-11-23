const { createApp } = Vue;

createApp({
    data() {
        return {
            token: localStorage.getItem('repair_jwt') || '',
            user: null,
            view: 'dashboard',
            loading: false,
            error: '',
            toastMsg: '',
            toastTimer: null,
            sse: null,

            loginForm: { login: '', password: '' },
            statuses: ['new', 'in_progress', 'waiting_parts', 'ready'],
            techTypes: ['Компьютер', 'Ноутбук', 'Монитор', 'Клавиатура', 'Мышка', 'Принтер', 'Другое'],

            requests: [],
            filter: { q: '', status: '' },
            stats: null,
            reportFilter: { from: '', to: '' },
            current: null,
            statusForm: { status: 'new', repair_parts: '' },
            masterId: '',
            commentText: '',
            form: {},

            technicians: [],
            customers: [],
            notifications: [],
            unread: 0,
            notifOpen: false,
        };
    },
    computed: {
        canCreate() {
            return this.user && ['manager', 'operator', 'customer'].includes(this.user.role);
        },
        canEditCurrent() {
            if (!this.user || !this.current) return false;
            if (['manager', 'operator'].includes(this.user.role)) return true;
            return this.user.role === 'customer' && this.current.status === 'new';
        },
        canChangeStatusCurrent() {
            if (!this.user || !this.current) return false;
            if (['manager', 'operator'].includes(this.user.role)) return true;
            return this.user.role === 'technician' && Number(this.current.master_id) === Number(this.user.user_id);
        },
        exportUrl() {
            const p = new URLSearchParams({ token: this.token, from: this.reportFilter.from, to: this.reportFilter.to });
            return 'api/export?' + p.toString();
        },
    },
    async mounted() {
        if (this.token) {
            try {
                this.user = await this.api('/auth/me');
                await this.initSession();
            } catch {
                this.logout();
            }
        }
    },
    methods: {
        async api(path, options = {}) {
            const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
            if (this.token) headers.Authorization = 'Bearer ' + this.token;
            const res = await fetch('api' + path, { ...options, headers });
            const json = await res.json();
            if (!res.ok || !json.ok) {
                throw new Error(json.error || 'Ошибка запроса');
            }
            return json.data;
        },
        showToast(msg) {
            this.toastMsg = msg;
            clearTimeout(this.toastTimer);
            this.toastTimer = setTimeout(() => { this.toastMsg = ''; }, 5000);
        },
        async login() {
            this.error = '';
            this.loading = true;
            try {
                const data = await this.api('/auth/login', { method: 'POST', body: JSON.stringify(this.loginForm) });
                this.token = data.token;
                this.user = data.user;
                localStorage.setItem('repair_jwt', this.token);
                await this.initSession();
            } catch (e) {
                this.error = e.message;
            } finally {
                this.loading = false;
            }
        },
        logout() {
            if (this.sse) this.sse.close();
            this.token = '';
            this.user = null;
            localStorage.removeItem('repair_jwt');
        },
        async initSession() {
            await Promise.all([this.loadRequests(), this.loadStats(), this.loadNotifications()]);
            if (['manager', 'operator'].includes(this.user.role)) {
                this.technicians = await this.api('/users?role=technician');
                this.customers = await this.api('/users?role=customer');
            }
            this.connectSSE();
        },
        connectSSE() {
            if (this.sse) this.sse.close();
            this.sse = new EventSource('sse.php?token=' + encodeURIComponent(this.token));
            this.sse.addEventListener('notification', (e) => {
                const note = JSON.parse(e.data);
                this.notifications.unshift({ ...note, is_read: 0 });
                this.unread++;
                this.showToast(note.message);
                this.loadRequests();
                this.loadStats();
                if (this.view === 'detail' && this.current && Number(note.request_id) === Number(this.current.request_id)) {
                    this.openRequest(this.current.request_id);
                }
            });
            this.sse.addEventListener('requests_changed', async () => {
                await this.loadRequests();
                await this.loadStats();
                if (this.view === 'detail' && this.current) {
                    try {
                        await this.openRequest(this.current.request_id);
                    } catch {
                        this.showToast('Открытая заявка была удалена');
                        this.go('requests');
                    }
                }
            });
        },
        async go(v) {
            this.error = '';
            this.view = v;
            if (v === 'dashboard' || v === 'report') await this.loadStats();
            if (v === 'dashboard' || v === 'requests') await this.loadRequests();
        },
        async loadRequests() {
            const p = new URLSearchParams();
            if (this.filter.q) p.set('q', this.filter.q);
            if (this.filter.status) p.set('status', this.filter.status);
            this.requests = await this.api('/requests?' + p.toString());
        },
        async loadStats() {
            const p = new URLSearchParams();
            if (this.view === 'report') {
                if (this.reportFilter.from) p.set('from', this.reportFilter.from);
                if (this.reportFilter.to) p.set('to', this.reportFilter.to);
            }
            this.stats = await this.api('/stats?' + p.toString());
        },
        async loadNotifications() {
            const data = await this.api('/notifications');
            this.notifications = data.items;
            this.unread = data.unread;
        },
        async readAllNotifs() {
            await this.api('/notifications/read', { method: 'POST' });
            this.unread = 0;
            this.notifications.forEach(n => n.is_read = 1);
        },
        async openRequest(id) {
            this.error = '';
            this.current = await this.api('/requests/' + id);
            this.statusForm = { status: this.current.status, repair_parts: this.current.repair_parts || '' };
            this.masterId = this.current.master_id || '';
            this.view = 'detail';
        },
        openForm(req) {
            this.error = '';
            this.form = req
                ? { request_id: req.request_id, tech_type: req.tech_type, tech_model: req.tech_model, problem: req.problem, start_date: req.start_date }
                : { request_id: null, client_id: '', tech_type: 'Компьютер', tech_model: '', problem: '', start_date: new Date().toISOString().slice(0, 10) };
            this.view = 'form';
        },
        async saveRequest() {
            try {
                const saved = this.form.request_id
                    ? await this.api('/requests/' + this.form.request_id, { method: 'PUT', body: JSON.stringify(this.form) })
                    : await this.api('/requests', { method: 'POST', body: JSON.stringify(this.form) });
                this.showToast('Заявка сохранена');
                await this.openRequest(saved.request_id);
            } catch (e) { this.error = e.message; }
        },
        async saveStatus() {
            try {
                await this.api(`/requests/${this.current.request_id}/status`, { method: 'PATCH', body: JSON.stringify(this.statusForm) });
                this.showToast('Статус обновлён');
                await this.openRequest(this.current.request_id);
            } catch (e) { this.error = e.message; }
        },
        async assignMaster() {
            try {
                await this.api(`/requests/${this.current.request_id}/master`, { method: 'PATCH', body: JSON.stringify({ master_id: this.masterId || null }) });
                this.showToast('Мастер обновлён');
                await this.openRequest(this.current.request_id);
            } catch (e) { this.error = e.message; }
        },
        async addComment() {
            try {
                await this.api(`/requests/${this.current.request_id}/comments`, { method: 'POST', body: JSON.stringify({ message: this.commentText }) });
                this.commentText = '';
                await this.openRequest(this.current.request_id);
            } catch (e) { this.error = e.message; }
        },
        async deleteRequest(id) {
            if (!confirm(`Удалить заявку №${id}?`)) return;
            await this.api('/requests/' + id, { method: 'DELETE' });
            this.showToast(`Заявка №${id} удалена`);
            await this.go('requests');
        },
        statusLabel(s) {
            return { new: 'Новая', in_progress: 'В ремонте', waiting_parts: 'Ждём запчасти', ready: 'Готова к выдаче' }[s] || s;
        },
        roleLabel(r) {
            return { manager: 'Менеджер', technician: 'Техник', operator: 'Оператор', customer: 'Заказчик' }[r] || r;
        },
        shortName(fio) {
            const p = (fio || '').trim().split(/\s+/);
            return p[1] || p[0] || '';
        },
        formatDate(d) {
            return d ? d.split('-').reverse().join('.') : '—';
        },
        barWidth(val, list) {
            const max = Math.max(...list.map(i => Number(i.total)), 1);
            return Math.round((Number(val) / max) * 100) + '%';
        },
    },
}).mount('#app');