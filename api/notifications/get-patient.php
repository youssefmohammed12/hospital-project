<?php
/**
 * HealthBridge — Get Patient Notifications
 * Returns recent notifications for the logged-in patient, plus unread count.
 * Uses the unified notifications table.
 */

require_once __DIR__ . '/../../includes/auth.php';

$userId = requireAuth()['id'];

try {
    $db = getDB();

    // Get notifications
    $stmt = $db->prepare(
        'SELECT id, ref_id AS appointment_id, title, message, is_read, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20'
    );
    $stmt->execute([$userId]);
    $notifications = $stmt->fetchAll();

    // Count unread
    $unreadStmt = $db->prepare(
        'SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0'
    );
    $unreadStmt->execute([$userId]);
    $unread = (int)($unreadStmt->fetch()['count'] ?? 0);

    jsonResponse(true, ['notifications' => $notifications, 'unread_count' => $unread]);

} catch (Exception $e) {
    error_log('Get Patient Notifications Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load notifications.']);
}

