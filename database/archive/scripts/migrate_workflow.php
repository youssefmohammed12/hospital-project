<?php
/**
 * HealthBridge — Visit Workflow Migration (Phase 5.3.2)
 * Run once to create workflow records for existing appointments.
 *
 * Past confirmed appointments -> Completed
 * Future confirmed appointments -> Waiting
 * Pending/Cancelled -> no workflow
 *
 * Access: Admin only
 */

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../services/VisitWorkflowService.php';

$user = requireAuth();
if ($user['role'] !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Admin access required.']);
}

try {
    $db = getDB();
    $service = new VisitWorkflowService($db);
    $stats = $service->migrateExisting();

    jsonResponse(true, [
        'message' => 'Migration completed.',
        'stats' => $stats
    ]);
} catch (Exception $e) {
    error_log('Workflow Migration Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Migration failed: ' . $e->getMessage()]);
}
