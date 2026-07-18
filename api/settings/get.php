<?php
/**
 * HealthBridge — Get User Settings
 * Returns user profile + preferences for the settings page.
 * Role-specific data is included based on user role.
 */

require_once __DIR__ . '/../../includes/auth.php';

$user = requireAuth();
$userId = $user['id'];
$role = $user['role'];

try {
    $db = getDB();

    // Get user profile
    $stmt = $db->prepare('SELECT id, name, email, phone, role, is_active, created_at FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();

    if (!$profile) {
        http_response_code(404);
        jsonResponse(false, ['message' => 'User not found.']);
    }

    // Get or create preferences
    $prefStmt = $db->prepare('SELECT * FROM user_preferences WHERE user_id = ? LIMIT 1');
    $prefStmt->execute([$userId]);
    $preferences = $prefStmt->fetch();

    if (!$preferences) {
        // Auto-create preferences
        $db->prepare('INSERT INTO user_preferences (user_id) VALUES (?)')->execute([$userId]);
        $prefStmt->execute([$userId]);
        $preferences = $prefStmt->fetch();
    }

    // Get doctor-specific data
    $doctorData = null;
    if ($role === 'doctor') {
        $docStmt = $db->prepare('SELECT * FROM doctors WHERE user_id = ? LIMIT 1');
        $docStmt->execute([$userId]);
        $doctorData = $docStmt->fetch();
    }

    // Get unread notification count from unified table
    $nStmt = $db->prepare('SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0');
    $nStmt->execute([$userId]);
    $unreadNotif = (int)($nStmt->fetch()['c'] ?? 0);

    jsonResponse(true, [
        'profile'     => $profile,
        'preferences' => $preferences,
        'doctor'      => $doctorData,
        'unread_notifications' => $unreadNotif,
    ]);

} catch (Exception $e) {
    error_log('Get Settings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load settings.']);
}
