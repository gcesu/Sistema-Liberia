<?php
/**
 * Carga variables de entorno desde archivo .env
 * Usar en Bluehost donde no hay acceso a configurar variables de entorno del servidor
 */

function loadEnv($path)
{
    if (!file_exists($path)) {
        return false;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignorar comentarios
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parsear KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remover comillas si las hay
            $value = trim($value, '"\'');

            // Establecer como variable de entorno
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
    return true;
}

// Cargar .env desde la raíz del proyecto
$envPath = dirname(__DIR__) . '/.env';
loadEnv($envPath);

/**
 * Obtiene una variable de entorno con valor por defecto
 */
function env($key, $default = null)
{
    $value = getenv($key);
    if ($value === false) {
        return $default;
    }
    return $value;
}
?>