<?php
/**
 * HealthBridge — Deactivate Department
 * Deactivates a department (soft delete) with audit logging.
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

    $department = $ds->getDepartmentById($departmentId);
    if (!$department) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Department not found.']);
    }

    $db->beginTransaction();

    try {
        $ds->deactivateDepartment($departmentId);
        $audit->logDepartmentDeactivate($departmentId, $department['name']);

        $db->commit();

        jsonResponse(true, ['message' => 'Department deactivated successfully.']);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('Deactivate Department Error: ' . $e->getMessage());
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
}

