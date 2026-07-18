<?php
/**
 * HealthBridge — Get Doctor Availability
 * Returns whether the logged-in doctor is currently available for bookings.
 * Reads from doctor_schedule_settings (Phase 1/2 unified availability).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

$userId = requireRole('doctor')['id'];

try {
    $db = getDB();

    // Read from new schedule settings (single source of truth)
    $ss = new ScheduleService($db);
    $schedule = $ss->getSchedule($userId);

    if ($schedule) {
        $available = (int)$schedule['settings']['is_available'];
    } else {
        // Fallback to old doctors table
        $stmt = $db->prepare('SELECT available FROM doctors WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $doctor = $stmt->fetch();
        if (!$doctor) {
            http_response_code(404);
            jsonResponse(false, ['message' => 'Doctor profile not found.']);
        }
        $available = (int)$doctor['available'];
    }

    jsonResponse(true, ['available' => $available]);

} catch (Exception $e) {
    error_log('Get Doctor Availability Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load availability status.']);
}
