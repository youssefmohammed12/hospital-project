<?php
/**
 * HealthBridge — Get Department Details
 * Returns detailed information about a specific department including assigned doctors.
 * Admin-only endpoint.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/DepartmentService.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
}

$departmentId = (int)($_GET['id'] ?? 0);

if ($departmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department ID is required.']);
}

try {
    $db = getDB();
    $ds = new DepartmentService($db);

    $department = $ds->getDepartmentById($departmentId);
    if (!$department) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Department not found.']);
    }

    $doctors = $ds->getDepartmentDoctors($departmentId);

    jsonResponse(true, [
        'department' => $department,
        'doctors' => $doctors
    ]);

} catch (Exception $e) {
    error_log('Get Department Details Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load department details.']);
}

