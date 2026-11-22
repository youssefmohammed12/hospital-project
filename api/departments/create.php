<?php
/**
 * HealthBridge — Create Department
 * Creates a new department with audit logging.
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

$name = trim($input['name'] ?? '');
$description = trim($input['description'] ?? '');
$status = $input['status'] ?? 'active';

if (empty($name)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department name is required.']);
}

try {
    $db = getDB();
    $ds = new DepartmentService($db, DepartmentService::schemaFromEnvironment());
    $audit = new AdminAuditService($db, (int)$user['id'], AuditService::schemaFromEnvironment());

    $db->beginTransaction();

    try {
        $departmentId = $ds->createDepartment([
            'name' => $name,
            'description' => $description,
            'status' => $status
        ]);

        // Log the action
        $audit->logDepartmentCreate($departmentId, [
            'name' => $name,
            'description' => $description,
            'status' => $status
        ]);

        $db->commit();

        jsonResponse(true, [
            'message' => 'Department created successfully.',
            'department_id' => $departmentId
        ]);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('Create Department Error: ' . $e->getMessage());
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
}

