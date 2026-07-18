<?php
/**
 * HealthBridge — Visit Workflow Transition Endpoint (Phase 5.3.2)
 * Handles workflow state transitions separate from appointment booking status.
 *
 * Workflow states: Waiting, In Progress, Ready to Complete, Completed
 * Appointment status: Pending, Confirmed, Cancelled (unchanged)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();
$appointmentId = (int)($input['appointment_id'] ?? 0);
$newStatus = trim($input['status'] ?? '');

if ($appointmentId <= 0) {
    jsonResponse(false, ['message' => 'Invalid appointment ID.']);
}

if (!$newStatus) {
    jsonResponse(false, ['message' => 'Status is required.']);
}

// Validate workflow status
$validStatuses = [
    VisitWorkflowService::STATUS_WAITING,
    VisitWorkflowService::STATUS_IN_PROGRESS,
    VisitWorkflowService::STATUS_READY_TO_COMPLETE,
    VisitWorkflowService::STATUS_COMPLETED
];

if (!in_array($newStatus, $validStatuses)) {
    jsonResponse(false, ['message' => 'Invalid workflow status.']);
}

try {
    $db = getDB();

    // Verify appointment exists and belongs to the doctor (if doctor)
    $stmt = $db->prepare(
        "SELECT a.*, u.id AS patient_user_id 
         FROM appointments a 
         LEFT JOIN users u ON a.user_id = u.id 
         WHERE a.id = ?"
    );
    $stmt->execute([$appointmentId]);
    $appt = $stmt->fetch();

    if (!$appt) {
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }

    // Authorization check
    if ($user['role'] === 'doctor') {
        if ((int)$appt['doctor_id'] !== (int)$user['id']) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'You can only manage workflow for your own appointments.']);
        }
    }

    // Phase 5.4.1: Ensure workflow exists before transition (self-healing)
    $wfService = new VisitWorkflowService($db);
    $wfService->getOrCreateWorkflow($appointmentId);
    
    // Perform workflow transition
    $wfService->transition($appointmentId, $newStatus);
    
    // Get the updated workflow to return to frontend
    $updatedWorkflow = $wfService->get($appointmentId);

    // Notify patient when visit is completed
    if ($newStatus === VisitWorkflowService::STATUS_COMPLETED && !empty($appt['patient_user_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$appt['patient_user_id'],
            NotificationService::TYPE_APPOINTMENT_COMPLETED,
            'Visit Completed',
            "Your appointment with {$appt['doctor']} on {$appt['date']} at {$appt['time']} has been completed.",
            'appointment',
            $appointmentId
        );
    }

    jsonResponse(true, [
        'message' => 'Workflow updated successfully.',
        'workflow' => $updatedWorkflow
    ]);

} catch (Exception $e) {
    error_log('Transition Workflow Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update workflow.']);
}

