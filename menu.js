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

      <!-- Menú Móvil Overlay -->
      <div id="mobile-menu-overlay" class="mobile-menu-overlay">
        <div class="flex justify-between items-center mb-10">
            <img src="https://liberiaairportshuttle.com/wp-content/uploads/2024/11/Grupo.png" alt="Logo" class="h-8">
            <button id="close-mobile-btn" class="text-white text-4xl">&times;</button>
        </div>
        <nav class="flex flex-col gap-6">
            <a href="index.html" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">📅 Reservas</a>
            <a href="viajes.html" class="text-white text-2xl font-bold border-b border-white/10 pb-4 no-underline">🚐 Viajes / Logística</a>
        </nav>
      </div>

      <!-- Header Principal -->
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
        </nav>

        <div class="ml-auto flex items-center gap-2 md:gap-4">
            <!-- Badge Online -->
            <div id="api-status-badge" class="hidden sm:flex items-center bg-white/10 px-3 py-1.5 rounded-full">
                <span id="api-dot" class="w-2 h-2 bg-green-400 rounded-full mr-2"></span>
                <span class="text-[10px] font-black uppercase tracking-widest text-white">Sistema OK</span>
            </div>
            
            <button id="global-refresh-btn" class="btn-sync tracking-widest">Actualizar</button>
        </div>
      </header>
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
  }

  highlightActiveLink() {
    const currentPath = window.location.pathname;
    const links = this.querySelectorAll('.nav-item');
    
    links.forEach(link => {
        const href = link.getAttribute('href');
        if ((currentPath === '/' || currentPath.endsWith('index.html')) && href === 'index.html') {
            link.classList.add('active');
        } else if (href !== '#' && currentPath.includes(href)) {
            link.classList.add('active');
        }
    });
  }
}

customElements.define('admin-navbar', AdminNavbar);