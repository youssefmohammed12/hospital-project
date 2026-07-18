<?php
/**
 * HealthBridge — Admin Reply to Contact Message
 * Sends a reply to a user's support inquiry and notifies them.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

$user = requireAuth();
$input = getJsonInput();

$messageId = (int)($input['id'] ?? 0);
$reply = trim($input['reply'] ?? '');

if ($messageId <= 0 || !$reply) {
    jsonResponse(false, ['message' => 'Message ID and reply text are required.']);
}

try {
    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM contact_messages WHERE id = ?");
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();

    if (!$msg) {
        jsonResponse(false, ['message' => 'Message not found.']);
    }

    $stmt = $db->prepare("UPDATE contact_messages SET reply = ?, replied_at = NOW() WHERE id = ?");
    $stmt->execute([$reply, $messageId]);

    // Notify the user who sent the message
    if (!empty($msg['user_id'])) {
        $ns = new NotificationService($db);
        $ns->create(
            (int)$msg['user_id'],
            NotificationService::TYPE_SUPPORT_REPLY,
            'Support Reply Received',
            "You received a reply regarding \"{$msg['subject']}\": {$reply}",
            'contact_message',
            $messageId
        );
    }

    jsonResponse(true, ['message' => 'Reply sent successfully.']);

} catch (Exception $e) {
    error_log('Reply Contact Message Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to send reply.']);
}
