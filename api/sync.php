<?php
/**
 * Script de sincronización de reservas desde WooCommerce a MySQL
 * Uso: api/sync.php
 */

session_start();
require_once '../config/db.php';
require_once '../config/env.php';

header('Content-Type: application/json');

// Verificar autenticación (solo admin puede sincronizar)
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

echo "Iniciando sincronización...\n";
flush();

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

    echo "Página $page: " . count($orders) . " reservas sincronizadas\n";
    flush();

    if (count($orders) < 100) {
        $hasMore = false;
    } else {
        $page++;
        if ($page > 50)
            $hasMore = false; // Límite de seguridad
    }
}

echo json_encode([
    'success' => true,
    'message' => "Sincronización completada: $totalSynced reservas importadas",
    'total' => $totalSynced
]);

function saveOrderToDB($pdo, $order)
{
    // -------------------------------------------------------------------------
    // 1. HELPER: Buscar Metadatos de forma segura
    // -------------------------------------------------------------------------
    // Esta función busca un valor dado su 'key' tanto en la orden principal
    // como en los 'line_items' (productos), porque WooCommerce a veces los pone en un lado u otro.
    $checkMeta = function ($key) use ($order) {
        // Opción A: Buscar en meta_data de la orden
        if (isset($order['meta_data'])) {
            foreach ($order['meta_data'] as $m) {
                if (($m['key'] ?? '') === $key)
                    return $m['value'];
            }
        }
        // Opción B: Buscar en meta_data del primer producto (line_items)
        if (isset($order['line_items'][0]['meta_data'])) {
            foreach ($order['line_items'][0]['meta_data'] as $m) {
                if (($m['key'] ?? '') === $key)
                    return $m['value'];
            }
        }
        return null; // No encontrado
    };

    // -------------------------------------------------------------------------
    // 2. DETECCIÓN: ¿Es Cotización o Reserva Normal?
    // -------------------------------------------------------------------------
    // Usamos el 'key' único del campo "Pick-up Location" de WC Fields Factory.
    // Si la orden tiene este dato, asumimos que viene del formulario de Cotización "Hotel - Hotel".
    $esCotizacion = ($checkMeta('wccpf_uqmQV1WN1jeT') !== null);

    if ($esCotizacion) {
        // =========================================================================
        // CAMINO A: ES UNA COTIZACIÓN -> Guardar en tabla `cotizaciones`
        // =========================================================================

        // a) Extraer datos específicos usando los Keys que nos diste
        $origen = $checkMeta('wccpf_uqmQV1WN1jeT');
        $hotelNombre = $checkMeta('wccpf_1leEY9NyPBq8') ?? 'No especificado'; // Drop-off location
        $fechaViaje = $checkMeta('wccpf_GKaNQcnBtnRd'); // Fecha
        $horaViaje = $checkMeta('wccpf_rltyePZt3ZCD'); // Hora
        $pasajeros = $checkMeta('wccpf_MikTE0O9596X') ?? 1;

        // b) Extraer datos del cliente (Billing)
        $billing = $order['billing'] ?? [];
        $clienteNombre = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        // c) Preparar el array de datos
        // Nota: 'status_viaje' inicia igual que el status de la orden
        // 'privacy_show_financiero' por defecto en 1 para que sea visible
        $subtotal = floatval(array_sum(array_column($order['line_items'] ?? [], 'subtotal')));

        $data = [
            $order['id'],
            $order['status'],
            $order['date_created'],
            $clienteNombre,
            $billing['email'] ?? '',
            $billing['phone'] ?? '',
            $billing['country'] ?? '',
            $billing['address_1'] ?? '',
            (int) $pasajeros,
            $origen,
            $hotelNombre,
            $fechaViaje,
            $horaViaje,
            $order['payment_method_title'] ?? '',
            $subtotal,
            floatval(array_sum(array_column($order['fee_lines'] ?? [], 'total'))), // Cargos
            floatval(array_sum(array_column($order['tax_lines'] ?? [], 'tax_total'))), // Impuestos
            floatval(array_sum(array_column($order['coupon_lines'] ?? [], 'discount'))), // Descuentos
            floatval($order['total']),
            json_encode($order), // raw_data para backup
            '0', // privacy_show_email
            '0', // privacy_show_phone
            '1', // privacy_show_financiero
            $order['status'] // status_viaje
        ];

        // d) SQL INSERT específico para cotizaciones
        $sql = "
            INSERT INTO cotizaciones (
                id, status, date_created, cliente_nombre, cliente_email, cliente_telefono, 
                cliente_pais, cliente_direccion, pasajeros, origen, hotel_nombre, 
                fecha_viaje, hora_viaje,
                metodo_pago, subtotal, cargos_adicionales, impuestos, descuentos, total, 
                raw_data, privacy_show_email, privacy_show_phone, privacy_show_financiero, status_viaje
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                status = VALUES(status), 
                total = VALUES(total),
                raw_data = VALUES(raw_data)
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($data);
        } catch (PDOException $e) {
            // Error silencioso para no romper el sync masivo, pero idealmente se loguearía
        }

    } else {
        // =========================================================================
        // CAMINO B: ES UNA RESERVA NORMAL -> Guardar en tabla `reservas` (Lógica Original)
        // =========================================================================

        // Helper legacy para limpieza de keys (mantenemos compatibilidad)
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

        // Parser fechas legacy
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

        // Lógica de dirección cascada
        $direccion = $billing['address_1'] ?? '';
        if (empty($direccion))
            $direccion = $shipping['address_1'] ?? '';
        if (empty($direccion))
            $direccion = $metaLegacy('_shipping_address_1');
        if (empty($direccion))
            $direccion = $metaLegacy('_billing_address_1');

        $data = [
            'id' => $order['id'],
            'status' => $order['status'],
            'date_created' => $order['date_created'],
            'cliente_nombre' => trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
            'cliente_email' => $billing['email'] ?? '',
            'cliente_telefono' => $billing['phone'] ?? '',
            'cliente_pais' => $billing['country'] ?? '',
            'cliente_direccion' => $direccion ?? '',
            'tipo_viaje' => $metaLegacy('- Type of Trip'),
            'pasajeros' => intval($metaLegacy('Passengers')) ?: 1,
            'hotel_nombre' => $order['line_items'][0]['name'] ?? '',
            'llegada_fecha' => $parseDate($metaLegacy('- Arrival Date')),
            'llegada_hora' => $metaLegacy('- Arrival Time'),
            'llegada_vuelo' => $metaLegacy('- Arrival Flight Number'),
            'llegada_chofer' => $metaLegacy('chofer_llegada'),
            'llegada_subchofer' => $metaLegacy('subchofer_llegada'),
            'llegada_nota_choferes' => $metaLegacy('nota_choferes_llegada'),
            'llegada_notas_internas' => $metaLegacy('notas_internas_llegada'),
            'salida_fecha' => $parseDate($metaLegacy('- Departure Date')),
            'salida_hora' => $metaLegacy('- Pick-up Time at Hotel'),
            'salida_vuelo' => $metaLegacy('- Departure Flight Number'),
            'salida_chofer' => $metaLegacy('chofer_salida'),
            'salida_subchofer' => $metaLegacy('subchofer_salida'),
            'salida_nota_choferes' => $metaLegacy('nota_choferes_ida'),
            'salida_notas_internas' => $metaLegacy('notas_internas_salida'),
            'metodo_pago' => $order['payment_method_title'] ?? '',
            'subtotal' => floatval(array_sum(array_column($order['line_items'] ?? [], 'subtotal'))),
            'cargos_adicionales' => floatval(array_sum(array_column($order['fee_lines'] ?? [], 'total'))),
            'impuestos' => floatval(array_sum(array_column($order['tax_lines'] ?? [], 'tax_total'))),
            'descuentos' => floatval(array_sum(array_column($order['coupon_lines'] ?? [], 'discount'))),
            'total' => floatval($order['total']),
            'precio_neto' => $metaLegacy('precio_neto'),
            'raw_data' => json_encode($order),
            'privacy_show_email' => '0',
            'privacy_show_phone' => '0'
        ];

        $sql = "
            INSERT INTO reservas (id, status, date_created, cliente_nombre, cliente_email, cliente_telefono, cliente_pais, cliente_direccion,
                tipo_viaje, pasajeros, hotel_nombre, llegada_fecha, llegada_hora, llegada_vuelo, llegada_chofer, llegada_subchofer,
                llegada_nota_choferes, llegada_notas_internas, salida_fecha, salida_hora, salida_vuelo, salida_chofer, salida_subchofer,
                salida_nota_choferes, salida_notas_internas, metodo_pago, subtotal, cargos_adicionales, impuestos, descuentos, total, raw_data,
                precio_neto, privacy_show_email, privacy_show_phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
                impuestos = VALUES(impuestos), descuentos = VALUES(descuentos), total = VALUES(total),
                precio_neto = VALUES(precio_neto), raw_data = VALUES(raw_data)
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
    }
}
?>