<?php
/**
 * HealthBridge — User Login
 * Validates credentials and creates a hardened PHP session.
 * POST { email, password } => { user: { id, name, email, role } }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AuthAbuseRateLimiter.php';
require_once __DIR__ . '/../../services/Repositories/AuthCredentialRepository.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();
$emailValue = $data['email'] ?? '';
$passwordValue = $data['password'] ?? '';

if (!is_string($emailValue) || !is_string($passwordValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Email and password must be submitted as text.']);
}

$email = trim($emailValue);
$password = $passwordValue;

// Basic validation
if ($email === '' || $password === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Email and password are required.']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid email address format.']);
}

// Throttle by source IP and a normalized HMAC of the submitted email.
// The limiter stores no plaintext email and ignores untrusted forwarding headers.
try {
    $limiter = new AuthAbuseRateLimiter();
    $retryAfter = $limiter->recordLoginAttempt(
        $email,
        (string)($_SERVER['REMOTE_ADDR'] ?? '')
    );
} catch (Throwable $e) {
    // Fail soft: when the limiter cannot reach its state storage, let this
    // attempt proceed without throttling rather than locking every user
    // out. The failure is logged; normal limiting resumes by itself once
    // the storage is healthy again.
    error_log('Login Rate Limiter Error: ' . $e->getMessage());
    $retryAfter = 0;
}

if ($retryAfter > 0) {
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    jsonResponse(false, ['message' => 'Too many sign-in attempts. Please try again later.']);
}

try {
    $db = getDB();
    // Live HTTP routing stays explicitly on v1 until the approved cutover.
    $credentials = new AuthCredentialRepository($db);
    $user = $credentials->findLoginUser($email);
} catch (Throwable $e) {
    error_log('Login DB Error: ' . $e->getMessage());
    http_response_code(503);
    jsonResponse(false, ['message' => 'Login is temporarily unavailable. Please try again.']);
}

// Verify one password hash for every valid login request. Unknown emails use a
// dummy bcrypt hash at cost 12, matching the current stored-hash distribution.
// Recheck this cost if password-hashing policy or existing hashes are upgraded.
$dummyPasswordHash = '$2y$12$6e.ryu9aVHeIpUSmZ62vAumUzpI7vLWAC8czy48GYiIxmZ9hafm.C';
$storedPasswordHash = is_array($user) && is_string($user['password_hash'] ?? null)
    ? $user['password_hash']
    : $dummyPasswordHash;
$passwordMatches = password_verify($password, $storedPasswordHash);

// Keep unknown and disabled accounts indistinguishable from wrong credentials.
if (!$user || !$passwordMatches || !(int)$user['is_active']) {
    http_response_code(401);
    jsonResponse(false, ['message' => 'Invalid email or password.']);
}

if (
    (int)($user['email_verification_required'] ?? 0) === 1
    && empty($user['email_verified_at'])
) {
    http_response_code(403);
    jsonResponse(false, [
        'code' => 'EMAIL_VERIFICATION_REQUIRED',
        'message' => 'Please verify your email address before signing in. Check your inbox for the verification link.',
    ]);
}

// Clear any previous identity, then rotate the session identifier after authentication.
$_SESSION = [];
if (!session_regenerate_id(true)) {
    error_log('Login Error: PHP could not regenerate the session ID.');
    http_response_code(500);
    jsonResponse(false, ['message' => 'Unable to start a secure session. Please try again.']);
}

$now = time();
$_SESSION['user_id'] = (int)$user['id'];
$_SESSION['role'] = $user['role'];
$_SESSION['name'] = $user['name'];
$_SESSION['email'] = $user['email'];
$_SESSION['auth_created_at'] = $now;
$_SESSION['auth_last_activity'] = $now;
$_SESSION['auth_session_version'] = (int)$user['session_version'];

// A data-free, JS-readable companion to the HttpOnly session cookie, with
// the same browser-session lifetime. Public pages check for its presence
// before firing any authenticated request, so a stale cached identity in
// localStorage can no longer trigger requests that only answer 401. It
// carries no identity information; the session cookie stays HttpOnly.
$hintSecure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
setcookie('hb_alive', '1', [
    'expires' => 0,
    'path' => '/',
    'secure' => $hintSecure,
    'httponly' => false,
    'samesite' => 'Lax',
]);

jsonResponse(true, [
    'user' => [
        'id' => (int)$user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
    ],
]);
