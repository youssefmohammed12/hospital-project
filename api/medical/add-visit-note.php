<?php
/**
 * HealthBridge — Add or Update Visit Note
 *
 * Doctors may edit notes for their own appointments; administrators may
 * correct notes for any appointment. Appointment identity and account locks
 * are resolved through the schema-aware clinical repository.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/Repositories/ClinicalEncounterRepository.php';

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole = (string)$user['role'];
$currentName = (string)($user['name'] ?? 'A clinician');


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

if (!in_array($currentRole, ['doctor', 'admin'], true)) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors and administrators can add visit notes.']);
}

$input = getJsonInput();
$appointmentId = parsePositiveInt($input['appointment_id'] ?? null);

$noteFields = ['diagnosis', 'symptoms', 'treatment', 'doctor_notes', 'follow_up_instructions'];
$values = [];
foreach ($noteFields as $field) {
    $value = $input[$field] ?? '';
    if (!is_string($value)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Visit note fields must be text.']);
    }
    $value = trim($value);
    if (mb_strlen($value) > 10000) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Visit note fields must not exceed 10000 characters.']);
    }
    $values[$field] = $value;
}

if ($appointmentId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid appointment ID is required.']);
}
if ($values['diagnosis'] === '' && $values['doctor_notes'] === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'At least diagnosis or doctor notes must be provided.']);
}

$db = null;

try {
    $db = getDB();
    $db->beginTransaction();

    $encounters = new ClinicalEncounterRepository($db);
    $identity = $encounters->getAppointmentContext($appointmentId);
    if (!$identity) {
        $db->rollBack();
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }

    $assignedDoctorId = (int)$identity['doctor_user_id'];
    $identityPatientId = $identity['patient_user_id'] !== null
        ? (int)$identity['patient_user_id']
        : null;

    // Account rows are locked before appointment/workflow rows.
    $accountLocks = new ClinicalAccessService($db);
    if (!$accountLocks->lockDoctorAndPatient($assignedDoctorId, $identityPatientId)) {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'The appointment account relationship is no longer available.']);
    }

    $appointment = $encounters->lockAppointmentContext($appointmentId);
    if (!$appointment) {
        $db->rollBack();
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }

    if (
        (int)$appointment['doctor_user_id'] !== $assignedDoctorId
        || (int)($appointment['patient_user_id'] ?? 0) !== (int)($identityPatientId ?? 0)
    ) {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'The appointment account relationship changed. Refresh and try again.']);
    }

    if ($currentRole === 'doctor' && (int)$appointment['doctor_user_id'] !== $currentUserId) {
        $db->rollBack();
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only add notes to your own appointments.']);
    }

    if ($appointment['status'] !== 'confirmed') {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'Visit notes can only be added to confirmed appointments.']);
    }

    $patientId = (int)($appointment['patient_user_id'] ?? 0);
    $assignedDoctorId = (int)$appointment['doctor_user_id'];
    if ($patientId <= 0) {
        $db->rollBack();
        http_response_code(409);
        jsonResponse(false, ['message' => 'A registered patient account is required for a clinical visit note.']);
    }

    // The appointment row is already locked; this serializes note writes.
    $workflowService = new VisitWorkflowService($db);
    $existing = $workflowService->getNote($appointmentId);

    $newValues = [
        'diagnosis' => $values['diagnosis'] !== '' ? $values['diagnosis'] : null,
        'symptoms' => $values['symptoms'] !== '' ? $values['symptoms'] : null,
        'treatment' => $values['treatment'] !== '' ? $values['treatment'] : null,
        'doctor_notes' => $values['doctor_notes'] !== '' ? $values['doctor_notes'] : null,
        'follow_up_instructions' => $values['follow_up_instructions'] !== '' ? $values['follow_up_instructions'] : null,
    ];

    $workflowService->saveNote($appointmentId, $patientId, $assignedDoctorId, $newValues);
    if ($existing) {
        $action = 'update';
        $oldValues = $existing;
        $successMessage = 'Visit note updated successfully.';
        $notificationTitle = 'Visit Note Updated';
        $notificationMessage = "The clinical note for your appointment on {$appointment['appointment_date']} has been updated by {$currentName}.";
    } else {
        $action = 'create';
        $oldValues = null;
        $successMessage = 'Visit note added successfully.';
        $notificationTitle = 'New Visit Notes Added';
        $notificationMessage = "Clinical visit notes for your appointment on {$appointment['appointment_date']} are now available in your Medical Record.";
    }

    $audit = new AuditService($db, $currentUserId, $currentRole);
    $auditLogged = $audit->log(
        $action,
        'patient',
        $patientId,
        $oldValues,
        $newValues,
        "Visit note {$action}d for appointment #{$appointmentId} by {$currentName}",
        $patientId,
        $assignedDoctorId
    );

    if (!$auditLogged) {
        throw new RuntimeException('Could not record the visit note audit event.');
    }

    // Persist the notification event with the clinical note and audit.
    $notifications = new NotificationService($db);
    $notifications->create(
        $patientId,
        NotificationService::TYPE_VISIT_NOTE_ADDED,
        $notificationTitle,
        $notificationMessage,
        'appointment',
        $appointmentId
    );

    $db->commit();

    // Delivery is best-effort after commit; the outbox worker handles retries.
    try {
        $notifications->processOutbox(50);
    } catch (Throwable $dispatchError) {
        error_log('Visit note committed; notification dispatch deferred: ' . get_class($dispatchError));
    }

    jsonResponse(true, ['message' => $successMessage]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Add Visit Note Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to save visit note. Please try again.']);
}
