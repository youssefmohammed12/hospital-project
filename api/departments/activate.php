<?php
/**
 * HealthBridge — Activate Department
 * Activates a previously deactivated department with audit logging.
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
        $ds->activateDepartment($departmentId);
        $audit->logDepartmentActivate($departmentId, $department['name']);

        $db->commit();

        jsonResponse(true, ['message' => 'Department activated successfully.']);

    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }

} catch (Exception $e) {
    error_log('Activate Department Error: ' . $e->getMessage());
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
}

