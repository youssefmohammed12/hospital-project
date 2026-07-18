<?php
/**
 * HealthBridge — Update Visit Workflow (Phase 5.3.2)
 * Transitions the visit workflow state for an appointment.
 * Does NOT modify appointments.status (booking status).
 *
 * Workflow: Waiting -> In Progress -> Ready to Complete -> Completed
 *
 * Access: Doctor only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/NotificationService.php';

$user = requireAuth();
$currentUserId = (int)$user['id'];

if ($user['role'] !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can update visit workflow.']);
}

$input = getJsonInput();
$appointmentId = (int)($input['appointment_id'] ?? 0);
$newStatus = trim($input['status'] ?? '');

if ($appointmentId <= 0) {
    jsonResponse(false, ['message' => 'Invalid appointment ID.']);
}

$validStatuses = [
    VisitWorkflowService::STATUS_WAITING,
    VisitWorkflowService::STATUS_IN_PROGRESS,
    VisitWorkflowService::STATUS_READY_TO_COMPLETE,
    VisitWorkflowService::STATUS_COMPLETED,
];

if (!in_array($newStatus, $validStatuses)) {
    jsonResponse(false, ['message' => 'Invalid workflow status. Valid: ' . implode(', ', $validStatuses)]);
}

try {
    $db = getDB();

    // Verify doctor owns this appointment
    $stmt = $db->prepare("SELECT id, doctor_id, user_id FROM appointments WHERE id = ? AND doctor_id = ?");
    $stmt->execute([$appointmentId, $currentUserId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Appointment not found or not assigned to you.']);
    }

    // Transition workflow
    $wf = new VisitWorkflowService($db);
    $wf->transition($appointmentId, $newStatus);

    // If completed, notify patient
    if ($newStatus === VisitWorkflowService::STATUS_COMPLETED) {
        $patientStmt = $db->prepare("SELECT name FROM users WHERE id = ?");
        $patientStmt->execute([$appt['user_id']]);
        $patient = $patientStmt->fetch();

        $ns = new NotificationService($db);
        $ns->create(
            (int)$appt['user_id'],
            NotificationService::TYPE_APPOINTMENT_CHANGED,
            'Visit Completed',
            "Your visit with Dr. " . ($user['name'] ?? '') . " has been completed. Your medical record has been updated.",
            'appointment',
            $appointmentId
        );
    }

    jsonResponse(true, [
        'message' => 'Workflow updated to ' . $newStatus,
        'workflow_status' => $newStatus,
    ]);

} catch (Exception $e) {
    error_log('Update Visit Workflow Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update workflow.']);
}
