<?php
/**
 * HealthBridge — Admin: Get Doctor Profile details
 * Returns personal info, appointment history, reviews, and stats for a selected doctor.
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    jsonResponse(false, ['message' => 'Doctor ID is required.']);
}

try {
    $db = getDB();
    
    // Get user/doctor details with department info
    $stmt = $db->prepare(
        'SELECT u.id, u.name, u.email, u.phone, u.is_active, u.created_at,
                d.specialty, d.rating, d.exp, d.available, d.emoji,
                d.department_id,
                dep.name AS department_name
         FROM users u
         LEFT JOIN doctors d ON u.id = d.user_id
         LEFT JOIN departments dep ON d.department_id = dep.id
         WHERE u.id = ? AND u.role = "doctor" LIMIT 1'
    );
    $stmt->execute([$id]);
    $doctor = $stmt->fetch();
    
    if (!$doctor) {
        jsonResponse(false, ['message' => 'Doctor not found.']);
    }
    
    // Get appointment history
    $apptStmt = $db->prepare(
        'SELECT id, patient_name, department, date, time, appointment_time_range, notes, status, created_at, doctor_id
         FROM appointments
         WHERE doctor_id = ?
         ORDER BY date DESC, time DESC'
    );
    $apptStmt->execute([$id]);
    $appointments = $apptStmt->fetchAll();
    
    // Add appointment_time_range to each appointment — prefer stored value
    foreach ($appointments as &$appt) {
        if (empty($appt['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$appt['doctor_id']);
            $appt['appointment_time_range'] = computeAppointmentTimeRange($appt['time'], $duration);
        }
    }
    unset($appt);
    
    // Get reviews
    $reviewsStmt = $db->prepare(
        'SELECT r.id, r.stars, r.review, r.created_at AS review_date,
                COALESCE(u.name, a.patient_name, \'Unknown Patient\') AS patient_name,
                COALESCE(u.email, \'—\') AS patient_email,
                a.date AS appt_date, a.time AS appt_time, a.doctor_id
         FROM ratings r
         JOIN appointments a ON r.appointment_id = a.id
         LEFT JOIN users u ON r.user_id = u.id
         WHERE r.doctor_id = ?
         ORDER BY r.created_at DESC'
    );
    $reviewsStmt->execute([$id]);
    $reviews = $reviewsStmt->fetchAll();
    
    // Add appointment_time_range to each review — prefer stored value
    foreach ($reviews as &$review) {
        if (empty($review['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$review['doctor_id']);
            $review['appointment_time_range'] = computeAppointmentTimeRange($review['appt_time'], $duration);
        }
    }
    unset($review);
    
    // Calculate stats
    $completedCount = 0;
    foreach ($appointments as $appt) {
        if ($appt['status'] === 'Confirmed') {
            $completedCount++;
        }
    }
    
    jsonResponse(true, [
        'doctor' => $doctor,
        'appointments' => $appointments,
        'reviews' => $reviews,
        'completed_count' => $completedCount
    ]);
    
} catch (Exception $e) {
    error_log('Get Doctor Profile Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load doctor profile details.']);
}

