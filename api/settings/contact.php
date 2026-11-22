<?php
/**
 * HealthBridge — Contact Form Handler
 * Stores contact form submissions in the database.
 * Binds logged-in user ID if session is active.
 * POST { name, email, phone?, department?, subject?, message }
 */

require_once __DIR__ . '/../../includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();

foreach (['name', 'email', 'message'] as $requiredField) {
    if (!isset($data[$requiredField]) || !is_string($data[$requiredField])) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Name, email, and message must be text values.']);
    }
}

foreach (['phone', 'department', 'subject'] as $optionalField) {
    if (array_key_exists($optionalField, $data) && $data[$optionalField] !== null && !is_string($data[$optionalField])) {
        http_response_code(400);
        jsonResponse(false, ['message' => 'Optional contact fields must be text values.']);
    }
}

$name       = sanitizeString($data['name'], 100);
$email      = sanitizeString($data['email'], 150);
$phone      = sanitizeString($data['phone'] ?? '', 30);
$department = sanitizeString($data['department'] ?? 'General Inquiry', 100);
$subject    = sanitizeString($data['subject'] ?? '', 200);
$message    = sanitizeString($data['message'], 5000);

// Associate an authenticated visitor with their account; reject stale/deactivated sessions.
$userId = null;
if (getCurrentUserId() !== null) {
    $sessionUser = requireAuth();
    $userId = (int)$sessionUser['id'];
}

// Validation
if (!$name || !$email || !$message) {
    jsonResponse(false, ['message' => 'Name, email, and message are required.']);
}
if (mb_strlen($name) < 2) {
    jsonResponse(false, ['message' => 'Name must be at least 2 characters.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, ['message' => 'Please enter a valid email address.']);
}
if (mb_strlen($message) < 10) {
    jsonResponse(false, ['message' => 'Message must be at least 10 characters.']);
}

try {
    $db = getDB();
    $db->prepare(
        'INSERT INTO contact_messages (user_id, name, email, phone, department, subject, message, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
    )->execute([$userId, $name, $email, $phone ?: null, $department ?: 'General Inquiry', $subject ?: null, $message]);

    jsonResponse(true, [
        'message' => 'Thank you for your message! We will get back to you soon.',
        'id'      => (int)$db->lastInsertId(),
    ]);

} catch (Exception $e) {
    error_log('Contact Form Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to send message. Please try again later.']);
}


