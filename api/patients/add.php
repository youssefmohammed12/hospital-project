<?php
/**
 * HealthBridge — Admin: Add Patient
 * Creates a new patient account (admin-only).
 * POST { name, email, password, phone? }
 */

require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

$data     = getJsonInput();
$name     = sanitizeString($data['name']     ?? '', 100);
$email    = sanitizeString($data['email']    ?? '', 150);
$password = trim($data['password'] ?? '');
$phone    = sanitizeString($data['phone']    ?? '', 20);

// Validation
if (!$name || !$email || !$password) {
    jsonResponse(false, ['message' => 'Name, email, and password are required.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, ['message' => 'Invalid email address.']);
}
if (strlen($password) < 6) {
    jsonResponse(false, ['message' => 'Password must be at least 6 characters.']);
}

try {
    $db = getDB();

    // Check duplicate email
    $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $check->execute([$email]);
    if ($check->fetch()) {
        jsonResponse(false, ['message' => 'A user with this email already exists.']);
    }

    // Insert patient
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $db->prepare(
        'INSERT INTO users (name, email, password, role, phone, created_at) VALUES (?, ?, ?, "patient", ?, NOW())'
    )->execute([$name, $email, $hashed, $phone ?: null]);

    jsonResponse(true, [
        'patient' => [
            'id'   => (int)$db->lastInsertId(),
            'name' => $name,
            'email' => $email,
            'role' => 'patient',
        ]
    ]);

} catch (Exception $e) {
    error_log('Add Patient Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to add patient. Please try again.']);
}

