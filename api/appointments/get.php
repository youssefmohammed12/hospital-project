<?php
/**
 * HealthBridge — Get Appointments
 * Returns appointments for the logged-in user (or all for admin).
 */

require_once __DIR__ . '/../../includes/auth.php';

$user  = requireAuth(); // Any logged-in user
$db    = getDB();

try {
    if ($user['role'] === 'admin') {
        // Admin sees all appointments
        $stmt = $db->query(
            'SELECT a.id, a.patient_name, a.department, a.doctor, a.date, a.time, a.appointment_time_range, a.notes, a.status, a.created_at, a.doctor_id
             FROM appointments a
             ORDER BY a.date DESC, a.time DESC'
        );
    } else {
        // Patient sees their own
        $stmt = $db->prepare(
            'SELECT id, patient_name, department, doctor, date, time, appointment_time_range, notes, status, created_at, doctor_id
             FROM appointments WHERE user_id = ? ORDER BY date DESC, time DESC'
        );
        $stmt->execute([$user['id']]);
    }

    $appointments = $stmt->fetchAll();

    // Add appointment_time_range to each appointment
    // Prefer stored value in DB (preserves original booking duration)
    foreach ($appointments as &$appt) {
        if (empty($appt['appointment_time_range'])) {
            $duration = getAppointmentDuration((int)$appt['doctor_id']);
            $appt['appointment_time_range'] = computeAppointmentTimeRange($appt['time'], $duration);
        }
    }
    unset($appt);

    jsonResponse(true, ['appointments' => $appointments, 'total' => count($appointments)]);

} catch (Exception $e) {
    error_log('Get Appointments Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load appointments.']);
}

