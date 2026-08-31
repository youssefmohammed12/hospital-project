<?php
/**
 * HealthBridge — Suggest Alternative Reschedule Time
 *
 * POST endpoint for doctors to suggest an alternative time for a pending reschedule.
 *
 * Request:
 *   appointment_id: int (required)
 *   suggested_date: string YYYY-MM-DD (required)
 *   suggested_time: string HH:MM (required)
 *   notes: string (optional)
 *
 * Response:
 *   success: true/false
 *   message: string
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/RescheduleService.php';

$user = requireAuth();
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

if ($userRole !== 'doctor' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only doctors can suggest reschedule times.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['appointment_id']) || empty($input['suggested_date']) || empty($input['suggested_time'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields: appointment_id, suggested_date, suggested_time.']);
    exit;
}

$appointmentId = (int)$input['appointment_id'];
$suggestedDate = trim($input['suggested_date']);
$suggestedTime = trim($input['suggested_time']);
$notes = trim($input['notes'] ?? '');

try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole);
    $result = $rs->suggestReschedule($appointmentId, $suggestedDate, $suggestedTime, $notes);
    echo json_encode($result);
} catch (Exception $e) {
    error_log('Suggest Reschedule Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Failed to suggest alternative time.']);
}
