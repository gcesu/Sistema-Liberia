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
      </style>

      <div id="mobile-menu-overlay" class="mobile-menu-overlay">
        <div class="flex justify-between items-center mb-10">
            <img src="https://liberiaairportshuttle.com/wp-content/uploads/2024/11/Grupo.png" alt="Logo" class="h-8">
            <button id="close-mobile-btn" class="text-white text-4xl">&times;</button>
        </div>
        <nav class="flex flex-col gap-6">
            <a href="index.html" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">📅 Reservas</a>
            <a href="viajes.html" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">🚐 Viajes</a>
            <a href="choferes.html" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">👤 Choferes</a>
            <a href="#" id="mobile-logout-btn" class="text-red-400 text-2xl font-bold border-b border-white/10 pb-4 no-underline">🚪 Cerrar Sesión</a>
        </nav>
      </div>

      <header class="top-nav">
        <button id="hamburger-btn" class="hamburger-btn">
            <span></span><span></span><span></span>
        </button>
        
        <a href="index.html" class="flex items-center">
            <img src="https://liberiaairportshuttle.com/wp-content/uploads/2024/11/Grupo.png" alt="Logo" class="h-6 md:h-8">
        </a>
        
        <nav class="nav-links-desktop">
            <a href="index.html" class="nav-item">Reservas</a>
            <a href="viajes.html" class="nav-item">Viajes</a>
            <a href="choferes.html" class="nav-item">Choferes</a>
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
                window.location.href = 'login.html';
            } catch (error) {
                console.error('Error al cerrar sesión:', error);
                sessionStorage.removeItem('session_token');
                window.location.href = 'login.html';
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
            if ((currentPath === '/' || currentPath.endsWith('index.html')) && href === 'index.html') {
                link.classList.add('active');
            } else if (href !== '#' && (currentPath.includes(href) || (currentPath === '/' && href === 'index.html'))) {
                link.classList.add('active');
            }
        });
    }
}

customElements.define('admin-navbar', AdminNavbar);