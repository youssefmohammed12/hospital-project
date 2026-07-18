<?php
/**
 * HealthBridge — Check Field Availability
 * 
 * Validates whether a given field value (email, national_id) 
 * is available for registration (not already taken).
 *
 * POST /api/auth/check-availability.php
 * Body: { "field": "email", "value": "user@example.com" }
 *    or: { "field": "national_id", "value": "3020915XXXXXXXX" }
 *
 * Returns: { "success": true, "available": true/false, "message": "..." }
 */

require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$field = trim($input['field'] ?? '');
$value = trim($input['value'] ?? '');

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

try {
    $db = getDB();
    
    if ($field === 'email') {
        // Validate email format
        $emailError = validateEmail($value);
        if ($emailError !== null) {
            http_response_code(400);
            jsonResponse(false, ['message' => $emailError]);
        }
        
        $taken = isEmailTaken($db, $value);
        $message = $taken ? 'This email is already registered.' : 'Email is available.';
        
    } elseif ($field === 'phone') {
        // Validate phone format
        $phoneError = validatePhone($value);
        if ($phoneError !== null) {
            http_response_code(400);
            jsonResponse(false, ['message' => $phoneError]);
        }
        
        $taken = isPhoneTaken($db, $value);
        $message = $taken ? 'This phone number is already registered.' : 'Phone number is available.';
        
    } elseif ($field === 'national_id') {
        // Validate national ID format (basic - full validation on submit)
        if (!preg_match('/^\d{14}$/', $value)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'National ID must be exactly 14 digits.']);
        }
        
        $taken = isNationalIdTaken($db, $value);
        $message = $taken ? 'This National ID is already registered.' : 'National ID is available.';
    }
    
    jsonResponse(true, [
        'available' => !$taken,
        'message' => $message,
    ]);
    
} catch (Exception $e) {
    error_log('Check Availability Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Availability check failed. Please try again.']);
}