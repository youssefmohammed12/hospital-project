<?php
/**
 * HealthBridge — Doctor Clinical Workspace Data API
 * Returns all patient data for the doctor's clinical workspace.
 * Reuses get_emr_data.php logic but adds current-visit context.
 *
 * Permissions: Doctor only — access to patients they have treated
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

if ($currentRole !== 'doctor') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only doctors can access this endpoint.']);
}

$patientId = (int)($_GET['patient_id'] ?? 0);
$appointmentId = (int)($_GET['appointment_id'] ?? 0);

if (!$patientId) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID is required.']);
}

try {
    $db = getDB();

    // ── Authorization: doctor must have treated this patient ──
    $authStmt = $db->prepare(
        "SELECT COUNT(*) as cnt FROM appointments
         WHERE doctor_id = ? AND user_id = ? AND status IN ('Confirmed','Cancelled')
         LIMIT 1"
    );
    $authStmt->execute([$currentUserId, $patientId]);
    $authRow = $authStmt->fetch();
    if ((int)$authRow['cnt'] === 0) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only view records of patients you have treated.']);
    }

    // ── 1. Patient Info ──
    $stmt = $db->prepare(
        "SELECT id, name, email, phone, is_active, created_at
         FROM users WHERE id = ? AND role = 'patient' LIMIT 1"
    );
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch();

    if (!$patient) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Patient not found.']);
    }

    // ── 2. Medical Record ──
    $mrStmt = $db->prepare("SELECT * FROM medical_records WHERE patient_id = ? LIMIT 1");
    $mrStmt->execute([$patientId]);
    $medicalRecord = $mrStmt->fetch();

    if (!$medicalRecord) {
        $insertStmt = $db->prepare("INSERT INTO medical_records (patient_id) VALUES (?)");
        $insertStmt->execute([$patientId]);
        $medicalRecord = [
            'id' => (int)$db->lastInsertId(),
            'patient_id' => $patientId,
            'blood_type' => null, 'height_cm' => null, 'weight_kg' => null,
            'date_of_birth' => null, 'gender' => null,
            'allergies' => null, 'chronic_diseases' => null,
            'current_medications' => null, 'previous_surgeries' => null,
            'family_history' => null,
            'emergency_contact_name' => null, 'emergency_contact_rel' => null,
            'emergency_contact_phone' => null,
            'medical_notes' => null,
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => null,
        ];
    }

    // Compute age
    $age = null;
    if (!empty($medicalRecord['date_of_birth'])) {
        $dob = new DateTime($medicalRecord['date_of_birth']);
        $now = new DateTime();
        $age = $dob->diff($now)->y;
    }

    // ── 3. Current Visit (the appointment) ──
    $currentVisit = null;
    if ($appointmentId) {
        $visitStmt = $db->prepare(
            "SELECT a.*, u.name as doctor_user_name, vw.status as workflow_status, 
                    vw.started_at as workflow_started_at, vw.completed_at as workflow_completed_at
             FROM appointments a
             LEFT JOIN users u ON a.doctor_id = u.id
             LEFT JOIN visit_workflow vw ON a.id = vw.appointment_id
             WHERE a.id = ? AND a.user_id = ? AND a.doctor_id = ?"
        );
        $visitStmt->execute([$appointmentId, $patientId, $currentUserId]);
        $currentVisit = $visitStmt->fetch();
        
        if ($currentVisit && empty($currentVisit['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$currentVisit['doctor_id']);
            $currentVisit['appointment_time_range'] = computeAppointmentTimeRange($currentVisit['time'], $duration);
        }

        // Build visit_workflow object from LEFT JOIN data
        if ($currentVisit && !empty($currentVisit['workflow_status'])) {
            $currentVisit['visit_workflow'] = [
                'appointment_id' => (int)$currentVisit['id'],
                'status' => $currentVisit['workflow_status'],
                'started_at' => $currentVisit['workflow_started_at'],
                'completed_at' => $currentVisit['workflow_completed_at'],
            ];
        }

        // Check if there's already a visit note for this appointment
        $noteStmt = $db->prepare(
            "SELECT * FROM visit_notes WHERE appointment_id = ? AND patient_id = ? LIMIT 1"
        );
        $noteStmt->execute([$appointmentId, $patientId]);
        $currentVisitNote = $noteStmt->fetch() ?: null;
    } else {
        // No specific appointment: get latest confirmed
        $visitStmt = $db->prepare(
            "SELECT a.*, u.name as doctor_user_name, vw.status as workflow_status, 
                    vw.started_at as workflow_started_at, vw.completed_at as workflow_completed_at
             FROM appointments a
             LEFT JOIN users u ON a.doctor_id = u.id
             LEFT JOIN visit_workflow vw ON a.id = vw.appointment_id
             WHERE a.user_id = ? AND a.doctor_id = ? AND a.status = 'Confirmed'
             ORDER BY a.date DESC LIMIT 1"
        );
        $visitStmt->execute([$patientId, $currentUserId]);
        $currentVisit = $visitStmt->fetch();
        
        if ($currentVisit && empty($currentVisit['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$currentVisit['doctor_id']);
            $currentVisit['appointment_time_range'] = computeAppointmentTimeRange($currentVisit['time'], $duration);
        }

        // Build visit_workflow object from LEFT JOIN data
        if ($currentVisit && !empty($currentVisit['workflow_status'])) {
            $currentVisit['visit_workflow'] = [
                'appointment_id' => (int)$currentVisit['id'],
                'status' => $currentVisit['workflow_status'],
                'started_at' => $currentVisit['workflow_started_at'],
                'completed_at' => $currentVisit['workflow_completed_at'],
            ];
        }

        $currentVisitNote = null;
        if ($currentVisit) {
            $noteStmt = $db->prepare(
                "SELECT * FROM visit_notes WHERE appointment_id = ? AND patient_id = ? LIMIT 1"
            );
            $noteStmt->execute([$currentVisit['id'], $patientId]);
            $currentVisitNote = $noteStmt->fetch() ?: null;
        }
    }

    // ── 4. All Appointments (for timeline) - Phase 5.4.1: LEFT JOIN visit_workflow ──
    $apptStmt = $db->prepare(
        "SELECT a.*, u.name as doctor_user_name, vw.status as workflow_status, 
                vw.started_at as workflow_started_at, vw.completed_at as workflow_completed_at
         FROM appointments a
         LEFT JOIN users u ON a.doctor_id = u.id
         LEFT JOIN visit_workflow vw ON a.id = vw.appointment_id
         WHERE a.user_id = ?
         ORDER BY a.date DESC, a.time DESC"
    );
    $apptStmt->execute([$patientId]);
    $appointments = $apptStmt->fetchAll();

    // Phase 5.4.1: Use LEFT JOIN data only - no auto-creation to preserve workflow state
    $apptIds = [];
    foreach ($appointments as &$appt) {
        $apptIds[] = (int)$appt['id'];
        
        if (empty($appt['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$appt['doctor_id']);
            $appt['appointment_time_range'] = computeAppointmentTimeRange($appt['time'], $duration);
        }

        // Build visit_workflow object from LEFT JOIN data only
        if ($appt['workflow_status']) {
            $appt['visit_workflow'] = [
                'appointment_id' => (int)$appt['id'],
                'status' => $appt['workflow_status'],
                'started_at' => $appt['workflow_started_at'],
                'completed_at' => $appt['workflow_completed_at'],
            ];
        } else {
            $appt['visit_workflow'] = null;
        }
    }
    unset($appt);

    // ── 5. Prescriptions ──
    $rxStmt = $db->prepare(
        "SELECT p.*, u.name as doctor_name, a.date as appt_date, a.department,
                (SELECT COUNT(*) FROM prescription_items WHERE prescription_id = p.id) as item_count
         FROM prescriptions p
         JOIN users u ON p.doctor_id = u.id
         JOIN appointments a ON p.appointment_id = a.id
         WHERE p.patient_id = ?
         ORDER BY p.created_at DESC"
    );
    $rxStmt->execute([$patientId]);
    $prescriptions = $rxStmt->fetchAll();

    $rxItemStmt = $db->prepare(
        "SELECT * FROM prescription_items WHERE prescription_id = ? ORDER BY sort_order"
    );
    foreach ($prescriptions as &$rx) {
        $rxItemStmt->execute([$rx['id']]);
        $rx['items'] = $rxItemStmt->fetchAll();
    }
    unset($rx);

    // ── 6. Visit Notes (for timeline) ──
    $visitNoteStmt = $db->prepare(
        "SELECT vn.*, a.date as appt_date, a.time as appt_time, a.department,
                u.name as doctor_name
         FROM visit_notes vn
         JOIN appointments a ON vn.appointment_id = a.id
         LEFT JOIN users u ON vn.doctor_id = u.id
         WHERE vn.patient_id = ?
         ORDER BY vn.created_at DESC"
    );
    $visitNoteStmt->execute([$patientId]);
    $visitNotes = $visitNoteStmt->fetchAll();

    // ── 6.5. Medical History Updates (for timeline) - Phase 5.3.2 ──
    $mhUpdateStmt = $db->prepare(
        "SELECT mhu.*, u.name as doctor_name
         FROM medical_history_updates mhu
         LEFT JOIN users u ON mhu.doctor_id = u.id
         WHERE mhu.patient_id = ?
         ORDER BY mhu.created_at DESC"
    );
    $mhUpdateStmt->execute([$patientId]);
    $medicalHistoryUpdates = $mhUpdateStmt->fetchAll();

    // ── 7. Current Visit Workflow - Phase 5.4.1: Already embedded in current_visit from LEFT JOIN
    $visitWorkflow = $currentVisit['visit_workflow'] ?? null;

    // ── 9. Summary Stats ──
    $totalAppts = count($appointments);
    $totalPrescriptions = count($prescriptions);
    $totalVisitNotes = count($visitNotes);

    // Allergies and chronic conditions
    $allergies = $medicalRecord['allergies'] ?? null;
    $chronicDiseases = $medicalRecord['chronic_diseases'] ?? null;
    $currentMedications = $medicalRecord['current_medications'] ?? null;

    jsonResponse(true, [
        'patient'              => $patient,
        'medical_record'       => $medicalRecord,
        'age'                  => $age,
        'current_visit'        => $currentVisit,
        'current_visit_note'   => $currentVisitNote,
        'visit_workflow'       => $visitWorkflow,
        'appointments'         => $appointments,
        'prescriptions'        => $prescriptions,
        'visit_notes'          => $visitNotes,
        'medical_history_updates' => $medicalHistoryUpdates,
        'allergies'            => $allergies,
        'chronic_diseases'     => $chronicDiseases,
        'current_medications'  => $currentMedications,
        'stats'                => [
            'total_appointments'  => $totalAppts,
            'total_prescriptions' => $totalPrescriptions,
            'total_visit_notes'   => $totalVisitNotes,
        ],
        'can_edit'             => true,
    ]);

} catch (Exception $e) {
    error_log('Get Doctor Workspace Data Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load patient workspace data.']);
}
