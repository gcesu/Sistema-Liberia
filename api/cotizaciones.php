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

// CORS para permitir peticiones desde el sitio WordPress
header('Access-Control-Allow-Origin: https://liberiaairportshuttle.com');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');

// Preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// POST es público (formulario de cotización desde WordPress)
// GET, PUT, DELETE requieren autenticación
if ($method !== 'POST' && !isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

// =============================================================================
// POST: Crear Cotización desde formulario público (WordPress)
// =============================================================================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request body']);
        exit;
    }

    $client = $input['client'] ?? [];
    $trips  = $input['trips'] ?? [];
    $notes  = $input['notes'] ?? '';

    // Validación mínima
    if (empty($client['name']) || empty($client['email']) || empty($client['phone']) || empty($trips)) {
        http_response_code(422);
        echo json_encode(['error' => 'Missing required fields']);
        exit;
    }

    try {
        // Tomar datos del primer viaje como referencia principal de la cotización
        $firstTrip   = $trips[0];
        $tripType    = $firstTrip['type'] ?? 'arrival';
        $origen      = '';
        $destino     = '';
        $fechaViaje  = null;
        $horaViaje   = null;

        if ($tripType === 'arrival') {
            $origen     = 'Airport LIR';
            $destino    = $firstTrip['arrival_hotel'] ?? '';
            $fechaViaje = $firstTrip['arrival_date'] ?? null;
            $horaViaje  = $firstTrip['arrival_time'] ?? null;
        } elseif ($tripType === 'departure') {
            $origen     = $firstTrip['departure_hotel'] ?? '';
            $destino    = 'Airport LIR';
            $fechaViaje = $firstTrip['departure_date'] ?? null;
            $horaViaje  = $firstTrip['departure_time'] ?? null;
        } elseif ($tripType === 'roundtrip') {
            $origen     = 'Airport LIR';
            $destino    = $firstTrip['rt_hotel'] ?? '';
            $fechaViaje = $firstTrip['rt_arrival_date'] ?? null;
            $horaViaje  = $firstTrip['rt_arrival_time'] ?? null;
        } elseif ($tripType === 'internal') {
            $origen     = $firstTrip['internal_pickup'] ?? '';
            $destino    = $firstTrip['internal_dropoff'] ?? '';
            $fechaViaje = $firstTrip['internal_date'] ?? null;
            $horaViaje  = $firstTrip['internal_time'] ?? null;
        }

        $pm = $client['payment_method'] ?? '';
        if ($pm === 'cash') $metodo_pago = 'Cash';
        elseif ($pm === 'credit_card') $metodo_pago = 'Credit Card';
        elseif ($pm === 'paypal') $metodo_pago = 'PayPal';
        else $metodo_pago = $pm;

        // Generar ID manualmente (la columna no es AUTO_INCREMENT).
        // Se usa un rango >= 10,000,000 para evitar conflictos con IDs de WooCommerce.
        // El frontend muestra estos IDs como "C-N" restando el offset (10000000 -> "C-1").
        $maxStmt = $pdo->query("
            SELECT GREATEST(
                COALESCE((SELECT MAX(id) FROM cotizaciones), 0),
                COALESCE((SELECT MAX(id) FROM reservas), 0),
                9999999
            ) AS max_id
        ");
        $cotizacionId = intval($maxStmt->fetchColumn()) + 1;

        // 1. Insertar en cotizaciones
        $stmt = $pdo->prepare("
            INSERT INTO cotizaciones (
                id, status, status_viaje, date_created,
                cliente_nombre, cliente_email, cliente_telefono, cliente_pais,
                pasajeros, origen, hotel_nombre, fecha_viaje, hora_viaje,
                metodo_pago, subtotal, total, raw_data,
                nota_cliente, privacy_show_email, privacy_show_phone, privacy_show_financiero
            ) VALUES (
                ?, 'pending', 'pending', NOW(),
                ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, 0, 0, ?,
                ?, '0', '0', '1'
            )
        ");
        $stmt->execute([
            $cotizacionId,
            $client['name'],
            $client['email'],
            $client['phone'],
            $client['country'] ?? '',
            intval($client['passengers']) ?: 1,
            $origen,
            $destino,
            $fechaViaje,
            $horaViaje,
            $metodo_pago,
            json_encode($input),
            $notes,
        ]);

        // 2. Insertar copia en reservas (es_cotizacion = 1)
        $stmtRes = $pdo->prepare("
            INSERT INTO reservas (
                id, status, date_created,
                cliente_nombre, cliente_email, cliente_telefono, cliente_pais,
                pasajeros, hotel_nombre, metodo_pago,
                subtotal, total, raw_data, nota_cliente, es_cotizacion
            ) VALUES (
                ?, 'pending', NOW(),
                ?, ?, ?, ?,
                ?, ?, ?,
                0, 0, ?, ?, 1
            )
        ");
        $stmtRes->execute([
            $cotizacionId,
            $client['name'],
            $client['email'],
            $client['phone'],
            $client['country'] ?? '',
            intval($client['passengers']) ?: 1,
            $destino,
            $metodo_pago,
            json_encode($input),
            $notes,
        ]);

        // 3. Insertar viajes individuales
        $itemIndex = 0;
        foreach ($trips as $trip) {
            $tipo = $trip['type'] ?? 'arrival';

            if ($tipo === 'arrival') {
                insertViaje($pdo, $cotizacionId, $itemIndex++, 'llegada',
                    $trip['arrival_date'] ?? null,
                    $trip['arrival_time'] ?? null,
                    $trip['arrival_flight'] ?? null,
                    intval($client['passengers']) ?: 1,
                    $trip['arrival_hotel'] ?? null
                );
            } elseif ($tipo === 'departure') {
                insertViaje($pdo, $cotizacionId, $itemIndex++, 'salida',
                    $trip['departure_date'] ?? null,
                    $trip['departure_time'] ?? null,
                    $trip['departure_flight'] ?? null,
                    intval($client['passengers']) ?: 1,
                    $trip['departure_hotel'] ?? null
                );
            } elseif ($tipo === 'roundtrip') {
                insertViaje($pdo, $cotizacionId, $itemIndex++, 'llegada',
                    $trip['rt_arrival_date'] ?? null,
                    $trip['rt_arrival_time'] ?? null,
                    $trip['rt_arrival_flight'] ?? null,
                    intval($client['passengers']) ?: 1,
                    $trip['rt_hotel'] ?? null
                );
                insertViaje($pdo, $cotizacionId, $itemIndex++, 'salida',
                    $trip['rt_departure_date'] ?? null,
                    $trip['rt_departure_time'] ?? null,
                    $trip['rt_departure_flight'] ?? null,
                    intval($client['passengers']) ?: 1,
                    $trip['rt_hotel'] ?? null
                );
            } elseif ($tipo === 'internal') {
                $ruta = ($trip['internal_pickup'] ?? '') . ' → ' . ($trip['internal_dropoff'] ?? '');
                insertViaje($pdo, $cotizacionId, $itemIndex++, 'interno',
                    $trip['internal_date'] ?? null,
                    $trip['internal_time'] ?? null,
                    null,
                    intval($client['passengers']) ?: 1,
                    $ruta
                );
            }
        }

        echo json_encode(['success' => true, 'id' => $cotizacionId]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// =============================================================================
// GET: Obtener Cotizaciones
// =============================================================================
if ($method === 'GET') {
    // Parámetros de filtro
    $hasId = isset($_GET['order_id']);
    $id = $hasId ? intval($_GET['order_id']) : null;
    $status = isset($_GET['status']) ? $_GET['status'] : null;
    $search = isset($_GET['search']) ? $_GET['search'] : null;

    // Paginación (opcional, por ahora trae todo o las últimas 100)
    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $limit = 100; // Limite por seguridad
    $offset = ($page - 1) * $limit;

    try {
        if ($hasId) {
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
    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($_GET['order_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Falta el ID de la cotización']);
        exit;
    }
    $id = intval($_GET['order_id']);

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

        // 2. Estado de cotización (se sincroniza entre cotizaciones.status, cotizaciones.status_viaje y reservas.status)
        $shouldSendCompletedEmail = false;
        if (isset($input['status_viaje'])) {
            // Detectar transición a "completed" para disparar el correo de confirmación
            $prevStmt = $pdo->prepare("SELECT status_viaje FROM cotizaciones WHERE id = ?");
            $prevStmt->execute([$id]);
            $prevStatus = $prevStmt->fetchColumn();
            if ($input['status_viaje'] === 'completed' && $prevStatus !== 'completed') {
                $shouldSendCompletedEmail = true;
            }

            $fieldsToUpdate[] = "status_viaje = ?";
            $params[] = $input['status_viaje'];

            // Mantener cotizaciones.status sincronizado para que la lista muestre el estado correcto
            $fieldsToUpdate[] = "status = ?";
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

        // Disparar correo de confirmación si la cotización pasó a "completed".
        // Se hace después de devolver la respuesta para no bloquear al cliente.
        if (!empty($shouldSendCompletedEmail)) {
            // Cerrar la conexión con el cliente (FastCGI/PHP-FPM) para que la espera
            // del SMTP no afecte la respuesta del PUT.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            try {
                require_once __DIR__ . '/lib/email_sender.php';
                sendQuoteCompletedEmail($pdo, $id);
            } catch (Throwable $e) {
                error_log("Error enviando correo de cotización $id completed: " . $e->getMessage());
            }
        }

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
    if (!isset($_GET['order_id'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Falta ID']);
        exit;
    }
    $id = intval($_GET['order_id']);

    try {
        $pdo->prepare("DELETE FROM viajes WHERE reserva_id = ?")->execute([$id]);
        $stmt = $pdo->prepare("DELETE FROM cotizaciones WHERE id = ?");
        $stmt->execute([$id]);

        // Mover a trash en WooCommerce
        $wooUrl = env('WOO_SITE_URL');
        $ck = env('WOO_CONSUMER_KEY');
        $cs = env('WOO_CONSUMER_SECRET');
        if ($wooUrl && $ck && $cs) {
            $ch = curl_init("$wooUrl/wp-json/wc/v3/orders/$id");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST => 'DELETE',
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_USERPWD => "$ck:$cs",
                CURLOPT_TIMEOUT => 30
            ]);
            curl_exec($ch);
            curl_close($ch);
        }

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

function insertViaje($pdo, $reservaId, $itemIndex, $tipo, $fecha, $hora, $vuelo, $pax, $destino)
{
    $stmt = $pdo->prepare("
        INSERT INTO viajes (reserva_id, item_index, tipo, fecha, hora, vuelo, pax, destino)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$reservaId, $itemIndex, $tipo, $fecha, $hora, $vuelo, $pax, $destino]);
}

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