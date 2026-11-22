<?php
/**
 * HealthBridge — Reject Appointment Reschedule
 *
 * POST endpoint for doctors to reject a pending reschedule request.
 *
 * On rejection:
 *   - appointment_date/appointment_time remain unchanged
 *   - status returns to 'Confirmed'
 *   - reschedule_status = 'rejected'
 *   - No data is lost
 *
 * Input (JSON):
 *   appointment_id (int, required)
 *   notes          (string, optional) Reason for rejection
 *
 * Output:
 *   { success: bool, message: string }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/RescheduleService.php';
require_once __DIR__ . '/../../services/ScheduleService.php';

header('Content-Type: application/json');

$user = requireAuth();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

// Only doctors and admins can reject reschedules
if ($userRole !== 'doctor' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only doctors can reject reschedules.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}
if (!is_array($input)) {
    $input = [];
}

$appointmentId = parsePositiveInt($input['appointment_id'] ?? null);
$notesInput = $input['notes'] ?? '';
if ($appointmentId === null || !is_string($notesInput)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Appointment ID and notes must be valid values.']);
    exit;
}
$notes = trim($notesInput);

try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole, RescheduleService::schemaFromEnvironment());

    $result = $rs->rejectReschedule($appointmentId, $notes);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(409);
        echo json_encode($result);
    }

} catch (\Throwable $e) {
    error_log('Reject Reschedule Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to reject reschedule request.']);
}