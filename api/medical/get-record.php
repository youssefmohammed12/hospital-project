<?php
/**
 * HealthBridge — Get Medical Record
 * Returns a patient's medical record and visit history.
 *
 * Permissions:
 *   - Patient: can view their own record only
 *   - Doctor: can view records of patients they have treated
 *   - Admin: can view any patient's record
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

// Determine which patient's record to fetch
$patientId = (int)($_GET['patient_id'] ?? 0);

if ($currentRole === 'patient') {
    // Patients can only view their own record
    $patientId = $currentUserId;
} elseif (!$patientId && $currentRole === 'doctor') {
    // Doctor needs a patient_id parameter
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID is required.']);
} elseif (!$patientId && $currentRole === 'admin') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID is required.']);
}

try {
    $db = getDB();

    // ── Authorization Checks ──
    if ($currentRole === 'doctor') {
        // Doctor must have a confirmed or completed appointment with this patient
        $authStmt = $db->prepare(
            "SELECT COUNT(*) as cnt FROM appointments
             WHERE doctor_id = ? AND user_id = ? AND status IN ('Confirmed', 'Cancelled')
             LIMIT 1"
        );
        $authStmt->execute([$currentUserId, $patientId]);
        $authRow = $authStmt->fetch();
        if ((int)$authRow['cnt'] === 0) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'You can only view records of patients you have treated.']);
        }
    }

    // ── Get patient info ──
    $patientStmt = $db->prepare(
        "SELECT id, name, email, phone, created_at FROM users WHERE id = ? AND role = 'patient' LIMIT 1"
    );
    $patientStmt->execute([$patientId]);
    $patient = $patientStmt->fetch();

    if (!$patient) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Patient not found.']);
    }

    // ── Get medical record ──
    $mrStmt = $db->prepare(
        "SELECT * FROM medical_records WHERE patient_id = ? LIMIT 1"
    );
    $mrStmt->execute([$patientId]);
    $medicalRecord = $mrStmt->fetch();

    // Auto-create medical record if it doesn't exist yet
    if (!$medicalRecord) {
        $insertStmt = $db->prepare("INSERT INTO medical_records (patient_id) VALUES (?)");
        $insertStmt->execute([$patientId]);
        $medicalRecord = [
            'id' => (int)$db->lastInsertId(),
            'patient_id' => $patientId,
            'blood_type' => null,
            'height_cm' => null,
            'weight_kg' => null,
            'date_of_birth' => null,
            'gender' => null,
            'allergies' => null,
            'chronic_diseases' => null,
            'current_medications' => null,
            'previous_surgeries' => null,
            'family_history' => null,
            'emergency_contact_name' => null,
            'emergency_contact_rel' => null,
            'emergency_contact_phone' => null,
            'medical_notes' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => null,
        ];
    }

    // ── Get visit history (visit_notes joined with appointments) ──
    $visitStmt = $db->prepare(
        "SELECT vn.id as visit_id, vn.diagnosis, vn.symptoms, vn.treatment,
                vn.doctor_notes, vn.created_at as visit_date,
                a.id as appointment_id, a.date as appt_date, a.time as appt_time,
                a.department, a.doctor as doctor_name, a.status as appt_status,
                u.name as doctor_display_name, a.doctor_id
         FROM visit_notes vn
         JOIN appointments a ON vn.appointment_id = a.id
         LEFT JOIN users u ON vn.doctor_id = u.id
         WHERE vn.patient_id = ?
         ORDER BY vn.created_at DESC"
    );
    $visitStmt->execute([$patientId]);
    $visitHistory = $visitStmt->fetchAll();
    
    // Add appointment_time_range to visit history — prefer stored value
    foreach ($visitHistory as &$visit) {
        if (empty($visit['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$visit['doctor_id']);
            $visit['appointment_time_range'] = computeAppointmentTimeRange($visit['appt_time'], $duration);
        }
    }
    unset($visit);

    // ── Get completed appointments without visit notes yet ──
    // These are appointments where a doctor can still add notes.
    // Doctors only see their own pending appointments; admins see all.
    if ($currentRole === 'doctor') {
        $pendingNotesStmt = $db->prepare(
            "SELECT a.id, a.date, a.time, a.department, a.doctor,
                    a.status, a.doctor_id
             FROM appointments a
             WHERE a.user_id = ?
               AND a.doctor_id = ?
               AND a.status = 'Confirmed'
               AND a.id NOT IN (SELECT appointment_id FROM visit_notes WHERE patient_id = ?)
             ORDER BY a.date DESC, a.time DESC"
        );
        $pendingNotesStmt->execute([$patientId, $currentUserId, $patientId]);
    } else {
        // Admin sees all pending-for-notes appointments for this patient
        $pendingNotesStmt = $db->prepare(
            "SELECT a.id, a.date, a.time, a.department, a.doctor,
                    a.status, a.doctor_id
             FROM appointments a
             WHERE a.user_id = ?
               AND a.status = 'Confirmed'
               AND a.id NOT IN (SELECT appointment_id FROM visit_notes WHERE patient_id = ?)
             ORDER BY a.date DESC, a.time DESC"
        );
        $pendingNotesStmt->execute([$patientId, $patientId]);
    }
    $pendingForNotes = $pendingNotesStmt->fetchAll();
    
    // Add appointment_time_range to pending for notes — prefer stored value
    foreach ($pendingForNotes as &$pfn) {
        if (empty($pfn['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$pfn['doctor_id']);
            $pfn['appointment_time_range'] = computeAppointmentTimeRange($pfn['time'], $duration);
        }
    }
    unset($pfn);

    jsonResponse(true, [
        'patient'         => $patient,
        'medical_record'  => $medicalRecord,
        'visit_history'   => $visitHistory,
        'pending_for_notes' => $pendingForNotes,
        'can_edit'        => in_array($currentRole, ['doctor', 'admin']),
    ]);

} catch (Exception $e) {
    error_log('Get Medical Record Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load medical record.']);
}
