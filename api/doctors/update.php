<?php
/**
 * HealthBridge — Admin: Update Doctor Details
 * Updates identity/contact fields and optional department assignment.
 * POST { id, name, email, phone?, department_id? }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/DoctorManagementRepository.php';

$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();
$id = parsePositiveInt($data['id'] ?? null);
$nameValue = $data['name'] ?? '';
$emailValue = $data['email'] ?? '';
$phoneValue = $data['phone'] ?? '';

if ($id === null || $id <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid doctor ID is required.']);
}
if (!is_string($nameValue) || !is_string($emailValue) || !is_string($phoneValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Name, email, and phone values must be text.']);
}

$name = sanitizeString($nameValue, 100);
$email = sanitizeString($emailValue, 150);
$phone = sanitizeString($phoneValue, 20);

$departmentValue = $data['department_id'] ?? null;
if ($departmentValue === null || $departmentValue === '') {
    $departmentId = 0;
} elseif (is_int($departmentValue) || (is_string($departmentValue) && ctype_digit($departmentValue))) {
    $departmentId = filter_var((string)$departmentValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
} else {
    $departmentId = false;
}

if ($departmentId === false) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department ID must be a positive integer when supplied.']);
}
if ($name === '' || $email === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'ID, name, and email are required.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid email address.']);
}

try {
    $db = getDB();
    $management = new DoctorManagementRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_DOCTORS_SCHEMA'));

    $changes = [
        'name' => $name,
        'email' => $email,
        'phone' => $phone !== '' ? $phone : null,
    ];

    // Keep identity and department changes atomic. Repository methods join this
    // caller-owned transaction and write the matching audit records.
    $db->beginTransaction();
    try {
        $management->updateDoctor($id, $changes, (int)$user['id']);
        if ($departmentId > 0) {
            $management->assignDoctorToDepartment($id, $departmentId, (int)$user['id']);
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }

    jsonResponse(true, ['message' => 'Doctor details updated successfully!']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if (str_contains($message, 'not found')) {
        http_response_code(404);
    } elseif (str_contains($message, 'already in use')) {
        http_response_code(409);
    } else {
        http_response_code(400);
    }
    jsonResponse(false, ['message' => $message]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if (isDuplicateKeyViolation($e)) {
        http_response_code(409);
        jsonResponse(false, ['message' => 'The submitted email or phone number is already in use.']);
    }
    error_log('Update Doctor Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update doctor details. Please try again.']);
}
