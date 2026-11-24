<?php
/**
 * HealthBridge — Doctor Clinical Workspace Data API
 * Returns all patient data for the doctor's clinical workspace.
 * Reuses get_emr_data.php logic but adds current-visit context.
 *
 * Permissions: Doctor only — access to patients they have treated
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';
require_once __DIR__ . '/../../services/PatientPortalService.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';

$user = requireRole('doctor');
$currentUserId = (int)$user['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$patientId = parsePositiveInt($_GET['patient_id'] ?? null);
$hasAppointmentId = array_key_exists('appointment_id', $_GET);
$appointmentId = $hasAppointmentId ? parsePositiveInt($_GET['appointment_id']) : null;

if ($patientId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid patient ID is required.']);
}

if ($hasAppointmentId && $appointmentId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid appointment ID is required.']);
}

try {
    $db = getDB();
    $workflowService = new VisitWorkflowService($db);

    // Use the same encounter-evidence policy as every other doctor EMR route.
    $clinicalAccess = new ClinicalAccessService($db);
    if (!$clinicalAccess->doctorCanAccessPatient($currentUserId, $patientId)) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'You can only view records of patients with a confirmed appointment or documented clinical encounter.']);
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
    $profile = (new PatientPortalService($db))->getMedicalProfile($patientId);
    $medicalRecord = [
            'id' => null,
            'patient_id' => $patientId,
            'blood_type' => $profile['blood_type'] ?? null,
            'height_cm' => $profile['height_cm'] ?? null,
            'weight_kg' => $profile['weight_kg'] ?? null,
            'date_of_birth' => $profile['date_of_birth'] ?? null,
            'gender' => $profile['gender'] ?? null,
            'allergies' => $profile['allergies'] ?? null,
            'chronic_diseases' => $profile['chronic_diseases'] ?? null,
            'current_medications' => $profile['current_medications'] ?? null,
            'previous_surgeries' => $profile['previous_surgeries'] ?? null,
            'family_history' => $profile['family_history'] ?? null,
            'emergency_contact_name' => $profile['emergency_contact']['name'] ?? null,
            'emergency_contact_rel' => $profile['emergency_contact']['relationship'] ?? null,
            'emergency_contact_phone' => $profile['emergency_contact']['phone'] ?? null,
            'medical_notes' => $profile['medical_notes'] ?? null,
            'created_at' => null,
            'updated_at' => null,
        ];

    if (!$medicalRecord) {
        // Workspace loading is read-only; explicit clinical edits create the record.
        $medicalRecord = [
            'id' => null,
            'patient_id' => $patientId,
            'blood_type' => null, 'height_cm' => null, 'weight_kg' => null,
            'date_of_birth' => null, 'gender' => null,
            'allergies' => null, 'chronic_diseases' => null,
            'current_medications' => null, 'previous_surgeries' => null,
            'family_history' => null,
            'emergency_contact_name' => null, 'emergency_contact_rel' => null,
            'emergency_contact_phone' => null,
            'medical_notes' => null,
            'created_at' => null, 'updated_at' => null,
        ];
    }

    // Compute age
    $age = null;
    if (!empty($medicalRecord['date_of_birth'])) {
        $dob = new DateTime($medicalRecord['date_of_birth']);
        $now = new DateTime();
        $age = $dob->diff($now)->y;
    }

    // V2 appointment read projection maps normalized columns to the existing
    // doctor-workspace DTO. Conditions appended below are fixed server literals.
    $v2AppointmentSelect = "
        SELECT a.id,a.patient_user_id AS user_id,a.patient_name_snapshot AS patient_name,
               a.doctor_user_id AS doctor_id,a.doctor_name_snapshot AS doctor,
               a.department_id,a.department_name_snapshot AS department,
               DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS date,
               TIME_FORMAT(a.starts_at,'%H:%i') AS time,
               a.source_time_snapshot,a.source_time_range_snapshot,
               a.source_time_range_snapshot AS appointment_time_range,
               a.duration_minutes,a.notes,a.created_at,
               ce.workflow_status,
               CASE
                 WHEN rr.request_status IN ('requested','suggested') THEN 'Reschedule Requested'
                 WHEN a.booking_status='pending' THEN 'Pending'
                 WHEN a.booking_status='confirmed' THEN 'Confirmed'
                 ELSE 'Cancelled'
               END AS status,
               u.name AS doctor_user_name
        FROM appointments a
        LEFT JOIN users u ON u.id=a.doctor_user_id
        LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
        LEFT JOIN appointment_reschedule_requests rr
          ON rr.id=(
            SELECT MAX(rr2.id) FROM appointment_reschedule_requests rr2
            WHERE rr2.appointment_id=a.id
          )
    ";

    // ── 3. Current Visit (the appointment) ──
    $currentVisit = null;
    if ($appointmentId) {
        $visitStmt = $db->prepare(
                $v2AppointmentSelect . " WHERE a.id=? AND a.patient_user_id=? AND a.doctor_user_id=?"
            );
        $visitStmt->execute([$appointmentId, $patientId, $currentUserId]);
        $currentVisit = $visitStmt->fetch();

        if ($currentVisit) {
            $sourceTime = (string)($currentVisit['source_time_snapshot'] ?? '');
            if ($sourceTime !== '' && AppointmentTime::normalize($sourceTime) === AppointmentTime::normalize((string)$currentVisit['time'])) {
                $currentVisit['time'] = $sourceTime;
                $rangeSnapshot = $currentVisit['source_time_range_snapshot'] ?? null;
                $currentVisit['appointment_time_range'] = is_string($rangeSnapshot) && trim($rangeSnapshot) !== ''
                    ? $rangeSnapshot
                    : AppointmentTime::range((string)$currentVisit['time'], max(1, (int)($currentVisit['duration_minutes'] ?? 30)));
            } else {
                $currentVisit['appointment_time_range'] = AppointmentTime::range(
                    (string)$currentVisit['time'],
                    max(1, (int)($currentVisit['duration_minutes'] ?? 30))
                );
            }
        }

        $currentWorkflow = $currentVisit ? $workflowService->get((int)$currentVisit['id']) : null;
        if ($currentVisit) {
            $currentVisit['visit_workflow'] = $currentWorkflow;
        }
        $currentVisitNote = $currentVisit ? $workflowService->getNote((int)$currentVisit['id']) : null;
    } else {
        // No specific appointment: get the latest confirmed appointment with this doctor.
        $visitStmt = $db->prepare(
                $v2AppointmentSelect . " WHERE a.patient_user_id=? AND a.doctor_user_id=?
                                         AND a.booking_status='confirmed'
                                         ORDER BY a.starts_at DESC LIMIT 1"
            );
        $visitStmt->execute([$patientId, $currentUserId]);
        $currentVisit = $visitStmt->fetch();

        if ($currentVisit) {
            $sourceTime = (string)($currentVisit['source_time_snapshot'] ?? '');
            if ($sourceTime !== '' && AppointmentTime::normalize($sourceTime) === AppointmentTime::normalize((string)$currentVisit['time'])) {
                $currentVisit['time'] = $sourceTime;
                $rangeSnapshot = $currentVisit['source_time_range_snapshot'] ?? null;
                $currentVisit['appointment_time_range'] = is_string($rangeSnapshot) && trim($rangeSnapshot) !== ''
                    ? $rangeSnapshot
                    : AppointmentTime::range((string)$currentVisit['time'], max(1, (int)($currentVisit['duration_minutes'] ?? 30)));
            } else {
                $currentVisit['appointment_time_range'] = AppointmentTime::range(
                    (string)$currentVisit['time'],
                    max(1, (int)($currentVisit['duration_minutes'] ?? 30))
                );
            }
        }

        $currentWorkflow = $currentVisit ? $workflowService->get((int)$currentVisit['id']) : null;
        if ($currentVisit) {
            $currentVisit['visit_workflow'] = $currentWorkflow;
        }
        $currentVisitNote = $currentVisit ? $workflowService->getNote((int)$currentVisit['id']) : null;
    }

    // ── 4. All Appointments (for timeline) ──
    $apptStmt = $db->prepare($v2AppointmentSelect . " WHERE a.patient_user_id=? ORDER BY a.starts_at DESC");
    $apptStmt->execute([$patientId]);
    $appointments = $apptStmt->fetchAll();

    // Read workflow DTOs through the shared repository. A missing workflow stays null;
    // workspace reads must never create one.
    $apptIds = array_map(static fn(array $appt): int => (int)$appt['id'], $appointments);
    $workflowByAppointment = $workflowService->getForAppointments($apptIds);
    foreach ($appointments as &$appt) {
        $sourceTime = (string)($appt['source_time_snapshot'] ?? '');
        if ($sourceTime !== '' && AppointmentTime::normalize($sourceTime) === AppointmentTime::normalize((string)$appt['time'])) {
            $appt['time'] = $sourceTime;
            $rangeSnapshot = $appt['source_time_range_snapshot'] ?? null;
            $appt['appointment_time_range'] = is_string($rangeSnapshot) && trim($rangeSnapshot) !== ''
                ? $rangeSnapshot
                : AppointmentTime::range((string)$appt['time'], max(1, (int)($appt['duration_minutes'] ?? 30)));
        } else {
            $appt['appointment_time_range'] = AppointmentTime::range(
                (string)$appt['time'],
                max(1, (int)($appt['duration_minutes'] ?? 30))
            );
        }

        $appt['visit_workflow'] = $workflowByAppointment[(int)$appt['id']] ?? null;
    }
    unset($appt);

    // ── 5. Prescriptions ──
    $prescriptionService = new PrescriptionService($db);
    $prescriptions = $prescriptionService->attachItems(
        $prescriptionService->getByPatient($patientId),
        'item_count'
    );

    // ── 6. Visit Notes (for timeline) ──
    $visitNotes = $workflowService->getNotesForPatient($patientId);

    // ── 6.5. Medical History Updates (for timeline) ──
    $mhUpdateStmt = $db->prepare(
            "SELECT mhe.id,mhe.patient_user_id AS patient_id,
                    mhe.doctor_user_id AS doctor_id,mhe.field_name,
                    mhe.old_value,mhe.new_value,mhe.created_at,
                    u.name AS doctor_name
             FROM medical_history_events mhe
             LEFT JOIN users u ON mhe.doctor_user_id=u.id
             WHERE mhe.patient_user_id=?
             ORDER BY mhe.created_at DESC,mhe.id DESC"
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

} catch (Throwable $e) {
    error_log('Get Doctor Workspace Data Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load patient workspace data.']);
}
