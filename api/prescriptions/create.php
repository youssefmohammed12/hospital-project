<?php
/**
 * HealthBridge — Create Prescription
 * Creates a new prescription with multiple medication items.
 *
 * Permissions:
 *   - Doctor: can create prescriptions only for their own appointments
 *   - Admin: CANNOT create prescriptions
 *   - Patient: CANNOT create prescriptions
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';
require_once __DIR__ . '/../../services/Repositories/ClinicalEncounterRepository.php';

header('Content-Type: application/json');

$user = requireRole('doctor');
$currentUserId = (int)$user['id'];
$schema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PRESCRIPTIONS_SCHEMA');
$currentName   = $user['name'] ?? 'A doctor';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();
$appointmentId = parsePositiveInt($input['appointment_id'] ?? null);
$notesInput    = $input['notes'] ?? '';
$items         = $input['items'] ?? [];

if (!is_string($notesInput)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription notes must be text.']);
}
$notes = trim($notesInput);
if (mb_strlen($notes) > 5000) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Prescription notes must not exceed 5000 characters.']);
}

if (!is_array($items)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Medication items must be provided as a list.']);
}

if ($appointmentId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Appointment ID is required.']);
}

// Validate items
$validationError = PrescriptionService::validateItems($items);
if ($validationError) {
    http_response_code(400);
    jsonResponse(false, ['message' => $validationError]);
}

try {
    $db = getDB();

    // Resolve a schema-neutral preflight context. The service repeats
    // assignment, eligibility and duplicate checks under row locks on create.
    $appointments = new ClinicalEncounterRepository($db, $schema);
    $appt = $appointments->getAppointmentContext($appointmentId);

    if (!$appt) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }
    if ((int)$appt['doctor_user_id'] !== $currentUserId) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only issue prescriptions for your own appointments.']);
    }
    if ($appt['status'] !== 'confirmed') {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Prescriptions can only be issued for confirmed appointments.']);
    }

    $patientId = (int)($appt['patient_user_id'] ?? 0);
    if ($patientId <= 0) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Cannot issue prescription: patient is not a registered user.']);
    }

    $ps = new PrescriptionService($db, $schema);
    $existing = $ps->getByAppointment($appointmentId);
    if ($existing) {
        http_response_code(409);
        jsonResponse(false, [
            'message' => 'A prescription already exists for this appointment. Each appointment can have only one prescription.',
            'prescription_id' => (int)$existing['id'],
        ]);
    }

    // ── Create prescription ──
    $prescriptionId = $ps->create(
        $patientId,
        $currentUserId,
        $appointmentId,
        $items,
        $notes ?: null
    );

    jsonResponse(true, [
        'message'         => 'Prescription issued successfully.',
        'prescription_id' => $prescriptionId,
    ]);

} catch (DomainException $e) {
    http_response_code(409);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
        http_response_code(409);
        jsonResponse(false, ['message' => 'A prescription already exists for this appointment or the appointment is no longer eligible.']);
    }
    error_log('Create Prescription Database Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to issue prescription.']);
} catch (Throwable $e) {
    error_log('Create Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to issue prescription.']);
}
