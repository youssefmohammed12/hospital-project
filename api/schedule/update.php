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
$input = getJsonInput();

// Determine target doctor
$doctorId = (int)($input['doctor_id'] ?? 0);

if ($user['role'] === 'doctor') {
    // Doctors can only edit their own schedule
    $doctorId = $user['id'];
} elseif ($user['role'] === 'admin') {
    // Admin must specify a doctor_id
    if ($doctorId <= 0) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Doctor ID is required for admin updates.']);
        exit;
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

// Validate weekly data
$weekly = $input['weekly'] ?? [];
$settings = $input['settings'] ?? [];

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

