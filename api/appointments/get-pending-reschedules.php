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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$user = requireAuth();
$userId = (int)$user['id'];
$userRole = $user['role'] ?? '';

if ($userRole !== 'doctor' && $userRole !== 'admin') {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Unauthorized.']);
}

try {
    $db = getDB();
    $rs = new RescheduleService($db, $userId, $userRole);

    if ($userRole === 'admin') {
        $requests = $rs->getAllPendingRequests();
    } else {
        $requests = $rs->getPendingRequestsForDoctor($userId);
    }

    jsonResponse(true, [
        'requests' => $requests,
        'count' => count($requests),
    ]);

} catch (Throwable $e) {
    error_log('Get Pending Reschedules Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load reschedule requests.']);
}