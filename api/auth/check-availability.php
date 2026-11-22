<?php
/**
 * HealthBridge — Check Field Availability
 * 
 * Validates registration field formats without disclosing account existence.
 * Retained as a compatibility endpoint; current frontend no longer calls it.
 *
 * POST /api/auth/check-availability.php
 * Body: { "field": "email", "value": "user@example.com" }
 *    or: { "field": "phone", "value": "01012345678" }
 *    or: { "field": "national_id", "value": "3020915XXXXXXXX" }
 *
 * Returns a format-valid response without revealing account existence. The available field is null until registration submission.
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Only accept POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$fieldValue = $input['field'] ?? null;
$valueInput = $input['value'] ?? null;
if (!is_string($fieldValue) || !is_string($valueInput)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Field and value must be text values.']);
}

$field = trim($fieldValue);
$value = trim($valueInput);

// Validate field parameter
$allowedFields = ['email', 'national_id', 'phone'];
if (!in_array($field, $allowedFields, true)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid field. Supported: email, national_id, phone.']);
}

// Validate value is not empty
if ($value === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => ucfirst($field) . ' value is required.']);
}

if ($field === 'email') {
    $emailError = validateEmail($value);
    if ($emailError !== null) {
        http_response_code(400);
        jsonResponse(false, ['message' => $emailError]);
    }
} elseif ($field === 'phone') {
    $phoneError = validatePhone($value);
    if ($phoneError !== null) {
        http_response_code(400);
        jsonResponse(false, ['message' => $phoneError]);
    }
} elseif ($field === 'national_id' && !preg_match('/^\d{14}$/', $value)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'National ID must be exactly 14 digits.']);
}

// Do not disclose whether an identifier belongs to an existing account.
// Uniqueness is enforced during registration, where duplicate details receive
// a generic response. Keep this endpoint for compatibility with older clients.
jsonResponse(true, [
    'available' => null,
    'message' => 'Availability is validated securely when you submit registration.',
]);