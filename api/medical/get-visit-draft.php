<?php
/**
 * HealthBridge — Get Visit Draft Endpoint (Phase 5.4)
 * Retrieves autosave draft for visit notes.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitDraftService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/Repositories/ClinicalEncounterRepository.php';

header('Content-Type: application/json');

$user = requireRole('doctor');
$schema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_CLINICAL_SCHEMA');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$appointmentId = parsePositiveInt($_GET['appointment_id'] ?? null);

if ($appointmentId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid appointment ID is required.']);
}

try {
    $db = getDB();
    
    // Resolve appointment ownership through the schema-aware context adapter.
    $encounters = new ClinicalEncounterRepository($db, $schema);
    $appointment = $encounters->getAppointmentContext($appointmentId);
    if (
        !$appointment
        || (int)$appointment['doctor_user_id'] !== (int)$user['id']
        || $appointment['status'] !== 'confirmed'
        || $appointment['patient_user_id'] === null
    ) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only access drafts for your own confirmed appointments.']);
    }

    $workflow = (new VisitWorkflowService($db, $schema))->get($appointmentId);
    if (($workflow['status'] ?? null) === VisitWorkflowService::STATUS_COMPLETED) {
        http_response_code(409);
        jsonResponse(false, ['message' => 'Completed visits no longer have editable drafts.']);
    }

    $draftService = new VisitDraftService($db, $schema);
    $draft = $draftService->get($appointmentId);

    jsonResponse(true, ['draft' => $draft]);

} catch (Exception $e) {
    error_log('Get Visit Draft Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to get draft.']);
}

