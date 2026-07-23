<?php
/**
 * HealthBridge — Approve Appointment Reschedule
 *
 * POST endpoint for doctors to approve a pending reschedule request.
 *
 * On approval:
 *   - appointment_date = pending_reschedule_date
 *   - appointment_time = pending_reschedule_time
 *   - status = 'Confirmed'
 *   - reschedule_status = 'approved'
 *
 * Input (JSON):
 *   appointment_id (int, required)
 *   notes          (string, optional) Doctor's notes
 *
 * Output:
 *   { success: bool, message: string }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/RescheduleService.php';

header('Content-Type: application/json');

$user = requireAuth();
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

// Only doctors and admins can approve reschedules
if ($userRole !== 'doctor' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only doctors can approve reschedules.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$appointmentId = (int)($input['appointment_id'] ?? 0);
$notes = trim($input['notes'] ?? '');

if (!$appointmentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Appointment ID is required.']);
    exit;
}

try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole);

    $result = $rs->approveReschedule($appointmentId, $notes);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(409);
        echo json_encode($result);
    }

} catch (Exception $e) {
    error_log('Approve Reschedule Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to approve reschedule request.']);
}