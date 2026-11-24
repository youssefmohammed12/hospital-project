<?php
/**
 * HealthBridge — Cancel Reschedule Request
 *
 * POST endpoint for patients to cancel a pending reschedule request.
 * Resets the appointment back to its original state.
 *
 * Request:
 *   appointment_id: int (required)
 *
 * Response:
 *   success: true
 *   message: "Reschedule request cancelled."
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/RescheduleService.php';

// ── Authentication & Authorization ────────────────────────
$user = requireAuth();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

if ($userRole !== 'patient' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only patients can cancel reschedule requests.']);
    exit;
}

// ── Parse Input ──────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$appointmentId = is_array($input) ? parsePositiveInt($input['appointment_id'] ?? null) : null;

if ($appointmentId === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid appointment_id is required.']);
    exit;
}

// ── Execute ──────────────────────────────────────────────
try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole);
    $result = $rs->cancelRescheduleRequest($appointmentId);

    echo json_encode($result);
} catch (Exception $e) {
    error_log('Cancel Reschedule Request Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Failed to cancel reschedule request.']);
}
