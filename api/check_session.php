<?php
session_start();

header('Content-Type: application/json');

// Verificar si hay sesión activa
if (isset($_SESSION['user_id']) && isset($_SESSION['usuario'])) {
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