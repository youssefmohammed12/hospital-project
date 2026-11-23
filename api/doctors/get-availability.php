<?php
/**
 * HealthBridge — Get Doctor Availability
 * Returns whether the logged-in doctor is currently available for bookings.
 * Reads from doctor_schedule_settings (Phase 1/2 unified availability).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

$userId = (int)requireRole('doctor')['id'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

try {
    $db = getDB();

    // Read from new schedule settings (single source of truth)
    $ss = new ScheduleService($db);
    $schedule = $ss->getSchedule($userId);

    if ($schedule) {
        $available = (int)$schedule['settings']['is_available'];
    } else {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Doctor schedule profile not found.']);
    }

    jsonResponse(true, ['available' => $available]);

} catch (Throwable $e) {
    error_log('Get Doctor Availability Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load availability status.']);
}
