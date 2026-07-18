<?php
/**
 * HealthBridge — Admin Approve Appointment
 * Confirms a pending appointment and notifies the patient.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();
$appointmentId = (int)($input['id'] ?? 0);

if ($appointmentId <= 0) {
    jsonResponse(false, ['message' => 'Invalid appointment ID.']);
}

try {
    $db = getDB();

    $stmt = $db->prepare("SELECT a.*, u.name AS patient_name, u.id AS patient_user_id FROM appointments a LEFT JOIN users u ON a.user_id = u.id WHERE a.id = ?");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }

    $stmt = $db->prepare("UPDATE appointments SET status = 'Confirmed' WHERE id = ?");
    $stmt->execute([$appointmentId]);

    // Phase 5.4.1: Auto-create workflow using getOrCreateWorkflow
    $wf = new VisitWorkflowService($db);
    $wf->getOrCreateWorkflow($appointmentId);

    // Notify patient
    if (!empty($appt['patient_user_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$appt['patient_user_id'],
            NotificationService::TYPE_APPOINTMENT_CONFIRMED,
            'Appointment Confirmed',
            "Your appointment with {$appt['doctor']} on {$appt['date']} at {$appt['time']} has been confirmed.",
            'appointment',
            $appointmentId
        );
    }

    
    // Log to audit (patient_id for EMR context; doctor_id from appointment)
    $audit = new AuditService($db, (int)$_SESSION['user_id'], $_SESSION['role']);
    $doctorUserId = (int)($appt['doctor_id'] ?? 0);
    $patientUserId = (int)($appt['patient_user_id'] ?? $appt['user_id'] ?? 0);
    $auditDesc = "Appointment on {$appt['date']} at {$appt['time']} approved: Pending → Confirmed";
    $audit->log('approve', 'appointment', $appointmentId, 'Pending', 'Confirmed', $auditDesc, $patientUserId ?: null, $doctorUserId ?: null);

    
    jsonResponse(true, ['message' => 'Appointment approved successfully.']);

} catch (Exception $e) {
    error_log('Approve Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to approve appointment.']);
}
