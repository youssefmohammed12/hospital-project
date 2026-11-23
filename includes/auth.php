<?php
/**
 * HealthBridge — Authentication & Main Bootstrap Loader
 * Centralizes session startup, session policy, and authenticated-user validation.
 */

// Do not allow PHP diagnostics to leak into JSON API responses.
ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_STRICT);

// Load shared dependencies before starting the session.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/permissions.php';

// Session policy: 30-minute idle timeout, 8-hour absolute lifetime.
if (session_status() === PHP_SESSION_NONE) {
    $requestIsHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $requestIsHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', '28800');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $requestIsHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Set response policy and same-origin checks before any endpoint can emit a response.
sendCorsHeaders();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Clear the current session and expire its cookie.
 */
function destroyAuthSession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    // The JS-readable session hint (set at login) dies with the session
    // it mirrors, whatever path destroyed the session.
    setcookie('hb_alive', '', [
        'expires' => time() - 42000,
        'path' => '/',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);

    session_destroy();
}

/**
 * Return verification columns for a user lookup. This lets existing installs
 * keep their current authentication working until Phase 17 is applied; once the
 * columns exist, the real persisted requirement is always enforced.
 */
function authEmailVerificationSelectColumns(PDO $db): string
{
    static $columnsAvailable = null;

    if ($columnsAvailable === null) {
        $stmt = $db->query(
            "SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'users'
               AND COLUMN_NAME IN ('email_verification_required', 'email_verified_at')"
        );
        $columnsAvailable = (int)$stmt->fetchColumn() === 2;
    }

    return $columnsAvailable
        ? 'email_verification_required, email_verified_at'
        : '0 AS email_verification_required, NULL AS email_verified_at';
}

/**
 * Get the current logged-in user's ID from session, or null if not logged in.
 */
function getCurrentUserId(): ?int
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $userId = filter_var($_SESSION['user_id'], FILTER_VALIDATE_INT);
    return $userId !== false && $userId > 0 ? (int)$userId : null;
}

/**
 * Get the current user's role from session, or an empty string if not set.
 */
function getCurrentRole(): string
{
    return is_string($_SESSION['role'] ?? null) ? $_SESSION['role'] : '';
}

/**
 * Verify authentication, enforce session lifetime, and refresh account state.
 *
 * A database lookup on each authenticated request ensures deactivated users and
 * changed roles do not continue using stale session values.
 *
 * @return array{id:int, role:string, name:string, email:string}
 */
function requireAuth(): array
{
    $userId = getCurrentUserId();
    if ($userId === null) {
        http_response_code(401);
        jsonResponse(false, ['message' => 'Please log in to access this resource.']);
    }

    $now = time();
    $createdAt = (int)($_SESSION['auth_created_at'] ?? $now);
    $lastActivity = (int)($_SESSION['auth_last_activity'] ?? $createdAt);

    // Reject future timestamps and expired sessions.
    if (
        $createdAt > $now + 60
        || $lastActivity > $now + 60
        || ($now - $lastActivity) > 1800
        || ($now - $createdAt) > 28800
    ) {
        destroyAuthSession();
        http_response_code(401);
        jsonResponse(false, ['message' => 'Your session has expired. Please log in again.']);
    }

    try {
        $db = getDB();
        $stmt = $db->prepare(
            'SELECT id, name, email, role, is_active, session_version, '
            . authEmailVerificationSelectColumns($db) . '
             FROM users
             WHERE id = ?
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('Authentication verification error: ' . $e->getMessage());
        http_response_code(503);
        jsonResponse(false, ['message' => 'Unable to verify your session right now. Please try again.']);
    }

    if (!$user) {
        destroyAuthSession();
        http_response_code(401);
        jsonResponse(false, ['message' => 'Your account is no longer available. Please log in again.']);
    }

    if (!(int)$user['is_active']) {
        destroyAuthSession();
        http_response_code(403);
        jsonResponse(false, ['message' => 'Your account has been disabled. Please contact the hospital administrator.']);
    }

    if (
        (int)($user['email_verification_required'] ?? 0) === 1
        && empty($user['email_verified_at'])
    ) {
        destroyAuthSession();
        http_response_code(403);
        jsonResponse(false, ['message' => 'This account requires email verification before access is allowed.']);
    }

    if (!in_array($user['role'], ['patient', 'doctor', 'admin'], true)) {
        destroyAuthSession();
        http_response_code(403);
        jsonResponse(false, ['message' => 'This account does not have a valid access role.']);
    }

    // Credential version mismatch revokes every session created before a password reset/change.
    $sessionVersion = filter_var($_SESSION['auth_session_version'] ?? null, FILTER_VALIDATE_INT);
    if ($sessionVersion === false || $sessionVersion < 1 || $sessionVersion !== (int)$user['session_version']) {
        destroyAuthSession();
        http_response_code(401);
        jsonResponse(false, ['message' => 'Your credentials have changed. Please log in again.']);
    }

    // Refresh mutable identity/role values from the database, never trust stale session role.
    $_SESSION['auth_session_version'] = (int)$user['session_version'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['name'] = $user['name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['auth_created_at'] = $createdAt;
    $_SESSION['auth_last_activity'] = $now;

    return [
        'id' => (int)$user['id'],
        'role' => $user['role'],
        'name' => $user['name'],
        'email' => $user['email'],
    ];
}
