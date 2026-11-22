<?php
/**
 * HealthBridge — Admin: Add Doctor
 * Creates the account, profile, default schedule, audit record and admin
 * notification outbox entries atomically through the schema-aware repository.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/Repositories/DoctorManagementRepository.php';

$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$nameValue = $input['name'] ?? '';
$emailValue = $input['email'] ?? '';
$password = $input['password'] ?? '';
$departmentId = parsePositiveInt($input['department_id'] ?? null);

if (!is_string($nameValue) || !is_string($emailValue) || !is_string($password)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Name, email, and password values must be text.']);
}
$name = trim($nameValue);
$email = trim($emailValue);

if ($name === '' || $email === '' || $password === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Name, email, and password are required.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid email address.']);
}
if (mb_strlen($name) > 100 || mb_strlen($email) > 150 || mb_strlen($password) < 8 || mb_strlen($password) > 200) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Doctor details or password do not meet the required length limits.']);
}
if ($departmentId === null || $departmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department is required.']);
}

try {
    $db = getDB();
    $duplicate = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $duplicate->execute([$email]);
    if ($duplicate->fetchColumn()) {
        http_response_code(409);
        jsonResponse(false, ['message' => 'A user with this email already exists.']);
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    if ($passwordHash === false) {
        throw new RuntimeException('Password hashing failed.');
    }

    // Current runtime is V1. The V2 implementation is already fixture-tested.
    $management = new DoctorManagementRepository($db, HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_DOCTORS_SCHEMA'));
    $doctorUserId = $management->createDoctor(
        $name,
        $email,
        $passwordHash,
        $departmentId,
        (int)$user['id']
    );

    $notifications = new NotificationService($db);
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Add Doctor Outbox Dispatch Error: ' . get_class($notificationError));
    }

    jsonResponse(true, ['message' => 'Doctor added successfully.', 'doctor_id' => $doctorUserId]);
} catch (DomainException $e) {
    http_response_code(str_contains($e->getMessage(), 'active administrator') ? 403 : 400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isDuplicateKeyViolation($e)) {
        http_response_code(409);
        jsonResponse(false, ['message' => 'A user with this email or phone already exists.']);
    }
    error_log('Add Doctor Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to add doctor. Please try again.']);
}
