<?php
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
if ($userRole !== 'patient' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
$appointmentId = is_array($input) ? parsePositiveInt($input['appointment_id'] ?? null) : null;
if ($appointmentId === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'A valid appointment_id is required.']);
    exit;
}
try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole, RescheduleService::schemaFromEnvironment());
    echo json_encode($rs->declineRescheduleSuggestion($appointmentId));
} catch (Exception $e) {
    error_log('Decline Suggestion Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to decline suggestion.']);
}
