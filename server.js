const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const url = require('url');

// Configuración de WooCommerce
const WOO_SITE_URL = 'https://liberiaairportshuttle.com';
const WOO_CONSUMER_KEY = 'ck_9ed293885403c0014233765ac48845870ad8d1f6';
const WOO_CONSUMER_SECRET = 'cs_0e121a30c38b1948f60da3f10ef3744ef9d8be7e';

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

/**
 * Servidor principal
 */
const server = http.createServer((req, res) => {
    const parsedUrl = url.parse(req.url, true);
    let pathname = parsedUrl.pathname;
    
    // Ruta raíz -> servir index.html
    if (pathname === '/') {
        pathname = '/index.html';
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
    console.log(`     • http://localhost:${PORT}/index.html`);
    console.log(`     • http://localhost:${PORT}/viajes.html`);
    console.log('');
    console.log('  🔐 API Proxy: Activo (credenciales protegidas)');
    console.log('');
    console.log('  Presiona Ctrl+C para detener el servidor');
    console.log('═══════════════════════════════════════════════════');
    console.log('');
});
