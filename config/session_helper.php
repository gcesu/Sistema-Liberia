<?php
/**
 * Helper de sesión: timeout por inactividad.
 *
 * Uso: llamar a `validateAndRefreshSession()` DESPUÉS de session_start()
 * en cualquier endpoint que use $_SESSION.
 *
 * - Si la sesión existe y han pasado más de SESSION_TIMEOUT segundos sin
 *   actividad → destruye la sesión y devuelve false.
 * - Si la sesión es válida → renueva el timestamp `last_activity` y devuelve true.
 * - Si no hay sesión activa → devuelve true sin hacer nada (para no romper
 *   endpoints públicos).
 */

if (!defined('SESSION_TIMEOUT')) {
    // 3600 segundos = 1 hora de inactividad
    define('SESSION_TIMEOUT', 3600);
}

function validateAndRefreshSession()
{
    // Si no hay usuario logueado, no hay nada que validar
    if (!isset($_SESSION['user_id'])) {
        return true;
    }

    // Verificar timeout de inactividad
    if (isset($_SESSION['last_activity'])) {
        $elapsed = time() - $_SESSION['last_activity'];
        if ($elapsed > SESSION_TIMEOUT) {
            // Sesión expirada — limpiar todo
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            session_destroy();
            return false;
        }
    }

    // Renovar timestamp de actividad
    $_SESSION['last_activity'] = time();
    return true;
}
