<?php
/**
 * API Local de Reservas
 * Lee y escribe reservas desde la base de datos MySQL local
 */

session_start();
require_once '../config/db.php';

// Headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════
// GET - Obtener reservas
// ═══════════════════════════════════════════════════
if ($method === 'GET') {

    // Si se pide una reserva específica
    if (isset($_GET['order_id']) && $_GET['order_id'] !== '') {
        $id = intval($_GET['order_id']);
        $stmt = $pdo->prepare("SELECT * FROM reservas WHERE id = ?");
        $stmt->execute([$id]);
        $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($reserva) {
            echo json_encode(transformarReservaParaFrontend($reserva));
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Reserva no encontrada']);
        }
        exit;
    }

    // Listar reservas con paginación
    $per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 100;
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $offset = ($page - 1) * $per_page;

    // Filtro por fecha (after)
    $whereClause = "1=1";
    $params = [];

    if (isset($_GET['after']) && $_GET['after'] !== '') {
        $whereClause .= " AND date_created >= ?";
        $params[] = $_GET['after'];
    }

    // Ordenamiento
    $orderBy = "date_created DESC";
    if (isset($_GET['orderby'])) {
        $allowed = ['date_created', 'id', 'status'];
        if (in_array($_GET['orderby'], $allowed)) {
            $order = (isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC') ? 'ASC' : 'DESC';
            $orderBy = $_GET['orderby'] . ' ' . $order;
        }
    }

    // Contar total
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM reservas WHERE $whereClause");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();
    $totalPages = ceil($total / $per_page);

    // Obtener reservas
    $sql = "SELECT * FROM reservas WHERE $whereClause ORDER BY $orderBy LIMIT $per_page OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reservas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Transformar al formato que espera el frontend
    $resultado = array_map('transformarReservaParaFrontend', $reservas);

    // Headers de paginación (compatibles con WooCommerce)
    header("X-WP-Total: $total");
    header("X-WP-TotalPages: $totalPages");

    echo json_encode($resultado);
    exit;
}

// ═══════════════════════════════════════════════════
// PUT - Actualizar reserva
// ═══════════════════════════════════════════════════
if ($method === 'PUT') {
    $id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de reserva requerido']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos']);
        exit;
    }

    // Construir campos a actualizar
    $updates = [];
    $params = [];

    // Status
    if (isset($input['status'])) {
        $updates[] = "status = ?";
        $params[] = $input['status'];
    }

    // Datos del cliente (billing)
    if (isset($input['billing'])) {
        $b = $input['billing'];
        if (isset($b['first_name']) || isset($b['last_name'])) {
            $nombre = trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? ''));
            $updates[] = "cliente_nombre = ?";
            $params[] = $nombre;
        }
        if (isset($b['email'])) {
            $updates[] = "cliente_email = ?";
            $params[] = $b['email'];
        }
        if (isset($b['phone'])) {
            $updates[] = "cliente_telefono = ?";
            $params[] = $b['phone'];
        }
        if (isset($b['country'])) {
            $updates[] = "cliente_pais = ?";
            $params[] = $b['country'];
        }
        if (isset($b['address_1'])) {
            $updates[] = "cliente_direccion = ?";
            $params[] = $b['address_1'];
        }
    }

    // Meta data
    if (isset($input['meta_data']) && is_array($input['meta_data'])) {
        $metaMap = [
            '- Arrival Date' => 'llegada_fecha',
            '- Arrival Time' => 'llegada_hora',
            '- Arrival Flight Number' => 'llegada_vuelo',
            'chofer_llegada' => 'llegada_chofer',
            'subchofer_llegada' => 'llegada_subchofer',
            'nota_choferes_llegada' => 'llegada_nota_choferes',
            'notas_internas_llegada' => 'llegada_notas_internas',
            '- Departure Date' => 'salida_fecha',
            '- Pick-up Time at Hotel' => 'salida_hora',
            '- Departure Flight Number' => 'salida_vuelo',
            'chofer_salida' => 'salida_chofer',
            'subchofer_salida' => 'salida_subchofer',
            'nota_choferes_ida' => 'salida_nota_choferes',
            'notas_internas_salida' => 'salida_notas_internas',
        ];

        foreach ($input['meta_data'] as $meta) {
            $key = $meta['key'] ?? '';
            $value = $meta['value'] ?? '';

            if (isset($metaMap[$key])) {
                $dbField = $metaMap[$key];
                $updates[] = "$dbField = ?";
                $params[] = $value;
            }
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No hay campos para actualizar']);
        exit;
    }

    $params[] = $id;
    $sql = "UPDATE reservas SET " . implode(', ', $updates) . " WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Obtener la reserva actualizada
        $stmt = $pdo->prepare("SELECT * FROM reservas WHERE id = ?");
        $stmt->execute([$id]);
        $reserva = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(transformarReservaParaFrontend($reserva));

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar: ' . $e->getMessage()]);
    }
    exit;
}

// Método no permitido
http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);

// ═══════════════════════════════════════════════════
// FUNCIONES AUXILIARES
// ═══════════════════════════════════════════════════

/**
 * Transforma una reserva de la BD al formato que espera el frontend
 * (compatible con el formato de WooCommerce)
 */
function transformarReservaParaFrontend($r)
{
    return [
        'id' => (int) $r['id'],
        'status' => $r['status'],
        'date_created' => $r['date_created'],
        'billing' => [
            'first_name' => explode(' ', $r['cliente_nombre'] ?? '')[0] ?? '',
            'last_name' => implode(' ', array_slice(explode(' ', $r['cliente_nombre'] ?? ''), 1)),
            'email' => $r['cliente_email'] ?? '',
            'phone' => $r['cliente_telefono'] ?? '',
            'country' => $r['cliente_pais'] ?? '',
            'address_1' => $r['cliente_direccion'] ?? '',
        ],
        'shipping' => [
            'first_name' => explode(' ', $r['cliente_nombre'] ?? '')[0] ?? '',
            'last_name' => implode(' ', array_slice(explode(' ', $r['cliente_nombre'] ?? ''), 1)),
            'address_1' => $r['cliente_direccion'] ?? '',
            'city' => '',
            'country' => $r['cliente_pais'] ?? '',
        ],
        'line_items' => [
            [
                'name' => $r['hotel_nombre'] ?? 'Transfer',
                'quantity' => (int) ($r['pasajeros'] ?? 1),
                'subtotal' => $r['subtotal'] ?? '0.00',
                'meta_data' => []
            ]
        ],
        'meta_data' => buildMetaData($r),
        'payment_method_title' => $r['metodo_pago'] ?? '',
        'total' => $r['total'] ?? '0.00',
        'fee_lines' => $r['cargos_adicionales'] > 0 ? [['name' => 'Cargos', 'total' => $r['cargos_adicionales']]] : [],
        'tax_lines' => $r['impuestos'] > 0 ? [['label' => 'Impuestos', 'tax_total' => $r['impuestos']]] : [],
        'coupon_lines' => $r['descuentos'] > 0 ? [['code' => 'Descuento', 'discount' => $r['descuentos']]] : [],
    ];
}

/**
 * Construye el array meta_data compatible con WooCommerce
 */
function buildMetaData($r)
{
    $meta = [];

    // Tipo de viaje
    if ($r['tipo_viaje']) {
        $meta[] = ['key' => '- Type of Trip', 'value' => $r['tipo_viaje']];
    }

    // Pasajeros
    if ($r['pasajeros']) {
        $meta[] = ['key' => 'Passengers', 'value' => $r['pasajeros']];
    }

    // Llegada
    if ($r['llegada_fecha']) {
        $meta[] = ['key' => '- Arrival Date', 'value' => $r['llegada_fecha']];
    }
    if ($r['llegada_hora']) {
        $meta[] = ['key' => '- Arrival Time', 'value' => $r['llegada_hora']];
    }
    if ($r['llegada_vuelo']) {
        $meta[] = ['key' => '- Arrival Flight Number', 'value' => $r['llegada_vuelo']];
    }
    if ($r['llegada_chofer']) {
        $meta[] = ['key' => 'chofer_llegada', 'value' => $r['llegada_chofer']];
    }
    if ($r['llegada_subchofer']) {
        $meta[] = ['key' => 'subchofer_llegada', 'value' => $r['llegada_subchofer']];
    }
    if ($r['llegada_nota_choferes']) {
        $meta[] = ['key' => 'nota_choferes_llegada', 'value' => $r['llegada_nota_choferes']];
    }
    if ($r['llegada_notas_internas']) {
        $meta[] = ['key' => 'notas_internas_llegada', 'value' => $r['llegada_notas_internas']];
    }

    // Salida
    if ($r['salida_fecha']) {
        $meta[] = ['key' => '- Departure Date', 'value' => $r['salida_fecha']];
    }
    if ($r['salida_hora']) {
        $meta[] = ['key' => '- Pick-up Time at Hotel', 'value' => $r['salida_hora']];
    }
    if ($r['salida_vuelo']) {
        $meta[] = ['key' => '- Departure Flight Number', 'value' => $r['salida_vuelo']];
    }
    if ($r['salida_chofer']) {
        $meta[] = ['key' => 'chofer_salida', 'value' => $r['salida_chofer']];
    }
    if ($r['salida_subchofer']) {
        $meta[] = ['key' => 'subchofer_salida', 'value' => $r['salida_subchofer']];
    }
    if ($r['salida_nota_choferes']) {
        $meta[] = ['key' => 'nota_choferes_ida', 'value' => $r['salida_nota_choferes']];
    }
    if ($r['salida_notas_internas']) {
        $meta[] = ['key' => 'notas_internas_salida', 'value' => $r['salida_notas_internas']];
    }

    return $meta;
}
?>