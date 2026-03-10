<?php
/**
 * API de Contabilidad de Choferes
 * GET: Obtiene viajes de un chofer en un mes específico
 * PUT: Actualiza el estado de pago de un viaje
 */

session_start();
require_once '../config/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════
// GET - Obtener viajes de un chofer por mes
// ═══════════════════════════════════════════════════
if ($method === 'GET') {
    $chofer = $_GET['chofer'] ?? '';
    $mes = $_GET['mes'] ?? ''; // Formato: YYYY-MM

    if (empty($chofer) || empty($mes)) {
        http_response_code(400);
        echo json_encode(['error' => 'Parámetros chofer y mes son requeridos']);
        exit;
    }

    // Validar formato de mes
    if (!preg_match('/^\d{4}-\d{2}$/', $mes)) {
        http_response_code(400);
        echo json_encode(['error' => 'Formato de mes inválido. Usar YYYY-MM']);
        exit;
    }

    $fechaInicio = $mes . '-01';
    $fechaFin = date('Y-m-t', strtotime($fechaInicio));

    try {
        // Obtener info del chofer
        $stmtChofer = $pdo->prepare("SELECT id, nombre, comision_tipo, comision_porcentaje FROM choferes WHERE nombre = ?");
        $stmtChofer->execute([$chofer]);
        $choferInfo = $stmtChofer->fetch(PDO::FETCH_ASSOC);

        if (!$choferInfo) {
            http_response_code(404);
            echo json_encode(['error' => 'Chofer no encontrado']);
            exit;
        }

        // Obtener viajes del chofer en el rango de fechas
        // Excluir reservas canceladas
        $sql = "
            SELECT v.id, v.reserva_id, v.item_index, v.tipo, v.fecha, v.hora, v.pax,
                   v.hotel, v.destino, v.precio_neto, v.pagado,
                   r.cliente_nombre, r.metodo_pago, r.raw_data, r.status as reserva_status
            FROM viajes v
            LEFT JOIN reservas r ON v.reserva_id = r.id
            WHERE v.chofer = ?
              AND v.fecha >= ?
              AND v.fecha <= ?
              AND r.status NOT IN ('cancelled', 'refunded', 'failed')
            ORDER BY v.fecha ASC, v.hora ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$chofer, $fechaInicio, $fechaFin]);
        $viajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Contar cuántos viajes comparten el mismo line_item por reserva
        // Para dividir el subtotal correctamente en roundtrips
        $tripCountPerItem = [];
        foreach ($viajes as $viaje) {
            $originalIdx = $viaje['item_index'] >= 1000 ? $viaje['item_index'] - 1000 : $viaje['item_index'];
            $key = $viaje['reserva_id'] . '_' . $originalIdx;
            if (!isset($tripCountPerItem[$key])) {
                // Contar TODOS los viajes de esta reserva con este item_index (no solo los del chofer actual)
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM viajes WHERE reserva_id = ? AND (item_index = ? OR item_index = ?)");
                $countStmt->execute([$viaje['reserva_id'], $originalIdx, $originalIdx + 1000]);
                $tripCountPerItem[$key] = (int) $countStmt->fetchColumn();
            }
        }

        // Procesar cada viaje para resolver el subtotal y destino
        $result = [];
        foreach ($viajes as $viaje) {
            $subtotal = 0;
            $destino = $viaje['destino'] ?: $viaje['hotel'] ?: 'Transfer';

            // Resolver subtotal desde raw_data
            if (!empty($viaje['raw_data'])) {
                $rawOrder = json_decode($viaje['raw_data'], true);
                if ($rawOrder && isset($rawOrder['line_items'])) {
                    $originalItemIndex = $viaje['item_index'] >= 1000
                        ? $viaje['item_index'] - 1000
                        : $viaje['item_index'];

                    $candidateIndexes = [$originalItemIndex];
                    if ($originalItemIndex > 0) {
                        $candidateIndexes[] = $originalItemIndex - 1;
                    }
                    $candidateIndexes[] = 0;
                    $candidateIndexes = array_values(array_unique($candidateIndexes));

                    foreach ($candidateIndexes as $candidateIdx) {
                        if (isset($rawOrder['line_items'][$candidateIdx])) {
                            $rawSubtotal = floatval($rawOrder['line_items'][$candidateIdx]['subtotal'] ?? 0);

                            // Dividir subtotal entre viajes que comparten el mismo line_item
                            $itemKey = $viaje['reserva_id'] . '_' . ($viaje['item_index'] >= 1000 ? $viaje['item_index'] - 1000 : $viaje['item_index']);
                            $siblingCount = $tripCountPerItem[$itemKey] ?? 1;
                            $subtotal = $rawSubtotal / max($siblingCount, 1);

                            // Resolver destino si está vacío
                            if ($destino === 'Transfer') {
                                $rawName = trim($rawOrder['line_items'][$candidateIdx]['name'] ?? '');
                                if ($rawName && strtolower($rawName) !== 'transfer') {
                                    $destino = $rawName;
                                }
                            }
                            break;
                        }
                    }
                }
            }

            // Calcular comisión
            $comision = null;
            $precioNeto = floatval($viaje['precio_neto'] ?? 0);

            if ($choferInfo['comision_tipo'] === 'porcentaje') {
                $porcentaje = floatval($choferInfo['comision_porcentaje'] ?? 0);
                $comision = $subtotal * ($porcentaje / 100);
            } elseif ($choferInfo['comision_tipo'] === 'neto') {
                // Si no hay precio neto asignado, no calcular comisión
                if ($precioNeto > 0) {
                    $comision = $subtotal - $precioNeto;
                    if ($comision < 0) $comision = 0;
                }
            }

            $result[] = [
                'id' => (int) $viaje['id'],
                'reserva_id' => (int) $viaje['reserva_id'],
                'tipo' => $viaje['tipo'],
                'fecha' => $viaje['fecha'],
                'hora' => $viaje['hora'],
                'pax' => (int) ($viaje['pax'] ?? 1),
                'destino' => $destino,
                'cliente_nombre' => $viaje['cliente_nombre'] ?? '',
                'metodo_pago' => $viaje['metodo_pago'] ?? '',
                'subtotal' => round($subtotal, 2),
                'precio_neto' => round($precioNeto, 2),
                'comision' => round($comision, 2),
                'pagado' => (int) ($viaje['pagado'] ?? 0)
            ];
        }

        echo json_encode([
            'chofer' => $choferInfo,
            'viajes' => $result
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// PUT - Actualizar estado de pago de un viaje
// ═══════════════════════════════════════════════════
if ($method === 'PUT') {
    $viajeId = isset($_GET['viaje_id']) ? intval($_GET['viaje_id']) : 0;

    if (!$viajeId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de viaje requerido']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['pagado'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Campo pagado requerido']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE viajes SET pagado = ? WHERE id = ?");
        $stmt->execute([(int) $input['pagado'], $viajeId]);

        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'viaje_id' => $viajeId, 'pagado' => (int) $input['pagado']]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Viaje no encontrado']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar: ' . $e->getMessage()]);
    }
    exit;
}

// Método no permitido
http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
?>