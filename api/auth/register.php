<?php
/**
 * HealthBridge — Patient Registration API
 * 
 * Accepts patient registration through a two-step flow.
 * Step 1 validates only; Step 2 validates, hashes and stores an encrypted intake job.
 * The CLI worker creates the patient aggregate in one database transaction.
 * 
 * POST /api/auth/register.php
 * 
 * Step 1 (validation only — no DB insert):
 * Body: {
 *   "step": 1,
 *   "first_name": "...",
 *   "last_name": "...",
 *   "email": "...",
 *   "phone": "...",
 *   "password": "...",
 *   "confirm_password": "..."
 * }
 * 
 * Step 2 (encrypted intake — account creation is asynchronous):
 * Body: {
 *   "step": 2,
 *   "first_name": "...",
 *   "last_name": "...",
 *   "email": "...",
 *   "phone": "...",
 *   "password": "...",
 *   "national_id": "...",
 *   "date_of_birth": "YYYY-MM-DD",
 *   "gender": "...",
 *   "governorate": "...",
 *   "city": "...",
 *   "address": "...",
 *   "blood_type": "...",
 *   "emergency_contact_name": "...",
 *   "emergency_contact_phone": "...",
 *   "emergency_contact_rel": "...",
 *   "allergies": "...",
 *   "chronic_diseases": "...",
 *   "current_medications": "...",
 *   "insurance_provider": "...",
 *   "insurance_number": "..."
 * }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AuthAbuseRateLimiter.php';
require_once __DIR__ . '/../../services/RegistrationProfileValidator.php';
require_once __DIR__ . '/../../services/RegistrationIntakeService.php';

header('Content-Type: application/json');

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$step = parsePositiveInt($input['step'] ?? null);

if (!in_array($step, [1, 2], true)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid step. Must be 1 or 2.']);
}

// Reject arrays/objects before trim(), validation helpers, or password hashing.
$stringFields = [
    'first_name', 'last_name', 'email', 'phone', 'password', 'confirm_password',
    'national_id', 'date_of_birth', 'gender', 'governorate', 'city', 'address',
    'blood_type', 'emergency_contact_name', 'emergency_contact_phone',
    'emergency_contact_rel', 'allergies', 'chronic_diseases',
    'current_medications', 'insurance_provider', 'insurance_number',
];
foreach ($stringFields as $field) {
    if (array_key_exists($field, $input) && !is_string($input[$field])) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Registration fields must be submitted as text.']);
    }
}

// Throttle by source IP and HMACs of the submitted identity fields. Raw identifiers
// are not stored. Use REMOTE_ADDR only; untrusted forwarding headers are ignored.
try {
    $limiter = new AuthAbuseRateLimiter();
    $retryAfter = $limiter->recordRegistrationAttempt(
        $input,
        $step,
        (string)($_SERVER['REMOTE_ADDR'] ?? '')
    );
} catch (Throwable $e) {
    // Fail soft, mirroring login (PHP audit, 23 Nov 2026, finding M3): when
    // the limiter cannot reach its state storage, let this attempt proceed
    // without throttling rather than taking registration down for everyone.
    // The failure is logged; normal limiting resumes by itself once the
    // storage is healthy again.
    error_log('Authentication Abuse Rate Limiter Error: ' . $e->getMessage());
    $retryAfter = 0;
}

if ($retryAfter > 0) {
    http_response_code(429);
    header('Retry-After: ' . $retryAfter);
    jsonResponse(false, ['message' => 'Too many registration attempts. Please try again later.']);
}

try {
    if ($step === 1) {
        handleStep1($input);
    } else {
        handleStep2($input);
    }
} catch (Throwable $e) {
    error_log('Register Error: ' . get_class($e));
    http_response_code(503);
    jsonResponse(false, ['message' => 'Registration is temporarily unavailable. Please try again later.']);
}

/**
 * Return a generic acknowledgment after the encrypted registration request is queued.
 * Account creation and identifier conflict classification are handled by the CLI worker.
 */
function respondRegistrationRequestReceived(): void
{
    jsonResponse(true, [
        'message' => 'Your registration request was received. If a new registration can be completed, we will send an email verification link. Your patient account will be created only after you confirm that link.',
    ]);
}

/**
 * Handle Step 1: Account Information — VALIDATION ONLY
 * 
 * Validates required field formats and password rules, then returns success.
 * It deliberately does not check account uniqueness or write to the database.
 * The validated data is stored on the client and submitted with Step 2.
 */
function handleStep1(array $input): void {
    // Sanitize inputs
    $firstName = trim($input['first_name'] ?? '');
    $lastName = trim($input['last_name'] ?? '');
    $email = trim($input['email'] ?? '');
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';
    
    // --- Validation ---
    $errors = [];
    
    // First name
    $fnError = validateRequired($firstName, 'First name');
    if ($fnError) $errors[] = $fnError;
    
    // Last name
    $lnError = validateRequired($lastName, 'Last name');
    if ($lnError) $errors[] = $lnError;
    
    // Validate identifier formats only. Uniqueness checks are deliberately
    // deferred to Step 2 so this validation-only step cannot enumerate accounts.
    $emailError = validateEmail($email);
    if ($emailError) {
        $errors[] = $emailError;
    }
    
    $phoneError = validatePhone($phone);
    if ($phoneError) {
        $errors[] = $phoneError;
    }
    
    // Password
    $pwValidation = validatePassword($password);
    if (!$pwValidation['valid']) {
        foreach ($pwValidation['errors'] as $err) {
            $errors[] = $err;
        }
    }
    
    // Confirm password
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match.';
    }
    
    if (!empty($errors)) {
        http_response_code(400);
        jsonResponse(false, ['message' => implode(' ', $errors), 'errors' => $errors]);
    }
    
    // --- Validation passed — no DB insert ---
    jsonResponse(true, [
        'message' => 'Validation passed. Please complete your patient profile.',
        'next_step' => 2,
    ]);
}

/**
 * Handle Step 2: validate, hash and enqueue one encrypted registration profile.
 * The CLI worker owns the atomic account, clinical profile, audit and outbox transaction.
 */
function handleStep2(array $input): void
{
    $validated = RegistrationProfileValidator::validateHttp($input);
    if ($validated['errors'] !== []) {
        http_response_code(400);
        jsonResponse(false, [
            'message' => implode(' ', $validated['errors']),
            'errors' => $validated['errors'],
        ]);
    }

    $password = (string)$input['password'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    unset($password, $input['password'], $input['confirm_password']);

    if (!is_string($passwordHash) || $passwordHash === '') {
        throw new RuntimeException('Registration password hashing failed.');
    }

    $profile = $validated['data'];
    $profile['password_hash'] = $passwordHash;
    unset($validated, $passwordHash);

    // This HTTP request performs no identity lookup and creates no account rows.
    // The worker owns duplicate handling and the complete patient transaction.
    $intake = new RegistrationIntakeService(getDB(), HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_AUTH_SCHEMA'));
    $intake->enqueue($profile);
    unset($profile, $intake);

    respondRegistrationRequestReceived();
}
