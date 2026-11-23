<?php
/**
 * HealthBridge — Get Appointments
 * GET: patients receive their own appointments; admins receive the full list.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/AppointmentBookingRepository.php';

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

if (!in_array($user['role'] ?? '', ['admin', 'patient'], true)) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Only admins and patients can use this appointment list.']);
}

try {
    $db = getDB();

    $statusSql = "CASE a.booking_status
            WHEN 'pending' THEN 'Pending'
            WHEN 'confirmed' THEN 'Confirmed'
            WHEN 'cancelled' THEN 'Cancelled'
            WHEN 'completed' THEN 'Completed'
            ELSE a.booking_status END AS status";
    $projection = "a.id, a.patient_name_snapshot AS patient_name,
            a.department_name_snapshot AS department, a.doctor_name_snapshot AS doctor,
            DATE(a.starts_at) AS date, TIME_FORMAT(a.starts_at, '%H:%i') AS time,
            CONCAT(TIME_FORMAT(a.starts_at, '%H:%i'), ' - ',
                   TIME_FORMAT(DATE_ADD(a.starts_at, INTERVAL a.duration_minutes MINUTE), '%H:%i')) AS appointment_time_range,
            a.notes, {$statusSql}, a.created_at, a.doctor_user_id AS doctor_id";
    if ($user['role'] === 'admin') {
        $stmt = $db->query(
            "SELECT {$projection} FROM appointments a
                 ORDER BY a.starts_at DESC, a.id DESC"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT {$projection} FROM appointments a
                 WHERE a.patient_user_id = ? ORDER BY a.starts_at DESC, a.id DESC"
        );
        $stmt->execute([$user['id']]);
    }

    $appointments = $stmt->fetchAll();

    jsonResponse(true, ['appointments' => $appointments, 'total' => count($appointments)]);

} catch (Exception $e) {
    error_log('Get Appointments Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load appointments.']);
}

