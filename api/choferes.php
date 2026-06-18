<?php
/**
 * API de Choferes
 * Obtiene la lista de choferes desde la base de datos
 */

session_start();
require_once '../config/db.php';
require_once '../config/session_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

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

$method = $_SERVER['REQUEST_METHOD'];

// ═══════════════════════════════════════════════════
// GET - Listar choferes
// ═══════════════════════════════════════════════════
if ($method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, nombre, telefono, comision_tipo, comision_porcentaje FROM choferes ORDER BY nombre");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
    }
    exit;
}

// ═══════════════════════════════════════════════════
// PUT - Actualizar tipo y porcentaje de comisión
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

        $stmt2 = $pdo->prepare("SELECT id, nombre, telefono, comision_tipo, comision_porcentaje FROM choferes WHERE id = ?");
        $stmt2->execute([$id]);
        echo json_encode($stmt2->fetch(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error al actualizar: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
?>