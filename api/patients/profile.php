<?php
/**
 * HealthBridge — Admin: Get Patient Profile details
 * Returns personal info, appointment history, doctors visited, reviews, and support messages for a selected patient.
 */

require_once __DIR__ . '/../../includes/auth.php';
requireRole('admin');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    jsonResponse(false, ['message' => 'Patient ID is required.']);
}

try {
    $db = getDB();
    
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
    
    // Get appointment history
    $apptStmt = $db->prepare(
        'SELECT id, doctor_id, doctor AS doctor_name, department, date, time, appointment_time_range, notes, status, created_at
         FROM appointments
         WHERE user_id = ?
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
    
    // Get ratings and reviews submitted
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
    
} catch (Exception $e) {
    error_log('Get Patient Profile Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load patient profile details.']);
}

