<?php
/**
 * HealthBridge — Get Patient Audit Log
 *
 * Returns patient-specific audit entries from patient_audit_log.
 * Legacy compatibility route; no exact current frontend API-path caller was found.
 *
 * GET /api/patients/get-audit.php?patient_id=2&limit=50
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/AuditService.php';

$user = requireAuth();

if (!in_array($user['role'] ?? '', ['admin', 'doctor', 'patient'], true)) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'You do not have permission to view patient audit history.']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$patientIdValue = $_GET['patient_id'] ?? null;
$patientId = is_string($patientIdValue) && ctype_digit($patientIdValue)
    ? filter_var($patientIdValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;

$limitValue = $_GET['limit'] ?? null;
$parsedLimit = $limitValue === null
    ? 50
    : (is_string($limitValue) && ctype_digit($limitValue)
        ? filter_var($limitValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false);

if ($patientId === false || $parsedLimit === false) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID and limit must be positive integers.']);
}
$limit = min(100, $parsedLimit);

// Authorization: patients can only view their own audit history
if ($user['role'] === 'patient' && (int)$user['id'] !== $patientId) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Access denied.']);
}

try {
    $db = getDB();

    // Authorization: doctor access uses the shared confirmed-appointment / clinical-encounter policy.
    if ($user['role'] === 'doctor') {
        $clinicalAccess = new ClinicalAccessService($db);
        if (!$clinicalAccess->doctorCanAccessPatient((int)$user['id'], $patientId)) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'You can only view audit history for patients with a confirmed appointment or documented clinical encounter.']);
        }
    }

    $stmt = $db->prepare("
        SELECT a.id,
               a.actor_user_id AS actor_id,
               a.actor_role_snapshot AS actor_role,
               a.action_code AS action,
               a.entity_type,
               a.entity_id,
               a.patient_user_id AS patient_id,
               a.doctor_user_id AS doctor_id,
               a.old_value,
               a.new_value,
               a.description,
               a.ip_address,
               a.user_agent,
               a.occurred_at AS created_at,
               COALESCE(u.name, 'Deleted User') AS actor_name
        FROM audit_events a
        LEFT JOIN users u ON a.actor_user_id = u.id
        WHERE a.patient_user_id = ?
           OR (a.entity_type = 'patient' AND a.entity_id = ?)
        ORDER BY a.occurred_at DESC, a.id DESC
        LIMIT ?
    ");
    $stmt->execute([$patientId, (string)$patientId, $limit]);
    $entries = $stmt->fetchAll();

    // A patient viewing their own trail must not receive other users'
    // network metadata (PHP audit, 23 Nov 2026, finding L5). Admin and
    // doctor views keep the full record.
    if (($user['role'] ?? '') === 'patient') {
        foreach ($entries as &$entry) {
            unset($entry['ip_address'], $entry['user_agent']);
        }
        unset($entry);
    }

    jsonResponse(true, ['entries' => $entries]);

} catch (Throwable $e) {
    error_log('Get Patient Audit Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load patient audit history.']);
}
