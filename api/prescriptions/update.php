<?php
/**
 * HealthBridge — Update Prescription
 * Updates an Active prescription's medication items and notes.
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
    jsonResponse(false, ['message' => 'Only doctors can update prescriptions.']);
}

$input = getJsonInput();
$prescriptionId = (int)($input['prescription_id'] ?? 0);
$notes          = trim($input['notes'] ?? '');
$items          = $input['items'] ?? [];

if (!$prescriptionId) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription ID is required.']);
}

$validationError = PrescriptionService::validateItems($items);
if ($validationError) {
    http_response_code(400);
    jsonResponse(false, ['message' => $validationError]);
}

try {
    $db = getDB();
    $ps = new PrescriptionService($db);

    $updated = $ps->update($prescriptionId, $items, $notes ?: null, $currentUserId);

    if (!$updated) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Cannot update this prescription. It may not exist, is not Active, or was created by another doctor.']);
    }

    $rx = $ps->get($prescriptionId);
    if ($rx && !empty($rx['patient_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$rx['patient_id'],
            NotificationService::TYPE_PRESCRIPTION_UPDATED,
            'Prescription Updated',
            "Your prescription has been updated by Dr. {$rx['appt_doctor_name']}. Please review the changes in your Prescriptions section.",
            'prescription',
            $prescriptionId
        );
    }

    // Log to audit (patient_id + doctor_id for EMR and Doctor Profile context)
    $audit = new AuditService($db, (int)$user['id'], $user['role']);
    $audit->log('update', 'prescription', $prescriptionId, null, null, "Prescription updated", $rx['patient_id'] ?? null, $currentUserId);

    jsonResponse(true, ['message' => 'Prescription updated successfully.', 'prescription_id' => $prescriptionId]);

} catch (Exception $e) {
    error_log('Update Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update prescription.']);
}
