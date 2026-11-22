<?php
/**
 * HealthBridge — Admin: Get Patient Profile details
 * Returns personal info, appointment history, doctors visited, reviews, and support messages for a selected patient.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/PatientManagementRepository.php';
requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$idValue = $_GET['id'] ?? null;
$id = is_string($idValue) && ctype_digit($idValue)
    ? filter_var($idValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
    : false;
if ($id === false) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid patient ID is required.']);
}

try {
    $db = getDB();
    $schema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_PATIENTS_SCHEMA');

    // Get user details
    $stmt = $db->prepare(
        'SELECT id, name, email, phone, is_active, created_at
         FROM users
         WHERE id = ? AND role = "patient" LIMIT 1'
    );
    $stmt->execute([$id]);
    $patient = $stmt->fetch();
    
    if (!$patient) {
        jsonResponse(false, ['message' => 'Patient not found.']);
    }
    
    // Get appointment history using the selected schema's timestamp/snapshot model.
    if ($schema === PatientManagementRepository::SCHEMA_V2) {
        $apptStmt = $db->prepare(
            "SELECT id, doctor_user_id AS doctor_id, doctor_name_snapshot AS doctor_name,
                    department_name_snapshot AS department, DATE(starts_at) AS date,
                    TIME_FORMAT(starts_at, '%H:%i') AS time,
                    CONCAT(TIME_FORMAT(starts_at, '%H:%i'), ' - ',
                           TIME_FORMAT(DATE_ADD(starts_at, INTERVAL duration_minutes MINUTE), '%H:%i')) AS appointment_time_range,
                    notes,
                    CASE booking_status
                        WHEN 'pending' THEN 'Pending'
                        WHEN 'confirmed' THEN 'Confirmed'
                        WHEN 'cancelled' THEN 'Cancelled'
                        WHEN 'completed' THEN 'Completed'
                        ELSE booking_status
                    END AS status,
                    created_at
             FROM appointments
             WHERE patient_user_id = ?
             ORDER BY starts_at DESC, id DESC"
        );
    } else {
        $apptStmt = $db->prepare(
            'SELECT id, doctor_id, doctor AS doctor_name, department, date, time, appointment_time_range, notes, status, created_at
             FROM appointments
             WHERE user_id = ?
             ORDER BY date DESC, time DESC'
        );
    }
    $apptStmt->execute([$id]);
    $appointments = $apptStmt->fetchAll();
    
    // V1 may need a duration fallback; V2 already projects from duration_minutes.
    if ($schema === PatientManagementRepository::SCHEMA_V1) {
        foreach ($appointments as &$appt) {
            if (empty($appt['appointment_time_range'])) {
                $duration = getAppointmentDuration((int)$appt['doctor_id']);
                $appt['appointment_time_range'] = computeAppointmentTimeRange($appt['time'], $duration);
            }
        }
        unset($appt);
    }
    
    // Get unique doctors visited (with status Confirmed)
    $doctorsVisited = [];
    $seenDoctors = [];
    foreach ($appointments as $appt) {
        if ($appt['status'] === 'Confirmed' && !in_array($appt['doctor_id'], $seenDoctors)) {
            $seenDoctors[] = $appt['doctor_id'];
            $doctorsVisited[] = [
                'id' => $appt['doctor_id'],
                'name' => $appt['doctor_name'],
                'department' => $appt['department']
            ];
        }
    }
    
    // Get ratings and reviews submitted.
    if ($schema === PatientManagementRepository::SCHEMA_V2) {
        $reviewsStmt = $db->prepare(
            "SELECT r.id, r.stars, r.review, r.created_at AS review_date,
                    COALESCE(u.name, a.doctor_name_snapshot, 'Unknown Doctor') AS doctor_name,
                    DATE(a.starts_at) AS appt_date,
                    TIME_FORMAT(a.starts_at, '%H:%i') AS appt_time,
                    CONCAT(TIME_FORMAT(a.starts_at, '%H:%i'), ' - ',
                           TIME_FORMAT(DATE_ADD(a.starts_at, INTERVAL a.duration_minutes MINUTE), '%H:%i')) AS appointment_time_range,
                    a.doctor_user_id AS doctor_id
             FROM ratings r
             JOIN appointments a ON r.appointment_id = a.id
             LEFT JOIN users u ON r.doctor_user_id = u.id
             WHERE r.patient_user_id = ?
             ORDER BY r.created_at DESC"
        );
    } else {
        $reviewsStmt = $db->prepare(
            'SELECT r.id, r.stars, r.review, r.created_at AS review_date,
                    COALESCE(u.name, a.doctor, \'Unknown Doctor\') AS doctor_name,
                    a.date AS appt_date, a.time AS appt_time, a.doctor_id
             FROM ratings r
             JOIN appointments a ON r.appointment_id = a.id
             LEFT JOIN users u ON r.doctor_id = u.id
             WHERE r.user_id = ?
             ORDER BY r.created_at DESC'
        );
    }
    $reviewsStmt->execute([$id]);
    $reviews = $reviewsStmt->fetchAll();
    
    // V2 computes its duration range from the stored appointment instant; V1 uses the legacy fallback.
    if ($schema === PatientManagementRepository::SCHEMA_V1) {
        foreach ($reviews as &$review) {
            if (empty($review['appointment_time_range'])) {
                $duration = getAppointmentDuration((int)$review['doctor_id']);
                $review['appointment_time_range'] = computeAppointmentTimeRange($review['appt_time'], $duration);
            }
        }
        unset($review);
    }
    
    // Get support messages sent
    $messagesStmt = $db->prepare(
        'SELECT id, phone, department, subject, message, reply, replied_at, created_at
         FROM contact_messages
         WHERE user_id = ?
         ORDER BY created_at DESC'
    );
    $messagesStmt->execute([$id]);
    $messages = $messagesStmt->fetchAll();
    
    jsonResponse(true, [
        'patient' => $patient,
        'appointments' => $appointments,
        'doctors_visited' => $doctorsVisited,
        'reviews' => $reviews,
        'messages' => $messages
    ]);
    
} catch (Throwable $e) {
    error_log('Get Patient Profile Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load patient profile details.']);
}

