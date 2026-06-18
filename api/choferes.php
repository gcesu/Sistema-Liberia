<?php
/**
 * API de Choferes
 */

session_start();
require_once '../config/db.php';
require_once '../config/session_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

if (!validateAndRefreshSession()) {
    http_response_code(401);
    echo json_encode(['error' => 'Sesión expirada']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════
// GET — Listar choferes
// ═══════════════════════════════════════════════════
if ($method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, nombre, telefono, link_param, comision_tipo, comision_porcentaje FROM choferes ORDER BY nombre");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// POST — Crear chofer
// ═══════════════════════════════════════════════════
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $nombre     = trim($input['nombre'] ?? '');
    $telefono   = trim($input['telefono'] ?? '');
    $link_param = trim($input['link_param'] ?? '');

    if (!$nombre) {
        http_response_code(422);
        echo json_encode(['error' => 'El nombre es requerido']);
        exit;
    }

    try {
        // Verificar nombre duplicado
        $check = $pdo->prepare("SELECT id FROM choferes WHERE nombre = ?");
        $check->execute([$nombre]);
        if ($check->fetch()) {
            http_response_code(422);
            echo json_encode(['error' => 'Ya existe un chofer con ese nombre']);
            exit;
        }

        // Verificar link_param duplicado
        if ($link_param) {
            $checkLink = $pdo->prepare("SELECT id FROM choferes WHERE link_param = ?");
            $checkLink->execute([$link_param]);
            if ($checkLink->fetch()) {
                http_response_code(422);
                echo json_encode(['error' => 'Ese link personalizado ya está en uso']);
                exit;
            }
        }

        $stmt = $pdo->prepare("INSERT INTO choferes (nombre, telefono, link_param, comision_tipo) VALUES (?, ?, ?, 'neto')");
        $stmt->execute([$nombre, $telefono ?: null, $link_param ?: null]);
        $newId = $pdo->lastInsertId();

        $stmt2 = $pdo->prepare("SELECT id, nombre, telefono, link_param, comision_tipo, comision_porcentaje FROM choferes WHERE id = ?");
        $stmt2->execute([$newId]);
        echo json_encode($stmt2->fetch(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al crear: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// PUT — Actualizar comisión (usado por contabilidad)
// ═══════════════════════════════════════════════════
if ($method === 'PUT') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de chofer requerido']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $tipo  = $input['comision_tipo'] ?? '';

    if (!in_array($tipo, ['porcentaje', 'neto'])) {
        http_response_code(422);
        echo json_encode(['error' => 'Tipo de comisión inválido']);
        exit;
    }

    $porcentaje = null;
    if ($tipo === 'porcentaje') {
        $porcentaje = isset($input['comision_porcentaje']) ? floatval($input['comision_porcentaje']) : 0;
        if ($porcentaje <= 0 || $porcentaje > 100) {
            http_response_code(422);
            echo json_encode(['error' => 'Porcentaje inválido (1–100)']);
            exit;
        }
    }

    try {
        $stmt = $pdo->prepare("UPDATE choferes SET comision_tipo = ?, comision_porcentaje = ? WHERE id = ?");
        $stmt->execute([$tipo, $porcentaje, $id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Chofer no encontrado']);
            exit;
        }

        $stmt2 = $pdo->prepare("SELECT id, nombre, telefono, link_param, comision_tipo, comision_porcentaje FROM choferes WHERE id = ?");
        $stmt2->execute([$id]);
        echo json_encode($stmt2->fetch(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// PATCH — Actualizar nombre, teléfono, link_param
// ═══════════════════════════════════════════════════
if ($method === 'PATCH') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de chofer requerido']);
        exit;
    }

    $input      = json_decode(file_get_contents('php://input'), true);
    $nombre     = trim($input['nombre'] ?? '');
    $telefono   = trim($input['telefono'] ?? '');
    $link_param = trim($input['link_param'] ?? '');

    if (!$nombre) {
        http_response_code(422);
        echo json_encode(['error' => 'El nombre es requerido']);
        exit;
    }

    try {
        // Verificar nombre duplicado (excluyendo el propio)
        $check = $pdo->prepare("SELECT id FROM choferes WHERE nombre = ? AND id != ?");
        $check->execute([$nombre, $id]);
        if ($check->fetch()) {
            http_response_code(422);
            echo json_encode(['error' => 'Ya existe un chofer con ese nombre']);
            exit;
        }

        // Verificar link_param duplicado (excluyendo el propio)
        if ($link_param) {
            $checkLink = $pdo->prepare("SELECT id FROM choferes WHERE link_param = ? AND id != ?");
            $checkLink->execute([$link_param, $id]);
            if ($checkLink->fetch()) {
                http_response_code(422);
                echo json_encode(['error' => 'Ese link personalizado ya está en uso']);
                exit;
            }
        }

        $stmt = $pdo->prepare("UPDATE choferes SET nombre = ?, telefono = ?, link_param = ? WHERE id = ?");
        $stmt->execute([$nombre, $telefono ?: null, $link_param ?: null, $id]);

        if ($stmt->rowCount() === 0) {
            // Puede que no cambió nada, verificar que existe
            $existCheck = $pdo->prepare("SELECT id FROM choferes WHERE id = ?");
            $existCheck->execute([$id]);
            if (!$existCheck->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Chofer no encontrado']);
                exit;
            }
        }

        $stmt2 = $pdo->prepare("SELECT id, nombre, telefono, link_param, comision_tipo, comision_porcentaje FROM choferes WHERE id = ?");
        $stmt2->execute([$id]);
        echo json_encode($stmt2->fetch(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// DELETE — Eliminar chofer
// ═══════════════════════════════════════════════════
if ($method === 'DELETE') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['error' => 'ID de chofer requerido']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM choferes WHERE id = ?");
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['error' => 'Chofer no encontrado']);
            exit;
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al eliminar: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
?>
