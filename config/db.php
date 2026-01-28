<?php
/**
 * Conexión a la base de datos
 * Las credenciales se cargan desde el archivo .env
 */

// Cargar variables de entorno
require_once __DIR__ . '/env.php';

// Detectar ambiente automáticamente
// En producción (Bluehost) usamos localhost
// En desarrollo local (Windows) usamos la IP del servidor
$isProduction = (PHP_OS_FAMILY !== 'Windows');
$host = $isProduction ? 'localhost' : env('DB_HOST', 'localhost');

$dbname = env('DB_NAME');
$username = env('DB_USER');
$password = env('DB_PASS');

// Validar que las credenciales existan
if (!$dbname || !$username || !$password) {
    die("Error: Credenciales de base de datos no configuradas. Verifica el archivo .env");
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}
?>
