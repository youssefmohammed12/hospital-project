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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();

$doctorIdValue = $input['doctor_id'] ?? null;
$doctorId = parsePositiveInt($doctorIdValue);
$dateValue = $input['date'] ?? null;

// Validate strict input types before parsing. In particular, never allow
// partial numeric IDs to coerce to a different doctor or arrays to reach trim().
if ($doctorId === null) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Doctor ID must be a positive integer.']);
}

if (!is_string($dateValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Date must be submitted as text in YYYY-MM-DD format.']);
}

$date = trim($dateValue);
$dateError = validateDate($date, 'Date');
if ($dateError !== null) {
    http_response_code(400);
    jsonResponse(false, ['message' => $dateError]);
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
