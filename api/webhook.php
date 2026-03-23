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

    if (!empty($signature) && !hash_equals($expectedSignature, $signature)) {
        logWebhook("ADVERTENCIA: Firma no coincide pero continuando...");
    }
}

// Decodificar payload
$order = json_decode($payload, true);

// Si no hay orden válida, responder OK sin procesar
if (!$order || !isset($order['id'])) {
    logWebhook("Payload sin orden válida - respondiendo OK (posible ping o prueba)");
    logWebhook("Contenido recibido: " . substr($payload, 0, 500));
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'No order to process']);
    exit;
}

// Detectar si es una eliminación
$isDeleted = strpos($wcTopic, 'deleted') !== false || strpos($wcTopic, 'trashed') !== false;

logWebhook("Procesando orden #{$order['id']} - Status: {$order['status']} - Topic: $wcTopic");

try {
    if ($isDeleted) {
        deleteOrderFromDB($pdo, $order['id']);
        logWebhook("Orden #{$order['id']} eliminada de la BD local");
        http_response_code(200);
        echo json_encode(['success' => true, 'order_id' => $order['id'], 'action' => 'deleted']);
        exit;
    }

    saveOrderToDB($pdo, $order);
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
    $stmt = $pdo->prepare("DELETE FROM viajes WHERE reserva_id = ?");
    $stmt->execute([$orderId]);

    $stmt = $pdo->prepare("DELETE FROM reservas WHERE id = ?");
    $stmt->execute([$orderId]);

    $stmt = $pdo->prepare("DELETE FROM cotizaciones WHERE id = ?");
    $stmt->execute([$orderId]);

    return true;
}

/**
 * Detecta si un line_item es un viaje interno (One Way Shuttle / Hotel-Hotel)
 * Usa la meta key específica del plugin Y el nombre del producto como fallback
 */
function esLineItemInterno($item)
{
    // Detección por meta key del plugin (método primario)
    foreach (($item['meta_data'] ?? []) as $m) {
        if (($m['key'] ?? '') === 'wccpf_uqmQV1WN1jeT') {
            return true;
        }
    }

    // Detección por nombre del producto (fallback)
    $nombre = strtolower(trim($item['name'] ?? ''));
    $nombresInternos = ['one way shuttle', 'hotel-hotel', 'hotel to hotel', 'hotel – hotel', 'hotel - hotel'];
    foreach ($nombresInternos as $n) {
        if (strpos($nombre, $n) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * Guarda una orden en la BD (soporta múltiples viajes por orden)
 */
function saveOrderToDB($pdo, $order)
{
    // Helper para buscar meta en array
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

    // Parser de fechas
    $parseDate = function ($dateStr) {
        if (!$dateStr)
            return null;
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
            return $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $dateStr, $matches)) {
            return $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $dateStr)) {
            return substr($dateStr, 0, 10);
        }
        return null;
    };

    // Parser de hora
    $parseTime = function ($timeStr) {
        if (!$timeStr)
            return null;
        $timeStr = trim($timeStr);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $timeStr, $matches)) {
            return str_pad($matches[1], 2, '0', STR_PAD_LEFT) . ':' . $matches[2] . ':00';
        }
        return null;
    };

    $billing = $order['billing'] ?? [];
    $shipping = $order['shipping'] ?? [];
    $lineItems = $order['line_items'] ?? [];

    $direccion = $billing['address_1'] ?? '';
    if (empty($direccion))
        $direccion = $shipping['address_1'] ?? '';

    $clienteNombre = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));
    $subtotal = floatval(array_sum(array_column($lineItems, 'subtotal')));

    // =========================================================================
    // DETECCIÓN: ¿Tiene algún line_item de viaje interno (One Way Shuttle)?
    // Si tiene AL MENOS UNO → toda la orden es cotización
    // =========================================================================
    $esCotizacion = false;
    foreach ($lineItems as $item) {
        if (esLineItemInterno($item)) {
            $esCotizacion = true;
            break;
        }
    }

    logWebhook("Orden #{$order['id']} - esCotizacion: " . ($esCotizacion ? 'SI' : 'NO'));

    // =========================================================================
    // 1. Guardar en tabla reservas (SIEMPRE, con flag es_cotizacion correcto)
    // =========================================================================
    $sqlReserva = "
        INSERT INTO reservas (id, status, date_created, cliente_nombre, cliente_email, cliente_telefono,
            cliente_pais, cliente_direccion, metodo_pago, subtotal, cargos_adicionales, impuestos,
            descuentos, total, raw_data, nota_cliente, privacy_show_email, privacy_show_phone, es_cotizacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            cliente_nombre = VALUES(cliente_nombre),
            cliente_email = VALUES(cliente_email),
            cliente_telefono = VALUES(cliente_telefono),
            cliente_pais = VALUES(cliente_pais),
            cliente_direccion = VALUES(cliente_direccion),
            metodo_pago = VALUES(metodo_pago),
            subtotal = VALUES(subtotal),
            cargos_adicionales = VALUES(cargos_adicionales),
            impuestos = VALUES(impuestos),
            descuentos = VALUES(descuentos),
            total = VALUES(total),
            raw_data = VALUES(raw_data),
            nota_cliente = VALUES(nota_cliente),
            es_cotizacion = VALUES(es_cotizacion)
    ";

    $stmt = $pdo->prepare($sqlReserva);
    $stmt->execute([
        $order['id'],
        $order['status'],
        $order['date_created'],
        $clienteNombre,
        $billing['email'] ?? '',
        $billing['phone'] ?? '',
        $billing['country'] ?? '',
        $direccion,
        $order['payment_method_title'] ?? '',
        $subtotal,
        floatval(array_sum(array_column($order['fee_lines'] ?? [], 'total'))),
        floatval(array_sum(array_column($order['tax_lines'] ?? [], 'tax_total'))),
        floatval(array_sum(array_column($order['coupon_lines'] ?? [], 'discount'))),
        floatval($order['total']),
        json_encode($order),
        $order['customer_note'] ?? '',
        '0',
        '0',
        $esCotizacion ? 1 : 0
    ]);

    // =========================================================================
    // 2. Si es cotización, también guardar en tabla cotizaciones
    // =========================================================================
    if ($esCotizacion) {
        // Buscar datos del primer line_item interno para el resumen
        $origen = '';
        $hotelNombre = '';
        $fechaViaje = null;
        $horaViaje = null;
        $pasajeros = 1;

        foreach ($lineItems as $item) {
            if (!esLineItemInterno($item))
                continue;
            $itemMeta = $item['meta_data'] ?? [];
            foreach ($itemMeta as $m) {
                $key = $m['key'] ?? '';
                if ($key === 'wccpf_uqmQV1WN1jeT')
                    $origen = $m['value'] ?? '';
                if ($key === 'wccpf_1leEY9NyPBq8')
                    $hotelNombre = $m['value'] ?? '';
                if ($key === 'wccpf_GKaNQcnBtnRd')
                    $fechaViaje = $m['value'] ?? null;
                if ($key === 'wccpf_rltyePZt3ZCD')
                    $horaViaje = $m['value'] ?? null;
                if ($key === 'wccpf_MikTE0O9596X')
                    $pasajeros = intval($m['value'] ?? 1);
            }
            if ($origen !== '')
                break; // usar el primer viaje interno encontrado
        }

        // Si no encontramos por meta keys, usar nombre del producto como destino
        if (empty($hotelNombre)) {
            foreach ($lineItems as $item) {
                if (esLineItemInterno($item)) {
                    $hotelNombre = $item['name'] ?? 'No especificado';
                    break;
                }
            }
        }

        $sqlCotizacion = "
            INSERT INTO cotizaciones (
                id, status, date_created, cliente_nombre, cliente_email, cliente_telefono,
                cliente_pais, cliente_direccion, pasajeros, origen, hotel_nombre,
                fecha_viaje, hora_viaje, metodo_pago, subtotal, cargos_adicionales,
                impuestos, descuentos, total, raw_data,
                privacy_show_email, privacy_show_phone, privacy_show_financiero, status_viaje
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                status       = VALUES(status),
                total        = VALUES(total),
                raw_data     = VALUES(raw_data),
                status_viaje = VALUES(status_viaje)
        ";

        $stmtCot = $pdo->prepare($sqlCotizacion);
        $stmtCot->execute([
            $order['id'],
            $order['status'],
            $order['date_created'],
            $clienteNombre,
            $billing['email'] ?? '',
            $billing['phone'] ?? '',
            $billing['country'] ?? '',
            $direccion,
            $pasajeros,
            $origen,
            $hotelNombre ?: 'No especificado',
            $fechaViaje,
            $horaViaje,
            $order['payment_method_title'] ?? '',
            $subtotal,
            floatval(array_sum(array_column($order['fee_lines'] ?? [], 'total'))),
            floatval(array_sum(array_column($order['tax_lines'] ?? [], 'tax_total'))),
            floatval(array_sum(array_column($order['coupon_lines'] ?? [], 'discount'))),
            floatval($order['total']),
            json_encode($order),
            '0',
            '0',
            '1',
            $order['status']
        ]);

        logWebhook("Orden #{$order['id']} guardada en tabla cotizaciones (origen: '$origen', destino: '$hotelNombre')");
    }

    // =========================================================================
    // 3. Crear / actualizar viajes para TODOS los line_items
    //    (llegada, salida E internos — sin importar si es cotización o no)
    // =========================================================================
    $savedTrips = [];

    foreach ($lineItems as $itemIndex => $item) {
        $itemMeta = $item['meta_data'] ?? [];
        $destinoName = $item['name'] ?? 'Transfer';

        if (esLineItemInterno($item)) {
            // ── VIAJE INTERNO ─────────────────────────────────────────────
            $pickupLocation = $findMeta($itemMeta, 'wccpf_uqmQV1WN1jeT') ?? '';
            $dropoffLocation = $findMeta($itemMeta, 'wccpf_1leEY9NyPBq8') ?? '';
            $pickupDate = $parseDate($findMeta($itemMeta, 'wccpf_GKaNQcnBtnRd'));
            $pickupTime = $parseTime($findMeta($itemMeta, 'wccpf_rltyePZt3ZCD'));
            $paxStr = $findMeta($itemMeta, 'wccpf_MikTE0O9596X') ?? '1';
            $pax = intval(preg_replace('/[^0-9]/', '', $paxStr)) ?: 1;

            // Si no hay fecha, igual guardamos el viaje con fecha NULL para no perder el registro
            $rutaCompleta = trim($pickupLocation) !== '' || trim($dropoffLocation) !== ''
                ? $pickupLocation . ' → ' . $dropoffLocation
                : $destinoName;

            saveTrip($pdo, [
                'reserva_id' => $order['id'],
                'item_index' => $itemIndex,
                'tipo' => 'interno',
                'fecha' => $pickupDate,
                'hora' => $pickupTime,
                'vuelo' => null,
                'pax' => $pax,
                'hotel' => '',
                'destino' => $rutaCompleta,
            ]);
            $savedTrips[] = ['item_index' => $itemIndex, 'tipo' => 'interno'];

            logWebhook("Viaje interno guardado: idx=$itemIndex, fecha=$pickupDate, ruta=$rutaCompleta");

        } else {
            // ── VIAJE NORMAL (llegada / salida) ───────────────────────────
            $tripType = $findMeta($itemMeta, '- Type of Trip') ?? $findMeta($itemMeta, 'Type of Trip') ?? '';
            $tripTypeLower = strtolower($tripType);

            $paxStr = $findMeta($itemMeta, 'Passengers') ?? '1';
            $pax = intval(preg_replace('/[^0-9]/', '', $paxStr)) ?: 1;

            $hasArrival = strpos($tripTypeLower, 'hotel') !== false
                || strpos($tripTypeLower, 'roundtrip') !== false
                || strpos($tripTypeLower, 'round trip') !== false;
            $hasDeparture = strpos($tripTypeLower, 'airport') !== false
                || strpos($tripTypeLower, 'roundtrip') !== false
                || strpos($tripTypeLower, 'round trip') !== false;

            $arrivalDate = $parseDate($findMeta($itemMeta, '- Arrival Date'));
            $departureDate = $parseDate($findMeta($itemMeta, '- Departure Date'));

            if ($arrivalDate && !$hasArrival)
                $hasArrival = true;
            if ($departureDate && !$hasDeparture)
                $hasDeparture = true;

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
                    'hotel' => '',
                    'destino' => $destinoName,
                ]);
                $savedTrips[] = ['item_index' => $itemIndex, 'tipo' => 'llegada'];
            }

            if ($hasDeparture && $departureDate) {
                $departureTime = $parseTime($findMeta($itemMeta, '- Pick-up Time at Hotel') ?? $findMeta($itemMeta, 'Pick up Time'));
                $departureFlight = $findMeta($itemMeta, '- Departure Flight Number') ?? $findMeta($itemMeta, 'Departure Flight');

                // Salidas en roundtrip usan item_index + 1000 para diferenciarlas
                $tripItemIndex = ($hasArrival && $arrivalDate) ? $itemIndex + 1000 : $itemIndex;

                saveTrip($pdo, [
                    'reserva_id' => $order['id'],
                    'item_index' => $tripItemIndex,
                    'tipo' => 'salida',
                    'fecha' => $departureDate,
                    'hora' => $departureTime,
                    'vuelo' => $departureFlight,
                    'pax' => $pax,
                    'hotel' => '',
                    'destino' => $destinoName,
                ]);
                $savedTrips[] = ['item_index' => $tripItemIndex, 'tipo' => 'salida'];
            }
        }
    }

    // =========================================================================
    // 4. Limpiar viajes huérfanos (índices que ya no existen en la orden)
    // =========================================================================
    if (!empty($savedTrips)) {
        $conditions = [];
        $cleanParams = [$order['id']];
        foreach ($savedTrips as $trip) {
            $conditions[] = "(item_index = ? AND tipo = ?)";
            $cleanParams[] = $trip['item_index'];
            $cleanParams[] = $trip['tipo'];
        }
        $keepCondition = implode(' OR ', $conditions);
        $deleteStmt = $pdo->prepare(
            "DELETE FROM viajes WHERE reserva_id = ? AND NOT ($keepCondition)"
        );
        $deleteStmt->execute($cleanParams);

        $deletedCount = $deleteStmt->rowCount();
        if ($deletedCount > 0) {
            logWebhook("Limpiados $deletedCount viaje(s) huérfano(s) de orden #{$order['id']}");
        }
    }
}

/**
 * Guarda un viaje individual en la tabla viajes
 */
function saveTrip($pdo, $data)
{
    $checkStmt = $pdo->prepare("SELECT id FROM viajes WHERE reserva_id = ? AND item_index = ? LIMIT 1");
    $checkStmt->execute([$data['reserva_id'], $data['item_index']]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // UPDATE: preservar chofer/notas/status editados manualmente, actualizar datos de viaje
        $updateSql = "
            UPDATE viajes SET
                tipo = ?, pax = ?, destino = ?
            WHERE id = ?
        ";
        $pdo->prepare($updateSql)->execute([
            $data['tipo'],
            $data['pax'],
            $data['destino'] ?? $data['hotel'],
            $existing['id']
        ]);
    } else {
        // INSERT: nuevo viaje
        $insertSql = "
            INSERT INTO viajes (reserva_id, item_index, tipo, fecha, hora, vuelo, pax, hotel, destino)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $pdo->prepare($insertSql)->execute([
            $data['reserva_id'],
            $data['item_index'],
            $data['tipo'],
            $data['fecha'],
            $data['hora'],
            $data['vuelo'],
            $data['pax'],
            $data['hotel'] ?? '',
            $data['destino'] ?? $data['hotel'] ?? '',
        ]);
    }
}