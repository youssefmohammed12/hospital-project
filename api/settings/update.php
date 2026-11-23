<?php
/**
 * HealthBridge — Update User Settings
 * Handles profile updates, preferences, doctor settings, and account deactivation.
 * POST { section, ...fields }
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../services/NotificationService.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/Repositories/DoctorSettingsRepository.php';
require_once __DIR__ . '/../../services/Repositories/AccountLifecycleRepository.php';

$user = requireAuth();
$userId = (int)$user['id'];
$role = (string)$user['role'];

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    jsonResponse(false, ['message' => 'Method not allowed.']);
}

$data = getJsonInput();
$sectionValue = $data['section'] ?? null;
if (!is_string($sectionValue)) {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Settings section must be text.']);
}
$section = sanitizeString($sectionValue, 50);

if ($section === '') {
    http_response_code(400);
    jsonResponse(false, ['message' => 'Settings section is required.']);
}

$db = null;

try {
    $db = getDB();
    $settingsSchema = HealthBridgeSchemaSelector::fromEnvironment('HEALTHBRIDGE_SETTINGS_SCHEMA');
    $auditSchema = AuditService::schemaFromEnvironment();

    // Legacy accounts may lack a preference row. Check first and insert only
    // immediately before a validated preference write; INSERT IGNORE reserves
    // AUTO_INCREMENT values even when it silently ignores an existing user row.
    $ensurePreferencesRow = static function () use ($db, $userId): void {
        $exists = $db->prepare('SELECT 1 FROM user_preferences WHERE user_id = ? LIMIT 1');
        $exists->execute([$userId]);
        if ($exists->fetchColumn()) {
            return;
        }

        try {
            $db->prepare('INSERT INTO user_preferences (user_id) VALUES (?)')
               ->execute([$userId]);
        } catch (PDOException $insertError) {
            // A concurrent request may have created the unique user row after
            // the initial read. Suppress only that race, not other DB failures.
            if ((string)$insertError->getCode() !== '23000') {
                throw $insertError;
            }
            $exists->execute([$userId]);
            if (!$exists->fetchColumn()) {
                throw $insertError;
            }
        }
    };

    switch ($section) {
        case 'profile': {
            $nameValue = $data['name'] ?? null;
            $emailValue = $data['email'] ?? null;
            $phoneValue = $data['phone'] ?? '';
            if (!is_string($nameValue) || !is_string($emailValue) || !is_string($phoneValue)) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Profile name, email, and phone must be text values.']);
            }

            $name = sanitizeString($nameValue, 100);
            $email = sanitizeString($emailValue, 150);
            $phone = sanitizeString($phoneValue, 20);

            if ($name === '' || $email === '') {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Name and email are required.']);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Invalid email address.']);
            }

            $duplicate = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
            $duplicate->execute([$email, $userId]);
            if ($duplicate->fetchColumn()) {
                http_response_code(409);
                jsonResponse(false, ['message' => 'This email is already in use.']);
            }

            $db->beginTransaction();
            $oldStmt = $db->prepare(
                'SELECT name, email, phone
                 FROM users
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE'
            );
            $oldStmt->execute([$userId]);
            $oldUser = $oldStmt->fetch();

            if (!$oldUser) {
                $db->rollBack();
                http_response_code(404);
                jsonResponse(false, ['message' => 'Account not found.']);
            }

            // Preserve unchanged legacy phone values; validate and check normalized
            // uniqueness only when the user supplies a non-empty replacement.
            $existingPhone = (string)($oldUser['phone'] ?? '');
            if ($phone !== '' && $phone !== $existingPhone) {
                $phoneError = validatePhone($phone);
                if ($phoneError !== null) {
                    $db->rollBack();
                    http_response_code(400);
                    jsonResponse(false, ['message' => $phoneError]);
                }

                if (isPhoneTaken($db, $phone, $userId)) {
                    $db->rollBack();
                    http_response_code(409);
                    jsonResponse(false, ['message' => 'This phone number is already in use by another account.']);
                }
            }

            $update = $db->prepare(
                'UPDATE users
                 SET name = ?, email = ?, phone = ?, updated_at = NOW()
                 WHERE id = ?'
            );
            $update->execute([$name, $email, $phone !== '' ? $phone : null, $userId]);

            $changes = [];
            if ($oldUser['name'] !== $name) {
                $changes[] = "Name: {$oldUser['name']} → {$name}";
            }
            if ($oldUser['email'] !== $email) {
                $changes[] = "Email: {$oldUser['email']} → {$email}";
            }
            $oldPhone = $oldUser['phone'] ?? 'None';
            $newPhone = $phone !== '' ? $phone : 'None';
            if ($oldPhone !== $newPhone) {
                $changes[] = "Phone: {$oldPhone} → {$newPhone}";
            }

            if (!empty($changes)) {
                $audit = new AuditService($db, $userId, $role, $auditSchema);
                $patientCtx = $role === 'patient' ? $userId : null;
                $doctorCtx = $role === 'doctor' ? $userId : null;
                $logged = $audit->log(
                    'update',
                    $role,
                    $userId,
                    ['name' => $oldUser['name'], 'email' => $oldUser['email'], 'phone' => $oldUser['phone']],
                    ['name' => $name, 'email' => $email, 'phone' => $phone !== '' ? $phone : null],
                    'Profile updated: ' . implode('; ', $changes),
                    $patientCtx,
                    $doctorCtx
                );
                if (!$logged) {
                    throw new RuntimeException('Could not record the profile update audit event.');
                }
            }

            // Enqueue the existing profile notice before commit so it is durable
            // only when the profile and audit update commit.
            $notifications = new NotificationService($db);
            $notifications->create(
                $userId,
                NotificationService::TYPE_PROFILE_UPDATED,
                'Profile Updated',
                'Your profile information has been updated successfully.',
                'user',
                $userId
            );

            $db->commit();

            try {
                $notifications->processOutbox(50);
            } catch (Throwable $notificationError) {
                error_log('Profile Update Outbox Dispatch Error: ' . get_class($notificationError));
            }

            jsonResponse(true, ['message' => 'Profile updated successfully!', 'name' => $name, 'email' => $email]);
            break;
        }

        case 'password': {
            $current = $data['current_password'] ?? '';
            $newPass = $data['new_password'] ?? '';
            $confirm = $data['confirm_password'] ?? '';

            if (!is_string($current) || !is_string($newPass) || !is_string($confirm)
                || $current === '' || $newPass === '' || $confirm === '') {
                http_response_code(400);
                jsonResponse(false, ['message' => 'All password fields are required.']);
            }

            $passwordValidation = validatePassword($newPass);
            if (!$passwordValidation['valid']) {
                http_response_code(400);
                jsonResponse(false, ['message' => implode(' ', $passwordValidation['errors'])]);
            }
            if ($newPass !== $confirm) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'New passwords do not match.']);
            }

            $sessionVersion = (int)($_SESSION['auth_session_version'] ?? 0);
            if ($sessionVersion < 1) {
                destroyAuthSession();
                http_response_code(401);
                jsonResponse(false, ['message' => 'Please log in again before changing your password.']);
            }

            $passwordColumn = $settingsSchema === DoctorSettingsRepository::SCHEMA_V1 ? 'password' : 'password_hash';
            $db->beginTransaction();
            $stmt = $db->prepare(
                "SELECT {$passwordColumn} AS password_hash, session_version
                 FROM users
                 WHERE id = ? AND session_version = ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmt->execute([$userId, $sessionVersion]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($current, $row['password_hash'])) {
                $db->rollBack();
                http_response_code(403);
                jsonResponse(false, ['message' => 'Current password is incorrect or the session is no longer current.']);
            }

            $hash = password_hash($newPass, PASSWORD_DEFAULT);
            $update = $db->prepare(
                "UPDATE users
                 SET {$passwordColumn} = ?, session_version = session_version + 1, updated_at = NOW()
                 WHERE id = ? AND session_version = ?"
            );
            $update->execute([$hash, $userId, $sessionVersion]);

            if ($update->rowCount() !== 1) {
                $db->rollBack();
                http_response_code(409);
                jsonResponse(false, ['message' => 'Your credentials changed in another session. Please log in again.']);
            }

            $audit = new AuditService($db, $userId, $role, $auditSchema);
            $patientCtx = $role === 'patient' ? $userId : null;
            $doctorCtx = $role === 'doctor' ? $userId : null;
            $logged = $audit->log(
                'update',
                $role,
                $userId,
                '[REDACTED]',
                '[REDACTED]',
                'Password changed by user',
                $patientCtx,
                $doctorCtx
            );
            if (!$logged) {
                throw new RuntimeException('Could not record the password change audit event.');
            }

            $db->commit();
            $versionStmt = $db->prepare('SELECT session_version FROM users WHERE id = ?');
            $versionStmt->execute([$userId]);
            $_SESSION['auth_session_version'] = (int)$versionStmt->fetchColumn();

            jsonResponse(true, ['message' => 'Password changed successfully!']);
            break;
        }

        case 'appearance': {
            $theme = sanitizeString($data['theme'] ?? '', 10);
            if (!in_array($theme, ['light', 'dark', 'system'], true)) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Invalid theme selection.']);
            }

            $ensurePreferencesRow();
            $db->prepare('UPDATE user_preferences SET theme = ? WHERE user_id = ?')
               ->execute([$theme, $userId]);

            jsonResponse(true, ['message' => 'Theme preference saved!', 'theme' => $theme]);
            break;
        }

        case 'notifications': {
            $fields = ['notif_appointment', 'notif_ratings', 'notif_messages', 'notif_announcements', 'notif_email'];
            $updates = [];
            $params = [];

            foreach ($fields as $field) {
                if (array_key_exists($field, $data)) {
                    $value = $data[$field];
                    if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
                        http_response_code(400);
                        jsonResponse(false, ['message' => 'Notification settings must be boolean values.']);
                    }

                    $updates[] = $field . ' = ?';
                    $params[] = in_array($value, [1, '1', true], true) ? 1 : 0;
                }
            }

            if (empty($updates)) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'No notification settings to update.']);
            }

            $ensurePreferencesRow();
            $params[] = $userId;
            $db->prepare('UPDATE user_preferences SET ' . implode(', ', $updates) . ' WHERE user_id = ?')
               ->execute($params);

            jsonResponse(true, ['message' => 'Notification preferences updated!']);
            break;
        }

        case 'doctor': {
            if ($role !== 'doctor') {
                http_response_code(403);
                jsonResponse(false, ['message' => 'Only doctors can access these settings.']);
            }

            $acceptNew = null;
            if (array_key_exists('accept_new_patients', $data)) {
                $value = $data['accept_new_patients'];
                if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
                    http_response_code(400);
                    jsonResponse(false, ['message' => 'Accept-new-patients must be a boolean value.']);
                }
                $acceptNew = in_array($value, [1, '1', true], true) ? 1 : 0;
            }

            $duration = null;
            if (array_key_exists('consultation_duration', $data)) {
                $durationValue = $data['consultation_duration'];
                if ((!is_int($durationValue) && !is_string($durationValue)) || filter_var($durationValue, FILTER_VALIDATE_INT) === false) {
                    http_response_code(400);
                    jsonResponse(false, ['message' => 'Consultation duration must be an integer.']);
                }
                $duration = (int)$durationValue;
                if ($duration < 15 || $duration > 120 || $duration % 5 !== 0) {
                    http_response_code(400);
                    jsonResponse(false, ['message' => 'Consultation duration must be between 15 and 120 minutes in 5-minute steps.']);
                }
            }

            $hoursStart = '';
            if (array_key_exists('working_hours_start', $data)) {
                if (!is_string($data['working_hours_start']) || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $data['working_hours_start'])) {
                    http_response_code(400);
                    jsonResponse(false, ['message' => 'Working-hours start must be a valid HH:MM time.']);
                }
                $hoursStart = $data['working_hours_start'];
            }

            $hoursEnd = '';
            if (array_key_exists('working_hours_end', $data)) {
                if (!is_string($data['working_hours_end']) || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $data['working_hours_end'])) {
                    http_response_code(400);
                    jsonResponse(false, ['message' => 'Working-hours end must be a valid HH:MM time.']);
                }
                $hoursEnd = $data['working_hours_end'];
            }

            if ($hoursStart !== '' && $hoursEnd !== '' && $hoursStart >= $hoursEnd) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Working-hours start must be before working-hours end.']);
            }

            $visible = null;
            if (array_key_exists('profile_visible', $data)) {
                $value = $data['profile_visible'];
                if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
                    http_response_code(400);
                    jsonResponse(false, ['message' => 'Profile visibility must be a boolean value.']);
                }
                $visible = in_array($value, [1, '1', true], true) ? 1 : 0;
            }

            // V2 requires the complete seven-day model instead of a global hours range.
            if ($settingsSchema === DoctorSettingsRepository::SCHEMA_V2
                && ($hoursStart !== '' || $hoursEnd !== '')
                && !array_key_exists('weekly_schedule', $data)) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'V2 settings require a seven-day weekly_schedule instead of global working hours.']);
            }
            $changes = [];
            if ($acceptNew !== null) {
                $changes['accept_new_patients'] = $acceptNew;
            }
            if ($duration !== null) {
                $changes['consultation_duration'] = $duration;
            }
            if ($visible !== null) {
                $changes['profile_visible'] = $visible;
            }
            if ($settingsSchema === DoctorSettingsRepository::SCHEMA_V1) {
                if ($hoursStart !== '') {
                    $changes['working_hours_start'] = $hoursStart;
                }
                if ($hoursEnd !== '') {
                    $changes['working_hours_end'] = $hoursEnd;
                }
            } elseif (array_key_exists('weekly_schedule', $data)) {
                if (!is_array($data['weekly_schedule'])) {
                    http_response_code(400);
                    jsonResponse(false, ['message' => 'Weekly schedule must be an array.']);
                }
                $changes['weekly_schedule'] = $data['weekly_schedule'];
            }

            $settingsRepository = new DoctorSettingsRepository($db, $settingsSchema);
            $settingsRepository->updateDoctorSettings($userId, $changes, $userId);

            jsonResponse(true, ['message' => 'Doctor settings updated!']);
            break;
        }

        case 'admin': {
            if ($role !== 'admin') {
                http_response_code(403);
                jsonResponse(false, ['message' => 'Only admins can access these settings.']);
            }

            $defaultTabValue = $data['admin_default_tab'] ?? '';
            if (!is_string($defaultTabValue)) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Default dashboard tab must be text.']);
            }
            $defaultTab = sanitizeString($defaultTabValue, 50);
            $allowedTabs = ['overview', 'doctors', 'patients', 'appointments', 'support-messages'];
            if ($defaultTab !== '' && !in_array($defaultTab, $allowedTabs, true)) {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Invalid default dashboard tab.']);
            }
            if ($defaultTab !== '') {
                if ($settingsSchema === DoctorSettingsRepository::SCHEMA_V1) {
                    $ensurePreferencesRow();
                    $db->prepare('UPDATE user_preferences SET admin_default_tab = ? WHERE user_id = ?')
                       ->execute([$defaultTab, $userId]);
                } else {
                    $db->prepare(
                        'INSERT INTO admin_preferences (admin_user_id, default_tab)
                         VALUES (?, ?)
                         ON DUPLICATE KEY UPDATE default_tab=VALUES(default_tab), updated_at=NOW()'
                    )->execute([$userId, $defaultTab]);
                }
            }

            jsonResponse(true, ['message' => 'Admin preferences updated!']);
            break;
        }

        case 'delete_account': {
            // Intentionally NOT role-gated at this endpoint (PHP audit,
            // 23 Nov 2026, finding L1): self-deactivation is a supported
            // feature for every role, and per-role safety — including the
            // last-admin guard — is enforced inside
            // AccountLifecycleRepository::deactivateSelf(). Do not add a
            // role check here without moving that protection first.
            $confirm = $data['confirm_password'] ?? '';
            if (!is_string($confirm) || $confirm === '') {
                http_response_code(400);
                jsonResponse(false, ['message' => 'Password is required to deactivate your account.']);
            }

            $lifecycle = new AccountLifecycleRepository(
                $db,
                $settingsSchema
            );
            $result = $lifecycle->deactivateSelf($userId, $role, $confirm);

            // Only doctor self-deactivation creates patient cancellation notices.
            // Delivery is post-commit; pending outbox rows remain retryable.
            if ($role === 'doctor' && (int)$result['cancelled_appointments'] > 0) {
                $notifications = new NotificationService($db);
                try {
                    $notifications->processOutbox(50);
                } catch (Throwable $notificationError) {
                    error_log('Self-Deactivation Outbox Dispatch Error: ' . get_class($notificationError));
                }
            }

            jsonResponse(true, [
                'message' => 'account_deactivated',
                'cancelled_appointments' => (int)$result['cancelled_appointments'],
                'redirect' => 'logout.php',
            ]);
            break;
        }

        default:
            http_response_code(400);
            jsonResponse(false, ['message' => 'Unknown settings section.']);
    }
} catch (DomainException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code((str_contains($e->getMessage(), 'not active') || str_contains($e->getMessage(), 'Password is incorrect')) ? 403 : 409);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (InvalidArgumentException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    jsonResponse(false, ['message' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    if (isDuplicateKeyViolation($e)) {
        http_response_code(409);
        jsonResponse(false, ['message' => 'The submitted email or phone number is already in use.']);
    }
    error_log('Update Settings Error: ' . $e->getMessage());
    http_response_code(500);
    jsonResponse(false, ['message' => 'Failed to update settings.']);
}
