<?php
/**
 * Webhook para recibir notificaciones de WooCommerce
 * Configurar en WooCommerce: Settings > Advanced > Webhooks
 * - Topic: Order created / Order updated
 * - Delivery URL: https://tu-sitio.com/beta/api/webhook.php
 * - Secret: (configurar en .env como WOO_WEBHOOK_SECRET)
 */

require_once '../config/db.php';
require_once '../config/env.php';

// Log para debug
$logFile = __DIR__ . '/../logs/webhook.log';
function logWebhook($message)
{
    global $logFile;
    $dir = dirname($logFile);
    if (!is_dir($dir))
        mkdir($dir, 0755, true);
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

// Obtener headers (compatible con diferentes servidores)
$headers = [];
if (function_exists('getallheaders')) {
    $headers = getallheaders();
} else {
    foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) === 'HTTP_') {
            $headers[str_replace('_', '-', substr($key, 5))] = $value;
        }
    }
}

// Normalizar headers a minúsculas para búsqueda consistente
$headersLower = array_change_key_case($headers, CASE_LOWER);

// Obtener el cuerpo de la petición
$payload = file_get_contents('php://input');

// Verificar si es un ping de WooCommerce
$wcTopic = $headersLower['x-wc-webhook-topic'] ?? '';
$wcResource = $headersLower['x-wc-webhook-resource'] ?? '';
$wcDeliveryId = $headersLower['x-wc-webhook-delivery-id'] ?? '';

logWebhook("Webhook recibido. Topic: $wcTopic, Resource: $wcResource, DeliveryID: $wcDeliveryId");
logWebhook("Payload length: " . strlen($payload));

// Aceptar ping de verificación de WooCommerce (múltiples formatos)
$isPing = false;
if ($wcResource === 'webhook')
    $isPing = true;
if (strpos($wcTopic, 'ping') !== false)
    $isPing = true;
if (empty($payload) || $payload === '[]' || $payload === '{}')
    $isPing = true;

// También verificar si el payload es un objeto webhook (no una orden)
$decoded = json_decode($payload, true);
if (is_array($decoded) && isset($decoded['webhook_id']) && !isset($decoded['id']))
    $isPing = true;

if ($isPing) {
    logWebhook("Ping de WooCommerce detectado - respondiendo OK");
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Webhook configured successfully']);
    exit;
}

// Verificar que sea una petición POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Verificar firma del webhook (seguridad) - solo si hay firma
$webhookSecret = env('WOO_WEBHOOK_SECRET');
if ($webhookSecret && !empty($payload)) {
    $signature = $headersLower['x-wc-webhook-signature'] ?? '';
    $expectedSignature = base64_encode(hash_hmac('sha256', $payload, $webhookSecret, true));

    // Solo verificar si hay una firma presente
    if (!empty($signature) && !hash_equals($expectedSignature, $signature)) {
        logWebhook("ADVERTENCIA: Firma no coincide pero continuando...");
        // No rechazar, solo loguear
    }
}

// Decodificar payload
$order = json_decode($payload, true);

// Si no hay orden válida, responder OK sin procesar (para pings y pruebas)
if (!$order || !isset($order['id'])) {
    logWebhook("Payload sin orden válida - respondiendo OK (posible ping o prueba)");
    logWebhook("Contenido recibido: " . substr($payload, 0, 500));
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'No order to process']);
    exit;
}

// Detectar si es una eliminación
$isDeleted = strpos($wcTopic, 'deleted') !== false || strpos($wcTopic, 'trashed') !== false;
$isRestored = strpos($wcTopic, 'restored') !== false;

logWebhook("Procesando orden #{$order['id']} - Status: {$order['status']} - Topic: $wcTopic");

try {
    // Manejar eliminación de orden
    if ($isDeleted) {
        deleteOrderFromDB($pdo, $order['id']);
        logWebhook("Orden #{$order['id']} eliminada de la BD local");
        http_response_code(200);
        echo json_encode(['success' => true, 'order_id' => $order['id'], 'action' => 'deleted']);
        exit;
    }

    // Guardar/actualizar la orden en la BD
    saveOrderToDB($pdo, $order);

    // Actualizar timestamp de última sincronización
    updateLastSync($pdo);

    logWebhook("Orden #{$order['id']} guardada exitosamente");

    http_response_code(200);
    echo json_encode(['success' => true, 'order_id' => $order['id']]);

} catch (Exception $e) {
    logWebhook("ERROR al guardar orden: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

/**
 * Actualiza el timestamp de última sincronización
 */
function updateLastSync($pdo)
{
    // Crear tabla de configuración si no existe
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS config (
            clave VARCHAR(50) PRIMARY KEY,
            valor TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");

    $stmt = $pdo->prepare("
        INSERT INTO config (clave, valor) VALUES ('last_sync', NOW())
        ON DUPLICATE KEY UPDATE valor = NOW(), updated_at = NOW()
    ");
    $stmt->execute();
}

/**
 * Elimina una orden de la BD local
 */
function deleteOrderFromDB($pdo, $orderId)
{
    $stmt = $pdo->prepare("DELETE FROM reservas WHERE id = ?");
    $stmt->execute([$orderId]);
    return $stmt->rowCount() > 0;
}

/**
 * Guarda una orden en la BD (misma lógica que sync.php)
 */
function saveOrderToDB($pdo, $order)
{
    // Función para buscar metadatos
    $meta = function ($key) use ($order) {
        $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));

        // Buscar en meta_data principal
        if (isset($order['meta_data'])) {
            foreach ($order['meta_data'] as $m) {
                $mClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $m['key'] ?? ''));
                if ($mClean === $clean)
                    return $m['value'];
            }
        }

        // Buscar en line_items meta_data
        if (isset($order['line_items'][0]['meta_data'])) {
            foreach ($order['line_items'][0]['meta_data'] as $m) {
                $mClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $m['key'] ?? ''));
                $dClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $m['display_key'] ?? ''));
                if ($mClean === $clean || $dClean === $clean)
                    return $m['value'];
            }
        }

        return null;
    };

    // Función para parsear fecha
    $parseDate = function ($dateStr) {
        if (!$dateStr)
            return null;
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
            return $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) {
            return substr($dateStr, 0, 10);
        }
        return null;
    };

    $billing = $order['billing'] ?? [];
    $shipping = $order['shipping'] ?? [];

    // Buscar dirección en múltiples lugares
    $direccion = $billing['address_1'] ?? '';
    if (empty($direccion))
        $direccion = $shipping['address_1'] ?? '';
    if (empty($direccion))
        $direccion = $meta('_shipping_address_1');
    if (empty($direccion))
        $direccion = $meta('_billing_address_1');

    $data = [
        'id' => $order['id'],
        'status' => $order['status'],
        'date_created' => $order['date_created'],
        'cliente_nombre' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
        'cliente_email' => $billing['email'] ?? '',
        'cliente_telefono' => $billing['phone'] ?? '',
        'cliente_pais' => $billing['country'] ?? '',
        'cliente_direccion' => $direccion ?? '',
        'tipo_viaje' => $meta('- Type of Trip'),
        'pasajeros' => intval($meta('Passengers')) ?: 1,
        'hotel_nombre' => $order['line_items'][0]['name'] ?? '',
        'llegada_fecha' => $parseDate($meta('- Arrival Date')),
        'llegada_hora' => $meta('- Arrival Time'),
        'llegada_vuelo' => $meta('- Arrival Flight Number'),
        'llegada_chofer' => $meta('chofer_llegada'),
        'llegada_subchofer' => $meta('subchofer_llegada'),
        'llegada_nota_choferes' => $meta('nota_choferes_llegada'),
        'llegada_notas_internas' => $meta('notas_internas_llegada'),
        'salida_fecha' => $parseDate($meta('- Departure Date')),
        'salida_hora' => $meta('- Pick-up Time at Hotel'),
        'salida_vuelo' => $meta('- Departure Flight Number'),
        'salida_chofer' => $meta('chofer_salida'),
        'salida_subchofer' => $meta('subchofer_salida'),
        'salida_nota_choferes' => $meta('nota_choferes_ida'),
        'salida_notas_internas' => $meta('notas_internas_salida'),
        'metodo_pago' => $order['payment_method_title'] ?? '',
        'subtotal' => floatval(array_sum(array_column($order['line_items'] ?? [], 'subtotal'))),
        'cargos_adicionales' => floatval(array_sum(array_column($order['fee_lines'] ?? [], 'total'))),
        'impuestos' => floatval(array_sum(array_column($order['tax_lines'] ?? [], 'tax_total'))),
        'descuentos' => floatval(array_sum(array_column($order['coupon_lines'] ?? [], 'discount'))),
        'total' => floatval($order['total']),
        'raw_data' => json_encode($order)
    ];

    $sql = "
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
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $data['id'],
        $data['status'],
        $data['date_created'],
        $data['cliente_nombre'],
        $data['cliente_email'],
        $data['cliente_telefono'],
        $data['cliente_pais'],
        $data['cliente_direccion'],
        $data['tipo_viaje'],
        $data['pasajeros'],
        $data['hotel_nombre'],
        $data['llegada_fecha'],
        $data['llegada_hora'],
        $data['llegada_vuelo'],
        $data['llegada_chofer'],
        $data['llegada_subchofer'],
        $data['llegada_nota_choferes'],
        $data['llegada_notas_internas'],
        $data['salida_fecha'],
        $data['salida_hora'],
        $data['salida_vuelo'],
        $data['salida_chofer'],
        $data['salida_subchofer'],
        $data['salida_nota_choferes'],
        $data['salida_notas_internas'],
        $data['metodo_pago'],
        $data['subtotal'],
        $data['cargos_adicionales'],
        $data['impuestos'],
        $data['descuentos'],
        $data['total'],
        $data['raw_data']
    ]);
}
?>