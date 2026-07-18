<?php
/**
 * HealthBridge — Update Department
 * Updates an existing department with audit logging.
 * Admin-only endpoint.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/DepartmentService.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
}

$departmentId = (int)($input['id'] ?? $_GET['id'] ?? 0);
$input = getJsonInput();

if ($departmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department ID is required.']);
}

try {
    $db = getDB();
    $ds = new DepartmentService($db);
    $audit = new AdminAuditService($db, (int)$user['id']);

    // Get current state for audit
    $oldDepartment = $ds->getDepartmentById($departmentId);
    if (!$oldDepartment) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Department not found.']);
    }

    $db->beginTransaction();

    try {
        $ds->updateDepartment($departmentId, [
            'name' => $input['name'] ?? $oldDepartment['name'],
            'description' => $input['description'] ?? $oldDepartment['description'],
            'status' => $input['status'] ?? $oldDepartment['status']
        ]);

        // Get new state for audit
        $newDepartment = $ds->getDepartmentById($departmentId);

        // Log the action
        $audit->logDepartmentUpdate($departmentId, $oldDepartment, $newDepartment);

        $db->commit();

        jsonResponse(true, ['message' => 'Department updated successfully.']);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('Update Department Error: ' . $e->getMessage());
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
}

