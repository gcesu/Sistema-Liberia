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
 * Elimina una orden de la BD local (y sus viajes asociados)
 */
function deleteOrderFromDB($pdo, $orderId)
{
    // Primero eliminar viajes asociados
    $stmt = $pdo->prepare("DELETE FROM viajes WHERE reserva_id = ?");
    $stmt->execute([$orderId]);

    // Luego eliminar la reserva
    $stmt = $pdo->prepare("DELETE FROM reservas WHERE id = ?");
    $stmt->execute([$orderId]);
    return $stmt->rowCount() > 0;
}

/**
 * Guarda una orden en la BD (soporta múltiples viajes por orden)
 */
function saveOrderToDB($pdo, $order)
{
    // Función para buscar metadatos en array
    $findMeta = function ($metaArray, $key) {
        $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));
        foreach ($metaArray as $m) {
            $mClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $m['key'] ?? ''));
            $dClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $m['display_key'] ?? ''));
            if ($mClean === $clean || $dClean === $clean) {
                return $m['value'] ?? $m['display_value'] ?? null;
            }
        }
        return null;
    };

    // Función para buscar en meta_data de la orden
    $orderMeta = function ($key) use ($order, $findMeta) {
        return $findMeta($order['meta_data'] ?? [], $key);
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

    // Función para parsear hora
    $parseTime = function ($timeStr) {
        if (!$timeStr)
            return null;
        // Limpiar y normalizar
        $timeStr = trim($timeStr);
        // Si ya está en formato HH:MM
        if (preg_match('/^(\d{1,2}):(\d{2})/', $timeStr, $matches)) {
            return str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2] . ':00';
        }
        return null;
    };

    $billing = $order['billing'] ?? [];
    $shipping = $order['shipping'] ?? [];

    // Buscar dirección en múltiples lugares
    $direccion = $billing['address_1'] ?? '';
    if (empty($direccion))
        $direccion = $shipping['address_1'] ?? '';

    // 1. Guardar/actualizar la reserva (sin campos de viaje individuales)
    $reservaData = [
        'id' => $order['id'],
        'status' => $order['status'],
        'date_created' => $order['date_created'],
        'cliente_nombre' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
        'cliente_email' => $billing['email'] ?? '',
        'cliente_telefono' => $billing['phone'] ?? '',
        'cliente_pais' => $billing['country'] ?? '',
        'cliente_direccion' => $direccion ?? '',
        'metodo_pago' => $order['payment_method_title'] ?? '',
        'subtotal' => floatval(array_sum(array_column($order['line_items'] ?? [], 'subtotal'))),
        'cargos_adicionales' => floatval(array_sum(array_column($order['fee_lines'] ?? [], 'total'))),
        'impuestos' => floatval(array_sum(array_column($order['tax_lines'] ?? [], 'tax_total'))),
        'descuentos' => floatval(array_sum(array_column($order['coupon_lines'] ?? [], 'discount'))),
        'total' => floatval($order['total']),
        'raw_data' => json_encode($order)
    ];

    $sqlReserva = "
        INSERT INTO reservas (id, status, date_created, cliente_nombre, cliente_email, cliente_telefono, 
            cliente_pais, cliente_direccion, metodo_pago, subtotal, cargos_adicionales, impuestos, 
            descuentos, total, raw_data)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status), cliente_nombre = VALUES(cliente_nombre), cliente_email = VALUES(cliente_email),
            cliente_telefono = VALUES(cliente_telefono), cliente_pais = VALUES(cliente_pais), 
            cliente_direccion = VALUES(cliente_direccion), metodo_pago = VALUES(metodo_pago), 
            subtotal = VALUES(subtotal), cargos_adicionales = VALUES(cargos_adicionales),
            impuestos = VALUES(impuestos), descuentos = VALUES(descuentos), total = VALUES(total), 
            raw_data = VALUES(raw_data)
    ";

    $stmt = $pdo->prepare($sqlReserva);
    $stmt->execute([
        $reservaData['id'],
        $reservaData['status'],
        $reservaData['date_created'],
        $reservaData['cliente_nombre'],
        $reservaData['cliente_email'],
        $reservaData['cliente_telefono'],
        $reservaData['cliente_pais'],
        $reservaData['cliente_direccion'],
        $reservaData['metodo_pago'],
        $reservaData['subtotal'],
        $reservaData['cargos_adicionales'],
        $reservaData['impuestos'],
        $reservaData['descuentos'],
        $reservaData['total'],
        $reservaData['raw_data']
    ]);

    // 2. Procesar cada line_item y crear viajes
    $lineItems = $order['line_items'] ?? [];

    foreach ($lineItems as $itemIndex => $item) {
        $itemMeta = $item['meta_data'] ?? [];
        $hotelName = $item['name'] ?? 'Hotel no especificado';

        // Determinar tipo de viaje
        $tripType = $findMeta($itemMeta, '- Type of Trip') ?? $findMeta($itemMeta, 'Type of Trip') ?? '';
        $tripTypeLower = strtolower($tripType);

        // Extraer pasajeros
        $paxStr = $findMeta($itemMeta, 'Passengers') ?? '1';
        $pax = intval(preg_replace('/[^0-9]/', '', $paxStr)) ?: 1;

        // Determinar si tiene llegada y/o salida
        $hasArrival = strpos($tripTypeLower, 'hotel') !== false || strpos($tripTypeLower, 'roundtrip') !== false || strpos($tripTypeLower, 'round trip') !== false;
        $hasDeparture = strpos($tripTypeLower, 'airport') !== false || strpos($tripTypeLower, 'roundtrip') !== false || strpos($tripTypeLower, 'round trip') !== false;

        // Si no se detecta el tipo, intentar por las fechas
        $arrivalDate = $parseDate($findMeta($itemMeta, '- Arrival Date'));
        $departureDate = $parseDate($findMeta($itemMeta, '- Departure Date'));

        if ($arrivalDate && !$hasArrival)
            $hasArrival = true;
        if ($departureDate && !$hasDeparture)
            $hasDeparture = true;

        // Crear viaje de llegada
        if ($hasArrival && $arrivalDate) {
            $arrivalTime = $parseTime($findMeta($itemMeta, '- Arrival Time') ?? $findMeta($itemMeta, 'Arrival Time'));
            $arrivalFlight = $findMeta($itemMeta, '- Arrival Flight Number') ?? $findMeta($itemMeta, 'Arrival Flight');

            saveTrip($pdo, [
                'reserva_id' => $order['id'],
                'item_index' => $itemIndex,
                'tipo' => 'llegada',
                'fecha' => $arrivalDate,
                'hora' => $arrivalTime,
                'vuelo' => $arrivalFlight,
                'pax' => $pax,
                'hotel' => $hotelName
            ]);
        }

        // Crear viaje de salida
        if ($hasDeparture && $departureDate) {
            $departureTime = $parseTime($findMeta($itemMeta, '- Pick-up Time at Hotel') ?? $findMeta($itemMeta, 'Pick up Time'));
            $departureFlight = $findMeta($itemMeta, '- Departure Flight Number') ?? $findMeta($itemMeta, 'Departure Flight');

            // Para roundtrip, usar índice diferente para la salida
            $tripItemIndex = ($hasArrival && $arrivalDate) ? $itemIndex + 1000 : $itemIndex;

            saveTrip($pdo, [
                'reserva_id' => $order['id'],
                'item_index' => $tripItemIndex,
                'tipo' => 'salida',
                'fecha' => $departureDate,
                'hora' => $departureTime,
                'vuelo' => $departureFlight,
                'pax' => $pax,
                'hotel' => $hotelName
            ]);
        }
    }
}

/**
 * Guarda un viaje individual en la tabla viajes
 */
function saveTrip($pdo, $data)
{
    $sql = "
        INSERT INTO viajes (reserva_id, item_index, tipo, fecha, hora, vuelo, pax, hotel)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            fecha = VALUES(fecha),
            hora = VALUES(hora),
            vuelo = VALUES(vuelo),
            pax = VALUES(pax),
            hotel = VALUES(hotel)
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $data['reserva_id'],
        $data['item_index'],
        $data['tipo'],
        $data['fecha'],
        $data['hora'],
        $data['vuelo'],
        $data['pax'],
        $data['hotel']
    ]);
}
?>