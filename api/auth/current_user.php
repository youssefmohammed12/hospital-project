<?php
/**
 * HealthBridge — Get Current User
 * Returns the logged-in user's data from the database.
 * Used by the frontend to sync localStorage with server session.
 */

require_once __DIR__ . '/../../includes/auth.php';

$user = requireAuth(); // Exits with 401 if not logged in

try {
    $db = getDB();
    $stmt = $db->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row) {
        session_destroy();
        http_response_code(401);
        jsonResponse(false, ['message' => 'User account not found.']);
    }

    // Sync role in session
    $_SESSION['role'] = $row['role'];

    jsonResponse(true, [
        'user' => [
            'id'    => (int)$row['id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
        ],
    ]);
} catch (Exception $e) {
    error_log('Current User Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Could not verify session.']);
}

