<?php
/**
 * HealthBridge — User Login
 * Validates credentials and creates a PHP session.
 * POST { email, password } => { user: { id, name, email, role } }
 */

require_once __DIR__ . '/../../includes/auth.php';
$data     = getJsonInput();
$email    = sanitizeString($data['email'] ?? '', 150);
$password = trim($data['password'] ?? '');

// Basic validation
if (!$email || !$password) {
    jsonResponse(false, ['message' => 'Email and password are required.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, ['message' => 'Invalid email address format.']);
}

try {
    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, email, password, role, is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
} catch (Exception $e) {
    http_response_code(500);
    error_log('Login DB Error: ' . $e->getMessage());
    jsonResponse(false, ['message' => 'Database connection failed. Please verify XAMPP is running.']);
}

// Verify credentials (generic message prevents user enumeration)
if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    jsonResponse(false, ['message' => 'Invalid email or password.']);
}

// Check account status
if (!$user['is_active']) {
    http_response_code(403);
    jsonResponse(false, ['message' => 'Your account has been disabled by an administrator.']);
}

// Create session
$_SESSION['user_id'] = $user['id'];
$_SESSION['role']    = $user['role'];
$_SESSION['name']    = $user['name'];

jsonResponse(true, [
    'user' => [
        'id'    => (int)$user['id'],
        'name'  => $user['name'],
        'email' => $user['email'],
        'role'  => $user['role'],
    ],
]);


