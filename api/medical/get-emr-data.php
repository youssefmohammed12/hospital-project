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
require_once __DIR__ . '/../../services/NotificationService.php';

$user = requireAuth();
$currentUserId = (int)$user['id'];
$currentRole   = $user['role'];

$patientId = (int)($_GET['patient_id'] ?? 0);

if ($currentRole === 'patient') {
    $patientId = $currentUserId;
} elseif (!$patientId) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Patient ID is required.']);
}

try {
    $db = getDB();

    // ── Authorization ──
    if ($currentRole === 'doctor') {
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

    // ── 2. Medical Record (includes blood_type, gender, DOB, emergency contact, etc.) ──
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

    // Compute age from date_of_birth
    $age = null;
    if (!empty($medicalRecord['date_of_birth'])) {
        $dob = new DateTime($medicalRecord['date_of_birth']);
        $now = new DateTime();
        $age = $dob->diff($now)->y;
    }

    // ── 3. Appointments ──
    $apptStmt = $db->prepare(
        "SELECT a.*, u.name as doctor_user_name
         FROM appointments a
         LEFT JOIN users u ON a.doctor_id = u.id
         WHERE a.user_id = ?
         ORDER BY a.date DESC, a.time DESC"
    );
    $apptStmt->execute([$patientId]);
    $appointments = $apptStmt->fetchAll();

    // Add time range
    foreach ($appointments as &$appt) {
        if (empty($appt['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$appt['doctor_id']);
            $appt['appointment_time_range'] = computeAppointmentTimeRange($appt['time'], $duration);
        }
    }
    unset($appt);

    // ── 4. Prescriptions ──
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

    // Get items for each prescription
    $rxItemStmt = $db->prepare(
        "SELECT * FROM prescription_items WHERE prescription_id = ? ORDER BY sort_order"
    );
    foreach ($prescriptions as &$rx) {
        $rxItemStmt->execute([$rx['id']]);
        $rx['items'] = $rxItemStmt->fetchAll();
    }
    unset($rx);

    // ── 5. Visit Notes ──
    $visitStmt = $db->prepare(
        "SELECT vn.*, a.date as appt_date, a.time as appt_time, a.department,
                u.name as doctor_name
         FROM visit_notes vn
         JOIN appointments a ON vn.appointment_id = a.id
         LEFT JOIN users u ON vn.doctor_id = u.id
         WHERE vn.patient_id = ?
         ORDER BY vn.created_at DESC"
    );
    $visitStmt->execute([$patientId]);
    $visitNotes = $visitStmt->fetchAll();

    // ── 6. Ratings ──
    $ratingStmt = $db->prepare(
        "SELECT r.*, u.name as doctor_name, a.date as appt_date, a.time as appt_time,
                a.doctor, a.department
         FROM ratings r
         JOIN users u ON r.doctor_id = u.id
         JOIN appointments a ON r.appointment_id = a.id
         WHERE r.user_id = ?
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
    $totalMedicalRecords = $medicalRecord ? 1 : 0;
    $totalRatings = count($ratings);
    $avgRating = 0;

    foreach ($appointments as $a) {
        if ($a['status'] === 'Confirmed') {
            // Check if date is in the past or future
            $apptDate = $a['date'];
            if ($apptDate >= date('Y-m-d')) {
                $upcomingAppts++;
            } else {
                $completedAppts++;
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
    $upcomingStmt = $db->prepare(
        "SELECT a.*, u.name as doctor_user_name
         FROM appointments a
         LEFT JOIN users u ON a.doctor_id = u.id
         WHERE a.user_id = ? AND a.date >= CURDATE() AND a.status IN ('Confirmed','Pending')
         ORDER BY a.date ASC, a.time ASC
         LIMIT 1"
    );
    $upcomingStmt->execute([$patientId]);
    $upcomingAppointment = $upcomingStmt->fetch();

    if ($upcomingAppointment && empty($upcomingAppointment['appointment_time_range'])) {
        $duration = getAppointmentDuration((int)$upcomingAppointment['doctor_id']);
        $upcomingAppointment['appointment_time_range'] = computeAppointmentTimeRange($upcomingAppointment['time'], $duration);
    }

    // ── 10. Latest appointment ──
    $latestApptStmt = $db->prepare(
        "SELECT a.*, u.name as doctor_user_name
         FROM appointments a
         LEFT JOIN users u ON a.doctor_id = u.id
         WHERE a.user_id = ?
         ORDER BY a.created_at DESC LIMIT 1"
    );
    $latestApptStmt->execute([$patientId]);
    $latestAppointment = $latestApptStmt->fetch();

    if ($latestAppointment && empty($latestAppointment['appointment_time_range'])) {
        $duration = getAppointmentDuration((int)$latestAppointment['doctor_id']);
        $latestAppointment['appointment_time_range'] = computeAppointmentTimeRange($latestAppointment['time'], $duration);
    }

    // ── 11. Latest prescription ──
    $latestRx = !empty($prescriptions) ? $prescriptions[0] : null;

    // ── 12. Latest visit note ──
    $latestVisit = !empty($visitNotes) ? $visitNotes[0] : null;

    // ── 13. Latest medical record / assigned doctor(s) ──
    $doctorsVisited = [];
    $seenDoctors = [];
    foreach ($appointments as $a) {
        if (in_array($a['status'], ['Confirmed','Pending']) && !in_array($a['doctor_id'], $seenDoctors)) {
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
            SELECT 
                mra.id,
                mra.field_name,
                mra.old_value,
                mra.new_value,
                mra.reason,
                mra.created_at,
                u.name as admin_name
            FROM medical_record_audit mra
            JOIN users u ON mra.admin_id = u.id
            WHERE mra.patient_id = ?
            ORDER BY mra.created_at DESC
        ");
        $auditStmt->execute([$patientId]);
        $auditHistory = $auditStmt->fetchAll();
    }

    // ── 15. Last Clinical Update (Doctor who last updated clinical fields) ──
    $lastClinicalUpdate = null;
    $clinicalUpdateStmt = $db->prepare("
        SELECT 
            u.name as doctor_name,
            mhu.created_at
        FROM medical_history_updates mhu
        JOIN users u ON mhu.doctor_id = u.id
        WHERE mhu.patient_id = ?
        ORDER BY mhu.created_at DESC
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

} catch (Exception $e) {
    error_log('Get EMR Data Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load patient EMR data.']);
}
