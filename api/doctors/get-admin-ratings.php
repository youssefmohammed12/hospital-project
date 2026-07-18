<?php
/**
 * HealthBridge — Get All Doctor Ratings (Admin Only)
 * Returns all ratings/reviews in the system with full identifying details.
 */

require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

try {
    $db = getDB();
    $stmt = $db->query(
        'SELECT r.id, r.stars, r.review, r.created_at AS review_date,
                COALESCE(u_patient.name, a.patient_name, \'Unknown Patient\') AS patient_name,
                COALESCE(u_patient.email, \'—\') AS patient_email,
                COALESCE(u_doctor.name, a.doctor, \'Unknown Doctor\') AS doctor_name,
                a.date AS appt_date, a.time AS appt_time, a.department, a.id AS appointment_id
         FROM ratings r
         JOIN appointments a ON r.appointment_id = a.id
         LEFT JOIN users u_patient ON r.user_id = u_patient.id
         LEFT JOIN users u_doctor ON r.doctor_id = u_doctor.id
         ORDER BY r.created_at DESC'
    );
    $ratings = $stmt->fetchAll();

    jsonResponse(true, ['ratings' => $ratings]);

} catch (Exception $e) {
    error_log('Get Admin Ratings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load system ratings.']);
}

