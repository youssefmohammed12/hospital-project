<?php
/**
 * HealthBridge — Get Logged-in User Contact Messages
 * GET => returns messages sent by the logged-in user and any support replies.
 */

require_once __DIR__ . '/../../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$user = requireAuth();
$userId = (int)$user['id'];

try {
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT id, name, email, phone, department, subject, message, reply, replied_at, is_read, created_at
         FROM contact_messages
         WHERE user_id = ?
         ORDER BY created_at DESC'
    );
    $stmt->execute([$userId]);
    $messages = $stmt->fetchAll();

    jsonResponse(true, ['messages' => $messages]);

} catch (Exception $e) {
    error_log('get_user_messages error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load messages.']);
}


