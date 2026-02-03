const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const url = require('url');
const mysql = require('mysql2/promise');

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

// Detectar ambiente automáticamente
// En producción (servidor Linux Bluehost) usamos localhost
// En desarrollo local (Windows) usamos la IP del servidor
const isProduction = process.platform !== 'win32' || process.env.NODE_ENV === 'production';
const DB_HOST = isProduction ? 'localhost' : (process.env.DB_HOST || 'localhost');

console.log(`🔧 Ambiente: ${isProduction ? 'PRODUCCIÓN' : 'DESARROLLO LOCAL'}`);
console.log(`🔧 DB Host: ${DB_HOST}`);

// Configuración de MySQL (desde .env)
const DB_CONFIG = {
    host: DB_HOST,
    user: process.env.DB_USER,
    password: process.env.DB_PASS,
    database: process.env.DB_NAME
};

let dbPool = null;

// Inicializar pool de conexiones MySQL
async function initDB() {
    try {
        dbPool = mysql.createPool(DB_CONFIG);
        const conn = await dbPool.getConnection();
        console.log('✅ Conexión a MySQL establecida');
        conn.release();
    } catch (err) {
        console.error('⚠️  Error conectando a MySQL:', err.message);
        console.log('   (Las reservas locales no funcionarán sin MySQL)');
    }
}

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
// API DE RESERVAS (MySQL Local)
// ═══════════════════════════════════════════════════

async function handleReservasAPI(req, res, parsedUrl) {
    res.setHeader('Content-Type', 'application/json');

    if (!dbPool) {
        res.writeHead(500);
        res.end(JSON.stringify({ error: 'Base de datos no disponible' }));
        return;
    }

    const method = req.method;

    try {
        // GET - Obtener reservas
        if (method === 'GET') {
            const orderId = parsedUrl.query.order_id;

            // Reserva específica
            if (orderId) {
                const [rows] = await dbPool.execute('SELECT * FROM reservas WHERE id = ?', [orderId]);
                if (rows.length > 0) {
                    res.writeHead(200);
                    res.end(JSON.stringify(transformReserva(rows[0])));
                } else {
                    res.writeHead(404);
                    res.end(JSON.stringify({ error: 'Reserva no encontrada' }));
                }
                return;
            }

            // Listar reservas
            const perPage = parseInt(parsedUrl.query.per_page) || 100;
            const page = parseInt(parsedUrl.query.page) || 1;
            const offset = (page - 1) * perPage;

            let whereClause = '1=1';
            const params = [];

            if (parsedUrl.query.after) {
                whereClause += ' AND date_created >= ?';
                params.push(parsedUrl.query.after);
            }

            // Contar total
            const [countResult] = await dbPool.execute(`SELECT COUNT(*) as total FROM reservas WHERE ${whereClause}`, params);
            const total = countResult[0].total;
            const totalPages = Math.ceil(total / perPage);

            // Obtener reservas
            const [rows] = await dbPool.execute(
                `SELECT * FROM reservas WHERE ${whereClause} ORDER BY date_created DESC LIMIT ? OFFSET ?`,
                [...params, perPage, offset]
            );

            res.setHeader('X-WP-Total', total);
            res.setHeader('X-WP-TotalPages', totalPages);
            res.writeHead(200);
            res.end(JSON.stringify(rows.map(transformReserva)));
            return;
        }

        // PUT - Actualizar reserva
        if (method === 'PUT') {
            const orderId = parsedUrl.query.order_id;
            if (!orderId) {
                res.writeHead(400);
                res.end(JSON.stringify({ error: 'ID requerido' }));
                return;
            }

            let body = '';
            req.on('data', chunk => body += chunk);
            req.on('end', async () => {
                const input = JSON.parse(body);
                const updates = [];
                const values = [];

                if (input.status) { updates.push('status = ?'); values.push(input.status); }
                if (input.billing) {
                    const b = input.billing;
                    if (b.first_name || b.last_name) {
                        updates.push('cliente_nombre = ?');
                        values.push(`${b.first_name || ''} ${b.last_name || ''}`.trim());
                    }
                    if (b.email) { updates.push('cliente_email = ?'); values.push(b.email); }
                    if (b.phone) { updates.push('cliente_telefono = ?'); values.push(b.phone); }
                    if (b.country) { updates.push('cliente_pais = ?'); values.push(b.country); }
                    if (b.address_1) { updates.push('cliente_direccion = ?'); values.push(b.address_1); }
                }

                if (input.meta_data) {
                    const metaMap = {
                        '- Arrival Date': 'llegada_fecha', '- Arrival Time': 'llegada_hora',
                        '- Arrival Flight Number': 'llegada_vuelo', 'chofer_llegada': 'llegada_chofer',
                        'subchofer_llegada': 'llegada_subchofer', 'nota_choferes_llegada': 'llegada_nota_choferes',
                        'notas_internas_llegada': 'llegada_notas_internas', '- Departure Date': 'salida_fecha',
                        '- Pick-up Time at Hotel': 'salida_hora', '- Departure Flight Number': 'salida_vuelo',
                        'chofer_salida': 'salida_chofer', 'subchofer_salida': 'salida_subchofer',
                        'nota_choferes_ida': 'salida_nota_choferes', 'notas_internas_salida': 'salida_notas_internas'
                    };
                    input.meta_data.forEach(m => {
                        if (metaMap[m.key]) { updates.push(`${metaMap[m.key]} = ?`); values.push(m.value); }
                    });
                }

                if (updates.length > 0) {
                    values.push(orderId);
                    await dbPool.execute(`UPDATE reservas SET ${updates.join(', ')} WHERE id = ?`, values);
                }

                const [rows] = await dbPool.execute('SELECT * FROM reservas WHERE id = ?', [orderId]);
                res.writeHead(200);
                res.end(JSON.stringify(transformReserva(rows[0])));
            });
            return;
        }

        res.writeHead(405);
        res.end(JSON.stringify({ error: 'Método no permitido' }));

    } catch (err) {
        console.error('Error en API reservas:', err);
        res.writeHead(500);
        res.end(JSON.stringify({ error: err.message }));
    }
}

function transformReserva(r) {
    return {
        id: r.id,
        status: r.status,
        date_created: r.date_created,
        billing: {
            first_name: (r.cliente_nombre || '').split(' ')[0] || '',
            last_name: (r.cliente_nombre || '').split(' ').slice(1).join(' '),
            email: r.cliente_email || '',
            phone: r.cliente_telefono || '',
            country: r.cliente_pais || '',
            address_1: r.cliente_direccion || ''
        },
        shipping: {
            first_name: (r.cliente_nombre || '').split(' ')[0] || '',
            last_name: (r.cliente_nombre || '').split(' ').slice(1).join(' '),
            address_1: r.cliente_direccion || '',
            city: '', country: r.cliente_pais || ''
        },
        line_items: [{
            name: r.hotel_nombre || 'Transfer',
            quantity: r.pasajeros || 1,
            subtotal: r.subtotal || '0.00',
            meta_data: []
        }],
        meta_data: buildMetaData(r),
        payment_method_title: r.metodo_pago || '',
        total: r.total || '0.00',
        fee_lines: r.cargos_adicionales > 0 ? [{ name: 'Cargos', total: r.cargos_adicionales }] : [],
        tax_lines: r.impuestos > 0 ? [{ label: 'Impuestos', tax_total: r.impuestos }] : [],
        coupon_lines: r.descuentos > 0 ? [{ code: 'Descuento', discount: r.descuentos }] : []
    };
}

function buildMetaData(r) {
    const meta = [];

    // Función para formatear hora (quitar segundos: HH:MM:SS -> HH:MM)
    const formatTime = (t) => {
        if (!t) return null;
        const str = String(t);
        if (str.length === 8 && str.includes(':')) return str.substring(0, 5);
        return str;
    };

    if (r.tipo_viaje) meta.push({ key: '- Type of Trip', value: r.tipo_viaje });
    if (r.pasajeros) meta.push({ key: 'Passengers', value: r.pasajeros });
    if (r.llegada_fecha) meta.push({ key: '- Arrival Date', value: r.llegada_fecha });
    if (r.llegada_hora) meta.push({ key: '- Arrival Time', value: formatTime(r.llegada_hora) });
    if (r.llegada_vuelo) meta.push({ key: '- Arrival Flight Number', value: r.llegada_vuelo });
    if (r.llegada_chofer) meta.push({ key: 'chofer_llegada', value: r.llegada_chofer });
    if (r.llegada_subchofer) meta.push({ key: 'subchofer_llegada', value: r.llegada_subchofer });
    if (r.llegada_nota_choferes) meta.push({ key: 'nota_choferes_llegada', value: r.llegada_nota_choferes });
    if (r.llegada_notas_internas) meta.push({ key: 'notas_internas_llegada', value: r.llegada_notas_internas });
    if (r.salida_fecha) meta.push({ key: '- Departure Date', value: r.salida_fecha });
    if (r.salida_hora) meta.push({ key: '- Pick-up Time at Hotel', value: formatTime(r.salida_hora) });
    if (r.salida_vuelo) meta.push({ key: '- Departure Flight Number', value: r.salida_vuelo });
    if (r.salida_chofer) meta.push({ key: 'chofer_salida', value: r.salida_chofer });
    if (r.salida_subchofer) meta.push({ key: 'subchofer_salida', value: r.salida_subchofer });
    if (r.salida_nota_choferes) meta.push({ key: 'nota_choferes_ida', value: r.salida_nota_choferes });
    if (r.salida_notas_internas) meta.push({ key: 'notas_internas_salida', value: r.salida_notas_internas });
    return meta;
}

// ═══════════════════════════════════════════════════
// API DE CHOFERES (MySQL Local)
// ═══════════════════════════════════════════════════

async function handleChoferesAPI(req, res) {
    res.setHeader('Content-Type', 'application/json');

    if (!dbPool) {
        res.writeHead(500);
        res.end(JSON.stringify({ error: 'Base de datos no disponible' }));
        return;
    }

    try {
        const [rows] = await dbPool.execute('SELECT id, nombre FROM choferes ORDER BY nombre');
        res.writeHead(200);
        res.end(JSON.stringify(rows));
    } catch (err) {
        console.error('Error en API choferes:', err);
        res.writeHead(500);
        res.end(JSON.stringify({ error: err.message }));
    }
}

// ═══════════════════════════════════════════════════
// SINCRONIZACIÓN WOOCOMMERCE → MySQL
// ═══════════════════════════════════════════════════

async function handleSyncAPI(req, res) {
    res.setHeader('Content-Type', 'application/json');

    if (!dbPool) {
        res.writeHead(500);
        res.end(JSON.stringify({ error: 'Base de datos no disponible' }));
        return;
    }

    console.log('🔄 Iniciando sincronización de reservas...');

    try {
        const auth = Buffer.from(`${WOO_CONSUMER_KEY}:${WOO_CONSUMER_SECRET}`).toString('base64');
        let page = 1;
        let totalSynced = 0;
        let hasMore = true;

        // Fecha de inicio: 7 meses atrás
        const startDate = new Date();
        startDate.setMonth(startDate.getMonth() - 7);
        const afterDate = startDate.toISOString().split('.')[0];

        while (hasMore) {
            const apiUrl = `${WOO_SITE_URL}/wp-json/wc/v3/orders?per_page=100&page=${page}&after=${afterDate}&orderby=date&order=desc`;

            const response = await fetch(apiUrl, {
                headers: { 'Authorization': `Basic ${auth}` }
            });

            if (!response.ok) {
                throw new Error(`Error API WooCommerce: ${response.status}`);
            }

            const orders = await response.json();

            if (!Array.isArray(orders) || orders.length === 0) {
                hasMore = false;
                break;
            }

            // Guardar cada orden en la BD
            for (const order of orders) {
                // Log para depurar la primera orden
                if (page === 1 && totalSynced === 0) {
                    const clean = (s) => s.toLowerCase().replace(/[^a-z0-9]/g, '');
                    const targetKey = '- Arrival Date';
                    const lineMeta = order.line_items?.[0]?.meta_data;
                    console.log('\n🔍 DEBUG - Buscando fecha de llegada:');
                    console.log('   Target (clean):', clean(targetKey));
                    console.log('   Metas disponibles:');
                    lineMeta?.forEach(m => {
                        console.log(`      key="${m.key}" -> clean="${clean(m.key || '')}" | value="${m.value}"`);
                    });
                }
                await saveOrderToDB(order);
                totalSynced++;
            }

            console.log(`   📦 Página ${page}: ${orders.length} reservas sincronizadas`);

            if (orders.length < 100) {
                hasMore = false;
            } else {
                page++;
                if (page > 50) hasMore = false; // Límite de seguridad
            }
        }

        console.log(`✅ Sincronización completada: ${totalSynced} reservas`);

        res.writeHead(200);
        res.end(JSON.stringify({
            success: true,
            message: `Sincronización completada: ${totalSynced} reservas importadas`,
            total: totalSynced
        }));

    } catch (err) {
        console.error('❌ Error en sincronización:', err);
        res.writeHead(500);
        res.end(JSON.stringify({ error: err.message }));
    }
}

async function saveOrderToDB(order) {
    // Función de limpieza para comparar claves (igual que deepScanMeta en el frontend)
    const clean = (s) => s.toLowerCase().replace(/[^a-z0-9]/g, '');

    const meta = (key) => {
        const target = clean(key);

        // Buscar en order.meta_data
        let found = order.meta_data?.find(m => clean(m.key || '') === target || clean(m.label || '') === target);
        if (found) return found.value;

        // Buscar en line_items[0].meta_data
        const lineItem = order.line_items?.[0];
        const lineMeta = lineItem?.meta_data?.find(m =>
            clean(m.key || '') === target || clean(m.display_key || '') === target
        );
        return lineMeta?.value || null;
    };

    // Función para convertir fechas de varios formatos a YYYY-MM-DD
    const parseDate = (dateStr) => {
        if (!dateStr) return null;

        // Si ya está en formato YYYY-MM-DD, retornarlo
        if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr;

        // Intentar parsear formatos como MM/DD/YYYY o DD/MM/YYYY
        const parts = dateStr.split('/');
        if (parts.length === 3) {
            let [a, b, year] = parts;
            // Si el año tiene 2 dígitos, convertir a 4
            if (year.length === 2) year = '20' + year;

            // Determinar si es MM/DD/YYYY o DD/MM/YYYY
            // Si el primer número > 12, entonces es DD/MM/YYYY
            if (parseInt(a) > 12) {
                return `${year}-${b.padStart(2, '0')}-${a.padStart(2, '0')}`;
            } else {
                // Asumimos MM/DD/YYYY (formato americano usado en la reserva)
                return `${year}-${a.padStart(2, '0')}-${b.padStart(2, '0')}`;
            }
        }

        return null;
    };

    // Buscar dirección en múltiples lugares
    let direccion = order.billing?.address_1 || '';
    if (!direccion) direccion = order.shipping?.address_1 || '';
    if (!direccion) direccion = meta('_shipping_address_1') || '';
    if (!direccion) direccion = meta('_billing_address_1') || '';
    if (!direccion) direccion = meta('shipping_address_1') || '';
    if (!direccion) direccion = meta('billing_address_1') || '';

    const data = {
        id: order.id,
        status: order.status,
        date_created: order.date_created,
        cliente_nombre: `${order.billing.first_name || ''} ${order.billing.last_name || ''}`.trim(),
        cliente_email: order.billing.email || '',
        cliente_telefono: order.billing.phone || '',
        cliente_pais: order.billing.country || '',
        cliente_direccion: direccion,
        tipo_viaje: meta('- Type of Trip'),
        pasajeros: parseInt(meta('Passengers')) || 1,
        hotel_nombre: order.line_items?.[0]?.name || '',
        llegada_fecha: parseDate(meta('- Arrival Date')),
        llegada_hora: meta('- Arrival Time') || null,
        llegada_vuelo: meta('- Arrival Flight Number') || null,
        llegada_chofer: meta('chofer_llegada') || null,
        llegada_subchofer: meta('subchofer_llegada') || null,
        llegada_nota_choferes: meta('nota_choferes_llegada') || null,
        llegada_notas_internas: meta('notas_internas_llegada') || null,
        salida_fecha: parseDate(meta('- Departure Date')),
        salida_hora: meta('- Pick-up Time at Hotel') || null,
        salida_vuelo: meta('- Departure Flight Number') || null,
        salida_chofer: meta('chofer_salida') || null,
        salida_subchofer: meta('subchofer_salida') || null,
        salida_nota_choferes: meta('nota_choferes_ida') || null,
        salida_notas_internas: meta('notas_internas_salida') || null,
        metodo_pago: order.payment_method_title || '',
        subtotal: parseFloat(order.line_items?.reduce((sum, item) => sum + parseFloat(item.subtotal || 0), 0)) || 0,
        cargos_adicionales: parseFloat(order.fee_lines?.reduce((sum, fee) => sum + parseFloat(fee.total || 0), 0)) || 0,
        impuestos: parseFloat(order.tax_lines?.reduce((sum, tax) => sum + parseFloat(tax.tax_total || 0), 0)) || 0,
        descuentos: parseFloat(order.coupon_lines?.reduce((sum, c) => sum + parseFloat(c.discount || 0), 0)) || 0,
        total: parseFloat(order.total) || 0,
        raw_data: JSON.stringify(order)
    };

    const sql = `
        INSERT INTO reservas (id, status, date_created, cliente_nombre, cliente_email, cliente_telefono, cliente_pais, cliente_direccion,
            tipo_viaje, pasajeros, hotel_nombre, llegada_fecha, llegada_hora, llegada_vuelo, llegada_chofer, llegada_subchofer,
            llegada_nota_choferes, llegada_notas_internas, salida_fecha, salida_hora, salida_vuelo, salida_chofer, salida_subchofer,
            salida_nota_choferes, salida_notas_internas, metodo_pago, subtotal, cargos_adicionales, impuestos, descuentos, total, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status), cliente_nombre = VALUES(cliente_nombre), cliente_email = VALUES(cliente_email),
            cliente_telefono = VALUES(cliente_telefono), cliente_pais = VALUES(cliente_pais), cliente_direccion = VALUES(cliente_direccion),
            tipo_viaje = VALUES(tipo_viaje), pasajeros = VALUES(pasajeros), hotel_nombre = VALUES(hotel_nombre),
            llegada_fecha = VALUES(llegada_fecha), llegada_hora = VALUES(llegada_hora), llegada_vuelo = VALUES(llegada_vuelo),
            llegada_chofer = VALUES(llegada_chofer), llegada_subchofer = VALUES(llegada_subchofer),
            llegada_nota_choferes = VALUES(llegada_nota_choferes), llegada_notas_internas = VALUES(llegada_notas_internas),
            salida_fecha = VALUES(salida_fecha), salida_hora = VALUES(salida_hora), salida_vuelo = VALUES(salida_vuelo),
            salida_chofer = VALUES(salida_chofer), salida_subchofer = VALUES(salida_subchofer),
            salida_nota_choferes = VALUES(salida_nota_choferes), salida_notas_internas = VALUES(salida_notas_internas),
            metodo_pago = VALUES(metodo_pago), subtotal = VALUES(subtotal), cargos_adicionales = VALUES(cargos_adicionales),
            impuestos = VALUES(impuestos), descuentos = VALUES(descuentos), total = VALUES(total), raw_data = VALUES(raw_data)
    `;

    await dbPool.execute(sql, [
        data.id, data.status, data.date_created, data.cliente_nombre, data.cliente_email, data.cliente_telefono,
        data.cliente_pais, data.cliente_direccion, data.tipo_viaje, data.pasajeros, data.hotel_nombre,
        data.llegada_fecha, data.llegada_hora, data.llegada_vuelo, data.llegada_chofer, data.llegada_subchofer,
        data.llegada_nota_choferes, data.llegada_notas_internas, data.salida_fecha, data.salida_hora, data.salida_vuelo,
        data.salida_chofer, data.salida_subchofer, data.salida_nota_choferes, data.salida_notas_internas,
        data.metodo_pago, data.subtotal, data.cargos_adicionales, data.impuestos, data.descuentos, data.total, data.raw_data
    ]);
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

    // API de reservas (MySQL local)
    if (pathname === '/api/reservas.php') {
        handleReservasAPI(req, res, parsedUrl);
        return;
    }

    // API de choferes (MySQL local)
    if (pathname === '/api/choferes.php') {
        handleChoferesAPI(req, res);
        return;
    }

    // API de sincronización WooCommerce -> MySQL
    if (pathname === '/api/sync') {
        handleSyncAPI(req, res);
        return;
    }

    // Si es petición al proxy API de WooCommerce
    if (pathname === '/woo-proxy.php' || pathname === '/api') {
        handleApiProxy(req, res, parsedUrl);
        return;
    }

    // Servir archivos estáticos
    const filePath = path.join(__dirname, pathname);
    serveStaticFile(req, res, filePath);
});

// Iniciar servidor
initDB().then(() => {
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
});
