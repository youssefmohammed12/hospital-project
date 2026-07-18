<?php
/**
 * HealthBridge — Deactivate Department
 * Deactivates a department (soft delete) with audit logging.
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

$departmentId = (int)($_GET['id'] ?? 0);

if ($departmentId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Department ID is required.']);
}

try {
    $db = getDB();
    $ds = new DepartmentService($db);
    $audit = new AdminAuditService($db, (int)$user['id']);

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

