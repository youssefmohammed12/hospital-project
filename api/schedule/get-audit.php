<?php
/**
 * HealthBridge — Get Schedule Audit Log
 *
 * Returns schedule-specific audit entries from schedule_audit_log.
 * Used by both admin doctor profile and doctor dashboard.
 *
 * GET /api/schedule/get-audit.php?doctor_id=3&limit=50
 */

require_once __DIR__ . '/../../includes/auth.php';

$user = requireAuth();
$doctorId = (int)($_GET['doctor_id'] ?? 0);
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;

if ($doctorId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Doctor ID is required.']);
}

// Authorization: doctors can only view their own schedule history
if ($user['role'] === 'doctor' && (int)$user['id'] !== $doctorId) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Access denied.']);
}

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT sal.*, COALESCE(u.name, 'Deleted User') as actor_name
        FROM schedule_audit_log sal
        LEFT JOIN users u ON sal.actor_id = u.id
        WHERE sal.doctor_id = ?
        ORDER BY sal.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$doctorId, $limit]);
    $entries = $stmt->fetchAll();

    jsonResponse(true, ['entries' => $entries]);

} catch (Exception $e) {
    error_log('Get Schedule Audit Error: ' . $e->getMessage());
    jsonResponse(false, ['message' => 'Failed to load schedule history.']);
}
