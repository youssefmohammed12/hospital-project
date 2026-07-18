<?php
/**
 * HealthBridge — Complete Prescription
 * Marks an Active prescription as Completed (read-only).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';

header('Content-Type: application/json');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

if ($currentRole !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can complete prescriptions.']);
}

$input = getJsonInput();
$prescriptionId = (int)($input['prescription_id'] ?? 0);

if (!$prescriptionId) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription ID is required.']);
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db);

    $completed = $ps->complete($prescriptionId, $currentUserId);

    if (!$completed) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Cannot complete this prescription. It may not exist, is not Active, or was created by another doctor.']);
    }

    $rx = $ps->get($prescriptionId);
    if ($rx && !empty($rx['patient_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$rx['patient_id'],
            NotificationService::TYPE_PRESCRIPTION_COMPLETED,
            'Prescription Completed',
            "Your prescription treatment has been marked as completed by Dr. {$rx['appt_doctor_name']}.",
            'prescription',
            $prescriptionId
        );
    }

    // Log to audit (patient_id + doctor_id for EMR and Doctor Profile context)
    $audit = new AuditService($db, (int)$user['id'], $user['role']);
    $audit->log('complete', 'prescription', $prescriptionId, 'Active', 'Completed', "Prescription marked as completed", $rx['patient_id'] ?? null, $currentUserId);

    jsonResponse(true, ['message' => 'Prescription marked as completed.', 'prescription_id' => $prescriptionId]);

} catch (Exception $e) {
    error_log('Complete Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to complete prescription.']);
}
