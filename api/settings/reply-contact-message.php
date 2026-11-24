<?php
/**
 * HealthBridge — Admin Reply to Contact Message
 * Sends a reply to a user's support inquiry and notifies them.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json');

$user = requireRole('admin');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$input = getJsonInput();

$messageId = parsePositiveInt($input['id'] ?? null);
$replyValue = $input['reply'] ?? null;
if ($messageId === null || !is_string($replyValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'A valid message ID and reply text are required.']);
}

$reply = trim($replyValue);
if ($reply === '' || mb_strlen($reply) > 5000) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Reply text must contain between 1 and 5000 characters.']);
}

try {
    $db = getDB();

    $db->beginTransaction();

    // Serialize concurrent replies for this message and couple the reply to its notice.
    $stmt = $db->prepare("SELECT * FROM contact_messages WHERE id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$messageId]);
    $msg = $stmt->fetch();

    if (!$msg) {
        $db->rollBack();
        jsonResponse(false, ['message' => 'Message not found.']);
    }

    $stmt = $db->prepare("UPDATE contact_messages SET reply = ?, replied_at = NOW() WHERE id = ?");
    $stmt->execute([$reply, $messageId]);

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

    $db->commit();

    // Delivery is post-commit; queued support notices remain durable for the worker.
    if (!empty($msg['user_id'])) {
        try {
            $ns->processOutbox(50);
        } catch (Throwable $notificationError) {
            error_log('Contact Reply Outbox Dispatch Error: ' . get_class($notificationError));
        }
    }
    jsonResponse(true, ['message' => 'Reply sent successfully.']);

} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Reply Contact Message Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to send reply.']);
}
