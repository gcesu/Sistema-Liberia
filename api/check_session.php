<?php
session_start();

header('Content-Type: application/json');

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

    echo json_encode([
        'authenticated' => true,
        'user_id' => $_SESSION['user_id'],
        'usuario' => $_SESSION['usuario']
    ]);
} else {
    echo json_encode([
        'authenticated' => false
    ]);
}
?>