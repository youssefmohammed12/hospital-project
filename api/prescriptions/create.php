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
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';

header('Content-Type: application/json');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];
$currentName   = $user['name'] ?? 'A doctor';

// Only doctors can issue prescriptions
if ($currentRole !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can issue prescriptions.']);
}

$input = getJsonInput();
$appointmentId = (int)($input['appointment_id'] ?? 0);
$notes         = trim($input['notes'] ?? '');
$items         = $input['items'] ?? [];

if (!$appointmentId) {
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

    // ── Get appointment details ──
    $apptStmt = $db->prepare(
        "SELECT a.*, u.name as patient_name FROM appointments a
         LEFT JOIN users u ON a.user_id = u.id
         WHERE a.id = ? LIMIT 1"
    );
    $apptStmt->execute([$appointmentId]);
    $appt = $apptStmt->fetch();

    if (!$appt) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Appointment not found.']);
    }

    // ── Authorization: Doctor can only prescribe for their own appointments ──
    if ((int)$appt['doctor_id'] !== $currentUserId) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only issue prescriptions for your own appointments.']);
    }

    // Only allow prescriptions for Confirmed appointments
    if ($appt['status'] !== 'Confirmed') {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Prescriptions can only be issued for confirmed appointments.']);
    }

    $patientId = (int)$appt['user_id'];
    if (!$patientId) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Cannot issue prescription: patient is not a registered user.']);
    }

    // ── Check if a prescription already exists for this appointment ──
    $ps = new PrescriptionService($db);
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

    // ── Notify the patient ──
    $ns = new NotificationService($db);
    $ns->create(
        $patientId,
        NotificationService::TYPE_PRESCRIPTION_ISSUED,
        'New Prescription Issued',
        "Dr. {$appt['doctor']} has issued a new prescription for your recent appointment. You can now view it in your Prescriptions section.",
        'prescription',
        $prescriptionId
    );

    // Log to audit (doctor doing the prescribing)
    $audit = new AuditService($db, (int)$user['id'], $user['role']);
    $audit->log('create', 'prescription', $prescriptionId, null, 
        ['patient_id' => $patientId, 'doctor_id' => $currentUserId],
        "Prescription created for patient ID $patientId", $patientId, $currentUserId);

    jsonResponse(true, [
        'message'         => 'Prescription issued successfully.',
        'prescription_id' => $prescriptionId,
    ]);

} catch (Exception $e) {
    error_log('Create Prescription Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to issue prescription.']);
}
