<?php
/**
 * HealthBridge — Get All Contact Messages (Admin Only)
 * GET => returns all contact messages in the system.
 */

require_once __DIR__ . '/../../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$user = requireRole('admin');

try {
    $db = getDB();
    $stmt = $db->query(
        'SELECT id, user_id, name, email, phone, department, subject, message, reply, replied_at, is_read, created_at
         FROM contact_messages
         ORDER BY created_at DESC'
    );
    $messages = $stmt->fetchAll();

    jsonResponse(true, ['messages' => $messages]);

} catch (Exception $e) {
    error_log('get_all_contact_messages error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load contact messages.']);
}


