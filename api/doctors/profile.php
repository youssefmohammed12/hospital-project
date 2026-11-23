<?php
/**
 * HealthBridge — Admin: Get Doctor Profile details
 * Returns personal info, appointment history, reviews, and stats for a selected doctor.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/DoctorManagementRepository.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';
requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$idValue = $_GET['id'] ?? null;
$id = is_string($idValue) && ctype_digit($idValue) ? (int)$idValue : 0;
if ($id <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid doctor ID is required.']);
}

try {
    $db = getDB();
    
    // Get user/doctor details with department info.
    $stmt = $db->prepare(
            'SELECT u.id,u.name,u.email,u.phone,u.is_active,u.created_at,
                    dp.specialty,COALESCE(rs.average_rating,0) AS rating,
                    dp.experience_years AS exp,
                    COALESCE(dss.is_available,0) AS available,
                    dp.profile_icon AS emoji,dp.department_id,
                    dep.name AS department_name
             FROM users u
             INNER JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
             LEFT JOIN v_doctor_rating_summary rs ON rs.doctor_user_id=u.id
             LEFT JOIN doctor_schedule_settings dss ON dss.doctor_user_id=u.id
             LEFT JOIN departments dep ON dep.id=dp.department_id
             WHERE u.id=? AND u.role="doctor" LIMIT 1'
        );
    $stmt->execute([$id]);
    $doctor = $stmt->fetch();
    
    if (!$doctor) {
        jsonResponse(false, ['message' => 'Doctor not found.']);
    }
    
    // Get appointment history.
    $apptStmt = $db->prepare(
            "SELECT a.id,a.patient_name_snapshot AS patient_name,
                    a.department_name_snapshot AS department,
                    DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS date,
                    TIME_FORMAT(a.starts_at,'%H:%i') AS time,
                    a.source_time_snapshot,a.source_time_range_snapshot,
                    a.duration_minutes,a.notes,a.created_at,
                    a.doctor_user_id AS doctor_id,
                    ce.workflow_status,
                    CASE
                      WHEN a.booking_status='confirmed' AND ce.workflow_status='completed' THEN 'Completed'
                      WHEN a.booking_status='confirmed' THEN 'Confirmed'
                      WHEN a.booking_status='pending' THEN 'Pending'
                      ELSE 'Cancelled'
                    END AS status
             FROM appointments a
             LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
             WHERE a.doctor_user_id=?
             ORDER BY a.starts_at DESC,a.id DESC"
        );
    $apptStmt->execute([$id]);
    $appointments = $apptStmt->fetchAll();

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

    // Get reviews with patient details for administrator view.
    $reviewsStmt = $db->prepare(
            "SELECT r.id,r.stars,r.review,r.is_public,(1-r.is_public) AS is_hidden,
                    r.created_at AS review_date,
                    COALESCE(u.name,a.patient_name_snapshot,'Unknown Patient') AS patient_name,
                    COALESCE(u.email,'—') AS patient_email,
                    DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS appt_date,
                    TIME_FORMAT(a.starts_at,'%H:%i') AS appt_time,
                    a.source_time_snapshot,a.source_time_range_snapshot,
                    a.duration_minutes,a.doctor_user_id AS doctor_id
             FROM ratings r
             JOIN appointments a ON r.appointment_id=a.id
             LEFT JOIN users u ON r.patient_user_id=u.id
             WHERE r.doctor_user_id=?
             ORDER BY r.created_at DESC"
        );
    $reviewsStmt->execute([$id]);
    $reviews = $reviewsStmt->fetchAll();

    foreach ($reviews as &$review) {
        $sourceTime = (string)($review['source_time_snapshot'] ?? '');
        if ($sourceTime !== '' && AppointmentTime::normalize($sourceTime) === AppointmentTime::normalize((string)$review['appt_time'])) {
            $review['appt_time'] = $sourceTime;
            $rangeSnapshot = $review['source_time_range_snapshot'] ?? null;
            $review['appointment_time_range'] = is_string($rangeSnapshot) && trim($rangeSnapshot) !== ''
                ? $rangeSnapshot
                : AppointmentTime::range((string)$review['appt_time'], max(1, (int)($review['duration_minutes'] ?? 30)));
        } else {
            $review['appointment_time_range'] = AppointmentTime::range(
                (string)$review['appt_time'],
                max(1, (int)($review['duration_minutes'] ?? 30))
            );
        }
    }
    unset($review);
    
    // Calculate completed clinical visits.
    $completedCount = 0;
    foreach ($appointments as $appt) {
        if (($appt['workflow_status'] ?? null) === 'completed') {
            $completedCount++;
        }
    }
    
    jsonResponse(true, [
        'doctor' => $doctor,
        'appointments' => $appointments,
        'reviews' => $reviews,
        'completed_count' => $completedCount
    ]);
    
} catch (Throwable $e) {
    error_log('Get Doctor Profile Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load doctor profile details.']);
}

