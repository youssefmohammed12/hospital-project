<?php
/**
 * HealthBridge — Get Doctor Schedule
 *
 * Returns the full schedule for a doctor.
 * - Doctors can only view their own schedule.
 * - Admins can view any doctor's schedule.
 * - Patients have no access.
 *
 * GET /api/schedule/get.php?doctor_id=123 (doctor_id is required for doctor/admin detail views).
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$user = requireAuth();

// Determine which doctor's schedule to fetch from the query string.
$doctorId = 0;

if ($user['role'] === 'doctor') {
    // Doctors can only view their own schedule.
    $doctorId = (int)$user['id'];
} elseif ($user['role'] === 'admin') {
    // An omitted ID (or the legacy explicit "0" overview marker) returns all schedules.
    if (array_key_exists('doctor_id', $_GET) && $_GET['doctor_id'] !== '0') {
        $doctorId = parsePositiveInt($_GET['doctor_id']);
        if ($doctorId === null) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Doctor ID must be a positive integer.']);
        }
    }

    if ($doctorId === 0) {
        withDB(function ($db) {
            $ss = new ScheduleService($db, ScheduleService::schemaFromEnvironment());
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
    $ss = new ScheduleService($db, ScheduleService::schemaFromEnvironment());
    $schedule = $ss->getSchedule($doctorId);

    if (!$schedule) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'Schedule not found for this doctor.']);
        return null;
    }

    return ['schedule' => $schedule];
}, 'Failed to load schedule.');
