<?php
/**
 * HealthBridge — Admin: Add Patient
 * Creates the patient account and normalized profile aggregate atomically.
 * POST { name, email, password, phone? }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/PatientManagementRepository.php';

$admin = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$name = is_string($input['name'] ?? null) ? trim($input['name']) : '';
$email = is_string($input['email'] ?? null) ? trim($input['email']) : '';
$password = is_string($input['password'] ?? null) ? $input['password'] : '';
$phone = is_string($input['phone'] ?? null) ? trim($input['phone']) : '';

$errors = [];
if ($name === '') {
    $errors[] = 'Patient name is required.';
} elseif (mb_strlen($name, 'UTF-8') < 2 || mb_strlen($name, 'UTF-8') > 100) {
    $errors[] = 'Patient name must be between 2 and 100 characters.';
}
$emailError = validateEmail($email);
if ($emailError !== null) {
    $errors[] = $emailError;
}
if ($password === '') {
    $errors[] = 'Password is required.';
} else {
    $passwordValidation = validatePassword($password);
    if (!$passwordValidation['valid']) {
        $errors = array_merge($errors, $passwordValidation['errors']);
    }
}
if (isset($input['phone']) && !is_string($input['phone'])) {
    $errors[] = 'Phone number must be text.';
} elseif ($phone !== '') {
    $phoneError = validatePhone($phone);
    if ($phoneError !== null) {
        $errors[] = $phoneError;
    }
}
if ($errors !== []) {
    http_response_code(400);
    jsonResponse(false, ['message' => implode(' ', $errors), 'errors' => $errors]);
}

try {
    $db = getDB();
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }
    $patientNumber = generatePatientNumber($db);

    // Live runtime remains V1 until the approved cutover.
    $management = new PatientManagementRepository($db);
    $patientId = $management->createPatient(
        $name,
        $email,
        $passwordHash,
        $patientNumber,
        $phone !== '' ? $phone : null,
        (int)$admin['id']
    );

    jsonResponse(true, [
        'message' => 'Patient added successfully.',
        'patient' => [
            'id' => $patientId,
            'name' => $name,
            'email' => $email,
            'role' => 'patient',
            'patient_number' => $patientNumber,
        ],
    ]);
} catch (DomainException $e) {
    $message = $e->getMessage();
    http_response_code(str_contains($message, 'already exists') || str_contains($message, 'already registered') ? 409 : 403);
    jsonResponse(false, ['message' => $message]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isDuplicateKeyViolation($e)) {
        http_response_code(409);
        jsonResponse(false, ['message' => 'The submitted email, phone number, or patient number is already in use.']);
    }
    error_log('Add Patient Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to add patient. Please try again.']);
}
