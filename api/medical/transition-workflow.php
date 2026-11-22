<?php
/**
 * HealthBridge — Visit Workflow Transition Endpoint
 * Handles workflow state transitions separate from appointment booking status.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/Repositories/ClinicalEncounterRepository.php';

header('Content-Type: application/json');

$user = requireRole('doctor');
$doctorId = (int)$user['id'];
$schema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_CLINICAL_SCHEMA');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$appointmentId = parsePositiveInt($input['appointment_id'] ?? null);
$statusValue = $input['status'] ?? '';
if (!is_string($statusValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Workflow status must be text.']);
}
$newStatus = trim($statusValue);

if ($appointmentId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid appointment ID is required.']);
}
if ($newStatus === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Status is required.']);
}

$validStatuses = [
    VisitWorkflowService::STATUS_IN_PROGRESS,
    VisitWorkflowService::STATUS_READY_TO_COMPLETE,
];
if (!in_array($newStatus, $validStatuses, true)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid workflow status.']);
}

$db = null;

try {
    $db = getDB();
    $db->beginTransaction();

    $encounters = new ClinicalEncounterRepository($db, $schema);
    $identity = $encounters->getAppointmentContext($appointmentId);
    if (
        !$identity
        || (int)$identity['doctor_user_id'] !== $doctorId
        || $identity['patient_user_id'] === null
    ) {
        $db->rollBack();
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found or not assigned to you.']);
    }

    // Account rows are locked before appointment/workflow rows.
    $accountLocks = new ClinicalAccessService($db, $schema);
    if (!$accountLocks->lockDoctorAndPatient($doctorId, (int)$identity['patient_user_id'])) {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'The appointment account relationship is no longer available.']);
    }

    $appointment = $encounters->lockAppointmentContext($appointmentId);
    if (
        !$appointment
        || (int)$appointment['doctor_user_id'] !== $doctorId
        || (int)($appointment['patient_user_id'] ?? 0) !== (int)$identity['patient_user_id']
        || $appointment['status'] !== 'confirmed'
    ) {
        $db->rollBack();
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found or not assigned to you.']);
    }

    // The service owns the workflow lock and validates the transition graph.
    $workflowService = new VisitWorkflowService($db, $schema);
    $workflowService->transition($appointmentId, $newStatus);
    $updatedWorkflow = $workflowService->get($appointmentId);

    $db->commit();
    jsonResponse(true, [
        'message' => 'Workflow updated successfully.',
        'workflow' => $updatedWorkflow,
    ]);
} catch (DomainException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(409);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Transition Workflow Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update workflow.']);
}
