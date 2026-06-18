<?php
/**
 * API de Administración de Usuarios
 * Solo accesible por usuarios con is_admin = 1
 */

session_start();
require_once '../config/db.php';
require_once '../config/session_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

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

// Verificar que sea admin
$stmtAdmin = $pdo->prepare("SELECT is_admin FROM usuarios WHERE id = ?");
$stmtAdmin->execute([$_SESSION['user_id']]);
$adminRow = $stmtAdmin->fetch(PDO::FETCH_ASSOC);

if (!$adminRow || !$adminRow['is_admin']) {
    http_response_code(403);
    echo json_encode(['error' => 'Acceso denegado']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ═══ GET - Listar usuarios ═══
if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, usuario, is_admin, permisos FROM usuarios ORDER BY id");
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($usuarios as &$u) {
        $u['is_admin'] = (int) $u['is_admin'];
        $u['permisos'] = $u['permisos'] ? json_decode($u['permisos'], true) : null;
    }

    echo json_encode($usuarios);
    exit;
}

// ═══ POST - Crear usuario ═══
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $usuario = trim($input['usuario'] ?? '');
    $contrasena = $input['contrasena'] ?? '';

    if (empty($usuario) || empty($contrasena)) {
        http_response_code(400);
        echo json_encode(['error' => 'Usuario y contraseña son requeridos']);
        exit;
    }

    // Verificar duplicado
    $check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
    $check->execute([$usuario]);
    if ($check->fetch()) {
        http_response_code(409);
        echo json_encode(['error' => 'El usuario ya existe']);
        exit;
    }

    $hash = password_hash($contrasena, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO usuarios (usuario, contrasena, is_admin, permisos) VALUES (?, ?, 0, NULL)");
    $stmt->execute([$usuario, $hash]);

    echo json_encode(['success' => true, 'id' => (int) $pdo->lastInsertId(), 'usuario' => $usuario]);
    exit;
}

// ═══ PUT - Actualizar usuario ═══
if ($method === 'PUT') {
    $userId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requerido']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    // Actualizar contraseña
    if (!empty($input['contrasena'])) {
        $hash = password_hash($input['contrasena'], PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE usuarios SET contrasena = ? WHERE id = ?")->execute([$hash, $userId]);
    }

    // Actualizar permisos
    if (array_key_exists('permisos', $input)) {
        $permisos = $input['permisos'] ? json_encode($input['permisos']) : null;
        $pdo->prepare("UPDATE usuarios SET permisos = ? WHERE id = ?")->execute([$permisos, $userId]);
    }

    echo json_encode(['success' => true]);
    exit;
}

// ═══ DELETE - Eliminar usuario ═══
if ($method === 'DELETE') {
    $userId = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if (!$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'ID requerido']);
        exit;
    }

    // No permitir eliminarse a sí mismo
    if ($userId === (int) $_SESSION['user_id']) {
        http_response_code(400);
        echo json_encode(['error' => 'No puedes eliminarte a ti mismo']);
        exit;
    }

    $pdo->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$userId]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);
?>