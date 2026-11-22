<?php
/**
 * HealthBridge — Confirm registration email ownership
 *
 * The bearer token is consumed only by this explicit same-origin POST.
 * Loading the verification page or following its link does not change state.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AuthAbuseRateLimiter.php';
require_once __DIR__ . '/../../services/RegistrationIntakeService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();
$tokenValue = $data['token'] ?? '';
if (!is_string($tokenValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'This verification link is invalid or has expired. Please request a new one.']);
}

$token = strtolower(trim($tokenValue));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'This verification link is invalid or has expired. Please request a new one.']);
}

$tokenHash = hash('sha256', $token);
try {
    $limiter = new AuthAbuseRateLimiter();
    $retryAfter = $limiter->recordRegistrationVerificationConfirmation(
        $tokenHash,
        (string)($_SERVER['REMOTE_ADDR'] ?? '')
    );
} catch (Throwable $error) {
    error_log('Registration verification confirmation limiter failed: ' . get_class($error));
    http_response_code(503);
    jsonResponse(false, ['message' => 'Email verification is temporarily unavailable. Please try again later.']);
}

if ($retryAfter > 0) {
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    jsonResponse(false, ['message' => 'Too many attempts. Please try again later.']);
}

try {
    $service = new RegistrationIntakeService(getDB(), HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_AUTH_SCHEMA'));
    if (!$service->confirmRegistrationEmail($token)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'This verification link is invalid, expired, or has already been used. Please request a new one.']);
    }

    jsonResponse(true, [
        'message' => 'Your email address is verified. Your account is now being prepared. You can sign in shortly.',
    ]);
} catch (Throwable $error) {
    error_log('Registration email confirmation failed: ' . get_class($error));
    http_response_code(503);
    jsonResponse(false, ['message' => 'Email verification is temporarily unavailable. Please try again later.']);
}
