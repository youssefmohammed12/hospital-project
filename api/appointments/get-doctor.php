<?php
/**
 * HealthBridge — Get Doctor's Appointments
 * GET: doctors receive their own appointments; admins may request one doctor by doctor_id.
 * Notification fetching is now handled by the unified API (api/notifications.php).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/VisitWorkflowService.php';
require_once __DIR__ . '/../../services/Repositories/AppointmentBookingRepository.php';

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

if (!in_array($user['role'] ?? '', ['admin', 'doctor'], true)) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Doctor appointments are not available to this account.']);
}

$requestedDoctorId = $_GET['doctor_id'] ?? null;
$parsedRequestedDoctorId = $requestedDoctorId !== null
    ? parsePositiveInt($requestedDoctorId)
    : null;

if ($user['role'] === 'admin') {
    if ($parsedRequestedDoctorId === null) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'A valid doctor ID is required.']);
    }
    $userId = $parsedRequestedDoctorId;
} else {
    $userId = (int)$user['id'];
    if ($requestedDoctorId !== null && $parsedRequestedDoctorId !== $userId) {
        http_response_code(403);
        jsonResponse(false, ['message' => 'Doctors can only view their own appointments.']);
    }
}

try {
    $db = getDB();
    $appointmentSchema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_APPOINTMENTS_SCHEMA');
    $clinicalSchema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_CLINICAL_SCHEMA');
    $wfService = new VisitWorkflowService($db, $clinicalSchema);

    // Get doctor profile
    $doctorStmt = $db->prepare('SELECT id, name, email FROM users WHERE id = ? AND role = "doctor" LIMIT 1');
    $doctorStmt->execute([$userId]);
    $doctor = $doctorStmt->fetch();

    if (!$doctor) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Doctor profile not found.']);
    }

    // Get appointments for this doctor
    if ($appointmentSchema === AppointmentBookingRepository::SCHEMA_V2) {
        $apptStmt = $db->prepare(
            "SELECT a.id, a.patient_user_id AS user_id, a.patient_user_id AS patient_id,
                    a.patient_name_snapshot AS patient_name,
                    a.department_name_snapshot AS department,
                    DATE(a.starts_at) AS date,
                    TIME_FORMAT(a.starts_at, '%H:%i') AS time,
                    CONCAT(TIME_FORMAT(a.starts_at, '%H:%i'), ' - ',
                           TIME_FORMAT(DATE_ADD(a.starts_at, INTERVAL a.duration_minutes MINUTE), '%H:%i')) AS appointment_time_range,
                    a.notes,
                    CASE a.booking_status
                        WHEN 'pending' THEN 'Pending'
                        WHEN 'confirmed' THEN 'Confirmed'
                        WHEN 'cancelled' THEN 'Cancelled'
                        WHEN 'completed' THEN 'Completed'
                        ELSE a.booking_status
                    END AS status,
                    a.created_at, a.doctor_user_id AS doctor_id,
                    CASE r.request_status
                        WHEN 'requested' THEN 'pending'
                        WHEN 'suggested' THEN 'suggested'
                        WHEN 'approved' THEN 'approved'
                        WHEN 'rejected' THEN 'rejected'
                        ELSE 'none'
                    END AS reschedule_status,
                    DATE(CASE WHEN r.request_status='suggested' AND r.proposed_starts_at IS NOT NULL
                              THEN r.proposed_starts_at ELSE r.requested_starts_at END) AS pending_reschedule_date,
                    TIME_FORMAT(CASE WHEN r.request_status='suggested' AND r.proposed_starts_at IS NOT NULL
                                     THEN r.proposed_starts_at ELSE r.requested_starts_at END, '%H:%i') AS pending_reschedule_time,
                    r.reason AS reschedule_reason, r.requested_at AS reschedule_requested_at
             FROM appointments a
             LEFT JOIN appointment_reschedule_requests r
               ON r.id = (SELECT MAX(r2.id) FROM appointment_reschedule_requests r2 WHERE r2.appointment_id=a.id)
             WHERE a.doctor_user_id = ?
             ORDER BY a.starts_at DESC, a.id DESC"
        );
    } else {
        $apptStmt = $db->prepare(
            'SELECT a.id, a.user_id, a.user_id AS patient_id, a.patient_name, a.department, a.date, a.time, a.appointment_time_range, a.notes, a.status, a.created_at, a.doctor_id,
                    a.reschedule_status, a.pending_reschedule_date, a.pending_reschedule_time, a.reschedule_reason, a.reschedule_requested_at
             FROM appointments a WHERE a.doctor_id = ? ORDER BY a.date DESC, a.time DESC'
        );
    }
    $apptStmt->execute([$doctor['id']]);
    $appointments = $apptStmt->fetchAll();

    // Get visit workflow for all appointments
    $apptIds = array_map(fn($a) => (int)$a['id'], $appointments);
    $workflowMap = !empty($apptIds) ? $wfService->getForAppointments($apptIds) : [];

    // Check for existing prescriptions and visit notes
    $rxCheckStmt = $db->prepare('SELECT id FROM prescriptions WHERE appointment_id = ? LIMIT 1');
    foreach ($appointments as &$appt) {
        $appt['has_prescription'] = false;
        $appt['prescription_id'] = null;
        $appt['has_visit_note'] = false;
        $appt['visit_workflow'] = $workflowMap[(int)$appt['id']] ?? null;
        
        if ($appt['status'] === 'Confirmed') {
            $rxCheckStmt->execute([(int)$appt['id']]);
            $existing = $rxCheckStmt->fetch();
            if ($existing) {
                $appt['has_prescription'] = true;
                $appt['prescription_id'] = (int)$existing['id'];
            }
            $appt['has_visit_note'] = $wfService->getNote((int)$appt['id']) !== null;
        }
        // Add appointment_time_range — prefer stored value
        if (empty($appt['appointment_time_range']) && $appointmentSchema === AppointmentBookingRepository::SCHEMA_V1) {
            $duration = getAppointmentDuration((int)$appt['doctor_id']);
            $appt['appointment_time_range'] = computeAppointmentTimeRange($appt['time'], $duration);
        }
    }

    jsonResponse(true, [
        'doctor'        => $doctor,
        'appointments'  => $appointments,
        'total'         => count($appointments),
    ]);

} catch (Exception $e) {
    error_log('Get Doctor Appointments Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load appointments.']);
}
