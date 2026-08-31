<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/RescheduleService.php';
$user = requireAuth();
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';
if ($userRole !== 'patient' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}
$input = json_decode(file_get_contents('php://input'), true);
if (!$input || empty($input['appointment_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing appointment_id.']);
    exit;
}
try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole);
    echo json_encode($rs->declineRescheduleSuggestion((int)$input['appointment_id']));
} catch (Exception $e) {
    error_log('Decline Suggestion Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to decline suggestion.']);
}
