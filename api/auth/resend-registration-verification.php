<?php
/**
 * HealthBridge — Resend registration email verification
 *
 * Always returns the same public success response for known/unknown,
 * conflicting, verified, expired and otherwise ineligible addresses.
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
$emailValue = $data['email'] ?? '';
if (!is_string($emailValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid email address is required.']);
}

$email = strtolower(trim($emailValue));
if ($email === '' || strlen($email) > 150 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid email address is required.']);
}

try {
    $limiter = new AuthAbuseRateLimiter();
    $retryAfter = $limiter->recordRegistrationVerificationResend(
        $email,
        (string)($_SERVER['REMOTE_ADDR'] ?? '')
    );
} catch (Throwable $error) {
    error_log('Registration verification resend limiter failed: ' . get_class($error));
    http_response_code(503);
    jsonResponse(false, ['message' => 'Verification email service is temporarily unavailable. Please try again later.']);
}

if ($retryAfter > 0) {
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    jsonResponse(false, ['message' => 'Too many requests. Please try again later.']);
}

try {
    $service = new RegistrationIntakeService(getDB(), HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_AUTH_SCHEMA'));
    $service->resendVerificationEmail($email);
    jsonResponse(true, [
        'message' => 'If a registration is waiting for email verification, a new verification message will be sent to that address.',
    ]);
} catch (Throwable $error) {
    error_log('Registration verification resend failed: ' . get_class($error));
    http_response_code(503);
    jsonResponse(false, ['message' => 'Verification email service is temporarily unavailable. Please try again later.']);
}
