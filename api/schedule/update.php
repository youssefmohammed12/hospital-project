<?php
/**
 * HealthBridge — Update Doctor Schedule
 *
 * Updates a doctor's working schedule including weekly days and settings.
 * - Doctors can only update their own schedule.
 * - Admins can update any doctor's schedule.
 *
 * POST: Expects JSON body with:
 *   doctor_id: int (optional for admin, auto-set for doctor)
 *   weekly: array of day objects
 *   settings: object with optional settings fields
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';
require_once __DIR__ . '/../../services/AuditService.php';

$user = requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();

// Determine target doctor. Doctors are always bound to their own account;
// an admin must provide a strictly positive target doctor ID.
$doctorId = 0;

if ($user['role'] === 'doctor') {
    $doctorId = (int)$user['id'];
} elseif ($user['role'] === 'admin') {
    $doctorId = parsePositiveInt($input['doctor_id'] ?? null);
    if ($doctorId === null) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'A valid doctor ID is required for admin updates.']);
    }
} else {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Access denied.']);
    exit;
}

// Validate doctor exists
$stmt = getDB()->prepare("SELECT id FROM users WHERE id = ? AND role = 'doctor'");
$stmt->execute([$doctorId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    jsonResponse(false, ['message' => 'Doctor not found.']);
    exit;
}

// Validate request structure before passing values to typed service validators.
$weekly = $input['weekly'] ?? [];
$settings = $input['settings'] ?? [];

if (!is_array($weekly) || !is_array($settings)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Weekly schedule and settings must be arrays.']);
}

foreach ($weekly as $day) {
    if (!is_array($day)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Each weekly schedule entry must be an object.']);
    }

    foreach (['day_of_week', 'is_working'] as $integerField) {
        if (array_key_exists($integerField, $day)) {
            $value = $day[$integerField];
            if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Weekly schedule day values must be integers.']);
            }
            if ($integerField === 'is_working' && !in_array((int)$value, [0, 1], true)) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Working-day values must be 0 or 1.']);
            }
        }
    }

    foreach (['start_time', 'end_time'] as $timeField) {
        if (array_key_exists($timeField, $day) && !is_string($day[$timeField])) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Schedule times must be text in HH:MM format.']);
        }
    }
}

foreach (['appointment_duration', 'max_appointments_per_day', 'is_available'] as $integerField) {
    if (array_key_exists($integerField, $settings)) {
        $value = $settings[$integerField];
        if ((!is_int($value) && !is_string($value)) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Schedule setting values must be integers.']);
        }
        if ($integerField === 'is_available' && !in_array((int)$value, [0, 1], true)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Availability must be 0 or 1.']);
        }
        if ($integerField === 'max_appointments_per_day' && ((int)$value < 1 || (int)$value > 100)) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Maximum appointments per day must be between 1 and 100.']);
        }
    }
}

foreach (['break_start', 'break_end'] as $timeField) {
    if (array_key_exists($timeField, $settings) && $settings[$timeField] !== null && !is_string($settings[$timeField])) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Break times must be text in HH:MM format.']);
    }
}

// Load hospital hours for boundary validation
$db = getDB();
$ss = new ScheduleService($db);
$hospitalHours = $ss->getHospitalHours();

$validationError = ScheduleService::validateWeekly($weekly, $hospitalHours['open'], $hospitalHours['close']);
if ($validationError) {
    http_response_code(400);
    jsonResponse(false, ['message' => $validationError]);
    exit;
}

// Validate settings
if (!empty($settings)) {
    $settingsError = ScheduleService::validateSettings($settings);
    if ($settingsError) {
        http_response_code(400);
        jsonResponse(false, ['message' => $settingsError]);
        exit;
    }
}

// Validate break is within working hours (if break is set)
$breakStart = $settings['break_start'] ?? null;
$breakEnd = $settings['break_end'] ?? null;
$breakError = ScheduleService::validateBreakWithinHours($weekly, $breakStart, $breakEnd);
if ($breakError) {
    http_response_code(400);
    jsonResponse(false, ['message' => $breakError]);
    exit;
}

// Perform the update
try {
    // Get old schedule for audit logging
    $oldSchedule = $ss->getSchedule($doctorId);
    
    $ss->updateSchedule($doctorId, $weekly, $settings);

    // Log schedule update to audit using universal AuditService
    // This captures both admin and doctor role changes
    try {
        $audit = new AuditService($db, (int)$user['id'], $user['role']);
        $audit->logScheduleChange($doctorId, $oldSchedule, [
            'weekly' => $weekly,
            'settings' => $settings
        ]);
    } catch (Exception $auditErr) {
        error_log('Audit log error (update_schedule): ' . $auditErr->getMessage());
    }

    // Return the updated schedule
    $schedule = $ss->getSchedule($doctorId);

    jsonResponse(true, [
        'message'  => 'Schedule updated successfully.',
        'schedule' => $schedule,
    ]);
} catch (Exception $e) {
    error_log('Schedule update error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update schedule.']);
}

