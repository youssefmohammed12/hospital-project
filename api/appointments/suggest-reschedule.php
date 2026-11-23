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
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

if ($userRole !== 'doctor' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only doctors can suggest reschedule times.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$appointmentId = parsePositiveInt($input['appointment_id'] ?? null);
$dateInput = $input['suggested_date'] ?? null;
$timeInput = $input['suggested_time'] ?? null;
$notesInput = $input['notes'] ?? '';

if (
    $appointmentId === null
    || !is_string($dateInput)
    || !is_string($timeInput)
    || !is_string($notesInput)
    || trim($dateInput) === ''
    || trim($timeInput) === ''
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Appointment ID, suggested date, and suggested time must be valid values.']);
    exit;
}

$suggestedDate = trim($dateInput);
$suggestedTime = trim($timeInput);
$notes = trim($notesInput);

try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole);
    $result = $rs->suggestReschedule($appointmentId, $suggestedDate, $suggestedTime, $notes);
    echo json_encode($result);
} catch (Exception $e) {
    error_log('Suggest Reschedule Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Failed to suggest alternative time.']);
}
