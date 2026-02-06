<?php
/**
 * API de Viajes
 * Maneja operaciones CRUD para viajes individuales
 */

session_start();
require_once '../config/db.php';
require_once '../config/env.php';

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
// GET - Obtener viajes
// ═══════════════════════════════════════════════════
if ($method === 'GET') {

    // Si se pide un viaje específico por ID
    if (isset($_GET['viaje_id']) && $_GET['viaje_id'] !== '') {
        $id = intval($_GET['viaje_id']);
        $stmt = $pdo->prepare("SELECT * FROM viajes WHERE id = ?");
        $stmt->execute([$id]);
        $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($viaje) {
            echo json_encode($viaje);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Viaje no encontrado']);
        }
        exit;
    }

    // Si se piden viajes de una reserva específica
    if (isset($_GET['reserva_id']) && $_GET['reserva_id'] !== '') {
        $reservaId = intval($_GET['reserva_id']);
        $stmt = $pdo->prepare("SELECT * FROM viajes WHERE reserva_id = ? ORDER BY item_index ASC");
        $stmt->execute([$reservaId]);
        $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($viajes);
        exit;
    }

    // Listar todos los viajes con filtros opcionales
    $whereClause = "1=1";
    $params = [];

    // Filtro por fecha
    if (isset($_GET['fecha']) && $_GET['fecha'] !== '') {
        $whereClause .= " AND fecha = ?";
        $params[] = $_GET['fecha'];
    }

    // Filtro por rango de fechas
    if (isset($_GET['fecha_desde']) && $_GET['fecha_desde'] !== '') {
        $whereClause .= " AND fecha >= ?";
        $params[] = $_GET['fecha_desde'];
    }
    if (isset($_GET['fecha_hasta']) && $_GET['fecha_hasta'] !== '') {
        $whereClause .= " AND fecha <= ?";
        $params[] = $_GET['fecha_hasta'];
    }

    // Filtro por tipo
    if (isset($_GET['tipo']) && $_GET['tipo'] !== '') {
        $whereClause .= " AND tipo = ?";
        $params[] = $_GET['tipo'];
    }

    // Filtro por chofer
    if (isset($_GET['chofer']) && $_GET['chofer'] !== '') {
        $whereClause .= " AND chofer = ?";
        $params[] = $_GET['chofer'];
    }

    // Filtro por status
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $whereClause .= " AND status = ?";
        $params[] = $_GET['status'];
    }

    // Ordenamiento
    $orderBy = "fecha ASC, hora ASC";

    // Obtener viajes con datos de la reserva
    $sql = "
        SELECT v.*, r.cliente_nombre, r.cliente_email, r.cliente_telefono, r.status as reserva_status
        FROM viajes v
        LEFT JOIN reservas r ON v.reserva_id = r.id
        WHERE $whereClause
        ORDER BY $orderBy
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($viajes);
    exit;
}

// ═══════════════════════════════════════════════════
// PUT - Actualizar viaje
// ═══════════════════════════════════════════════════
if ($method === 'PUT') {
    $id = isset($_GET['viaje_id']) ? intval($_GET['viaje_id']) : 0;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de viaje requerido']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Datos inválidos']);
        exit;
    }

    // Campos permitidos para actualizar
    $allowedFields = [
        'fecha',
        'hora',
        'vuelo',
        'chofer',
        'subchofer',
        'nota_choferes',
        'notas_internas',
        'status',
        'pax',
        'hotel'
    ];

    $updates = [];
    $params = [];

    foreach ($allowedFields as $field) {
        if (isset($input[$field])) {
            $updates[] = "$field = ?";
            $params[] = $input[$field];
        }
    }

    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'No hay campos para actualizar']);
        exit;
    }

    $params[] = $id;
    $sql = "UPDATE viajes SET " . implode(', ', $updates) . " WHERE id = ?";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Obtener el viaje actualizado
        $stmt = $pdo->prepare("SELECT * FROM viajes WHERE id = ?");
        $stmt->execute([$id]);
        $viaje = $stmt->fetch(PDO::FETCH_ASSOC);

        // Sincronizar con WooCommerce si es necesario
        if ($viaje) {
            $wooResult = syncViajeToWooCommerce($pdo, $viaje, $input);
            $viaje['woo_sync'] = $wooResult;
        }

        echo json_encode($viaje);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// DELETE - Eliminar viaje
// ═══════════════════════════════════════════════════
if ($method === 'DELETE') {
    $id = isset($_GET['viaje_id']) ? intval($_GET['viaje_id']) : 0;

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de viaje requerido']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM viajes WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => "Viaje #$id eliminado"]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Viaje no encontrado']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al eliminar: ' . $e->getMessage()]);
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
 * Sincroniza cambios del viaje con WooCommerce
 */
function syncViajeToWooCommerce($pdo, $viaje, $changes)
{
    $wooUrl = env('WOO_SITE_URL');
    $consumerKey = env('WOO_CONSUMER_KEY');
    $consumerSecret = env('WOO_CONSUMER_SECRET');

    if (!$wooUrl || !$consumerKey || !$consumerSecret) {
        return ['success' => false, 'error' => 'Credenciales no configuradas'];
    }

    $orderId = $viaje['reserva_id'];
    $itemIndex = $viaje['item_index'];
    $tipo = $viaje['tipo'];

    // Construir metadata para WooCommerce
    $metaData = [];

    // Mapear campos de viaje a keys de WooCommerce
    $keyPrefix = "viaje_{$orderId}_{$itemIndex}_";

    foreach ($changes as $field => $value) {
        $metaData[] = ['key' => $keyPrefix . $field, 'value' => $value];
    }

    // También actualizar campos legacy si aplica (compatibilidad)
    if (isset($changes['chofer'])) {
        $legacyKey = $tipo === 'llegada' ? 'chofer_llegada' : 'chofer_salida';
        $metaData[] = ['key' => $legacyKey, 'value' => $changes['chofer']];
    }
    if (isset($changes['status'])) {
        $legacyKey = $tipo === 'llegada' ? 'status_llegada' : 'status_salida';
        $metaData[] = ['key' => $legacyKey, 'value' => $changes['status']];
    }

    if (empty($metaData)) {
        return ['success' => true, 'message' => 'Sin cambios para WooCommerce'];
    }

    $wooData = ['meta_data' => $metaData];
    $apiUrl = "$wooUrl/wp-json/wc/v3/orders/$orderId";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$consumerKey:$consumerSecret");
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($wooData));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error];
    }

    if ($httpCode >= 200 && $httpCode < 300) {
        return ['success' => true, 'http_code' => $httpCode];
    } else {
        return ['success' => false, 'http_code' => $httpCode, 'response' => substr($response, 0, 200)];
    }
}
?>