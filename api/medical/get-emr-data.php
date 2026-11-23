<?php
/**
 * HealthBridge — Unified EMR Data API
 * Returns all patient data for the Electronic Medical Record view.
 * Reuses existing services/queries — no duplicate logic.
 *
 * Permissions:
 *   - Admin: full access to any patient
 *   - Doctor: access to patients they have treated
 *   - Patient: access to own record only
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ClinicalAccessService.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/PrescriptionService.php';
require_once __DIR__ . '/../../services/PatientPortalService.php';
require_once __DIR__ . '/../../services/Repositories/ClinicalEncounterRepository.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

if (!in_array($currentRole, ['admin', 'doctor', 'patient'], true)) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'You do not have permission to view patient records.']);
}

$patientId = parsePositiveInt($_GET['patient_id'] ?? null);

if ($currentRole === 'patient') {
    $patientId = $currentUserId;
} elseif ($patientId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid patient ID is required.']);
}

try {
    $db = getDB();

    // ── Authorization ──
    if ($currentRole === 'doctor') {
        $clinicalAccess = new ClinicalAccessService($db);
        if (!$clinicalAccess->doctorCanAccessPatient($currentUserId, $patientId)) {
            http_response_code(403);
            jsonResponse(false, ['message' => 'You can only view records of patients with a confirmed appointment or documented clinical encounter.']);
        }
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

    // ── 2. Medical Record: V2 profile fields are split across profile tables. ──
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
        // A GET request must not create clinical records; return an empty view model.
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

    // Compute age from date_of_birth
    $age = null;
    if (!empty($medicalRecord['date_of_birth'])) {
        $dob = new DateTime($medicalRecord['date_of_birth']);
        $now = new DateTime();
        $age = $dob->diff($now)->y;
    }

    // ── 3. Appointments ──
    $apptStmt = $db->prepare(
        "SELECT a.id,a.patient_user_id AS user_id,
                a.doctor_user_id AS doctor_id,
                a.doctor_name_snapshot AS doctor,
                a.department_id,a.department_name_snapshot AS department,
                DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS date,
                TIME_FORMAT(a.starts_at,'%H:%i') AS time,
                a.source_time_snapshot,
                a.source_time_range_snapshot,
                a.source_time_range_snapshot AS appointment_time_range,
                a.duration_minutes,a.notes,a.created_at,
                ce.workflow_status,
                CASE
                  WHEN rr.request_status IN ('requested','suggested') THEN 'Reschedule Requested'
                  WHEN a.booking_status='pending' THEN 'Pending'
                  WHEN a.booking_status='confirmed' THEN 'Confirmed'
                  WHEN a.booking_status='cancelled' THEN 'Cancelled'
                  ELSE 'Cancelled'
                END AS status,
                CASE
                  WHEN rr.request_status IN ('requested','suggested') THEN 'pending'
                  WHEN rr.request_status='approved' THEN 'approved'
                  WHEN rr.request_status='rejected' THEN 'rejected'
                  ELSE 'none'
                END AS reschedule_status,
                CASE
                  WHEN rr.request_status='suggested' AND rr.proposed_starts_at IS NOT NULL
                    THEN DATE_FORMAT(rr.proposed_starts_at,'%Y-%m-%d')
                  WHEN rr.request_status IN ('requested','suggested')
                    THEN DATE_FORMAT(rr.requested_starts_at,'%Y-%m-%d')
                  ELSE NULL
                END AS pending_reschedule_date,
                CASE
                  WHEN rr.request_status='suggested' AND rr.proposed_starts_at IS NOT NULL
                    THEN TIME_FORMAT(rr.proposed_starts_at,'%H:%i')
                  WHEN rr.request_status IN ('requested','suggested')
                    THEN TIME_FORMAT(rr.requested_starts_at,'%H:%i')
                  ELSE NULL
                END AS pending_reschedule_time,
                rr.reason AS reschedule_reason,
                rr.requested_at AS reschedule_requested_at,
                u.name AS doctor_user_name
         FROM appointments a
         LEFT JOIN users u ON a.doctor_user_id=u.id
         LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
         LEFT JOIN appointment_reschedule_requests rr
           ON rr.id=(
             SELECT MAX(rr2.id) FROM appointment_reschedule_requests rr2
             WHERE rr2.appointment_id=a.id
           )
         WHERE a.patient_user_id=?
         ORDER BY a.starts_at DESC"
    );
    $apptStmt->execute([$patientId]);
    $appointments = $apptStmt->fetchAll();

    // V2 preserves the source display time/range unless the appointment moved;
    // moved appointments use their retained duration, not a legacy doctor row.
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
    }
    unset($appt);

    // ── 4. Prescriptions ──
    $prescriptionService = new PrescriptionService($db);
    $prescriptions = $prescriptionService->attachItems(
        $prescriptionService->getByPatient($patientId),
        'item_count'
    );

    // ── 5. Visit Notes ──
    $workflowService = new VisitWorkflowService($db);
    $visitNotes = $workflowService->getNotesForPatient($patientId);

    // ── 6. Ratings ──
    $ratingStmt = $db->prepare(
        "SELECT r.id,r.appointment_id,
                r.patient_user_id AS user_id,r.doctor_user_id AS doctor_id,
                r.stars,r.review,r.is_public,(1-r.is_public) AS is_hidden,r.created_at,
                u.name AS doctor_name,
                DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS appt_date,
                TIME_FORMAT(a.starts_at,'%H:%i') AS appt_time,
                a.doctor_name_snapshot AS doctor,
                a.department_name_snapshot AS department
         FROM ratings r
         JOIN users u ON r.doctor_user_id=u.id
         JOIN appointments a ON r.appointment_id=a.id
         WHERE r.patient_user_id=?
         ORDER BY r.created_at DESC"
    );
    $ratingStmt->execute([$patientId]);
    $ratings = $ratingStmt->fetchAll();

    // ── 7. Notifications ──
    $notifStmt = $db->prepare(
        "SELECT id, type, title, message, ref_type, ref_id, is_read, created_at
         FROM notifications WHERE user_id = ?
         ORDER BY created_at DESC LIMIT 50"
    );
    $notifStmt->execute([$patientId]);
    $notifications = $notifStmt->fetchAll();

    // ── 8. Summary Stats ──
    $totalAppts = count($appointments);
    $upcomingAppts = 0;
    $completedAppts = 0;
    $cancelledAppts = 0;
    $totalPrescriptions = count($prescriptions);
    $totalVisitNotes = count($visitNotes);
    $totalMedicalRecords = !empty($medicalRecord['id']) ? 1 : 0;
    $profileExistsStmt = $db->prepare(
            'SELECT 1 FROM patient_medical_profiles WHERE patient_user_id=? LIMIT 1'
        );
    $profileExistsStmt->execute([$patientId]);
    $totalMedicalRecords = $profileExistsStmt->fetchColumn() ? 1 : 0;
    $totalRatings = count($ratings);
    $avgRating = 0;

    foreach ($appointments as $a) {
        if ($a['status'] === 'Confirmed') {
            if (($a['workflow_status'] ?? null) === 'completed') {
                $completedAppts++;
            } elseif ($a['date'] >= date('Y-m-d')) {
                $upcomingAppts++;
            }
        } elseif ($a['status'] === 'Reschedule Requested') {
            if ($a['date'] >= date('Y-m-d')) {
                $upcomingAppts++;
            }
        } elseif ($a['status'] === 'Cancelled') {
            $cancelledAppts++;
        } elseif ($a['status'] === 'Pending') {
            $upcomingAppts++;
        }
    }

    foreach ($ratings as $r) {
        $avgRating += (int)$r['stars'];
    }
    if ($totalRatings > 0) {
        $avgRating = round($avgRating / $totalRatings, 1);
    }

    // ── 9. Upcoming appointment ──
    $upcomingAppointment = null;
    // The V2 query is already ordered by starts_at DESC; reverse a copy
    // so the first qualifying row is the earliest upcoming appointment.
    $upcomingCandidates = array_reverse($appointments);
    $today = date('Y-m-d');
    foreach ($upcomingCandidates as $candidate) {
        if (
            $candidate['date'] >= $today
            && in_array($candidate['status'], ['Confirmed', 'Pending', 'Reschedule Requested'], true)
        ) {
            $upcomingAppointment = $candidate;
            break;
        }
    }

    // ── 10. Latest appointment ──
    // Appointment rows are already ordered by starts_at DESC in V2.
    $latestAppointment = $appointments[0] ?? null;

    // ── 11. Latest prescription ──
    $latestRx = !empty($prescriptions) ? $prescriptions[0] : null;

    // ── 12. Latest visit note ──
    $latestVisit = !empty($visitNotes) ? $visitNotes[0] : null;

    // ── 13. Latest medical record / assigned doctor(s) ──
    $doctorsVisited = [];
    $seenDoctors = [];
    foreach ($appointments as $a) {
        if (in_array($a['status'], ['Confirmed','Pending','Reschedule Requested'], true) && !in_array($a['doctor_id'], $seenDoctors, true)) {
            $seenDoctors[] = $a['doctor_id'];
            $doctorsVisited[] = [
                'id' => $a['doctor_id'],
                'name' => $a['doctor'] ?: ($a['doctor_user_name'] ?? ''),
                'department' => $a['department']
            ];
        }
    }

    // ── 14. Audit History (Admin only) ──
    $auditHistory = [];
    if ($currentRole === 'admin') {
        $auditStmt = $db->prepare("
            SELECT ae.id,ae.old_value,ae.new_value,ae.description,
                   ae.occurred_at AS created_at,u.name AS admin_name
            FROM audit_events ae
            JOIN users u ON u.id=ae.actor_user_id
            WHERE ae.patient_user_id=?
              AND ae.action_code='medical_record_admin_correction'
              AND ae.entity_type='patient'
            ORDER BY ae.occurred_at DESC,ae.id DESC
        ");
        $auditStmt->execute([$patientId]);
        foreach ($auditStmt->fetchAll(PDO::FETCH_ASSOC) as $event) {
            $oldValues = json_decode((string)($event['old_value'] ?? ''), true);
            $newValues = json_decode((string)($event['new_value'] ?? ''), true);
            if (!is_array($oldValues)) {
                $oldValues = [];
            }
            if (!is_array($newValues)) {
                $newValues = [];
            }
            $fields = array_values(array_unique(array_merge(
                array_keys($oldValues),
                array_keys($newValues)
            )));
            $reason = preg_replace(
                '/^Administrative medical record correction\. Reason:\s*/',
                '',
                (string)($event['description'] ?? '')
            );
            foreach ($fields as $field) {
                $oldValue = $oldValues[$field] ?? null;
                $newValue = $newValues[$field] ?? null;
                $auditHistory[] = [
                    'id' => (int)$event['id'],
                    'field_name' => (string)$field,
                    'old_value' => is_scalar($oldValue) || $oldValue === null ? (string)($oldValue ?? '') : json_encode($oldValue),
                    'new_value' => is_scalar($newValue) || $newValue === null ? (string)($newValue ?? '') : json_encode($newValue),
                    'reason' => $reason,
                    'created_at' => $event['created_at'],
                    'admin_name' => $event['admin_name'],
                ];
            }
        }
    }

    // ── 15. Last Clinical Update (Doctor who last updated clinical fields) ──
    $lastClinicalUpdate = null;
    $clinicalUpdateStmt = $db->prepare("
        SELECT u.name AS doctor_name,mhe.created_at
        FROM medical_history_events mhe
        JOIN users u ON mhe.doctor_user_id=u.id
        WHERE mhe.patient_user_id=?
        ORDER BY mhe.created_at DESC,mhe.id DESC
        LIMIT 1
    ");
    $clinicalUpdateStmt->execute([$patientId]);
    $lastClinicalUpdate = $clinicalUpdateStmt->fetch();

    jsonResponse(true, [
        'patient'            => $patient,
        'medical_record'     => $medicalRecord,
        'age'                => $age,
        'appointments'       => $appointments,
        'prescriptions'      => $prescriptions,
        'visit_notes'        => $visitNotes,
        'ratings'            => $ratings,
        'notifications'      => $notifications,
        'stats'              => [
            'total_appointments'  => $totalAppts,
            'upcoming'            => $upcomingAppts,
            'completed'           => $completedAppts,
            'cancelled'           => $cancelledAppts,
            'total_prescriptions' => $totalPrescriptions,
            'total_visit_notes'   => $totalVisitNotes,
            'total_medical_records' => $totalMedicalRecords,
            'total_ratings'       => $totalRatings,
            'average_rating'      => $avgRating,
        ],
        'upcoming_appointment' => $upcomingAppointment ?: null,
        'latest_appointment'   => $latestAppointment ?: null,
        'latest_prescription'  => $latestRx,
        'latest_visit_note'    => $latestVisit,
        'doctors_visited'      => $doctorsVisited,
        'audit_history'       => $auditHistory,
        'last_clinical_update' => $lastClinicalUpdate,
        'can_edit'             => in_array($currentRole, ['admin', 'doctor']),
    ]);

} catch (Throwable $e) {
    error_log('Get EMR Data Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load patient EMR data.']);
}
