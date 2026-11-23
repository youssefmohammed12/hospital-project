<?php
/**
 * HealthBridge — Get User Settings
 * Returns user profile + preferences for the settings page.
 * Role-specific data is included based on user role.
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/Repositories/DoctorSettingsRepository.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

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

    // Read preferences without mutating state. Defaults are created by the explicit update path.
    $prefStmt = $db->prepare('SELECT * FROM user_preferences WHERE user_id = ? LIMIT 1');
    $prefStmt->execute([$userId]);
    $preferences = $prefStmt->fetch();

    if (!$preferences) {
        $preferences = [
            'id' => null,
            'user_id' => (int)$userId,
            'theme' => 'system',
            'notif_appointment' => 1,
            'notif_ratings' => 1,
            'notif_messages' => 1,
            'notif_announcements' => 1,
            'notif_email' => 0,
            'accept_new_patients' => 1,
            'consultation_duration' => 30,
            'working_hours_start' => '09:00',
            'working_hours_end' => '17:00',
            'profile_visible' => 1,
            'admin_default_tab' => 'overview',
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    $doctorData = null;
    $weeklySchedule = null;
    if ($role === 'doctor') {
        $doctorSettings = new DoctorSettingsRepository($db);
        $practice = $doctorSettings->getDoctorSettings((int)$userId);
        $preferences = array_merge($preferences, $practice['preferences']);

        $docStmt = $db->prepare(
            'SELECT doctor_user_id AS user_id, department_id, specialty,
                        experience_years AS exp, accepts_new_patients AS available,
                        profile_visible
                 FROM doctor_profiles WHERE doctor_user_id=? LIMIT 1'
        );
        $docStmt->execute([$userId]);
        $doctorData = $docStmt->fetch();
        $weeklySchedule = $practice['weekly_schedule'];
    }

    if ($role === 'admin') {
        $adminPrefStmt = $db->prepare('SELECT default_tab FROM admin_preferences WHERE admin_user_id=? LIMIT 1');
        $adminPrefStmt->execute([$userId]);
        $preferences['admin_default_tab'] = $adminPrefStmt->fetchColumn() ?: 'overview';
    }

    // Get unread notification count from unified table
    $nStmt = $db->prepare('SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0');
    $nStmt->execute([$userId]);
    $unreadNotif = (int)($nStmt->fetch()['c'] ?? 0);

    jsonResponse(true, [
        'profile'     => $profile,
        'preferences' => $preferences,
        'doctor'      => $doctorData,
        'weekly_schedule' => $weeklySchedule,
        'unread_notifications' => $unreadNotif,
    ]);

} catch (Exception $e) {
    error_log('Get Settings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to load settings.']);
}
