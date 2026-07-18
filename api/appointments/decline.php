<?php
/**
 * HealthBridge — Admin Decline Appointment
 * Cancels a pending appointment and notifies the patient.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();
$appointmentId = (int)($input['id'] ?? 0);
$reason = trim($input['reason'] ?? '');

if ($appointmentId <= 0) {
    jsonResponse(false, ['message' => 'Invalid appointment ID.']);
}

try {
    $db = getDB();

    $stmt = $db->prepare("SELECT a.*, u.id AS patient_user_id FROM appointments a LEFT JOIN users u ON a.user_id = u.id WHERE a.id = ?");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }

    $stmt = $db->prepare("UPDATE appointments SET status = 'Cancelled' WHERE id = ?");
    $stmt->execute([$appointmentId]);

    // Notify patient
    if (!empty($appt['patient_user_id'])) {
        $message = "Your appointment with {$appt['doctor']} on {$appt['date']} at {$appt['time']} has been declined.";
        if ($reason) $message .= " Reason: {$reason}";

        $ns = new NotificationService($db);
        $ns->create(
            (int)$appt['patient_user_id'],
            NotificationService::TYPE_APPOINTMENT_DECLINED,
            'Appointment Declined',
            $message,
            'appointment',
            $appointmentId
        );
    }

    
    // Log to audit (patient_id for EMR context; doctor_id from appointment)
    $audit = new AuditService($db, (int)$_SESSION['user_id'], $_SESSION['role']);
    $doctorUserId = (int)($appt['doctor_id'] ?? 0);
    $patientUserId = (int)($appt['patient_user_id'] ?? $appt['user_id'] ?? 0);
    $auditDesc = "Appointment on {$appt['date']} at {$appt['time']} declined: Pending → Cancelled" . ($reason ? ". Reason: {$reason}" : '');
    $audit->log('decline', 'appointment', $appointmentId, 'Pending', 'Cancelled', $auditDesc, $patientUserId ?: null, $doctorUserId ?: null);

    
    jsonResponse(true, ['message' => 'Appointment declined successfully.']);

} catch (Exception $e) {
    error_log('Decline Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to decline appointment.']);
}
