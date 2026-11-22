<?php
/**
 * HealthBridge — Notifications API
 *
 * Unified REST endpoint for all notification operations.
 * Handles both GET (fetch) and POST (actions) requests.
 *
 * GET  /api/notifications.php?page=1
 * POST /api/notifications.php  { action: 'mark_read', id: 123 }
 * POST /api/notifications.php  { action: 'mark_all_read' }
 * POST /api/notifications.php  { action: 'delete', id: 123 }
 * POST /api/notifications.php  { action: 'delete_all' }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';

// Require authentication before any notification data is accessed.
$user = requireAuth();
$currentUserId = (int) $user['id'];
$currentRole   = $user['role'] ?? '';

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($requestMethod, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$db = getDB();

// Parse JSON input for POST requests
$postInput = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postInput = getJsonInput();
}

// Admins may manage notifications for a selected patient. Other roles are
// always bound to their authenticated account, regardless of supplied IDs.
$targetUserId = $currentUserId;
if ($currentRole === 'admin') {
    $patientIdValue = $_GET['patient_id'] ?? $postInput['patient_id'] ?? null;
    if ($patientIdValue !== null && $patientIdValue !== '') {
        $patientId = parsePositiveInt($patientIdValue);
        if ($patientId === null) {
            http_response_code(400);
            jsonResponse(false, ['message' => 'Patient ID must be a positive integer.']);
        }

        $patientStmt = $db->prepare("SELECT id FROM users WHERE id = ? AND role = 'patient' LIMIT 1");
        $patientStmt->execute([$patientId]);
        if (!$patientStmt->fetchColumn()) {
            http_response_code(404);
            jsonResponse(false, ['message' => 'Patient not found.']);
        }

        $targetUserId = $patientId;
    }
}

$ns = new NotificationService($db);

// ── GET: Fetch notifications ───────────────────────────────
if ($requestMethod === 'GET') {
    $pageValue = $_GET['page'] ?? 1;
    $pageParsed = filter_var($pageValue, FILTER_VALIDATE_INT);
    if ($pageParsed === false) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Page must be an integer.']);
    }

    $page = max(1, (int)$pageParsed);
    $pageIndex = $page - 1;
    if ($pageIndex > intdiv(PHP_INT_MAX, 20)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Page value is too large.']);
    }

    $result = $ns->getNotifications($targetUserId, $page);
    jsonResponse(true, $result);
}

// ── POST: Perform an action ────────────────────────────────
if ($requestMethod === 'POST') {
    $action = $postInput['action'] ?? '';
    if (!is_string($action)) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Notification action must be text.']);
    }

    switch ($action) {
        case 'mark_read':
            $id = parsePositiveInt($postInput['id'] ?? null);
            if ($id === null) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Invalid notification ID.']);
            }
            $ns->markAsRead($id, $targetUserId);
            jsonResponse(true, [
                'message'       => 'Marked as read.',
                'unread_count'  => $ns->getUnreadCount($targetUserId),
            ]);
            break;

        case 'mark_all_read':
            $updated = $ns->markAllAsRead($targetUserId);
            jsonResponse(true, [
                'message'       => "{$updated} notification(s) marked as read.",
                'unread_count'  => 0,
            ]);
            break;

        case 'delete':
            $id = parsePositiveInt($postInput['id'] ?? null);
            if ($id === null) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Invalid notification ID.']);
            }
            $ns->delete($id, $targetUserId);
            jsonResponse(true, [
                'message'       => 'Notification deleted.',
                'unread_count'  => $ns->getUnreadCount($targetUserId),
            ]);
            break;

        case 'delete_all':
            $deleted = $ns->deleteAll($targetUserId);
            jsonResponse(true, [
                'message'       => "{$deleted} notification(s) deleted.",
                'unread_count'  => 0,
            ]);
            break;

        default:
            jsonResponse(false, ['message' => 'Unknown action.']);
    }
}

// Unsupported method
http_response_code(405);
jsonResponse(false, ['message' => 'Method not allowed.']);

