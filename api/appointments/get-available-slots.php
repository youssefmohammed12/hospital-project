<?php
/**
 * HealthBridge — Get Available Appointment Slots
 *
 * Returns available time slots for a doctor on a specific date.
 * Slots are generated dynamically from the doctor's schedule.
 *
 * POST: Expects JSON body with:
 *   doctor_id: int (required)
 *   date: string YYYY-MM-DD (required)
 *
 * Returns:
 *   slots: array of { time: "HH:MM", label: "9:00 AM" }
 *   message: string (if no slots available, explains why)
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

$user = requireAuth();
$input = getJsonInput();

$doctorId = (int)($input['doctor_id'] ?? 0);
$date = trim($input['date'] ?? '');

// Validate input
if ($doctorId <= 0) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Doctor ID is required.']);
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Valid date (YYYY-MM-DD) is required.']);
    exit;
}

// Validate date is not in the past
if ($date < date('Y-m-d')) {
    jsonResponse(true, [
        'slots'   => [],
        'message' => 'Cannot book appointments in the past.',
    ]);
    exit;
}

try {
    $db = getDB();
    $ss = new ScheduleService($db);

    // Get available slots
    $slots = $ss->getAvailableSlots($doctorId, $date);

    // Get appointment duration for the slot picker
    $duration = 30;
    $schedule = $ss->getSchedule($doctorId);
    if ($schedule) {
        $duration = (int)($schedule['settings']['appointment_duration'] ?? 30);
    }

    // Determine message if no slots
    $message = '';
    if (empty($slots)) {
        // Check why no slots are available
        $schedule = $ss->getSchedule($doctorId);
        if (!$schedule) {
            $message = 'Doctor schedule not found.';
        } elseif ((int)$schedule['settings']['is_available'] !== 1) {
            $message = 'This doctor is currently unavailable.';
        } else {
            $timestamp = strtotime($date);
            $dayOfWeek = (int)date('N', $timestamp);

            $isWorkingDay = false;
            foreach ($schedule['weekly'] as $day) {
                if ((int)$day['day_of_week'] === $dayOfWeek && (int)$day['is_working'] === 1) {
                    $isWorkingDay = true;
                    break;
                }
            }

            if (!$isWorkingDay) {
                $message = 'No appointments available for this day.';
            } elseif ($date === date('Y-m-d')) {
                $message = 'No available slots remaining for today.';
            } else {
                $message = 'This day is fully booked.';
            }
        }
    }

    jsonResponse(true, [
        'slots'     => $slots,
        'message'   => $message,
        'duration'  => $duration,
    ]);

} catch (Exception $e) {
    error_log('Get Available Slots Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load available slots.']);
}
