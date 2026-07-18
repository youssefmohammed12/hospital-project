<?php
/**
 * HealthBridge — Get Doctor Schedule
 *
 * Returns the full schedule for a doctor.
 * - Doctors can only view their own schedule.
 * - Admins can view any doctor's schedule.
 * - Patients have no access.
 *
 * GET / POST: Expects JSON body with optional 'doctor_id' (admin only).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

// Require authentication
$user = requireAuth();

// Determine which doctor's schedule to fetch
$input = getJsonInput();
$doctorId = (int)($input['doctor_id'] ?? 0);

if ($user['role'] === 'doctor') {
    // Doctors can only view their own schedule
    $doctorId = $user['id'];
} elseif ($user['role'] === 'admin') {
    // Admin must specify a doctor_id, or we return all schedules
    if ($doctorId <= 0) {
        // Return all schedules for admin overview
        withDB(function ($db) {
            $ss = new ScheduleService($db);
            return ['schedules' => $ss->getAllSchedules()];
        }, 'Failed to load schedules.');
        exit;
    }
} else {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Access denied.']);
    exit;
}

if ($doctorId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Invalid doctor ID.']);
    exit;
}

withDB(function ($db) use ($doctorId) {
    $ss = new ScheduleService($db);
    $schedule = $ss->getSchedule($doctorId);

    if (!$schedule) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Schedule not found for this doctor.']);
        return null;
    }

    return ['schedule' => $schedule];
}, 'Failed to load schedule.');
