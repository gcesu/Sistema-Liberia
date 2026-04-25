<?php
/**
 * Sincronización de reservas específicas por ID desde WooCommerce
 * Uso: api/sync_by_id.php?ids=32098 o api/sync_by_id.php?ids=32098,32099,32100
 *
 * Reutiliza las funciones de sync.php (saveOrderToDB, etc.)
 * Para evitar que sync.php se ejecute al incluirlo, definimos una constante de guarda.
 */

define('SYNC_BY_ID', true);

session_start();
require_once '../config/db.php';
require_once '../config/env.php';

header('Content-Type: application/json');

// Verificar autenticación
if (!isset($_SESSION['user_id'])) {
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if ($token) {
        $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE session_token = ?");
        $stmt->execute([$token]);
        if (!$stmt->fetch()) {
            http_response_code(401);
            echo json_encode(['error' => 'No autorizado']);
            exit;
        }
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'No autorizado']);
        exit;
    }
}

// Validar parámetro ids
if (empty($_GET['ids'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Parámetro ids requerido. Uso: ?ids=32098 o ?ids=32098,32099,32100']);
    exit;
}

$ids = array_filter(array_map('intval', explode(',', $_GET['ids'])));
if (empty($ids)) {
    http_response_code(400);
    echo json_encode(['error' => 'IDs inválidos']);
    exit;
}

// Configuración de WooCommerce
$wooUrl = env('WOO_SITE_URL');
$consumerKey = env('WOO_CONSUMER_KEY');
$consumerSecret = env('WOO_CONSUMER_SECRET');

if (!$wooUrl || !$consumerKey || !$consumerSecret) {
    http_response_code(500);
    echo json_encode(['error' => 'Credenciales de WooCommerce no configuradas']);
    exit;
}

// Incluir funciones de sync.php (saveOrderToDB, esLineItemInterno, saveTripSync)
// La constante SYNC_BY_ID evita que sync.php ejecute su lógica principal
require_once 'sync.php';

$results = [];
$synced = 0;
$errors = [];

foreach ($ids as $orderId) {
    $apiUrl = "$wooUrl/wp-json/wc/v3/orders/$orderId";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, "$consumerKey:$consumerSecret");
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        $errors[] = "Orden $orderId: Error HTTP $httpCode";
        $results[] = ['id' => $orderId, 'status' => 'error', 'message' => "HTTP $httpCode"];
        continue;
    }

    $order = json_decode($response, true);
    if (!$order || !isset($order['id'])) {
        $errors[] = "Orden $orderId: Respuesta inválida";
        $results[] = ['id' => $orderId, 'status' => 'error', 'message' => 'Respuesta inválida'];
        continue;
    }

    try {
        saveOrderToDB($pdo, $order);
        $synced++;
        $results[] = [
            'id' => $orderId,
            'status' => 'ok',
            'order_status' => $order['status'],
            'cliente' => trim(($order['billing']['first_name'] ?? '') . ' ' . ($order['billing']['last_name'] ?? ''))
        ];
    } catch (Exception $e) {
        $errors[] = "Orden $orderId: " . $e->getMessage();
        $results[] = ['id' => $orderId, 'status' => 'error', 'message' => $e->getMessage()];
    }
}

echo json_encode([
    'success' => true,
    'message' => "Sincronización completada: $synced de " . count($ids) . " reservas",
    'synced' => $synced,
    'total_requested' => count($ids),
    'errors' => $errors,
    'results' => $results
]);
