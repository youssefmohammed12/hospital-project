<?php
/**
 * HealthBridge — Cancel Prescription
 * Cancels an Active prescription with a required reason.
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
    jsonResponse(false, ['message' => 'Only doctors can cancel prescriptions.']);
}

$input = getJsonInput();
$prescriptionId = (int)($input['prescription_id'] ?? 0);
$reason         = trim($input['reason'] ?? '');

if (!$prescriptionId) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription ID is required.']);
}

if (empty($reason)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Cancellation reason is required.']);
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db);

    $cancelled = $ps->cancel($prescriptionId, $currentUserId, $reason);

    if (!$cancelled) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Cannot cancel this prescription. It may not exist, is not Active, or was created by another doctor.']);
    }

    $rx = $ps->get($prescriptionId);
    if ($rx && !empty($rx['patient_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$rx['patient_id'],
            NotificationService::TYPE_PRESCRIPTION_CANCELLED,
            'Prescription Cancelled',
            "Your prescription has been cancelled by Dr. {$rx['appt_doctor_name']}. Reason: {$reason}",
            'prescription',
            $prescriptionId
        );
    }

    // Log to audit (patient_id + doctor_id for EMR and Doctor Profile context)
    $audit = new AuditService($db, (int)$user['id'], $user['role']);
    $audit->log('cancel', 'prescription', $prescriptionId, 'Active', 'Cancelled', "Prescription cancelled: {$reason}", $rx['patient_id'] ?? null, $currentUserId);

    jsonResponse(true, ['message' => 'Prescription cancelled successfully.', 'prescription_id' => $prescriptionId]);

} catch (Exception $e) {
    error_log('Cancel Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to cancel prescription.']);
}
