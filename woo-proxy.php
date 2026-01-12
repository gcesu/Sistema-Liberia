<?php
/**
 * WooCommerce API Proxy
 * Protege las credenciales de la API de WooCommerce
 */

// Configuración de errores (desactivar en producción)
error_reporting(0);
ini_set('display_errors', 0);

// Headers de seguridad
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Solo permitir métodos específicos
$allowed_methods = ['GET', 'PUT', 'POST'];
if (!in_array($_SERVER['REQUEST_METHOD'], $allowed_methods)) {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Credenciales de WooCommerce (mantén esto PRIVADO)
define('WOO_SITE_URL', 'https://liberiaairportshuttle.com');
define('WOO_CONSUMER_KEY', 'ck_9ed293885403c0014233765ac48845870ad8d1f6');
define('WOO_CONSUMER_SECRET', 'cs_0e121a30c38b1948f60da3f10ef3744ef9d8be7e');

/**
 * Construye la URL completa de la API de WooCommerce
 */
function buildWooApiUrl($endpoint, $params = []) {
    $base = WOO_SITE_URL . '/wp-json/wc/v3/orders';
    
    // Si hay un ID de orden específico en el endpoint
    if ($endpoint && $endpoint !== '') {
        $base .= '/' . $endpoint;
    }
    
    // Agregar parámetros de query
    if (!empty($params)) {
        $base .= '?' . http_build_query($params);
    }
    
    return $base;
}

/**
 * Realiza la petición a WooCommerce
 */
function makeWooRequest($url, $method = 'GET', $body = null) {
    $auth = base64_encode(WOO_CONSUMER_KEY . ':' . WOO_CONSUMER_SECRET);
    
    $options = [
        'http' => [
            'method' => $method,
            'header' => [
                'Authorization: Basic ' . $auth,
                'Content-Type: application/json',
                'User-Agent: WooProxy/1.0'
            ],
            'ignore_errors' => true
        ]
    ];
    
    if ($body && in_array($method, ['POST', 'PUT'])) {
        $options['http']['content'] = is_string($body) ? $body : json_encode($body);
    }
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    // Extraer headers de respuesta
    $headers = [];
    if (isset($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (stripos($header, 'x-wp-total:') !== false) {
                $headers['x-wp-total'] = trim(explode(':', $header)[1]);
            }
            if (stripos($header, 'x-wp-totalpages:') !== false) {
                $headers['x-wp-totalpages'] = trim(explode(':', $header)[1]);
            }
        }
    }
    
    return [
        'body' => $response,
        'headers' => $headers,
        'http_response_header' => $http_response_header ?? []
    ];
}

// Obtener parámetros
$method = $_SERVER['REQUEST_METHOD'];
$order_id = $_GET['order_id'] ?? '';
$query_params = $_GET;
unset($query_params['order_id']); // Remover order_id de los params

// Construir URL
$api_url = buildWooApiUrl($order_id, $query_params);

// Obtener body para PUT/POST
$request_body = null;
if (in_array($method, ['PUT', 'POST'])) {
    $request_body = file_get_contents('php://input');
}

// Hacer la petición
$result = makeWooRequest($api_url, $method, $request_body);

// Reenviar headers importantes
if (isset($result['headers']['x-wp-total'])) {
    header('X-WP-Total: ' . $result['headers']['x-wp-total']);
}
if (isset($result['headers']['x-wp-totalpages'])) {
    header('X-WP-TotalPages: ' . $result['headers']['x-wp-totalpages']);
}

// Detectar código de estado HTTP
$status_code = 200;
if (isset($result['http_response_header'][0])) {
    preg_match('/\d{3}/', $result['http_response_header'][0], $matches);
    if (isset($matches[0])) {
        $status_code = (int)$matches[0];
    }
}
http_response_code($status_code);

// Devolver respuesta
echo $result['body'] ?: json_encode(['error' => 'No response from API']);
