<?php
/**
 * HealthBridge — Doctor Update Appointment
 * Allows a doctor to update appointment details and notifies the patient.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();
$appointmentId = (int)($input['id'] ?? 0);
$newStatus = trim($input['status'] ?? '');
$newDate = trim($input['date'] ?? '');
$newTime = trim($input['time'] ?? '');

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

    $updates = [];
    $params = [];

    if ($newStatus) {
        // Validate that status is a booking status, not a workflow status
        $validBookingStatuses = ['Pending', 'Confirmed', 'Cancelled', 'Declined'];
        if (!in_array($newStatus, $validBookingStatuses)) {
            jsonResponse(false, ['message' => 'Invalid booking status. Use transition_workflow.php for workflow states.']);
        }
        $updates[] = "status = ?";
        $params[] = $newStatus;
    }
    if ($newDate) {
        $updates[] = "date = ?";
        $params[] = $newDate;
    }
    if ($newTime) {
        $updates[] = "time = ?";
        $params[] = $newTime;
    }

    if (empty($updates)) {
        jsonResponse(false, ['message' => 'No changes provided.']);
    }

    $params[] = $appointmentId;
    $stmt = $db->prepare("UPDATE appointments SET " . implode(', ', $updates) . " WHERE id = ?");
    $stmt->execute($params);

    // Phase 5.4.1: Auto-create workflow when appointment is confirmed
    if ($newStatus === 'Confirmed') {
        $wf = new VisitWorkflowService($db);
        $wf->getOrCreateWorkflow($appointmentId);
    }

    // Notify patient
    if (!empty($appt['patient_user_id'])) {
        $ns = new NotificationService($db);
        $notificationType = NotificationService::TYPE_APPOINTMENT_CHANGED;
        $title = 'Appointment Updated';
        $message = "Your appointment with {$appt['doctor']} has been updated.";

        if ($newStatus === 'Confirmed') {
            $notificationType = NotificationService::TYPE_APPOINTMENT_CONFIRMED;
            $title = 'Appointment Confirmed';
            $message = "Your appointment with {$appt['doctor']} has been confirmed.";
        } elseif ($newStatus === 'Cancelled') {
            $notificationType = NotificationService::TYPE_APPOINTMENT_CANCELLED;
            $title = 'Appointment Cancelled';
            $message = "Your appointment with {$appt['doctor']} has been cancelled.";
        }

        if ($newDate || $newTime) {
            $apptTime = $newTime ?: $appt['time'];
            $apptDate = $newDate ?: $appt['date'];
            $duration = getAppointmentDuration((int)$appt['doctor_id']);
            $timeRangeStr = computeAppointmentTimeRange($apptTime, $duration);
            $message .= " New date: {$apptDate} ({$timeRangeStr}).";
        }

        $ns->create(
            (int)$appt['patient_user_id'],
            $notificationType,
            $title,
            $message,
            'appointment',
            $appointmentId
        );
    }

    
    // Log to audit
    $audit = new AuditService($db, (int)$user['id'], $user['role']);
    $audit->log('update', 'appointment', $appointmentId, null, $data, "Appointment updated by {$user['name']}", $patientUserId, $doctorId);

    
    jsonResponse(true, ['message' => 'Appointment updated successfully.']);

} catch (Exception $e) {
    error_log('Doctor Update Appointment Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update appointment.']);
}
