<?php
/**
 * HealthBridge — API Response, request-origin, and security-header helpers.
 */

/**
 * Application-wide Content Security Policy. Keep synchronized with the Apache fallback in .htaccess.
 * The same policy protects PHP/API responses and standalone HTML print responses.
 */
const HEALTHBRIDGE_CONTENT_SECURITY_POLICY = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' https://cdn.jsdelivr.net https://unpkg.com; script-src-attr 'none'; style-src 'self' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com; style-src-attr 'none'; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com https://unpkg.com data:; img-src 'self' data: blob: https://images.unsplash.com https://*.basemaps.cartocdn.com https://unpkg.com; connect-src 'self'";

/**
 * Determine whether a supplied Origin exactly matches this request origin.
 * CORS is intentionally same-origin only; the frontend and API are served together.
 */
function isSameRequestOrigin(string $origin): bool
{
    $originParts = parse_url($origin);
    $hostHeader = $_SERVER['HTTP_HOST'] ?? '';
    $requestParts = $hostHeader !== '' ? parse_url('http://' . $hostHeader) : false;

    if (!is_array($originParts) || !is_array($requestParts)) {
        return false;
    }

    $originScheme = strtolower((string)($originParts['scheme'] ?? ''));
    $originHost = strtolower((string)($originParts['host'] ?? ''));
    $requestHost = strtolower((string)($requestParts['host'] ?? ''));

    if (!in_array($originScheme, ['http', 'https'], true) || $originHost === '' || $requestHost === '') {
        return false;
    }

    $requestIsHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $requestScheme = $requestIsHttps ? 'https' : 'http';

    $originPort = isset($originParts['port'])
        ? (int)$originParts['port']
        : ($originScheme === 'https' ? 443 : 80);

    $requestPort = isset($requestParts['port'])
        ? (int)$requestParts['port']
        : (int)($_SERVER['SERVER_PORT'] ?? ($requestIsHttps ? 443 : 80));

    return $originScheme === $requestScheme
        && $originHost === $requestHost
        && $originPort === $requestPort
        && !isset($originParts['user'])
        && !isset($originParts['pass'])
        && !isset($originParts['query'])
        && !isset($originParts['fragment'])
        && (!isset($originParts['path']) || $originParts['path'] === '' || $originParts['path'] === '/');
}

/**
 * Send common JSON/security headers and reject cross-origin browser requests.
 * No Access-Control-Allow-Origin header is sent: this application uses same-origin
 * pages and API requests. State-changing requests are protected by Origin and
 * Fetch Metadata checks in addition to SameSite cookies.
 */
function sendCorsHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('Content-Security-Policy: ' . HEALTHBRIDGE_CONTENT_SECURITY_POLICY);
    header('Cache-Control: no-store, private');
    header('Pragma: no-cache');

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin !== '' && !isSameRequestOrigin($origin)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cross-origin requests are not allowed.']);
        exit;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $isStateChanging = !in_array($method, ['GET', 'HEAD', 'OPTIONS'], true);
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));

    if ($isStateChanging && $fetchSite !== '' && $fetchSite !== 'same-origin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cross-origin state changes are not allowed.']);
        exit;
    }

    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Send a JSON response and terminate the request.
 */
function jsonResponse(bool $success, array $data = []): void
{
    echo json_encode(
        array_merge(['success' => $success], $data),
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/**
 * Identify a MySQL/MariaDB duplicate-key constraint violation without exposing
 * database error text or submitted identifier values to the caller.
 */
function isDuplicateKeyViolation(Throwable $error): bool
{
    return $error instanceof PDOException
        && (string)$error->getCode() === '23000'
        && (int)($error->errorInfo[1] ?? 0) === 1062;
}

/**
 * Get a JSON request body. Invalid or non-object JSON returns an empty array.
 */
function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Execute a database operation with consistent error handling.
 */
function withDB(callable $fn, string $errorMessage = 'Database operation failed.', int $successCode = 200): void
{
    try {
        $db = getDB();
        $result = $fn($db);

        if ($result !== null) {
            http_response_code($successCode);
            jsonResponse(true, $result);
        }
    } catch (Throwable $e) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $source = $trace[1]['file'] ?? 'unknown';
        error_log('DB Error [' . $source . ']: ' . $e->getMessage());
        http_response_code(500);
        jsonResponse(false, ['message' => $errorMessage]);
    }
}
