class AdminNavbar extends HTMLElement {
    connectedCallback() {
        this.innerHTML = `
      <style>
        /* --- ESTILOS DEL MENÚ --- */
        :host {
            --primary: #002a3f;
            --accent: #ed4441;
            font-family: 'Lato', sans-serif;
        }

        /* Navbar Superior */
        .top-nav {
            background-color: var(--primary);
            height: 70px;
            display: flex;
            align-items: center;
            padding: 0 15px;
            color: white;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        @media (min-width: 1024px) {
            .top-nav { padding: 0 30px; }
        }

        .nav-links-desktop {
            display: none;
            align-items: center;
            margin-left: 40px;
            height: 100%;
        }

        @media (min-width: 1024px) {
            .nav-links-desktop { display: flex; }
        }

        .nav-item {
            height: 100%;
            display: flex;
            align-items: center;
            padding: 0 20px;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
            transition: all 0.3s;
            position: relative;
            cursor: pointer;
            color: white;
            text-decoration: none;
        }

        .nav-item:hover, .nav-item.active {
            opacity: 1;
            background: rgba(255,255,255,0.05);
        }

        .nav-item.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background-color: var(--accent);
        }

        /* Botón Actualizar */
        .btn-sync {
            background-color: var(--accent);
            color: white;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-sync:hover { background-color: #d93a37; }

        /* Menú Hamburguesa Móvil */
        .mobile-menu-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 42, 63, 0.98);
            backdrop-filter: blur(8px);
            z-index: 150;
            display: none;
            flex-direction: column;
            padding: 40px 20px;
        }

        .mobile-menu-overlay.open { display: flex; }

        .hamburger-btn {
            display: flex;
            flex-direction: column;
            justify-content: space-around;
            width: 24px;
            height: 18px;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            z-index: 160;
            margin-right: 15px;
        }

        @media (min-width: 1024px) {
            .hamburger-btn { display: none; }
        }

        .hamburger-btn span {
            width: 24px;
            height: 2px;
            background: white;
            transition: all 0.3s linear;
        }

        /* Toast de notificaciones */
        .notif-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            color: white;
            padding: 16px 20px;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 700;
            z-index: 200;
            display: none;
            align-items: center;
            gap: 12px;
            animation: notifToastSlideIn 0.4s ease;
            max-width: 360px;
        }
        .notif-toast.show { display: flex; }
        .notif-toast.notif-toast-reserva {
            background: linear-gradient(135deg, #10b981, #059669);
            box-shadow: 0 10px 40px rgba(16, 185, 129, 0.4);
        }
        .notif-toast.notif-toast-cotizacion {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            box-shadow: 0 10px 40px rgba(124, 58, 237, 0.4);
            bottom: 90px;
        }
        @keyframes notifToastSlideIn {
            from { opacity: 0; transform: translateY(20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .notif-toast .notif-toast-icon { font-size: 24px; flex-shrink: 0; }
        .notif-toast .notif-toast-content { flex: 1; }
        .notif-toast .notif-toast-title { font-size: 15px; font-weight: 800; margin-bottom: 4px; }
        .notif-toast .notif-toast-message { font-size: 12px; opacity: 0.9; }
        .notif-toast .notif-toast-btn {
            background: rgba(255,255,255,0.2); border: none; color: white;
            padding: 8px 16px; border-radius: 8px; font-size: 11px; font-weight: 800;
            text-transform: uppercase; cursor: pointer; transition: background 0.2s; white-space: nowrap;
        }
        .notif-toast .notif-toast-btn:hover { background: rgba(255,255,255,0.3); }
        .notif-toast .notif-toast-close {
            position: absolute; top: 8px; right: 8px; background: none; border: none;
            color: white; opacity: 0.6; font-size: 18px; cursor: pointer; padding: 0; line-height: 1;
        }
        .notif-toast .notif-toast-close:hover { opacity: 1; }
      </style>

      <!-- Toast Nuevas Reservas -->
      <div id="notif-toast-reservas" class="notif-toast notif-toast-reserva">
          <button class="notif-toast-close" id="notif-close-reservas">&times;</button>
          <div class="notif-toast-icon">🔔</div>
          <div class="notif-toast-content">
              <div class="notif-toast-title">Nuevas Reservas</div>
              <div class="notif-toast-message" id="notif-msg-reservas">Hay 1 nueva reserva disponible</div>
          </div>
          <button class="notif-toast-btn" id="notif-btn-reservas">Ver Ahora</button>
      </div>

      <!-- Toast Nuevas Cotizaciones -->
      <div id="notif-toast-cotizaciones" class="notif-toast notif-toast-cotizacion">
          <button class="notif-toast-close" id="notif-close-cotizaciones">&times;</button>
          <div class="notif-toast-icon">🔔</div>
          <div class="notif-toast-content">
              <div class="notif-toast-title">Nueva Cotización</div>
              <div class="notif-toast-message" id="notif-msg-cotizaciones">Hay 1 nueva cotización disponible</div>
          </div>
          <button class="notif-toast-btn" id="notif-btn-cotizaciones">Ver Ahora</button>
      </div>

      <div id="mobile-menu-overlay" class="mobile-menu-overlay">
        <div class="flex justify-between items-center mb-10">
            <img src="https://liberiaairportshuttle.com/wp-content/uploads/2024/11/Grupo.png" alt="Logo" class="h-8">
            <button id="close-mobile-btn" class="text-white text-4xl">&times;</button>
        </div>
        <nav class="flex flex-col gap-6">
            <a href="/cotizaciones" data-page="cotizaciones" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">📝 Cotizaciones</a>
            <a href="/reservas" data-page="reservas" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">📅 Reservas</a>
            <a href="/viajes" data-page="viajes" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">🚐 Viajes</a>
            <a href="/choferes" data-page="choferes" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">👤 Choferes</a>
            <a href="/contabilidad" data-page="contabilidad" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">💰 Contabilidad</a>
            <a href="/admin" data-page="admin" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline" style="display:none;">⚙️ Admin</a>
            <a href="#" id="mobile-logout-btn" class="text-red-400 text-2xl font-bold border-b border-white/10 pb-4 no-underline">🚪 Cerrar Sesión</a>
        </nav>
      </div>

      <header class="top-nav">
        <button id="hamburger-btn" class="hamburger-btn">
            <span></span><span></span><span></span>
        </button>

        <a href="/reservas" class="flex items-center">
            <img src="https://liberiaairportshuttle.com/wp-content/uploads/2024/11/Grupo.png" alt="Logo" class="h-6 md:h-8">
        </a>

        <nav class="nav-links-desktop">
            <a href="/cotizaciones" class="nav-item" data-page="cotizaciones">Cotizaciones</a>
            <a href="/reservas" class="nav-item" data-page="reservas">Reservas</a>
            <a href="/viajes" class="nav-item" data-page="viajes">Viajes</a>
            <a href="/choferes" class="nav-item" data-page="choferes">Choferes</a>
            <a href="/contabilidad" class="nav-item" data-page="contabilidad">Contabilidad</a>
            <a href="/admin" class="nav-item" data-page="admin" style="display:none;">Admin</a>
        </nav>

        <div class="ml-auto flex items-center gap-2 md:gap-4">
            <div id="api-status-badge" class="hidden sm:flex items-center bg-white/10 px-3 py-1.5 rounded-full">
                <span id="api-dot" class="w-2 h-2 bg-green-400 rounded-full mr-2"></span>
                <span class="text-[10px] font-black uppercase tracking-widest text-white">Online</span>
            </div>

            <button id="global-refresh-btn" class="btn-sync tracking-widest">Actualizar</button>
            <button id="logout-btn" class="hidden lg:flex items-center gap-2 bg-white/10 hover:bg-red-500 px-3 py-1.5 rounded-full transition-all cursor-pointer border-none">
                <span class="text-[10px] font-black uppercase tracking-widest text-white">Salir</span>
            </button>
        </div>
      </header>

      <!-- Modal Confirmación Logout -->
      <div id="logout-modal" class="logout-modal-overlay hidden">
        <div class="logout-modal-box">
          <div class="logout-modal-icon">🚪</div>
          <h3 class="logout-modal-title">¿Cerrar sesión?</h3>
          <p class="logout-modal-text">Tendrás que volver a iniciar sesión para acceder al sistema.</p>
          <div class="logout-modal-buttons">
            <button id="logout-cancel-btn" class="logout-btn-cancel">Cancelar</button>
            <button id="logout-confirm-btn" class="logout-btn-confirm">Sí, cerrar sesión</button>
          </div>
        </div>
      </div>

      <style>
        .logout-modal-overlay {
          position: fixed;
          inset: 0;
          background: rgba(0, 42, 63, 0.9);
          backdrop-filter: blur(8px);
          z-index: 300;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 20px;
        }
        .logout-modal-overlay.hidden { display: none; }
        .logout-modal-box {
          background: white;
          border-radius: 20px;
          padding: 40px;
          max-width: 400px;
          width: 100%;
          text-align: center;
          box-shadow: 0 25px 50px rgba(0,0,0,0.3);
          animation: modalSlideIn 0.3s ease;
        }
        @keyframes modalSlideIn {
          from { opacity: 0; transform: scale(0.9) translateY(-20px); }
          to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .logout-modal-icon { font-size: 48px; margin-bottom: 16px; }
        .logout-modal-title {
          font-family: 'Oswald', sans-serif;
          font-size: 24px;
          font-weight: 700;
          color: #002a3f;
          text-transform: uppercase;
          margin: 0 0 12px 0;
        }
        .logout-modal-text {
          color: #64748b;
          font-size: 14px;
          margin: 0 0 24px 0;
          line-height: 1.5;
        }
        .logout-modal-buttons {
          display: flex;
          gap: 12px;
          justify-content: center;
        }
        .logout-btn-cancel {
          padding: 12px 24px;
          border-radius: 10px;
          font-size: 13px;
          font-weight: 800;
          text-transform: uppercase;
          cursor: pointer;
          transition: all 0.2s;
          background: #f1f5f9;
          color: #64748b;
          border: none;
        }
        .logout-btn-cancel:hover { background: #e2e8f0; }
        .logout-btn-confirm {
          padding: 12px 24px;
          border-radius: 10px;
          font-size: 13px;
          font-weight: 800;
          text-transform: uppercase;
          cursor: pointer;
          transition: all 0.2s;
          background: #ef4444;
          color: white;
          border: none;
        }
        .logout-btn-confirm:hover { background: #dc2626; }
      </style>
    `;

        this.setupEvents();
        this.highlightActiveLink();
        this.setupNotifications();
        this.applyPermissions();
    }

    setupEvents() {
        const overlay = this.querySelector('#mobile-menu-overlay');
        const hamBtn = this.querySelector('#hamburger-btn');
        const closeBtn = this.querySelector('#close-mobile-btn');

        const toggleMenu = () => overlay.classList.toggle('open');

        hamBtn.addEventListener('click', toggleMenu);
        closeBtn.addEventListener('click', toggleMenu);

        this.querySelectorAll('.mobile-menu-overlay a').forEach(link => {
            link.addEventListener('click', () => overlay.classList.remove('open'));
        });

        const refreshBtn = this.querySelector('#global-refresh-btn');
        refreshBtn.addEventListener('click', () => {
            if (typeof window.refreshData === 'function') {
                window.refreshData(1);
            } else {
                window.location.reload();
            }
        });

        // Logout elements
        const logoutBtn = this.querySelector('#logout-btn');
        const mobileLogoutBtn = this.querySelector('#mobile-logout-btn');
        const logoutModal = this.querySelector('#logout-modal');
        const logoutCancelBtn = this.querySelector('#logout-cancel-btn');
        const logoutConfirmBtn = this.querySelector('#logout-confirm-btn');

        // Mostrar modal de confirmación
        const showLogoutModal = (e) => {
            e.preventDefault();
            overlay.classList.remove('open'); // Cerrar menú móvil si está abierto
            logoutModal.classList.remove('hidden');
        };

        // Cerrar modal
        const hideLogoutModal = () => {
            logoutModal.classList.add('hidden');
        };

        // Confirmar logout
        const confirmLogout = async () => {
            try {
                sessionStorage.removeItem('session_token');
                await fetch('api/logout.php');
                window.location.href = '/login';
            } catch (error) {
                console.error('Error al cerrar sesión:', error);
                sessionStorage.removeItem('session_token');
                window.location.href = '/login';
            }
        };

        // Event listeners
        if (logoutBtn) logoutBtn.addEventListener('click', showLogoutModal);
        if (mobileLogoutBtn) mobileLogoutBtn.addEventListener('click', showLogoutModal);
        if (logoutCancelBtn) logoutCancelBtn.addEventListener('click', hideLogoutModal);
        if (logoutConfirmBtn) logoutConfirmBtn.addEventListener('click', confirmLogout);

        // Cerrar modal al hacer clic fuera
        if (logoutModal) {
            logoutModal.addEventListener('click', (e) => {
                if (e.target === logoutModal) hideLogoutModal();
            });
        }
    }

    highlightActiveLink() {
        const currentPath = window.location.pathname;
        const links = this.querySelectorAll('.nav-item');

        links.forEach(link => {
            const href = link.getAttribute('href');
            // Check for reservas/index page
            if ((currentPath === '/' || currentPath === '/reservas' || currentPath.endsWith('index.html')) && href === '/reservas') {
                link.classList.add('active');
            } else if (href !== '#' && (currentPath === href || currentPath === href + '.html')) {
                link.classList.add('active');
            }
        });
    }

    // ========== SISTEMA DE NOTIFICACIONES GLOBAL ==========
    setupNotifications() {
        this._pollingPaused = false;
        this._pollingInterval = null;
        const POLLING_MS = 30000;

        // Inicializar tiempos de última verificación
        const now = new Date().toISOString().replace('T', ' ').substring(0, 19);
        if (!sessionStorage.getItem('last_check_time')) {
            sessionStorage.setItem('last_check_time', now);
        }
        if (!sessionStorage.getItem('last_quote_check_time')) {
            sessionStorage.setItem('last_quote_check_time', now);
        }

        // Event listeners para toasts
        this.querySelector('#notif-close-reservas').addEventListener('click', () => this.hideToast('reservas'));
        this.querySelector('#notif-btn-reservas').addEventListener('click', () => this.handleVerAhora('reservas'));
        this.querySelector('#notif-close-cotizaciones').addEventListener('click', () => this.hideToast('cotizaciones'));
        this.querySelector('#notif-btn-cotizaciones').addEventListener('click', () => this.handleVerAhora('cotizaciones'));

        // Iniciar polling después de 3 segundos
        setTimeout(() => {
            this._pollingInterval = setInterval(() => this.checkForNew(), POLLING_MS);
        }, 3000);
    }

    async checkForNew() {
        if (this._pollingPaused) return;
        try {
            const token = sessionStorage.getItem('session_token');
            if (!token) return;

            // Usar el timestamp más antiguo entre reservas y cotizaciones
            const sinceRes = sessionStorage.getItem('last_check_time');
            const sinceQuote = sessionStorage.getItem('last_quote_check_time');
            const since = sinceRes < sinceQuote ? sinceRes : sinceQuote;

            const response = await fetch(`api/check_new.php?since=${encodeURIComponent(since)}`, {
                headers: { 'X-Session-Token': token }
            });
            if (!response.ok) return;

            const data = await response.json();

            // Verificar nuevas reservas
            if (data.new_count > 0 && data.reservations) {
                const notifiedIds = JSON.parse(localStorage.getItem('notified_reservation_ids') || '[]');
                const newItems = data.reservations.filter(r => !notifiedIds.includes(r.id));

                if (newItems.length > 0) {
                    const newIds = newItems.map(r => r.id);
                    const allIds = [...notifiedIds, ...newIds].slice(-200);
                    localStorage.setItem('notified_reservation_ids', JSON.stringify(allIds));
                    this.showToast('reservas', newItems.length, newItems);
                }
            }

            // Verificar nuevas cotizaciones
            if (data.new_quotes_count > 0 && data.quotes) {
                const notifiedIds = JSON.parse(localStorage.getItem('notified_quote_ids') || '[]');
                const newItems = data.quotes.filter(q => !notifiedIds.includes(q.id));

                if (newItems.length > 0) {
                    const newIds = newItems.map(q => q.id);
                    const allIds = [...notifiedIds, ...newIds].slice(-200);
                    localStorage.setItem('notified_quote_ids', JSON.stringify(allIds));
                    this.showToast('cotizaciones', newItems.length, newItems);
                }
            }

            // Actualizar tiempos con hora del servidor
            if (data.server_time) {
                sessionStorage.setItem('last_check_time', data.server_time);
                sessionStorage.setItem('last_quote_check_time', data.server_time);
            }

        } catch (err) {
            console.error('Error verificando notificaciones:', err);
        }
    }

    showToast(type, count, items) {
        const toast = this.querySelector(`#notif-toast-${type}`);
        const msg = this.querySelector(`#notif-msg-${type}`);

        if (type === 'reservas') {
            if (count === 1 && items[0]) {
                msg.textContent = `${items[0].cliente_nombre} - #${items[0].id}`;
            } else {
                msg.textContent = `Hay ${count} nueva${count > 1 ? 's' : ''} reserva${count > 1 ? 's' : ''} disponible${count > 1 ? 's' : ''}`;
            }
        } else {
            if (count === 1 && items[0]) {
                msg.textContent = `${items[0].cliente_nombre} - #${items[0].id}`;
            } else {
                msg.textContent = `Hay ${count} nueva${count > 1 ? 's' : ''} cotización${count > 1 ? 'es' : ''} disponible${count > 1 ? 's' : ''}`;
            }
        }

        toast.classList.add('show');
        this.playNotificationSound();
    }

    hideToast(type) {
        const toast = this.querySelector(`#notif-toast-${type}`);
        toast.classList.remove('show');

        const timeKey = type === 'reservas' ? 'last_check_time' : 'last_quote_check_time';
        sessionStorage.setItem(timeKey, new Date().toISOString().replace('T', ' ').substring(0, 19));

        this.pausePolling(60);
    }

    handleVerAhora(type) {
        this.hideToast(type);

        const currentPath = window.location.pathname;
        const isOnReservas = currentPath === '/' || currentPath === '/reservas' || currentPath.endsWith('index.html');
        const isOnCotizaciones = currentPath === '/cotizaciones' || currentPath.endsWith('cotizaciones.html');

        if (type === 'reservas') {
            if (isOnReservas && typeof window.refreshData === 'function') {
                window.refreshData(1);
            } else {
                window.location.href = '/reservas';
            }
        } else {
            if (isOnCotizaciones) {
                if (typeof window.loadOrders === 'function') {
                    window.loadOrders();
                } else if (typeof window.refreshData === 'function') {
                    window.refreshData(1);
                } else {
                    window.location.reload();
                }
            } else {
                window.location.href = '/cotizaciones';
            }
        }
    }

    pausePolling(seconds = 60) {
        this._pollingPaused = true;
        setTimeout(() => { this._pollingPaused = false; }, seconds * 1000);
    }

    async applyPermissions() {
        try {
            const token = sessionStorage.getItem('session_token');
            if (!token) return;

            const resp = await fetch('api/check_session.php', { headers: { 'X-Session-Token': token } });
            const data = await resp.json();
            if (!data.authenticated) return;

            // Mostrar link Admin solo para admins
            if (data.is_admin) {
                this.querySelectorAll('[data-page="admin"]').forEach(el => el.style.display = '');
            }

            // Si es admin o no tiene permisos configurados (null), mostrar todo
            if (data.is_admin || !data.permisos) return;

            // Ocultar links sin permiso
            const pages = ['reservas', 'cotizaciones', 'viajes', 'choferes', 'contabilidad'];
            pages.forEach(page => {
                if (!data.permisos[page]) {
                    this.querySelectorAll(`[data-page="${page}"]`).forEach(el => el.style.display = 'none');
                }
            });
        } catch (e) {
            console.error('Error aplicando permisos:', e);
        }
    }

    playNotificationSound() {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const playTone = (freq, startTime, duration) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = freq;
                osc.type = 'sine';
                gain.gain.setValueAtTime(0, startTime);
                gain.gain.linearRampToValueAtTime(0.4, startTime + 0.01);
                gain.gain.exponentialRampToValueAtTime(0.01, startTime + duration);
                osc.start(startTime);
                osc.stop(startTime + duration);
            };
            const now = ctx.currentTime;
            playTone(880, now, 0.12);
            playTone(1175, now + 0.15, 0.15);
        } catch (e) {}
    }
}

customElements.define('admin-navbar', AdminNavbar);
