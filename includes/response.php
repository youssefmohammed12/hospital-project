<?php
/**
 * HealthBridge — API Response & CORS helpers
 */

/**
 * Send standard CORS headers for API endpoints
 */
function sendCorsHeaders(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');

    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * Send a JSON response and exit
 * @param bool $success
 * @param array $data
 */
function jsonResponse(bool $success, array $data = []): void {
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

/**
 * Get and validate JSON input from request body
 * @return array
 */
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

/**
 * Execute a database operation with automatic error handling.
 * Wraps the callback in try/catch, logs errors, and returns JSON responses.
 *
 * @param callable $fn Function that receives a PDO instance and returns data
 * @param string $errorMessage User-facing error message on failure
 * @param int $successCode HTTP code for success (default 200)
 */
function withDB(callable $fn, string $errorMessage = 'Database operation failed.', int $successCode = 200): void {
    try {
        $db = getDB();
        $result = $fn($db);
        // If the function already sent a response (e.g., via jsonResponse), we're done
        if ($result !== null) {
            http_response_code($successCode);
            jsonResponse(true, $result);
        }
    } catch (Exception $e) {
        error_log('DB Error [' . debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['file'] . ']: ' . $e->getMessage());
        http_response_code(500);
        jsonResponse(false, ['message' => $errorMessage]);
    }
}
