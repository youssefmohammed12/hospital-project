<?php
/**
 * HealthBridge — Logout
 * Destroys the PHP session and clears the session cookie.
 */

require_once __DIR__ . '/../../includes/auth.php';
$_SESSION = [];

// Clear the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $params['path'],
        'domain'   => $params['domain'],
        'secure'   => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => 'Strict',
    ]);
}

session_destroy();
jsonResponse(true);


