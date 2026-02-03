<?php
/**
 * API de Choferes
 * Obtiene la lista de choferes desde la base de datos
 */

session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
    // Seleccionar solo los campos básicos
    $stmt = $pdo->query("SELECT id, nombre FROM choferes ORDER BY nombre");
    $choferes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($choferes);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos: ' . $e->getMessage()]);
}
?>