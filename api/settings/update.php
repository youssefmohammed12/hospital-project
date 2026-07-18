<?php
/**
 * HealthBridge — Update User Settings
 * Handles profile updates, preference changes, and doctor-specific settings.
 * POST { section, ...fields }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';

$user = requireAuth();
$userId = $user['id'];
$role = $user['role'];

$data   = getJsonInput();
$section = sanitizeString($data['section'] ?? '', 50);

if (!$section) {
    jsonResponse(false, ['message' => 'Settings section is required.']);
}

try {
    $db = getDB();

    switch ($section) {

        /* ── PROFILE ─────────────────────────────────── */
        case 'profile':
            $name  = sanitizeString($data['name']  ?? '', 100);
            $email = sanitizeString($data['email'] ?? '', 150);
            $phone = sanitizeString($data['phone'] ?? '', 20);

            if (!$name || !$email) {
                jsonResponse(false, ['message' => 'Name and email are required.']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(false, ['message' => 'Invalid email address.']);
            }

            // Check duplicate email
            $check = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1');
            $check->execute([$email, $userId]);
            if ($check->fetch()) {
                jsonResponse(false, ['message' => 'This email is already in use.']);
            }

            // Fetch old values for comparison
            $oldStmt = $db->prepare('SELECT name, email, phone FROM users WHERE id = ? LIMIT 1');
            $oldStmt->execute([$userId]);
            $oldUser = $oldStmt->fetch();

            $db->prepare('UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?')
               ->execute([$name, $email, $phone ?: null, $userId]);

            // Build change list and audit if anything changed
            $changes = [];
            if ($oldUser['name'] !== $name) $changes[] = "Name: {$oldUser['name']} → {$name}";
            if ($oldUser['email'] !== $email) $changes[] = "Email: {$oldUser['email']} → {$email}";
            $oldPhone = $oldUser['phone'] ?? 'None';
            $newPhone = $phone ?: 'None';
            if ($oldPhone !== $newPhone) $changes[] = "Phone: {$oldPhone} → {$newPhone}";
            if (!empty($changes)) {
                $audit = new AuditService($db, $userId, $role);
                $patientCtx = ($role === 'patient') ? (int)$userId : null;
                $doctorCtx  = ($role === 'doctor')  ? (int)$userId : null;
                $audit->log('update', $role, $userId, null, null, 'Profile updated: ' . implode(', ', $changes), $patientCtx, $doctorCtx);
            }

            // Notify user of profile update
            $ns = new NotificationService($db);
            $ns->create(
                $userId,
                NotificationService::TYPE_PROFILE_UPDATED,
                'Profile Updated',
                'Your profile information has been updated successfully.',
                'user',
                $userId
            );

            jsonResponse(true, ['message' => 'Profile updated successfully!', 'name' => $name, 'email' => $email]);
            break;

        /* ── PASSWORD ────────────────────────────────── */
        case 'password':
            $current    = trim($data['current_password'] ?? '');
            $newPass    = trim($data['new_password'] ?? '');
            $confirm    = trim($data['confirm_password'] ?? '');

            if (!$current || !$newPass || !$confirm) {
                jsonResponse(false, ['message' => 'All password fields are required.']);
            }
            if (strlen($newPass) < 6) {
                jsonResponse(false, ['message' => 'New password must be at least 6 characters.']);
            }
            if ($newPass !== $confirm) {
                jsonResponse(false, ['message' => 'New passwords do not match.']);
            }

            // Verify current password
            $stmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($current, $row['password'])) {
                jsonResponse(false, ['message' => 'Current password is incorrect.']);
            }

            $hash = password_hash($newPass, PASSWORD_BCRYPT);
            $db->prepare('UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?')
               ->execute([$hash, $userId]);

            // Log password change event — never log the hash
            try {
                $audit = new AuditService($db, $userId, $role);
                $patientCtx = ($role === 'patient') ? (int)$userId : null;
                $doctorCtx  = ($role === 'doctor')  ? (int)$userId : null;
                $audit->log('update', $role, $userId, '[REDACTED]', '[REDACTED]', 'Password changed by user', $patientCtx, $doctorCtx);
            } catch (Exception $auditErr) {
                error_log('Audit log error (password change): ' . $auditErr->getMessage());
            }

            jsonResponse(true, ['message' => 'Password changed successfully!']);
            break;

        /* ── APPEARANCE (Theme) ──────────────────────── */
        case 'appearance':
            $theme = sanitizeString($data['theme'] ?? '', 10);
            if (!in_array($theme, ['light', 'dark', 'system'], true)) {
                jsonResponse(false, ['message' => 'Invalid theme selection.']);
            }

            $db->prepare('UPDATE user_preferences SET theme = ? WHERE user_id = ?')
               ->execute([$theme, $userId]);

            jsonResponse(true, ['message' => 'Theme preference saved!', 'theme' => $theme]);
            break;

        /* ── NOTIFICATIONS ───────────────────────────── */
        case 'notifications':
            $fields = ['notif_appointment', 'notif_ratings', 'notif_messages', 'notif_announcements', 'notif_email'];
            $updates = [];
            $params = [];

            foreach ($fields as $f) {
                if (isset($data[$f])) {
                    $updates[] = "$f = ?";
                    $params[]  = $data[$f] ? 1 : 0;
                }
            }

            if (empty($updates)) {
                jsonResponse(false, ['message' => 'No notification settings to update.']);
            }

            $params[] = $userId;
            $db->prepare('UPDATE user_preferences SET ' . implode(', ', $updates) . ' WHERE user_id = ?')
               ->execute($params);

            jsonResponse(true, ['message' => 'Notification preferences updated!']);
            break;

        /* ── DOCTOR SETTINGS ─────────────────────────── */
        case 'doctor':
            if ($role !== 'doctor') {
                jsonResponse(false, ['message' => 'Only doctors can access these settings.']);
            }

            $acceptNew  = isset($data['accept_new_patients']) ? ($data['accept_new_patients'] ? 1 : 0) : null;
            $duration   = isset($data['consultation_duration']) ? (int)$data['consultation_duration'] : null;
            $hoursStart = sanitizeString($data['working_hours_start'] ?? '', 5);
            $hoursEnd   = sanitizeString($data['working_hours_end'] ?? '', 5);
            $visible    = isset($data['profile_visible']) ? ($data['profile_visible'] ? 1 : 0) : null;

            $updates = [];
            $params  = [];

            if ($acceptNew !== null) {
                $updates[] = 'accept_new_patients = ?';
                $params[]  = $acceptNew;
            }
            if ($duration !== null && $duration > 0) {
                $updates[] = 'consultation_duration = ?';
                $params[]  = $duration;
            }
            if ($hoursStart && preg_match('/^\d{2}:\d{2}$/', $hoursStart)) {
                $updates[] = 'working_hours_start = ?';
                $params[]  = $hoursStart;
            }
            if ($hoursEnd && preg_match('/^\d{2}:\d{2}$/', $hoursEnd)) {
                $updates[] = 'working_hours_end = ?';
                $params[]  = $hoursEnd;
            }
            if ($visible !== null) {
                $updates[] = 'profile_visible = ?';
                $params[]  = $visible;
            }

            if (!empty($updates)) {
                $params[] = $userId;
                $db->prepare('UPDATE user_preferences SET ' . implode(', ', $updates) . ' WHERE user_id = ?')
                   ->execute($params);
            }

            // Also sync doctor availability if accept_new_patients was toggled
            if ($acceptNew !== null) {
                $db->prepare('UPDATE doctors SET available = ? WHERE user_id = ?')
                   ->execute([$acceptNew, $userId]);
            }

            jsonResponse(true, ['message' => 'Doctor settings updated!']);
            break;

        /* ── ADMIN SETTINGS ──────────────────────────── */
        case 'admin':
            if ($role !== 'admin') {
                jsonResponse(false, ['message' => 'Only admins can access these settings.']);
            }

            $defaultTab = sanitizeString($data['admin_default_tab'] ?? '', 50);
            if ($defaultTab) {
                $db->prepare('UPDATE user_preferences SET admin_default_tab = ? WHERE user_id = ?')
                   ->execute([$defaultTab, $userId]);
            }

            jsonResponse(true, ['message' => 'Admin preferences updated!']);
            break;

        /* ── DELETE ACCOUNT ──────────────────────────── */
        case 'delete_account':
            $confirm = trim($data['confirm_password'] ?? '');
            if (!$confirm) {
                jsonResponse(false, ['message' => 'Password is required to delete your account.']);
            }

            $stmt = $db->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($confirm, $row['password'])) {
                jsonResponse(false, ['message' => 'Password is incorrect. Account not deleted.']);
            }

            // CASCADE will remove preferences, appointments, notifications, ratings
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);

            jsonResponse(true, ['message' => 'account_deleted', 'redirect' => 'logout.php']);
            break;

        default:
            jsonResponse(false, ['message' => 'Unknown settings section.']);
    }

} catch (Exception $e) {
    error_log('Update Settings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update settings.']);
}
