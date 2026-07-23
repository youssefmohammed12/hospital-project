<?php
/**
 * HealthBridge — Patient Cancel Appointment
 * Allows a patient to cancel their own appointment with confirmation.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

$user = requireAuth();
$userId = (int)$user['id'];
$role = $user['role'] ?? '';

// Only patients can cancel their own appointments
if ($role !== 'patient') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Patient access required.']);
}

$input = getJsonInput();
$appointmentId = (int)($input['id'] ?? 0);

if ($appointmentId <= 0) {
    jsonResponse(false, ['message' => 'Invalid appointment ID.']);
}

try {
    $db = getDB();

    // Verify appointment belongs to this patient
    $stmt = $db->prepare("SELECT a.*, u.id AS patient_user_id, d.user_id AS doctor_user_id 
                          FROM appointments a 
                          LEFT JOIN users u ON a.user_id = u.id
                          LEFT JOIN doctors d ON a.doctor_id = d.user_id
                          WHERE a.id = ?");
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }

    if ((int)$appt['user_id'] !== $userId) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only cancel your own appointments.']);
    }

    // Check if appointment can be cancelled (not already cancelled or completed)
    if ($appt['status'] === 'Cancelled') {
        jsonResponse(false, ['message' => 'This appointment is already cancelled.']);
    }

    if ($appt['status'] === 'Completed') {
        jsonResponse(false, ['message' => 'Cannot cancel completed appointments.']);
    }

    // Check if appointment is in the past
    $appointmentDate = new DateTime($appt['date'] . ' ' . $appt['time']);
    $now = new DateTime();
    if ($appointmentDate < $now) {
        jsonResponse(false, ['message' => 'Cannot cancel past appointments.']);
    }

    // Update appointment status
    $stmt = $db->prepare("UPDATE appointments SET status = 'Cancelled' WHERE id = ?");
    $stmt->execute([$appointmentId]);

    // Notify doctor
    if (!empty($appt['doctor_user_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$appt['doctor_user_id'],
            NotificationService::TYPE_APPOINTMENT_CANCELLED,
            'Appointment Cancelled',
            "Patient {$appt['patient_name']} has cancelled their appointment on {$appt['date']} at {$appt['time']}.",
            'appointment',
            $appointmentId
        );
    }

    // Log to audit
    $audit = new AuditService($db, $userId, $role);
    $audit->log('cancel', 'appointment', $appointmentId, null, $appt, "Appointment cancelled by patient", $userId, $appt['doctor_id']);

    jsonResponse(true, ['message' => 'Appointment cancelled successfully.']);

} catch (Exception $e) {
    error_log('Patient Cancel Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to cancel appointment.']);
}
