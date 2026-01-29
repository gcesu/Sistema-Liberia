<?php
/**
 * Endpoint para verificar si hay reservas nuevas (polling del frontend)
 * Retorna la cantidad de reservas creadas desde la última verificación del usuario
 */

session_start();
require_once '../config/db.php';

header('Content-Type: application/json');

// Verificar autenticación con token
$clientToken = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
$serverToken = $_SESSION['session_token'] ?? '';

if (!isset($_SESSION['user_id']) || empty($clientToken) || $clientToken !== $serverToken) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

try {
    // Obtener timestamp de última verificación del usuario (enviado por frontend)
    $lastCheck = $_GET['since'] ?? null;

    if (!$lastCheck) {
        // Si no hay timestamp, devolver 0 nuevas
        echo json_encode([
            'new_count' => 0,
            'server_time' => date('Y-m-d H:i:s'),
            'last_sync' => getLastSync($pdo)
        ]);
        exit;
    }

    // Contar reservas creadas después del último check
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM reservas 
        WHERE date_created > ?
    ");
    $stmt->execute([$lastCheck]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    // Obtener las IDs de las nuevas reservas (para mostrar en notificación)
    $stmt2 = $pdo->prepare("
        SELECT id, cliente_nombre, tipo_viaje, llegada_fecha, salida_fecha
        FROM reservas 
        WHERE date_created > ?
        ORDER BY date_created DESC
        LIMIT 5
    ");
    $stmt2->execute([$lastCheck]);
    $newReservations = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'new_count' => (int) $result['count'],
        'reservations' => $newReservations,
        'server_time' => date('Y-m-d H:i:s'),
        'last_sync' => getLastSync($pdo)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos']);
}

/**
 * Obtiene el timestamp de la última sincronización
 */
function getLastSync($pdo)
{
    try {
        $stmt = $pdo->query("SELECT valor FROM config WHERE clave = 'last_sync'");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['valor'] : null;
    } catch (Exception $e) {
        return null;
    }
}
?>