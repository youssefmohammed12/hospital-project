<?php
/**
 * HealthBridge — Authentication & Main Bootstrap Loader
 * Centralizes session start, auth helpers, and includes all sub-dependencies.
 */

// Suppress PHP notices/warnings from polluting JSON API responses
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT);

// Load all sub-includes in logical order
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

// Send CORS headers and start session once
sendCorsHeaders();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Get the current logged-in user's ID from session, or null if not logged in.
 */
function getCurrentUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get the current user's role from session, or empty string if not set.
 */
function getCurrentRole(): string {
    return $_SESSION['role'] ?? '';
}

/**
 * Verify the user is logged in. Returns user data or exits with 401.
 *
 * @return array User data with id, role, name
 */
function requireAuth(): array {
    $userId = getCurrentUserId();
    if (!$userId) {
        http_response_code(401);
        jsonResponse(false, ['message' => 'Please log in to access this resource.']);
    }
    return [
        'id' => $userId,
        'role' => getCurrentRole(),
        'name' => $_SESSION['name'] ?? 'User'
    ];
}
