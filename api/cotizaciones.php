<?php
/**
 * API Local de Cotizaciones (Sistema Liberia)
 * Maneja las operaciones CRUD para la tabla `cotizaciones`
 */

session_start();
require_once '../config/db.php';
require_once '../config/env.php';

// Headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Verificar autenticación (igual que Reservas)
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// =============================================================================
// GET: Obtener Cotizaciones
// =============================================================================
if ($method === 'GET') {
    // Parámetros de filtro
    $id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $search = isset($_GET['search']) ? $_GET['search'] : null;

    // Paginación (opcional, por ahora trae todo o las últimas 100)
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = 100; // Limite por seguridad
    $offset = ($page - 1) * $limit;

    try {
        if ($id) {
            // Obtener una sola cotización
            $stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $result = transformarCotizacionParaFrontend($row);
                // Agregar viajes
                $vStmt = $pdo->prepare("SELECT * FROM viajes WHERE reserva_id = ? ORDER BY item_index ASC");
                $vStmt->execute([$row['id']]);
                $result['viajes'] = $vStmt->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode($result);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Cotización no encontrada']);
            }
        } else {
            // Listar cotizaciones con filtros
            $sql = "SELECT * FROM cotizaciones WHERE 1=1";
            $params = [];

            if ($status && $status !== 'all') {
                $sql .= " AND status = ?";
                $params[] = $status;
            }

            if ($search) {
                $sql .= " AND (id LIKE ? OR cliente_nombre LIKE ? OR cliente_email LIKE ?)";
                $wildcard = "%$search%";
                $params[] = $wildcard;
                $params[] = $wildcard;
                $params[] = $wildcard;
            }

            $sql .= " ORDER BY date_created DESC LIMIT $limit OFFSET $offset";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Transformar datos e incluir viajes
            $output = [];
            foreach ($rows as $row) {
                $item = transformarCotizacionParaFrontend($row);
                $vStmt = $pdo->prepare("SELECT * FROM viajes WHERE reserva_id = ? ORDER BY item_index ASC");
                $vStmt->execute([$row['id']]);
                $item['viajes'] = $vStmt->fetchAll(PDO::FETCH_ASSOC);
                $output[] = $item;
            }
            echo json_encode($output);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================================================
// PUT: Actualizar Cotización
// =============================================================================
elseif ($method === 'PUT') {
    // Leer el ID desde query param o body
    $id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Falta el ID de la cotización']);
        exit;
    }

    try {
        // Campos permitidos para actualizar
        // Nota: Agrega aquí todos los campos que tu frontend pueda editar
        $fieldsToUpdate = [];
        $params = [];

        // 1. Status
        if (isset($input['status'])) {
            $fieldsToUpdate[] = "status = ?";
            $params[] = $input['status'];
        }

        // 2. Estado de cotización (se guarda en cotizaciones Y en reservas)
        if (isset($input['status_viaje'])) {
            $fieldsToUpdate[] = "status_viaje = ?";
            $params[] = $input['status_viaje'];

            // Sincronizar estado en tabla reservas
            $stmtRes = $pdo->prepare("UPDATE reservas SET status = ? WHERE id = ?");
            $stmtRes->execute([$input['status_viaje'], $id]);
        }

        // 3. Datos Financieros (Editables Manualmente)
        if (isset($input['subtotal'])) {
            $fieldsToUpdate[] = "subtotal = ?";
            $params[] = $input['subtotal'];
        }
        if (isset($input['total'])) {
            $fieldsToUpdate[] = "total = ?";
            $params[] = $input['total'];
        }

        // 3. Método de pago
        if (isset($input['payment_method_title'])) {
            $fieldsToUpdate[] = "metodo_pago = ?";
            $params[] = $input['payment_method_title'];
        }

        // 4. Privacidad (si el frontend mandara meta_data para esto)
        // Por ahora lo simplificamos, pero puedes agregar lógica aquí si editas privacidad

        if (empty($fieldsToUpdate)) {
            echo json_encode(['message' => 'Nada que actualizar']);
            exit;
        }

        // Ejecutar Update
        $sql = "UPDATE cotizaciones SET " . implode(", ", $fieldsToUpdate) . " WHERE id = ?";
        $params[] = $id;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Devolver el objeto actualizado
        $stmt = $pdo->prepare("SELECT * FROM cotizaciones WHERE id = ?");
        $stmt->execute([$id]);
        $updatedRow = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(transformarCotizacionParaFrontend($updatedRow));

        // Opcional: Sincronizar cambios hacia WooCommerce (si aplica)
        // syncToWooCommerce($id, $input);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error actualizando: ' . $e->getMessage()]);
    }
    exit;
}

// =============================================================================
// DELETE: Borrar Cotización
// =============================================================================
elseif ($method === 'DELETE') {
    $id = isset($_GET['order_id']) ? intval($_GET['order_id']) : null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'Falta ID']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM cotizaciones WHERE id = ?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit;
}

// =============================================================================
// FUNCIONES AUXILIARES
// =============================================================================

function transformarCotizacionParaFrontend($r)
{
    if (!$r)
        return null;

    // Decodificar el JSON original para tener acceso a todo si se necesita
    $rawData = json_decode($r['raw_data'], true) ?: [];

    // Reconstruir estructura compatible con el Frontend existente
    // (Tu frontend espera : id, status, billing, line_items, meta_data, etc.)

    // 1. Line Items (Productos)
    // Intentamos recuperar los originales, o construimos uno dummy con los datos de la tabla
    $lineItems = $rawData['line_items'] ?? [];
    if (empty($lineItems)) {
        $lineItems[] = [
            'name' => $r['hotel_nombre'], // En cotizaciones, este es el destino principal
            'quantity' => 1,
            'subtotal' => $r['subtotal'],
            'total' => $r['total']
        ];
    }

    // 2. Meta Data (Para compatibilidad con la vista de detalles)
    // Aquí mapeamos las columnas de la tabla a formato key/value
    $metaData = $rawData['meta_data'] ?? [];

    // Agregar/Sobrescribir con los valores frescos de la DB
    // Usamos un helper simple
    $addMeta = function (&$arr, $key, $val) {
        // Buscar si ya existe y actualizar
        foreach ($arr as &$m) {
            if (($m['key'] ?? '') === $key) {
                $m['value'] = $val;
                return;
            }
        }
        // Si no, agregar
        $arr[] = ['key' => $key, 'value' => $val];
    };

    $addMeta($metaData, 'privacy_show_email', $r['privacy_show_email']);
    $addMeta($metaData, 'privacy_show_phone', $r['privacy_show_phone']);
    $addMeta($metaData, 'privacy_show_financiero', $r['privacy_show_financiero']);

    // Campos específicos para mostrar en el modal
    $addMeta($metaData, 'Origen', $r['origen']);
    $addMeta($metaData, 'Destino', $r['hotel_nombre']);
    $addMeta($metaData, 'Fecha Viaje', $r['fecha_viaje']);
    $addMeta($metaData, 'Hora Viaje', $r['hora_viaje']);


    // 3. Estructura Final
    return [
        'id' => (int) $r['id'],
        'status' => $r['status'],
        'date_created' => $r['date_created'],
        'total' => $r['total'],
        'subtotal' => $r['subtotal'], // Importante para la edición financiera
        'payment_method_title' => $r['metodo_pago'],
        'currency' => $rawData['currency'] ?? 'USD',

        // Billing / Cliente
        'billing' => [
            'first_name' => '', // Nombre completo ya está en cliente_nombre
            'last_name' => $r['cliente_nombre'],
            'email' => $r['cliente_email'],
            'phone' => $r['cliente_telefono'],
            'country' => $r['cliente_pais'],
            'address_1' => $r['cliente_direccion']
        ],

        // Items y Fees
        'line_items' => $lineItems,
        'fee_lines' => $rawData['fee_lines'] ?? [], // Cargos originales
        'tax_lines' => $rawData['tax_lines'] ?? [],
        'coupon_lines' => $rawData['coupon_lines'] ?? [],

        // Metadata
        'meta_data' => $metaData,

        // Campos directos adicionales (para uso fácil en JS)
        'origen' => $r['origen'],
        'hotel_nombre' => $r['hotel_nombre'],
        'fecha_viaje' => $r['fecha_viaje'],
        'hora_viaje' => $r['hora_viaje'],
        'pasajeros' => $r['pasajeros'],
        'status_viaje' => $r['status_viaje'],
        'customer_note' => $r['nota_cliente'] ?: ($rawData['customer_note'] ?? '')
    ];
}
?>