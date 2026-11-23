<?php
/**
 * HealthBridge — Save Visit Draft Endpoint
 * Saves autosave drafts through the schema-aware clinical repository.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitDraftService.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/Repositories/ClinicalEncounterRepository.php';

header('Content-Type: application/json');

$user = requireRole('doctor');
$doctorId = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$appointmentId = parsePositiveInt($input['appointment_id'] ?? null);
$draftFields = ['diagnosis', 'symptoms', 'treatment', 'doctor_notes', 'follow_up_instructions'];
foreach ($draftFields as $field) {
    if (isset($input[$field]) && !is_string($input[$field])) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Visit draft fields must be text.']);
    }
    if (isset($input[$field]) && mb_strlen($input[$field]) > 10000) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Visit draft fields must not exceed 10000 characters.']);
    }
}

if ($appointmentId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid appointment ID is required.']);
}

$db = null;

try {
    $db = getDB();
    $db->beginTransaction();

    $encounters = new ClinicalEncounterRepository($db);
    $identity = $encounters->getAppointmentContext($appointmentId);
    if (!$identity || (int)$identity['doctor_user_id'] !== $doctorId || $identity['patient_user_id'] === null) {
        $db->rollBack();
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only save drafts for your own registered-patient appointments.']);
    }

    // Lock both accounts before locking the appointment row.
    $accountLocks = new ClinicalAccessService($db);
    if (!$accountLocks->lockDoctorAndPatient($doctorId, (int)$identity['patient_user_id'])) {
        $db->rollBack();
        http_response_code(403);
        jsonResponse(false, ['message' => 'Your doctor or patient account relationship is unavailable.']);
    }

    $appointment = $encounters->lockAppointmentContext($appointmentId);
    if (
        !$appointment
        || (int)$appointment['doctor_user_id'] !== $doctorId
        || (int)($appointment['patient_user_id'] ?? 0) !== (int)$identity['patient_user_id']
        || $appointment['status'] !== 'confirmed'
    ) {
        $db->rollBack();
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only save drafts for your own confirmed appointments.']);
    }

    $workflowService = new VisitWorkflowService($db);
    $workflow = $workflowService->get($appointmentId);
    if (($workflow['status'] ?? null) === VisitWorkflowService::STATUS_COMPLETED) {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'Completed visits cannot be edited as drafts.']);
    }

    $draftService = new VisitDraftService($db);
    if (!$draftService->save($appointmentId, $doctorId, $input)) {
        throw new RuntimeException('Could not save visit draft.');
    }

    $db->commit();
    jsonResponse(true, ['message' => 'Draft saved.']);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Save Visit Draft Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to save draft.']);
}
