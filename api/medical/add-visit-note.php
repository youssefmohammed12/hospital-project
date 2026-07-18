<?php
/**
 * HealthBridge — Add Visit Note
 * Adds a doctor's visit note for a completed appointment.
 * Each appointment can have at most one visit note (UNIQUE constraint).
 *
 * Permissions:
 *   - Doctor: can add notes for their own appointments
 *   - Admin: can add notes for any appointment
 *   - Patient: CANNOT add notes
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';

header('Content-Type: application/json');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];
$currentName   = $user['name'] ?? 'A doctor';

// Only doctors and admins can add visit notes
if (!in_array($currentRole, ['doctor', 'admin'])) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors and admins can add visit notes.']);
}

$input = getJsonInput();
$appointmentId = (int)($input['appointment_id'] ?? 0);
$diagnosis     = trim($input['diagnosis'] ?? '');
$symptoms      = trim($input['symptoms'] ?? '');
$treatment     = trim($input['treatment'] ?? '');
$doctorNotes   = trim($input['doctor_notes'] ?? '');

if (!$appointmentId) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Appointment ID is required.']);
}

if (empty($diagnosis) && empty($doctorNotes)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'At least diagnosis or doctor notes must be provided.']);
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

    // ── Authorization ──
    if ($currentRole === 'doctor' && (int)$appt['doctor_id'] !== $currentUserId) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only add notes to your own appointments.']);
    }

    // Only allow notes for Confirmed appointments
    if ($appt['status'] !== 'Confirmed') {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Visit notes can only be added to confirmed appointments.']);
    }

    // ── Check if visit note already exists ──
    $checkStmt = $db->prepare("SELECT id FROM visit_notes WHERE appointment_id = ? LIMIT 1");
    $checkStmt->execute([$appointmentId]);
    if ($checkStmt->fetch()) {
        // Update existing note instead
        $updateStmt = $db->prepare(
            "UPDATE visit_notes SET diagnosis = ?, symptoms = ?, treatment = ?, doctor_notes = ?
             WHERE appointment_id = ?"
        );
        $updateStmt->execute([$diagnosis, $symptoms, $treatment, $doctorNotes, $appointmentId]);

        // ── Notify the patient that visit notes have been updated ──
        if (!empty($appt['user_id'])) {
            $ns = new NotificationService($db);
            $formattedDate = date('M j, Y', strtotime($appt['date']));
            $ns->create(
                (int)$appt['user_id'],
                NotificationService::TYPE_VISIT_NOTE_ADDED,
                'Visit Note Updated',
                "Dr. {$appt['doctor']} updated your visit notes from your appointment on {$formattedDate}.",
                'appointment',
                $appointmentId
            );
        }

        // Log to audit
        $audit = new AuditService($db, (int)$user['id'], $user['role']);
        $audit->log('update', 'patient', (int)$appt['user_id'], null, 
            ['diagnosis' => $diagnosis ?? '', 'treatment' => $treatment ?? ''],
            "Visit note updated for appointment #$appointmentId", (int)$appt['user_id'], $currentUserId);

        jsonResponse(true, ['message' => 'Visit note updated successfully.']);
    }

    // ── Insert new visit note ──
    $insertStmt = $db->prepare(
        "INSERT INTO visit_notes (appointment_id, patient_id, doctor_id, diagnosis, symptoms, treatment, doctor_notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $insertStmt->execute([
        $appointmentId,
        (int)$appt['user_id'],
        $currentUserId,
        $diagnosis,
        $symptoms,
        $treatment,
        $doctorNotes,
    ]);

    // ── Notify the patient that visit notes have been added ──
    if (!empty($appt['user_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$appt['user_id'],
            NotificationService::TYPE_VISIT_NOTE_ADDED,
            'New Visit Notes Added',
            "Dr. {$appt['doctor']} has added visit notes for your appointment on {$appt['date']}. You can now view them in your Medical Record.",
            'appointment',
            $appointmentId
        );
    }

    // Log to audit
    $audit = new AuditService($db, (int)$user['id'], $user['role']);
    $audit->log('create', 'patient', (int)$appt['user_id'], null,
        ['diagnosis' => $diagnosis ?? '', 'treatment' => $treatment ?? ''],
        "Visit note added for appointment #$appointmentId", (int)$appt['user_id'], $currentUserId);

    jsonResponse(true, ['message' => 'Visit note added successfully.']);

} catch (Exception $e) {
    error_log('Add Visit Note Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to add visit note.']);
}
