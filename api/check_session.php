<?php
session_start();
require_once '../config/session_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Validar timeout por inactividad (1 hora)
if (!validateAndRefreshSession()) {
    echo json_encode(['authenticated' => false, 'reason' => 'session_expired']);
    exit;
}

// Obtener token del header
$headers = getallheaders();
$clientToken = $headers['X-Session-Token'] ?? '';

// Verificar si hay sesión activa Y el token coincide
if (isset($_SESSION['user_id']) && isset($_SESSION['usuario']) && isset($_SESSION['session_token'])) {
    // Si se envía token, debe coincidir
    if (!empty($clientToken) && $clientToken !== $_SESSION['session_token']) {
        // Token no coincide - sesión inválida
        echo json_encode(['authenticated' => false]);
        exit;
    }

    // Obtener permisos y rol del usuario
    require_once '../config/db.php';
    $stmtPerms = $pdo->prepare("SELECT is_admin, permisos FROM usuarios WHERE id = ?");
    $stmtPerms->execute([$_SESSION['user_id']]);
    $userRow = $stmtPerms->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'authenticated' => true,
        'user_id' => $_SESSION['user_id'],
        'usuario' => $_SESSION['usuario'],
        'is_admin' => (int) ($userRow['is_admin'] ?? 0),
        'permisos' => $userRow['permisos'] ? json_decode($userRow['permisos'], true) : null
    ]);
} else {
    echo json_encode([
        'authenticated' => false
    ]);
}
?>