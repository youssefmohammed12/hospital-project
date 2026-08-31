<?php
/**
 * HealthBridge — Request Appointment Reschedule
 *
 * POST endpoint for patients to request rescheduling an appointment.
 *
 * Validates:
 *   - Appointment belongs to the patient
 *   - Status is 'Confirmed'
 *   - No pending reschedule exists
 *   - New slot is valid and different from current
 *
 * Does NOT update appointment_date/appointment_time.
 * Stores requested new date/time in pending_reschedule_* fields.
 *
 * Input (JSON):
 *   appointment_id (int, required)
 *   new_date        (string, required, YYYY-MM-DD)
 *   new_time        (string, required, HH:MM or HH:MM AM/PM)
 *   reason          (string, optional)
 *
 * Output:
 *   { success: bool, message: string }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/RescheduleService.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

header('Content-Type: application/json');

$user = requireAuth();
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

// Only patients (and admins) can request reschedules
if ($userRole !== 'patient' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only patients can request reschedules.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$appointmentId = (int)($input['appointment_id'] ?? 0);
$newDate = trim($input['new_date'] ?? '');
$newTime = trim($input['new_time'] ?? '');
$reason = trim($input['reason'] ?? '');

// ── Validation ──
if (!$appointmentId || !$newDate || !$newTime) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Appointment ID, new date, and new time are required.']);
    exit;
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid date format. Use YYYY-MM-DD.']);
    exit;
}

try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole);

    $result = $rs->requestReschedule($appointmentId, $newDate, $newTime, $reason);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(409);
        echo json_encode($result);
    }

} catch (\Throwable $e) {
    error_log('Request Reschedule Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    http_response_code(409);
    echo json_encode(['success' => false, 'message' => 'Failed to submit reschedule request.']);
}