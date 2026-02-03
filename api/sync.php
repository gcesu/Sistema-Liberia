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
        // Manejar formato MM/DD/YYYY
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
            return $matches[3] . '-' . str_pad($matches[1], 2, '0', STR_PAD_LEFT) . '-' . str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        }
        // Manejar formato YYYY-MM-DD
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
    if (empty($direccion))
        $direccion = $meta('shipping_address_1');
    if (empty($direccion))
        $direccion = $meta('billing_address_1');

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