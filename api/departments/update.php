<?php
/**
 * HealthBridge — Update Department
 * Updates an existing department with audit logging.
 * Admin-only endpoint.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/DepartmentService.php';
require_once __DIR__ . '/../../services/AdminAuditService.php';

$user = requireRole('admin');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$departmentId = (int)($input['id'] ?? 0);

if ($departmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department ID is required.']);
}

try {
    $db = getDB();
    $ds = new DepartmentService($db, DepartmentService::schemaFromEnvironment());
    $audit = new AdminAuditService($db, (int)$user['id'], AuditService::schemaFromEnvironment());

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

