<?php
/**
 * HealthBridge — Get Patient Audit Log
 *
 * Returns patient-specific audit entries from patient_audit_log.
 * Used by admin patient profile and patient EMR pages.
 *
 * GET /api/patients/get-audit.php?patient_id=2&limit=50
 */

require_once __DIR__ . '/../../includes/auth.php';

$user = requireAuth();
$patientId = (int)($_GET['patient_id'] ?? 0);
$limit = isset($_GET['limit']) ? max(1, min(100, (int)$_GET['limit'])) : 50;

if ($patientId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID is required.']);
}

// Authorization: patients can only view their own audit history
if ($user['role'] === 'patient' && (int)$user['id'] !== $patientId) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Access denied.']);
}

// Authorization: doctors can view audit for patients they have treated
if ($user['role'] === 'doctor') {
    $db = getDB();
    $authStmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM appointments
         WHERE doctor_id = ? AND user_id = ? AND status IN ('Confirmed', 'Cancelled')
         LIMIT 1"
    );
    $authStmt->execute([(int)$user['id'], $patientId]);
    if ((int)$authStmt->fetch()['cnt'] === 0) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only view records of patients you have treated.']);
    }
}

try {
    $db = getDB();

    $stmt = $db->prepare("
        SELECT pal.*, COALESCE(u.name, 'Deleted User') as actor_name
        FROM patient_audit_log pal
        LEFT JOIN users u ON pal.actor_id = u.id
        WHERE pal.patient_id = ?
        ORDER BY pal.created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$patientId, $limit]);
    $entries = $stmt->fetchAll();

    jsonResponse(true, ['entries' => $entries]);

} catch (Exception $e) {
    error_log('Get Patient Audit Error: ' . $e->getMessage());
    jsonResponse(false, ['message' => 'Failed to load patient audit history.']);
}
