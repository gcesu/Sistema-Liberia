const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const url = require('url');

// ═══════════════════════════════════════════════════
// CARGAR VARIABLES DE ENTORNO DESDE .env
// ═══════════════════════════════════════════════════
function loadEnv() {
    const envPath = path.join(__dirname, '.env');
    if (fs.existsSync(envPath)) {
        const content = fs.readFileSync(envPath, 'utf8');
        content.split('\n').forEach(line => {
            line = line.trim();
            if (line && !line.startsWith('#') && line.includes('=')) {
                const [key, ...valueParts] = line.split('=');
                const value = valueParts.join('=').trim().replace(/^["']|["']$/g, '');
                process.env[key.trim()] = value;
            }
        });
    }
}
loadEnv();

// Configuración de WooCommerce (desde .env)
const WOO_SITE_URL = process.env.WOO_SITE_URL || 'https://liberiaairportshuttle.com';
const WOO_CONSUMER_KEY = process.env.WOO_CONSUMER_KEY;
const WOO_CONSUMER_SECRET = process.env.WOO_CONSUMER_SECRET;

// Validar que las credenciales existan
if (!WOO_CONSUMER_KEY || !WOO_CONSUMER_SECRET) {
    console.error('⚠️  ADVERTENCIA: Credenciales de WooCommerce no encontradas en .env');
}

const PORT = 3000;

// MIME types para servir archivos estáticos
const mimeTypes = {
    '.html': 'text/html',
    '.js': 'text/javascript',
    '.css': 'text/css',
    '.json': 'application/json',
    '.png': 'image/png',
    '.jpg': 'image/jpg',
    '.gif': 'image/gif',
    '.svg': 'image/svg+xml',
    '.ico': 'image/x-icon'
};

/**
 * Proxy para WooCommerce API
 */
function handleApiProxy(req, res, parsedUrl) {
    const auth = Buffer.from(`${WOO_CONSUMER_KEY}:${WOO_CONSUMER_SECRET}`).toString('base64');
    
    // Construir URL de WooCommerce
    let apiPath = '/wp-json/wc/v3/orders';
    const orderId = parsedUrl.query.order_id;
    
    if (orderId) {
        apiPath += `/${orderId}`;
        delete parsedUrl.query.order_id;
    }
    
    // Agregar query params
    const queryString = new URLSearchParams(parsedUrl.query).toString();
    const fullUrl = `${WOO_SITE_URL}${apiPath}${queryString ? '?' + queryString : ''}`;
    
    const options = {
        method: req.method,
        headers: {
            'Authorization': `Basic ${auth}`,
            'Content-Type': 'application/json',
            'User-Agent': 'NodeProxy/1.0'
        }
    };
    
    // Realizar la petición a WooCommerce
    const apiReq = https.request(fullUrl, options, (apiRes) => {
        // Reenviar headers importantes
        res.setHeader('Content-Type', 'application/json');
        res.setHeader('Access-Control-Allow-Origin', '*');
        
        if (apiRes.headers['x-wp-total']) {
            res.setHeader('X-WP-Total', apiRes.headers['x-wp-total']);
        }
        if (apiRes.headers['x-wp-totalpages']) {
            res.setHeader('X-WP-TotalPages', apiRes.headers['x-wp-totalpages']);
        }
        
        res.writeHead(apiRes.statusCode);
        
        let data = '';
        apiRes.on('data', chunk => data += chunk);
        apiRes.on('end', () => res.end(data));
    });
    
    apiReq.on('error', (error) => {
        console.error('Error en API:', error);
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'Error conectando con WooCommerce' }));
    });
    
    // Si hay body (PUT/POST), reenviarlo
    if (req.method === 'PUT' || req.method === 'POST') {
        let body = '';
        req.on('data', chunk => body += chunk);
        req.on('end', () => apiReq.end(body));
    } else {
        apiReq.end();
    }
}

/**
 * Servir archivos estáticos
 */
function serveStaticFile(req, res, filePath) {
    fs.readFile(filePath, (err, data) => {
        if (err) {
            if (err.code === 'ENOENT') {
                res.writeHead(404, { 'Content-Type': 'text/html' });
                res.end('<h1>404 - Archivo no encontrado</h1>');
            } else {
                res.writeHead(500);
                res.end('Error del servidor');
            }
        } else {
            const ext = path.extname(filePath);
            const contentType = mimeTypes[ext] || 'application/octet-stream';
            res.writeHead(200, { 'Content-Type': contentType });
            res.end(data);
        }
    });
}

// ═══════════════════════════════════════════════════
// SISTEMA DE AUTENTICACIÓN LOCAL (para desarrollo)
// ═══════════════════════════════════════════════════

// Usuarios para pruebas locales (en producción usar la BD)
const LOCAL_USERS = {
    'admin': 'adminliberiashuttle2026'  // usuario: contraseña (igual que en la BD)
};

// Sesiones en memoria (para desarrollo local)
let sessions = {};

function generateSessionId() {
    return Math.random().toString(36).substring(2) + Date.now().toString(36);
}

function handleLogin(req, res) {
    let body = '';
    req.on('data', chunk => body += chunk);
    req.on('end', () => {
        // Parse form data
        const params = new URLSearchParams(body);
        const usuario = params.get('usuario');
        const contrasena = params.get('contrasena');
        
        res.setHeader('Content-Type', 'application/json');
        
        // Verificar credenciales
        if (LOCAL_USERS[usuario] && LOCAL_USERS[usuario] === contrasena) {
            const sessionId = generateSessionId();
            sessions[sessionId] = { user_id: 1, usuario: usuario };
            
            // Enviar cookie de sesión
            res.setHeader('Set-Cookie', `session_id=${sessionId}; Path=/; HttpOnly`);
            res.writeHead(200);
            res.end(JSON.stringify({ success: true, message: 'Login exitoso' }));
        } else {
            res.writeHead(200);
            res.end(JSON.stringify({ success: false, message: 'Usuario o contraseña incorrectos' }));
        }
    });
}

function handleCheckSession(req, res) {
    res.setHeader('Content-Type', 'application/json');
    
    // Leer cookie de sesión
    const cookies = req.headers.cookie || '';
    const sessionMatch = cookies.match(/session_id=([^;]+)/);
    const sessionId = sessionMatch ? sessionMatch[1] : null;
    
    if (sessionId && sessions[sessionId]) {
        res.writeHead(200);
        res.end(JSON.stringify({
            authenticated: true,
            user_id: sessions[sessionId].user_id,
            usuario: sessions[sessionId].usuario
        }));
    } else {
        res.writeHead(200);
        res.end(JSON.stringify({ authenticated: false }));
    }
}

function handleLogout(req, res) {
    res.setHeader('Content-Type', 'application/json');
    
    // Leer y eliminar sesión
    const cookies = req.headers.cookie || '';
    const sessionMatch = cookies.match(/session_id=([^;]+)/);
    const sessionId = sessionMatch ? sessionMatch[1] : null;
    
    if (sessionId && sessions[sessionId]) {
        delete sessions[sessionId];
    }
    
    // Limpiar cookie
    res.setHeader('Set-Cookie', 'session_id=; Path=/; HttpOnly; Max-Age=0');
    res.writeHead(200);
    res.end(JSON.stringify({ success: true, message: 'Sesión cerrada' }));
}

/**
 * Servidor principal
 */
const server = http.createServer((req, res) => {
    const parsedUrl = url.parse(req.url, true);
    let pathname = parsedUrl.pathname;
    
    // Ruta raíz -> servir login.html
    if (pathname === '/') {
        pathname = '/login.html';
    }
    
    // ══════ RUTAS DE AUTENTICACIÓN ══════
    if (pathname === '/api/login.php' && req.method === 'POST') {
        handleLogin(req, res);
        return;
    }
    
    if (pathname === '/api/check_session.php') {
        handleCheckSession(req, res);
        return;
    }
    
    if (pathname === '/api/logout.php') {
        handleLogout(req, res);
        return;
    }
    
    // Si es petición al proxy API
    if (pathname === '/woo-proxy.php' || pathname === '/api') {
        handleApiProxy(req, res, parsedUrl);
        return;
    }
    
    // Servir archivos estáticos
    const filePath = path.join(__dirname, pathname);
    serveStaticFile(req, res, filePath);
});

server.listen(PORT, () => {
    console.log('');
    console.log('═══════════════════════════════════════════════════');
    console.log('  🚀 Servidor Local Iniciado');
    console.log('═══════════════════════════════════════════════════');
    console.log('');
    console.log(`  ➜ Local:   http://localhost:${PORT}`);
    console.log(`  ➜ Network: http://127.0.0.1:${PORT}`);
    console.log('');
    console.log('  📄 Páginas disponibles:');
    console.log(`     • http://localhost:${PORT}/login.html`);
    console.log('');
    console.log('  🔐 API Proxy: Activo (credenciales protegidas)');
    console.log('');
    console.log('  Presiona Ctrl+C para detener el servidor');
    console.log('═══════════════════════════════════════════════════');
    console.log('');
});
