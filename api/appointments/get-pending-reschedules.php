<?php
/**
 * HealthBridge — Get Pending Reschedule Requests
 *
 * GET endpoint for doctors and admins to view pending reschedule requests.
 *
 * For doctors: returns only their own pending requests.
 * For admins: returns all pending requests.
 *
 * Output:
 *   { success: bool, requests: array }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/RescheduleService.php';

header('Content-Type: application/json');

$user = requireAuth();
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

if ($userRole !== 'doctor' && $userRole !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole);

    if ($userRole === 'admin') {
        $requests = $rs->getAllPendingRequests();
    } else {
        $requests = $rs->getPendingRequestsForDoctor($userId);
    }

    echo json_encode([
        'success' => true,
        'requests' => $requests,
        'count' => count($requests),
    ]);

} catch (Exception $e) {
    error_log('Get Pending Reschedules Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load reschedule requests.']);
}