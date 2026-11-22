<?php
/**
 * HealthBridge — Atomic Complete Visit Endpoint (Phase 5.4)
 * Minimal test version
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/VisitDraftService.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';
require_once __DIR__ . '/../../services/Repositories/ClinicalEncounterRepository.php';

header('Content-Type: application/json');

$user = requireRole('doctor');
$schema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_CLINICAL_SCHEMA');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$appointmentId = parsePositiveInt($input['appointment_id'] ?? null);
$doctorId = (int)$user['id'];

if ($appointmentId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid appointment ID is required.']);
}

$clinicalFields = ['diagnosis', 'symptoms', 'treatment', 'doctor_notes', 'follow_up_instructions'];
foreach ($clinicalFields as $field) {
    if (isset($input[$field]) && !is_string($input[$field])) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Clinical note fields must be text.']);
    }
}
$diagnosis = trim($input['diagnosis'] ?? '');
$symptoms = trim($input['symptoms'] ?? '');
$treatment = trim($input['treatment'] ?? '');
$doctorNotes = trim($input['doctor_notes'] ?? '');
$followUpInstructions = trim($input['follow_up_instructions'] ?? '');

if ($diagnosis === '' || $symptoms === '' || $treatment === '') {
    http_response_code(422);
    jsonResponse(false, ['message' => 'Diagnosis, symptoms, and treatment plan are required before completing a visit.']);
}
foreach ([$diagnosis, $symptoms, $treatment, $doctorNotes, $followUpInstructions] as $value) {
    if (mb_strlen($value) > 10000) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Clinical note fields must not exceed 10000 characters.']);
    }
}

try {
    $db = getDB();
    $db->beginTransaction();

    // Resolve appointment identity, then lock doctor -> patient -> appointment.
    $encounters = new ClinicalEncounterRepository($db, $schema);
    $identity = $encounters->getAppointmentContext($appointmentId);
    if (
        !$identity
        || (int)$identity['doctor_user_id'] !== $doctorId
        || $identity['patient_user_id'] === null
        || $identity['status'] !== 'confirmed'
    ) {
        $db->rollBack();
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found or not confirmed.']);
    }

    $patientId = (int)$identity['patient_user_id'];
    $accountLocks = new ClinicalAccessService($db, $schema);
    if (!$accountLocks->lockDoctorAndPatient($doctorId, $patientId)) {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'The appointment account relationship is no longer available.']);
    }

    $appointment = $encounters->lockAppointmentContext($appointmentId);
    if (
        !$appointment
        || (int)$appointment['doctor_user_id'] !== $doctorId
        || (int)($appointment['patient_user_id'] ?? 0) !== $patientId
        || $appointment['status'] !== 'confirmed'
    ) {
        $db->rollBack();
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found or not confirmed.']);
    }

    // Load and lock workflow before performing any clinical side effects.
    $wfService = new VisitWorkflowService($db, $schema);
    $workflowBefore = $wfService->getOrCreateWorkflow($appointmentId);
    if (($workflowBefore['status'] ?? null) === VisitWorkflowService::STATUS_COMPLETED) {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'This visit has already been completed.']);
    }
    if (($workflowBefore['status'] ?? null) === VisitWorkflowService::STATUS_WAITING) {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'Start the visit before completing it.']);
    }

    // 1. Save the required clinical note, including the follow-up instructions
    // collected by the doctor workspace.
    $noteValues = [
        'diagnosis' => $diagnosis,
        'symptoms' => $symptoms,
        'treatment' => $treatment,
        'doctor_notes' => $doctorNotes !== '' ? $doctorNotes : null,
        'follow_up_instructions' => $followUpInstructions !== '' ? $followUpInstructions : null,
    ];
    $wfService->saveNote(
        $appointmentId,
        $patientId,
        $doctorId,
        $noteValues
    );

    // 2. Finalize active prescriptions inside this same clinical transaction.
    $prescriptionService = new PrescriptionService($db, $schema);
    $prescriptionService->completeForAppointmentInTransaction($appointmentId, $doctorId);

    // 3. Complete the locked workflow only after note and prescription writes succeed.
    $wfService->transition($appointmentId, VisitWorkflowService::STATUS_COMPLETED);

    // Keep an explicit audit event in the same transaction as the clinical completion.
    $audit = new AuditService($db, $doctorId, 'doctor', $schema);
    $auditLogged = $audit->log(
        'complete_visit',
        'appointment',
        $appointmentId,
        ['workflow_status' => $workflowBefore['status'] ?? null],
        ['workflow_status' => VisitWorkflowService::STATUS_COMPLETED],
        "Clinical visit completed by doctor {$doctorId}",
        $patientId,
        $doctorId
    );
    if (!$auditLogged) {
        throw new RuntimeException('Could not record the clinical completion audit event.');
    }

    // 4. Delete draft
    $draftService = new VisitDraftService($db, $schema);
    $draftService->delete($appointmentId);

    // Queue the notice in the same transaction as the clinical completion.
    // notification_outbox has no recipient FK, so this does not reacquire the
    // patient account lock that the older notifications table required.
    $ns = new NotificationService($db);
    if ($patientId > 0) {
        $ns->create(
            $patientId,
            NotificationService::TYPE_APPOINTMENT_COMPLETED,
            'Visit Completed',
            "Your appointment with {$appointment['doctor_name']} on {$appointment['appointment_date']} at {$appointment['appointment_time']} has been completed.",
            'appointment',
            $appointmentId
        );
    }

    // The clinical note, prescription updates, workflow, draft, audit and
    // notification outbox event commit or roll back together.
    $db->commit();

    // Attempt prompt delivery only after releasing the clinical transaction.
    // Any dispatch failure leaves the event for the CLI retry worker.
    try {
        $ns->processOutbox(50);
    } catch (Throwable $notificationError) {
        error_log('Complete Visit Outbox Dispatch Error: ' . get_class($notificationError));
    }

    jsonResponse(true, ['message' => 'Visit completed successfully.']);

} catch (DomainException $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(409);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Complete Visit Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to complete visit. Please try again.']);
}

