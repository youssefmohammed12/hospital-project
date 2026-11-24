<?php
/**
 * HealthBridge — Assign Doctor to Department
 * Admin-only department assignment using the canonical users.id identity.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/DoctorManagementRepository.php';

$user = requireRole('admin');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$doctorUserId = parsePositiveInt($input['doctor_id'] ?? null);
$departmentId = parsePositiveInt($input['department_id'] ?? null);

if ($doctorUserId === null || $doctorUserId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Doctor ID is required.']);
}
if ($departmentId === null || $departmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department ID is required.']);
}

try {
    $db = getDB();
    $management = new DoctorManagementRepository($db);
    $management->assignDoctorToDepartment($doctorUserId, $departmentId, (int)$user['id']);
    jsonResponse(true, ['message' => 'Doctor assigned to department successfully.']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    http_response_code(str_contains($message, 'not found') ? 404 : 400);
    jsonResponse(false, ['message' => $message]);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('Assign Doctor Department Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to assign doctor to department.']);
}
