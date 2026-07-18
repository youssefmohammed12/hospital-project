<?php
/**
 * HealthBridge — Get Doctor's Ratings & Reviews
 * Returns ratings for the logged-in doctor.
 * Anonymized: hides patient name, email, and ID.
 */

require_once __DIR__ . '/../../includes/auth.php';

$user = requireRole('doctor');
$doctorId = $user['id'];

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT r.id, r.stars, r.review, r.created_at AS review_date,
                a.date AS appt_date, a.time AS appt_time, a.appointment_time_range, a.id AS appointment_id, a.doctor_id
         FROM ratings r
         JOIN appointments a ON r.appointment_id = a.id
         WHERE r.doctor_id = ?
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([$doctorId]);
    $ratings = $stmt->fetchAll();
    
    // Add appointment_time_range to each rating — prefer stored value
    foreach ($ratings as &$rating) {
        if (empty($rating['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$rating['doctor_id']);
            $rating['appointment_time_range'] = computeAppointmentTimeRange($rating['appt_time'], $duration);
        }
    }
    unset($rating);

    jsonResponse(true, ['ratings' => $ratings]);

} catch (Exception $e) {
    error_log('Get Doctor Ratings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load ratings.']);
}

