<?php
/**
 * Script de sincronización de reservas desde WooCommerce a MySQL
 * Uso: api/sync.php
 */

// Si se incluye desde sync_by_id.php, solo exponer funciones
if (defined('SYNC_BY_ID')) {
    goto sync_helpers;
}

session_start();
require_once '../config/db.php';
require_once '../config/env.php';
require_once '../config/session_helper.php';

header('Content-Type: application/json');

// Validar timeout de sesión por inactividad
if (!validateAndRefreshSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesión expirada']);
    exit;
}

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// Configuración de WooCommerce
$wooUrl = env('WOO_SITE_URL');
$consumerKey = env('WOO_CONSUMER_KEY');
$consumerSecret = env('WOO_CONSUMER_SECRET');

if (!$wooUrl || !$consumerKey || !$consumerSecret) {
    http_response_code(500);
    echo json_encode(['error' => 'Credenciales de WooCommerce no configuradas']);
    exit;
}

$page = 1;
$totalSynced = 0;
$hasMore = true;

// Fecha de inicio: 7 meses atrás
$startDate = date('Y-m-d\TH:i:s', strtotime('-7 months'));

while ($hasMore) {
    $apiUrl = "$wooUrl/wp-json/wc/v3/orders?per_page=100&page=$page&after=$startDate&orderby=date&order=desc";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$consumerKey:$consumerSecret");
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        echo json_encode(['error' => "Error API WooCommerce: $httpCode"]);
        exit;
    }

    $orders = json_decode($response, true);

    if (!is_array($orders) || count($orders) === 0) {
        $hasMore = false;
        break;
    }

    foreach ($orders as $order) {
        saveOrderToDB($pdo, $order);
        $totalSynced++;
    }

    if (count($orders) < 100) {
        $hasMore = false;
    } else {
        $page++;
        if ($page > 50)
            $hasMore = false;
    }
}

echo json_encode([
    'success' => true,
    'message' => "Sincronización completada: $totalSynced reservas importadas",
    'total' => $totalSynced
]);

// =========================================================================
// HELPERS
// =========================================================================
sync_helpers:

/**
 * Detecta si un line_item es un viaje interno (One Way Shuttle / Hotel-Hotel)
 * Usa la meta key específica del plugin Y el nombre del producto como fallback
 */
function esLineItemInterno($item)
{
    // Detección por meta key del formulario (método primario)
    foreach (($item['meta_data'] ?? []) as $m) {
        $k = $m['key'] ?? '';
        if ($k === '- Pick-up Location' || $k === 'wccpf_uqmQV1WN1jeT') {
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

function saveOrderToDB($pdo, $order)
{
    // Helper para buscar meta en array
    $checkMeta = function ($key) use ($order) {
        if (isset($order['meta_data'])) {
            foreach ($order['meta_data'] as $m) {
                if (($m['key'] ?? '') === $key)
                    return $m['value'];
            }
        }
        if (isset($order['line_items'][0]['meta_data'])) {
            foreach ($order['line_items'][0]['meta_data'] as $m) {
                if (($m['key'] ?? '') === $key)
                    return $m['value'];
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
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2})$/', $dateStr, $matches)) {
            $year = '20' . $matches[3];
            return $year . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $dateStr, $matches)) {
            return $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{2})$/', $dateStr, $matches)) {
            $year = '20' . $matches[3];
            return $year . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
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

    // Helper para buscar meta en item específico
    $findItemMeta = function ($metaArray, $key) {
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

    // =========================================================================
    // 1. Guardar en tabla reservas (SIEMPRE)
    // =========================================================================
    $sql = "
        INSERT INTO reservas (id, status, date_created, cliente_nombre, cliente_email, cliente_telefono,
            cliente_pais, cliente_direccion, tipo_viaje, pasajeros, hotel_nombre,
            llegada_fecha, llegada_hora, llegada_vuelo, llegada_chofer, llegada_subchofer,
            llegada_nota_choferes, llegada_notas_internas,
            salida_fecha, salida_hora, salida_vuelo, salida_chofer, salida_subchofer,
            salida_nota_choferes, salida_notas_internas,
            metodo_pago, subtotal, cargos_adicionales, impuestos, descuentos, total,
            raw_data, nota_cliente, privacy_show_email, privacy_show_phone, es_cotizacion)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            status             = VALUES(status),
            cliente_nombre     = VALUES(cliente_nombre),
            cliente_email      = VALUES(cliente_email),
            cliente_telefono   = VALUES(cliente_telefono),
            cliente_pais       = VALUES(cliente_pais),
            cliente_direccion  = VALUES(cliente_direccion),
            tipo_viaje         = VALUES(tipo_viaje),
            pasajeros          = VALUES(pasajeros),
            hotel_nombre       = VALUES(hotel_nombre),
            llegada_fecha      = VALUES(llegada_fecha),
            llegada_hora       = VALUES(llegada_hora),
            llegada_vuelo      = VALUES(llegada_vuelo),
            llegada_chofer     = VALUES(llegada_chofer),
            llegada_subchofer  = VALUES(llegada_subchofer),
            llegada_nota_choferes  = VALUES(llegada_nota_choferes),
            llegada_notas_internas = VALUES(llegada_notas_internas),
            salida_fecha       = VALUES(salida_fecha),
            salida_hora        = VALUES(salida_hora),
            salida_vuelo       = VALUES(salida_vuelo),
            salida_chofer      = VALUES(salida_chofer),
            salida_subchofer   = VALUES(salida_subchofer),
            salida_nota_choferes  = VALUES(salida_nota_choferes),
            salida_notas_internas = VALUES(salida_notas_internas),
            metodo_pago        = VALUES(metodo_pago),
            subtotal           = VALUES(subtotal),
            cargos_adicionales = VALUES(cargos_adicionales),
            impuestos          = VALUES(impuestos),
            descuentos         = VALUES(descuentos),
            total              = VALUES(total),
            raw_data           = VALUES(raw_data),
            nota_cliente       = VALUES(nota_cliente),
            es_cotizacion      = VALUES(es_cotizacion)
    ";

    // Para reservas normales, extraer datos de llegada/salida del primer line_item
    // (para compatibilidad con el campo legacy de la tabla reservas)
    $metaLegacy = function ($key) use ($order) {
        $clean = strtolower(preg_replace('/[^a-z0-9]/i', '', $key));
        if (isset($order['meta_data'])) {
            foreach ($order['meta_data'] as $m) {
                $mClean = strtolower(preg_replace('/[^a-z0-9]/i', '', $m['key'] ?? ''));
                if ($mClean === $clean)
                    return $m['value'];
            }
        }
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

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $order['id'],
        $order['status'],
        $order['date_created'],
        $clienteNombre,
        $billing['email'] ?? '',
        $billing['phone'] ?? '',
        $billing['country'] ?? '',
        $direccion,
        $metaLegacy('- Type of Trip'),
        intval($metaLegacy('Passengers')) ?: 1,
        $lineItems[0]['name'] ?? '',
        $parseDate($metaLegacy('- Arrival Date')),
        $metaLegacy('- Arrival Time'),
        $metaLegacy('- Arrival Flight Number'),
        $metaLegacy('chofer_llegada'),
        $metaLegacy('subchofer_llegada'),
        $metaLegacy('nota_choferes_llegada'),
        $metaLegacy('notas_internas_llegada'),
        $parseDate($metaLegacy('- Departure Date')),
        $metaLegacy('- Pick-up Time at Hotel'),
        $metaLegacy('- Departure Flight Number'),
        $metaLegacy('chofer_salida'),
        $metaLegacy('subchofer_salida'),
        $metaLegacy('nota_choferes_ida'),
        $metaLegacy('notas_internas_salida'),
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
        $esCotizacion ? 1 : 0,
    ]);

    // =========================================================================
    // 2. Si es cotización, también guardar en tabla cotizaciones
    // =========================================================================
    if ($esCotizacion) {
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
                $val = $m['value'] ?? $m['display_value'] ?? '';
                if (is_array($val)) $val = implode(', ', $val);
                $val = trim((string)$val);
                if (($key === '- Pick-up Location' || $key === 'wccpf_uqmQV1WN1jeT') && $val !== '')
                    $origen = $val;
                if (($key === '- Drop-off Location' || $key === 'wccpf_1leEY9NyPBq8') && $val !== '')
                    $hotelNombre = $val;
                if (($key === '- Pick-up Date' || $key === 'wccpf_GKaNQcnBtnRd') && $val !== '')
                    $fechaViaje = $val;
                if (($key === '- Pick-up Time' || $key === 'wccpf_rltyePZt3ZCD') && $val !== '')
                    $horaViaje = $val;
                if (($key === '- Passengers' || $key === 'wccpf_MikTE0O9596X') && $val !== '')
                    $pasajeros = intval($val) ?: 1;
            }
            if ($origen !== '')
                break;
        }

        if (empty($hotelNombre)) {
            foreach ($lineItems as $item) {
                if (esLineItemInterno($item)) {
                    $hotelNombre = $item['name'] ?? 'No especificado';
                    break;
                }
            }
        }

        $sqlCot = "
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

        try {
            $stmt = $pdo->prepare($sqlCot);
            $stmt->execute([
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
        } catch (PDOException $e) {
            // Error silencioso para no romper el sync masivo
        }
    }

    // =========================================================================
    // 3. Crear viajes para TODOS los line_items
    //    (llegada, salida E internos — sin importar si es cotización o no)
    // =========================================================================
    $savedTrips = [];

    foreach ($lineItems as $itemIndex => $item) {
        $itemMeta = $item['meta_data'] ?? [];
        $hotelName = $item['name'] ?? 'Transfer';

        if (esLineItemInterno($item)) {
            // ── VIAJE INTERNO ─────────────────────────────────────────────
            // Extraer valores con fallback a display_value
            $getMetaVal = function ($metaArr, $targetKey, $altKey = null) {
                foreach ($metaArr as $m) {
                    $k = $m['key'] ?? '';
                    if ($k === $targetKey || ($altKey && $k === $altKey)) {
                        $val = $m['value'] ?? $m['display_value'] ?? '';
                        if (is_array($val)) $val = implode(', ', $val);
                        return trim((string)$val);
                    }
                }
                return '';
            };

            $pickupLocation = $getMetaVal($itemMeta, '- Pick-up Location', 'wccpf_uqmQV1WN1jeT');
            $dropoffLocation = $getMetaVal($itemMeta, '- Drop-off Location', 'wccpf_1leEY9NyPBq8');
            $pickupDate = $parseDate($getMetaVal($itemMeta, '- Pick-up Date', 'wccpf_GKaNQcnBtnRd'));
            $pickupTime = $parseTime($getMetaVal($itemMeta, '- Pick-up Time', 'wccpf_rltyePZt3ZCD'));
            $paxStr = $getMetaVal($itemMeta, '- Passengers', 'wccpf_MikTE0O9596X') ?: '1';
            $pax = intval(preg_replace('/[^0-9]/', '', $paxStr)) ?: 1;

            $rutaCompleta = ($pickupLocation !== '' || $dropoffLocation !== '')
                ? $pickupLocation . ' → ' . $dropoffLocation
                : $hotelName;

            saveTripSync($pdo, [
                'reserva_id' => $order['id'],
                'item_index' => $itemIndex,
                'tipo' => 'interno',
                'fecha' => $pickupDate,
                'hora' => $pickupTime,
                'vuelo' => null,
                'pax' => $pax,
                'hotel' => $rutaCompleta,
                'destino' => $rutaCompleta,
            ]);
            $savedTrips[] = ['item_index' => $itemIndex, 'tipo' => 'interno'];

        } else {
            // ── VIAJE NORMAL (llegada / salida) ───────────────────────────
            $tripType = $findItemMeta($itemMeta, '- Type of Trip') ?? $findItemMeta($itemMeta, 'Type of Trip') ?? '';
            $tripTypeLower = strtolower($tripType);

            $paxStr = $findItemMeta($itemMeta, 'Passengers') ?? '1';
            $pax = intval(preg_replace('/[^0-9]/', '', $paxStr)) ?: 1;

            $hasArrival = strpos($tripTypeLower, 'hotel') !== false
                || strpos($tripTypeLower, 'roundtrip') !== false
                || strpos($tripTypeLower, 'round trip') !== false;
            $hasDeparture = strpos($tripTypeLower, 'airport') !== false
                || strpos($tripTypeLower, 'roundtrip') !== false
                || strpos($tripTypeLower, 'round trip') !== false;

            $arrivalDate = $parseDate($findItemMeta($itemMeta, '- Arrival Date'));
            $departureDate = $parseDate($findItemMeta($itemMeta, '- Departure Date'));

            if ($arrivalDate && !$hasArrival)
                $hasArrival = true;
            if ($departureDate && !$hasDeparture)
                $hasDeparture = true;

            if ($hasArrival && $arrivalDate) {
                $arrivalTime = $parseTime($findItemMeta($itemMeta, '- Arrival Time') ?? $findItemMeta($itemMeta, 'Arrival Time'));
                $arrivalFlight = $findItemMeta($itemMeta, '- Arrival Flight Number') ?? $findItemMeta($itemMeta, 'Arrival Flight');

                saveTripSync($pdo, [
                    'reserva_id' => $order['id'],
                    'item_index' => $itemIndex,
                    'tipo' => 'llegada',
                    'fecha' => $arrivalDate,
                    'hora' => $arrivalTime,
                    'vuelo' => $arrivalFlight,
                    'pax' => $pax,
                    'hotel' => null,
                ]);
                $savedTrips[] = ['item_index' => $itemIndex, 'tipo' => 'llegada'];
            }

            if ($hasDeparture && $departureDate) {
                $departureTime = $parseTime($findItemMeta($itemMeta, '- Pick-up Time at Hotel') ?? $findItemMeta($itemMeta, 'Pick up Time'));
                $departureFlight = $findItemMeta($itemMeta, '- Departure Flight Number') ?? $findItemMeta($itemMeta, 'Departure Flight');

                $tripItemIndex = ($hasArrival && $arrivalDate) ? $itemIndex + 1000 : $itemIndex;

                saveTripSync($pdo, [
                    'reserva_id' => $order['id'],
                    'item_index' => $tripItemIndex,
                    'tipo' => 'salida',
                    'fecha' => $departureDate,
                    'hora' => $departureTime,
                    'vuelo' => $departureFlight,
                    'pax' => $pax,
                    'hotel' => null,
                ]);
                $savedTrips[] = ['item_index' => $tripItemIndex, 'tipo' => 'salida'];
            }
        }
    }

    // Limpiar viajes huérfanos
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
    }
}

/**
 * Guarda un viaje individual en la tabla viajes (usado por sync)
 */
function saveTripSync($pdo, $data)
{
    $checkStmt = $pdo->prepare("SELECT id FROM viajes WHERE reserva_id = ? AND item_index = ? LIMIT 1");
    $checkStmt->execute([$data['reserva_id'], $data['item_index']]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    $destino = $data['destino'] ?? null;

    if ($existing) {
        // UPDATE: actualizar solo campos de sync, preservar chofer/notas/status editados manualmente
        $updateSql = "
            UPDATE viajes SET
                tipo = ?, fecha = ?, hora = ?, vuelo = ?, pax = ?, hotel = ?, destino = ?
            WHERE id = ?
        ";
        $pdo->prepare($updateSql)->execute([
            $data['tipo'],
            $data['fecha'],
            $data['hora'],
            $data['vuelo'],
            $data['pax'],
            $data['hotel'],
            $destino,
            $existing['id']
        ]);
    } else {
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
            $data['hotel'],
            $destino,
        ]);
    }
}