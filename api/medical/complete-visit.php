<?php
/**
 * HealthBridge — Atomic Complete Visit Endpoint (Phase 5.4)
 * Minimal test version
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/VisitDraftService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();

if ($user['role'] !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can complete visits.']);
}

$appointmentId = (int)($input['appointment_id'] ?? 0);
$doctorId = (int)$user['id'];

if ($appointmentId <= 0) {
    jsonResponse(false, ['message' => 'Appointment ID is required.']);
}

try {
    $db = getDB();
    $db->beginTransaction();

    // Verify appointment belongs to this doctor and is confirmed
    $stmt = $db->prepare(
        "SELECT a.*, u.id as patient_user_id 
         FROM appointments a 
         LEFT JOIN users u ON a.user_id = u.id
         WHERE a.id = ? AND a.doctor_id = ? AND a.status = 'Confirmed' 
         FOR UPDATE"
    );
    $stmt->execute([$appointmentId, $doctorId]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        $db->rollBack();
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found or not confirmed.']);
    }

    // 1. Save visit note if provided
    if (!empty($input['diagnosis']) || !empty($input['symptoms']) || !empty($input['treatment'])) {
        $noteStmt = $db->prepare(
            "INSERT INTO visit_notes 
             (appointment_id, patient_id, doctor_id, diagnosis, symptoms, treatment, doctor_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
             diagnosis = VALUES(diagnosis),
             symptoms = VALUES(symptoms),
             treatment = VALUES(treatment),
             doctor_notes = VALUES(doctor_notes),
             updated_at = NOW()"
        );
        $noteStmt->execute([
            $appointmentId,
            $appointment['user_id'],
            $doctorId,
            $input['diagnosis'] ?? null,
            $input['symptoms'] ?? null,
            $input['treatment'] ?? null,
            $input['doctor_notes'] ?? null,
        ]);
    }

    // 2. Finalize active prescriptions for this appointment
    $rxStmt = $db->prepare(
        "UPDATE prescriptions 
         SET status = 'Completed', updated_at = NOW()
         WHERE appointment_id = ? AND doctor_id = ? AND status = 'Active'"
    );
    $rxStmt->execute([$appointmentId, $doctorId]);

    // 3. Mark workflow as completed
    $wfService = new VisitWorkflowService($db);
    $wfService->getOrCreateWorkflow($appointmentId);
    $wfService->transition($appointmentId, 'Completed');

    // 4. Delete draft
    $draftService = new VisitDraftService($db);
    $draftService->delete($appointmentId);

    // 5. Notify patient
    if (!empty($appointment['patient_user_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$appointment['patient_user_id'],
            NotificationService::TYPE_APPOINTMENT_COMPLETED,
            'Visit Completed',
            "Your appointment with {$appointment['doctor']} on {$appointment['date']} at {$appointment['time']} has been completed.",
            'appointment',
            $appointmentId
        );
    }

    $db->commit();

    jsonResponse(true, ['message' => 'Visit completed successfully.']);

} catch (Exception $e) {
    error_log('Complete Visit Error: ' . $e->getMessage());
    error_log('Complete Visit Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to complete visit: ' . $e->getMessage()]);
}

