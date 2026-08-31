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

// Require authentication
$user = requireAuth();
$currentUserId = (int) $user['id'];
$currentRole   = $user['role'] ?? '';

$ns = new NotificationService(getDB());

// Parse JSON input for POST requests
$postInput = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postInput = getJsonInput();
}

// Determine target user: admins can act on behalf of a patient via patient_id param
// Use patient_id from GET param or POST body
$targetUserId = $currentUserId;
if ($currentRole === 'admin') {
    $patientId = (int)($_GET['patient_id'] ?? $postInput['patient_id'] ?? 0);
    if ($patientId > 0) {
        $targetUserId = $patientId;
    }
}

// ── GET: Fetch notifications ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $result = $ns->getNotifications($targetUserId, $page);
    jsonResponse(true, $result);
}

// ── POST: Perform an action ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $postInput['action'] ?? '';

    switch ($action) {
        case 'mark_read':
            $id = (int) ($postInput['id'] ?? 0);
            if ($id <= 0) {
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
            $id = (int) ($postInput['id'] ?? 0);
            if ($id <= 0) {
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

