<?php
declare(strict_types=1);

require_once __DIR__ . '/../NotificationService.php';
require_once __DIR__ . '/../ClinicalAccessService.php';

/**
 * Patient account and appointment mutations (V2 schema).
 */
final class PatientManagementRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** Create an admin-provisioned patient and its profile aggregate. */
    public function createPatient(
        string $name,
        string $email,
        string $passwordHash,
        string $patientNumber,
        ?string $phone,
        int $actorUserId
    ): int {
        $name = trim($name);
        $email = trim($email);
        $patientNumber = trim($patientNumber);
        $phone = $phone !== null && trim($phone) !== '' ? trim($phone) : null;

        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Patient name must contain 2 to 100 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            throw new InvalidArgumentException('A valid patient email address is required.');
        }
        if ($passwordHash === '' || mb_strlen($passwordHash) > 255) {
            throw new InvalidArgumentException('A valid password hash is required.');
        }
        if ($patientNumber === '' || mb_strlen($patientNumber) > 20) {
            throw new InvalidArgumentException('A valid patient number is required.');
        }

        return $this->withTransaction(function () use ($name, $email, $passwordHash, $patientNumber, $phone, $actorUserId): int {
            $this->assertActor($actorUserId, 'admin');

            $emailCheck = $this->db->prepare('SELECT id FROM users WHERE email=? LIMIT 1 FOR UPDATE');
            $emailCheck->execute([$email]);
            if ($emailCheck->fetchColumn()) {
                throw new DomainException('A user with this email already exists.');
            }
            if ($phone !== null) {
                $phoneCheck = $this->db->prepare('SELECT id FROM users WHERE phone_normalized=? LIMIT 1');
                $phoneCheck->execute([$this->normalizePhone($phone)]);
                if ($phoneCheck->fetchColumn()) {
                    throw new DomainException('This phone number is already registered.');
                }
            }

            $stmt = $this->db->prepare(
                "INSERT INTO users (name,email,password_hash,role,patient_number,phone,is_active,created_at)
                 VALUES (?,?,?,'patient',?,?,1,NOW())"
            );
            $stmt->execute([$name,$email,$passwordHash,$patientNumber,$phone]);
            $patientId = (int)$this->db->lastInsertId();
            if ($patientId <= 0) {
                throw new RuntimeException('Patient account ID could not be generated.');
            }

            $this->db->prepare('INSERT IGNORE INTO user_preferences (user_id) VALUES (?)')
                ->execute([$patientId]);
            $this->db->prepare('INSERT INTO patient_profiles (patient_user_id) VALUES (?)')
                ->execute([$patientId]);
            $this->db->prepare('INSERT INTO patient_medical_profiles (patient_user_id) VALUES (?)')
                ->execute([$patientId]);

            $this->writeAudit(
                $actorUserId,
                'admin',
                'create',
                'patient',
                $patientId,
                null,
                ['patient_number'=>$patientNumber],
                'Patient account created by an administrator.',
                $patientId
            );

            return $patientId;
        });
    }

    /** Update admin-editable fields. Password hashes only; never raw passwords. */
    public function updatePatient(int $patientId, array $changes, int $actorUserId, string $actorRole = 'admin'): array
    {
        if ($patientId <= 0) {
            throw new InvalidArgumentException('Patient user ID must be positive.');
        }
        foreach (array_keys($changes) as $key) {
            if (!in_array($key, ['name', 'email', 'phone', 'gender', 'date_of_birth', 'is_active', 'password_hash'], true)) {
                throw new InvalidArgumentException('Unsupported patient update field.');
            }
        }

        return $this->withTransaction(function () use ($patientId, $changes, $actorUserId, $actorRole): array {
            $this->assertActor($actorUserId, $actorRole);
            if ($actorRole === 'patient' && $patientId !== $actorUserId) {
                throw new DomainException('Patients may only update their own account.');
            }
            $patient = $this->lockPatient($patientId);
            $oldProfile = $this->lockPatientDemographics($patientId);

            $name = array_key_exists('name', $changes) ? trim((string)$changes['name']) : (string)$patient['name'];
            $email = array_key_exists('email', $changes) ? trim((string)$changes['email']) : (string)$patient['email'];
            $phone = array_key_exists('phone', $changes)
                ? (trim((string)$changes['phone']) !== '' ? trim((string)$changes['phone']) : null)
                : ($patient['phone'] !== null ? (string)$patient['phone'] : null);

            if ($name === '' || mb_strlen($name) > 100) {
                throw new InvalidArgumentException('Patient name must contain 1 to 100 characters.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
                throw new InvalidArgumentException('A valid patient email address is required.');
            }
            if ($email !== (string)$patient['email']) {
                $duplicate = $this->db->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
                $duplicate->execute([$email, $patientId]);
                if ($duplicate->fetchColumn()) {
                    throw new DomainException('This email is already in use by another account.');
                }
            }
            if ($phone !== null && $phone !== ($patient['phone'] !== null ? (string)$patient['phone'] : null)) {
                $duplicate = $this->db->prepare('SELECT id FROM users WHERE phone_normalized=? AND id<>? LIMIT 1');
                $duplicate->execute([$this->normalizePhone($phone), $patientId]);
                if ($duplicate->fetchColumn()) {
                    throw new DomainException('This phone number is already in use by another account.');
                }
            }

            $gender = array_key_exists('gender', $changes) ? $changes['gender'] : $oldProfile['gender'];
            $dob = array_key_exists('date_of_birth', $changes) ? $changes['date_of_birth'] : $oldProfile['date_of_birth'];
            if ($gender !== null && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
                throw new InvalidArgumentException('Gender must be Male, Female, or Other.');
            }
            if ($dob !== null) {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$dob);
                $errors = DateTimeImmutable::getLastErrors();
                if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                    || $date->format('Y-m-d') !== (string)$dob) {
                    throw new InvalidArgumentException('Date of birth must be a valid YYYY-MM-DD date.');
                }
            }

            $oldActive = (int)$patient['is_active'];
            $newActive = array_key_exists('is_active', $changes) && $changes['is_active'] !== null
                ? (int)$changes['is_active'] : $oldActive;
            if (!in_array($newActive, [0, 1], true)) {
                throw new InvalidArgumentException('Patient account status must be 0 or 1.');
            }
            $passwordHash = $changes['password_hash'] ?? null;
            if ($passwordHash !== null && (!is_string($passwordHash) || $passwordHash === '' || mb_strlen($passwordHash) > 255)) {
                throw new InvalidArgumentException('A valid password hash is required.');
            }

            $descriptions = [];
            if ((string)$patient['name'] !== $name) { $descriptions[] = "Name: {$patient['name']} → {$name}"; }
            if ((string)$patient['email'] !== $email) { $descriptions[] = "Email: {$patient['email']} → {$email}"; }
            $oldPhone = $patient['phone'] ?? 'None';
            $newPhone = $phone ?? 'None';
            if ($oldPhone !== $newPhone) { $descriptions[] = "Phone: {$oldPhone} → {$newPhone}"; }
            if ($gender !== $oldProfile['gender']) {
                $descriptions[] = 'Gender: ' . ($oldProfile['gender'] ?: 'Not specified') . ' → ' . ($gender ?: 'Not specified');
            }
            if ($dob !== $oldProfile['date_of_birth']) {
                $descriptions[] = 'Date of birth: ' . ($oldProfile['date_of_birth'] ?: 'Not specified') . ' → ' . ($dob ?: 'Not specified');
            }
            $statusChanged = $newActive !== $oldActive;
            if ($statusChanged) {
                $descriptions[] = 'Status: ' . ($oldActive ? 'Active' : 'Inactive') . ' → ' . ($newActive ? 'Active' : 'Inactive');
            }
            $passwordChanged = $passwordHash !== null;
            if ($passwordChanged) { $descriptions[] = 'Password reset by administrator'; }

            if (!$descriptions) {
                return ['patient_user_id' => $patientId, 'changed' => false, 'status_changed' => false, 'password_changed' => false];
            }

            $assignments = [];
            $params = [];
            if ((string)$patient['name'] !== $name) {
                $assignments[] = 'name=?';
                $params[] = $name;
            }
            if ((string)$patient['email'] !== $email) {
                $assignments[] = 'email=?';
                $params[] = $email;
            }
            if (($patient['phone'] !== null ? (string)$patient['phone'] : null) !== $phone) {
                $assignments[] = 'phone=?';
                $params[] = $phone;
            }
            if ($statusChanged) {
                $assignments[] = 'is_active=?';
                $params[] = $newActive;
            }
            if ($passwordChanged) {
                $assignments[] = "password_hash=?";
                $params[] = $passwordHash;
            }
            if ($statusChanged || $passwordChanged) {
                $assignments[] = 'session_version=session_version+1';
            }
            if ($assignments) {
                $assignments[] = 'updated_at=NOW()';
                $params[] = $patientId;
                $update = $this->db->prepare(
                    "UPDATE users SET " . implode(', ', $assignments) . " WHERE id=? AND role='patient'"
                );
                $update->execute($params);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Patient account changed before the update could be saved.');
                }
            }

            if ($gender !== $oldProfile['gender'] || $dob !== $oldProfile['date_of_birth']) {
                $this->savePatientDemographics($patientId, $gender, $dob);
            }

            $this->writeAudit(
                $actorUserId, $actorRole, 'update', 'patient', $patientId,
                null, null, 'Patient profile updated: ' . implode(', ', $descriptions), $patientId
            );

            if ($statusChanged) {
                $statusText = $newActive === 1 ? 'activated' : 'deactivated';
                $this->notify($patientId, NotificationService::TYPE_ACCOUNT_STATUS_CHANGED,
                    'Account Status Changed', "Your account has been {$statusText} by an administrator.", 'user', $patientId);
            }
            $otherChanges = count($descriptions) - ($statusChanged ? 1 : 0);
            if (!$statusChanged || $otherChanges > 0) {
                $this->notify($patientId, NotificationService::TYPE_PROFILE_UPDATED,
                    'Profile Updated', 'Your profile information has been updated by an administrator.', 'user', $patientId);
            }

            return [
                'patient_user_id' => $patientId,
                'changed' => true,
                'status_changed' => $statusChanged,
                'password_changed' => $passwordChanged,
            ];
        });
    }

    /**
     * Update normalized patient medical data while preserving doctor access rules,
     * per-field doctor history, administrative correction reasons and notifications.
     */
    public function updateMedicalProfile(
        int $patientId,
        array $changes,
        int $actorUserId,
        string $actorRole,
        string $reason = ''
    ): array {
        if ($patientId <= 0 || $actorUserId <= 0 || !in_array($actorRole, ['doctor', 'admin'], true)) {
            throw new InvalidArgumentException('A valid patient and authenticated doctor/admin actor are required.');
        }

        $allowed = [
            'blood_type', 'height_cm', 'weight_kg', 'date_of_birth', 'gender',
            'allergies', 'chronic_diseases', 'current_medications',
            'previous_surgeries', 'family_history',
            'emergency_contact_name', 'emergency_contact_rel', 'emergency_contact_phone',
            'medical_notes',
        ];
        foreach (array_keys($changes) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported medical profile field: ' . $field);
            }
        }
        if (mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('Administrative correction reason must not exceed 1000 characters.');
        }

        return $this->withTransaction(function () use ($patientId, $changes, $actorUserId, $actorRole, $reason): array {
            $actor = $this->db->prepare(
                'SELECT id FROM users WHERE id=? AND role=? AND is_active=1 LIMIT 1 FOR UPDATE'
            );
            $actor->execute([$actorUserId, $actorRole]);
            if (!$actor->fetchColumn()) {
                throw new DomainException('The authenticated account is not active.');
            }

            if ($actorRole === 'doctor') {
                require_once __DIR__ . '/../ClinicalAccessService.php';
                $access = new ClinicalAccessService($this->db);
                if (!$access->lockDoctorAndPatient($actorUserId, $patientId)
                    || !$access->doctorCanAccessPatient($actorUserId, $patientId, true)) {
                    throw new DomainException('You can only update records for patients with a confirmed appointment or documented clinical encounter.');
                }
            } else {
                $this->lockPatient($patientId);
            }

            $profileFields = [
                'date_of_birth', 'gender',
                'emergency_contact_name', 'emergency_contact_rel', 'emergency_contact_phone',
            ];
            $medicalFields = [
                'blood_type', 'height_cm', 'weight_kg', 'allergies', 'chronic_diseases',
                'current_medications', 'previous_surgeries', 'family_history', 'medical_notes',
            ];

            $this->db->prepare(
                'INSERT IGNORE INTO patient_profiles (patient_user_id) VALUES (?)'
            )->execute([$patientId]);
            $this->db->prepare(
                'INSERT IGNORE INTO patient_medical_profiles (patient_user_id) VALUES (?)'
            )->execute([$patientId]);
            $profileLock = $this->db->prepare(
                'SELECT date_of_birth,gender,emergency_name AS emergency_contact_name,
                        emergency_relation AS emergency_contact_rel,emergency_phone AS emergency_contact_phone
                 FROM patient_profiles WHERE patient_user_id=? LIMIT 1 FOR UPDATE'
            );
            $profileLock->execute([$patientId]);
            $profile = $profileLock->fetch(PDO::FETCH_ASSOC) ?: [];
            $medicalLock = $this->db->prepare(
                'SELECT blood_type,height_cm,weight_kg,allergies,chronic_diseases,
                        current_medications,previous_surgeries,family_history,medical_notes
                 FROM patient_medical_profiles WHERE patient_user_id=? LIMIT 1 FOR UPDATE'
            );
            $medicalLock->execute([$patientId]);
            $medical = $medicalLock->fetch(PDO::FETCH_ASSOC) ?: [];
            $existing = array_merge($profile, $medical);

            $sensitiveAdminFields = [
                'blood_type', 'emergency_contact_name', 'emergency_contact_phone',
            ];
            $oldValues = [];
            $newValues = [];
            $changedFields = [];
            foreach ($changes as $field => $value) {
                if ($value === '') {
                    $value = null;
                }
                if (in_array($field, ['height_cm', 'weight_kg'], true) && $value !== null) {
                    if (!is_numeric($value)) {
                        throw new InvalidArgumentException('Height and weight values must be numeric.');
                    }
                    $value = (float)$value;
                    $minimum = $field === 'height_cm' ? 20.0 : 0.5;
                    $maximum = $field === 'height_cm' ? 300.0 : 1000.0;
                    if ($value < $minimum || $value > $maximum) {
                        throw new InvalidArgumentException('Height or weight is outside the allowed range.');
                    }
                } elseif ($field === 'date_of_birth' && $value !== null) {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
                    $errors = DateTimeImmutable::getLastErrors();
                    if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                        || $date->format('Y-m-d') !== (string)$value) {
                        throw new InvalidArgumentException('Date of birth must be a valid YYYY-MM-DD date.');
                    }
                } elseif ($field === 'gender' && $value !== null
                    && !in_array($value, ['Male', 'Female', 'Other'], true)) {
                    throw new InvalidArgumentException('Gender must be Male, Female, or Other.');
                } elseif ($value !== null && !is_string($value)) {
                    throw new InvalidArgumentException('Medical record text fields must be text.');
                }

                if (is_string($value) && mb_strlen($value) > 10000) {
                    throw new InvalidArgumentException('Medical record text fields must not exceed 10000 characters.');
                }

                $oldValue = $existing[$field] ?? null;
                if ((string)($oldValue ?? '') === (string)($value ?? '') && ($oldValue === null) === ($value === null)) {
                    continue;
                }
                $oldValues[$field] = $oldValue;
                $newValues[$field] = $value;
                $changedFields[] = $field;
            }

            if (!$changedFields) {
                return ['patient_user_id' => $patientId, 'changed' => false, 'changed_fields' => []];
            }

            $adminSensitiveChanges = array_intersect($changedFields, $sensitiveAdminFields);
            if ($actorRole === 'admin' && $adminSensitiveChanges && trim($reason) === '') {
                throw new DomainException('Reason is required for administrative corrections.');
            }

            $profileMap = [
                'date_of_birth' => 'date_of_birth',
                'gender' => 'gender',
                'emergency_contact_name' => 'emergency_name',
                'emergency_contact_rel' => 'emergency_relation',
                'emergency_contact_phone' => 'emergency_phone',
            ];
            $medicalMap = array_fill_keys($medicalFields, null);
            $medicalMap['height_cm'] = 'height_cm';
            $medicalMap['weight_kg'] = 'weight_kg';

            $profileUpdates = [];
            $profileParams = [];
            $medicalUpdates = [];
            $medicalParams = [];
            foreach ($changedFields as $field) {
                if (in_array($field, $profileFields, true)) {
                    $column = $profileMap[$field];
                    $profileUpdates[] = $column . '=?';
                    $profileParams[] = $newValues[$field];
                } else {
                    $medicalUpdates[] = $field . '=?';
                    $medicalParams[] = $newValues[$field];
                }
            }
            if ($profileUpdates) {
                $profileParams[] = $patientId;
                $this->db->prepare(
                    'UPDATE patient_profiles SET ' . implode(',', $profileUpdates)
                    . ',updated_at=NOW() WHERE patient_user_id=?'
                )->execute($profileParams);
            }
            if ($medicalUpdates) {
                $medicalParams[] = $patientId;
                $this->db->prepare(
                    'UPDATE patient_medical_profiles SET ' . implode(',', $medicalUpdates)
                    . ',updated_at=NOW() WHERE patient_user_id=?'
                )->execute($medicalParams);
            }

            $descriptions = [];
            foreach ($changedFields as $field) {
                $descriptions[] = $field . ': '
                    . (string)($oldValues[$field] ?? 'None') . ' → '
                    . (string)($newValues[$field] ?? 'None');
            }
            $this->writeAudit(
                $actorUserId,
                $actorRole,
                'update',
                'patient',
                $patientId,
                $oldValues,
                $newValues,
                'Medical record updated: ' . implode(', ', $descriptions)
                    . ($actorRole === 'admin' && $adminSensitiveChanges ? ' | Administrative reason: ' . $reason : ''),
                $patientId
            );

            if ($actorRole === 'admin' && $adminSensitiveChanges) {
                $this->writeAudit(
                    $actorUserId,
                    'admin',
                    'medical_record_admin_correction',
                    'patient',
                    $patientId,
                    array_intersect_key($oldValues, array_flip($sensitiveAdminFields)),
                    array_intersect_key($newValues, array_flip($sensitiveAdminFields)),
                    'Administrative medical record correction. Reason: ' . $reason,
                    $patientId
                );
            }

            if ($actorRole === 'doctor') {
                $history = $this->db->prepare(
                    'INSERT INTO medical_history_events
                     (patient_user_id,doctor_user_id,field_name,old_value,new_value,created_at)
                     VALUES (?,?,?,?,?,NOW())'
                );
                foreach ($changedFields as $field) {
                    $history->execute([
                        $patientId,
                        $actorUserId,
                        $field,
                        $oldValues[$field] === null ? null : (string)$oldValues[$field],
                        $newValues[$field] === null ? null : (string)$newValues[$field],
                    ]);
                }
            }

            $this->notify(
                $patientId,
                NotificationService::TYPE_MEDICAL_RECORD_UPDATED,
                'Medical Record Updated',
                $actorRole === 'doctor'
                    ? 'A doctor updated your medical record. Review the latest information in your Medical Record.'
                    : 'Your medical record was updated by the hospital administration. You can review the changes in your Medical Record.',
                'medical_record',
                $patientId
            );

            return [
                'patient_user_id' => $patientId,
                'changed' => true,
                'changed_fields' => $changedFields,
            ];
        });
    }

    /**
     * Record a doctor-authored medical history timeline event.
     *
     * V2 medical profile updates already write this event in the same
     * transaction as the profile mutation. The follow-up legacy endpoint
     * calls this method immediately afterward; matching recent events are
     * acknowledged instead of duplicated.
     */
    public function recordMedicalHistoryUpdate(
        int $patientId,
        int $doctorId,
        string $field,
        string $oldValue,
        string $newValue
    ): bool {
        $allowedFields = [
            'blood_type', 'height_cm', 'weight_kg', 'date_of_birth', 'gender',
            'allergies', 'chronic_diseases', 'current_medications', 'previous_surgeries',
            'family_history', 'emergency_contact_name', 'emergency_contact_rel',
            'emergency_contact_phone', 'medical_notes',
        ];
        if ($patientId <= 0 || $doctorId <= 0) {
            throw new InvalidArgumentException('Patient and doctor IDs must be positive.');
        }
        if (!in_array($field, $allowedFields, true)) {
            throw new InvalidArgumentException('Invalid medical record field.');
        }
        if (mb_strlen($oldValue) > 10000 || mb_strlen($newValue) > 10000) {
            throw new InvalidArgumentException('Medical history values cannot exceed 10000 characters.');
        }
        $storedOldValue = $oldValue === '' ? null : $oldValue;
        $storedNewValue = $newValue === '' ? null : $newValue;
        if ($storedOldValue === $storedNewValue) {
            return false;
        }

        return $this->withTransaction(function () use ($patientId, $doctorId, $field, $storedOldValue, $storedNewValue): bool {
            $access = new ClinicalAccessService($this->db);
            if (!$access->lockDoctorAndPatient($doctorId, $patientId)) {
                throw new DomainException('The doctor or patient account is unavailable for this clinical update.');
            }
            if (!$access->doctorCanAccessPatient($doctorId, $patientId, true)) {
                throw new DomainException('The doctor does not have an eligible clinical relationship with this patient.');
            }

            $duplicate = $this->db->prepare(
                'SELECT id FROM medical_history_events
                 WHERE patient_user_id=? AND doctor_user_id=? AND field_name=?
                   AND old_value <=> ? AND new_value <=> ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
                 ORDER BY id DESC LIMIT 1'
            );
            $duplicate->execute([$patientId,$doctorId,$field,$storedOldValue,$storedNewValue]);
            if ($duplicate->fetchColumn()) {
                return false;
            }

            $stmt = $this->db->prepare(
                'INSERT INTO medical_history_events
                 (patient_user_id,doctor_user_id,field_name,old_value,new_value,created_at)
                 VALUES (?,?,?,?,?,NOW())'
            );
            $stmt->execute([$patientId,$doctorId,$field,$storedOldValue,$storedNewValue]);
            return true;
        });
    }

    /** Atomically toggle a patient's account access and revoke existing sessions. */
    public function togglePatientActive(int $patientId, int $actorUserId): array
    {
        if ($patientId <= 0) {
            throw new InvalidArgumentException('Patient user ID must be positive.');
        }

        return $this->withTransaction(function () use ($patientId, $actorUserId): array {
            $this->assertActor($actorUserId, 'admin');
            $patient = $this->lockPatient($patientId);
            $oldActive = (int)$patient['is_active'];
            $newActive = $oldActive === 1 ? 0 : 1;
            $stmt = $this->db->prepare(
                "UPDATE users SET is_active=?,session_version=session_version+1,updated_at=NOW()
                 WHERE id=? AND role='patient' AND is_active=?"
            );
            $stmt->execute([$newActive, $patientId, $oldActive]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Patient account status changed during the toggle operation.');
            }

            $statusText = $newActive === 1 ? 'activated' : 'deactivated';
            $this->writeAudit(
                $actorUserId,
                'admin',
                $newActive === 1 ? 'activate' : 'deactivate',
                'patient',
                $patientId,
                ['is_active' => $oldActive],
                ['is_active' => $newActive],
                "Patient account {$statusText} by an administrator; active sessions revoked.",
                $patientId
            );
            $this->notify(
                $patientId,
                NotificationService::TYPE_ACCOUNT_STATUS_CHANGED,
                'Account Status Changed',
                "Your account has been {$statusText} by an administrator.",
                'user',
                $patientId
            );

            return [
                'patient_user_id' => $patientId,
                'patient_name' => (string)$patient['name'],
                'is_active' => $newActive,
                'changed' => true,
            ];
        });
    }

    /** Reset password and revoke sessions atomically. */
    public function resetPatientPassword(int $patientId, string $passwordHash, int $actorUserId): void
    {
        if ($patientId <= 0 || $passwordHash === '' || mb_strlen($passwordHash) > 255) {
            throw new InvalidArgumentException('A valid patient ID and password hash are required.');
        }
        $this->withTransaction(function () use ($patientId, $passwordHash, $actorUserId): void {
            $this->assertActor($actorUserId, 'admin');
            $this->lockPatient($patientId);
            $stmt = $this->db->prepare(
                "UPDATE users SET password_hash=?, session_version=session_version+1, updated_at=NOW()
                 WHERE id=? AND role='patient'"
            );
            $stmt->execute([$passwordHash, $patientId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Patient credentials changed during this request.');
            }
            $this->writeAudit($actorUserId, 'admin', 'update', 'patient', $patientId,
                null, null, 'Patient password reset by an administrator; existing sessions were revoked.', $patientId);
            $this->notify($patientId, NotificationService::TYPE_PASSWORD_CHANGED, 'Password Changed',
                'An administrator reset your password. Your previous sessions have been revoked.', 'user', $patientId);
        });
    }

    /** Archive patient sign-in and cancel only appointments without clinical evidence. */
    public function archivePatient(int $patientId, int $actorUserId): array
    {
        if ($patientId <= 0) { throw new InvalidArgumentException('Patient user ID must be positive.'); }

        return $this->withTransaction(function () use ($patientId, $actorUserId): array {
            $this->assertActor($actorUserId, 'admin');
            $patient = $this->lockPatient($patientId);
            if ((int)$patient['is_active'] !== 1) {
                throw new DomainException('This patient account is already disabled.');
            }

            $appointments = $this->findPatientCancellableAppointments($patientId);
            foreach ($appointments as $appointment) {
                $appointmentId = (int)$appointment['id'];
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET booking_status='cancelled'
                     WHERE id=? AND booking_status IN ('pending','confirmed')"
                );
                $stmt->execute([$appointmentId]);
                $this->db->prepare(
                    "UPDATE appointment_reschedule_requests
                     SET request_status='cancelled', responded_by_user_id=?, responded_at=NOW()
                     WHERE appointment_id=? AND request_status IN ('requested','suggested')"
                )->execute([$actorUserId, $appointmentId]);
                $this->writeAppointmentEvent($appointmentId, $actorUserId, 'patient_account_archived',
                    (string)$appointment['booking_status'], ['reason' => 'patient_account_archived']);
                if ($stmt->rowCount() !== 1) {
                    throw new RuntimeException('An appointment changed while the patient account was being archived.');
                }
            }

            $deactivate = $this->db->prepare(
                "UPDATE users SET is_active=0, session_version=session_version+1, updated_at=NOW()
                 WHERE id=? AND role='patient' AND is_active=1"
            );
            $deactivate->execute([$patientId]);
            if ($deactivate->rowCount() !== 1) {
                throw new RuntimeException('Patient account status changed during the archive operation.');
            }

            $this->writeAudit($actorUserId, 'admin', 'archive', 'patient', $patientId,
                ['is_active' => 1], ['is_active' => 0],
                'Patient account archived; sign-in disabled; ' . count($appointments)
                    . ' not-started appointment(s) cancelled; clinical history retained.', $patientId);

            return [
                'patient_user_id' => $patientId,
                'patient_name' => (string)$patient['name'],
                'is_active' => 0,
                'cancelled_appointments' => count($appointments),
                'changed' => true,
            ];
        });
    }

    /** Patient self-cancellation using doctor-first lock ordering. */
    public function cancelAppointmentByPatient(int $appointmentId, int $patientUserId): array
    {
        if ($appointmentId <= 0 || $patientUserId <= 0) {
            throw new InvalidArgumentException('Appointment and patient IDs must be positive.');
        }

        return $this->withTransaction(function () use ($appointmentId, $patientUserId): array {
            $this->assertActor($patientUserId, 'patient');
            $lookup = $this->db->prepare(
                "SELECT doctor_user_id FROM appointments WHERE id=? AND patient_user_id=? LIMIT 1"
            );
            $lookup->execute([$appointmentId, $patientUserId]);
            $doctorUserId = (int)$lookup->fetchColumn();
            if ($doctorUserId <= 0) { throw new DomainException('Appointment not found.'); }

            $doctorLock = $this->db->prepare("SELECT id FROM users WHERE id=? AND role='doctor' LIMIT 1 FOR UPDATE");
            $doctorLock->execute([$doctorUserId]);
            if (!$doctorLock->fetchColumn()) { throw new DomainException('The assigned doctor account is unavailable.'); }

            $stmt = $this->db->prepare(
                'SELECT * FROM appointments
                 WHERE id=? AND patient_user_id=? AND doctor_user_id=? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$appointmentId, $patientUserId, $doctorUserId]);
            $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$appointment) { throw new DomainException('Appointment not found.'); }

            $status = (string)$appointment['booking_status'];
            $startsAt = (string)$appointment['starts_at'];
            $patientName = (string)$appointment['patient_name_snapshot'];
            $doctorName = (string)$appointment['doctor_name_snapshot'];
            $workflowStmt = $this->db->prepare(
                'SELECT workflow_status FROM clinical_encounters WHERE appointment_id=? LIMIT 1 FOR UPDATE'
            );
            $workflowStmt->execute([$appointmentId]);
            $workflow = $workflowStmt->fetchColumn() ?: null;

            if ($status === 'cancelled') { throw new DomainException('This appointment is already cancelled.'); }
            $requestStmt = $this->db->prepare(
                "SELECT id FROM appointment_reschedule_requests
                 WHERE appointment_id=? AND request_status IN ('requested','suggested')
                 ORDER BY id DESC LIMIT 1 FOR UPDATE"
            );
            $requestStmt->execute([$appointmentId]);
            $hasPendingReschedule = (bool)$requestStmt->fetchColumn();
            if ($hasPendingReschedule) {
                throw new DomainException('Cancel or resolve the pending reschedule request before cancelling the appointment.');
            }
            if (in_array($workflow, ['Completed', 'completed'], true)) {
                throw new DomainException('Cannot cancel a completed visit.');
            }
            if (in_array($workflow, ['In Progress', 'Ready to Complete', 'in_progress', 'ready_to_complete'], true)) {
                throw new DomainException('Cannot cancel an appointment after the clinical encounter has started. Contact the clinic if the visit needs to be stopped.');
            }

            $allowed = in_array($status, ['pending', 'confirmed'], true);
            if (!$allowed) { throw new DomainException('This appointment cannot be cancelled in its current state.'); }
            if (new DateTimeImmutable($startsAt) <= new DateTimeImmutable()) {
                throw new DomainException('Cannot cancel an appointment that has already started.');
            }

            $update = $this->db->prepare("UPDATE appointments SET booking_status='cancelled' WHERE id=? AND patient_user_id=? AND booking_status IN ('pending','confirmed')");
            $update->execute([$appointmentId, $patientUserId]);
            if ($update->rowCount() !== 1) { throw new RuntimeException('The appointment changed before cancellation.'); }

            $this->writeAudit($patientUserId, 'patient', 'cancel', 'appointment', $appointmentId,
                ['status' => $status], ['status' => 'cancelled'], 'Appointment cancelled by patient',
                $patientUserId, $doctorUserId);
            $this->writeAppointmentEvent($appointmentId, $patientUserId, 'patient_cancelled',
                $status, ['reason' => 'patient_requested']);

            $date = substr($startsAt, 0, 10);
            $time = substr($startsAt, 11, 5);
            $this->notify($doctorUserId, NotificationService::TYPE_APPOINTMENT_CANCELLED,
                'Appointment Cancelled',
                "Patient {$patientName} has cancelled their appointment on {$date} at {$time}.",
                'appointment', $appointmentId);

            return ['appointment_id' => $appointmentId, 'doctor_user_id' => $doctorUserId, 'doctor_name' => $doctorName];
        });
    }

    private function findPatientCancellableAppointments(int $patientId): array
    {
        $sql = "SELECT a.id, a.booking_status FROM appointments a
                    LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
                    WHERE a.patient_user_id=? AND a.booking_status IN ('pending','confirmed')
                      AND (ce.id IS NULL OR (
                          ce.workflow_status IN ('uninitialized','waiting')
                          AND ce.diagnosis IS NULL AND ce.symptoms IS NULL
                          AND ce.treatment IS NULL AND ce.doctor_notes IS NULL
                          AND ce.follow_up_instructions IS NULL
                      ))
                      AND NOT EXISTS (SELECT 1 FROM encounter_drafts ed WHERE ed.appointment_id=a.id)
                      AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.appointment_id=a.id)
                    FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function lockPatient(int $patientId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id,name,email,phone,is_active,session_version
             FROM users WHERE id=? AND role='patient' LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$patientId]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$patient) { throw new DomainException('Patient not found.'); }
        return $patient;
    }

    private function lockPatientDemographics(int $patientId): array
    {
        $sql = 'SELECT gender,date_of_birth FROM patient_profiles WHERE patient_user_id=? LIMIT 1 FOR UPDATE';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['gender' => $row['gender'] ?? null, 'date_of_birth' => $row['date_of_birth'] ?? null];
    }

    private function savePatientDemographics(int $patientId, ?string $gender, ?string $dob): void
    {
        $exists = $this->db->prepare('SELECT patient_user_id FROM patient_profiles WHERE patient_user_id=? LIMIT 1 FOR UPDATE');
        $exists->execute([$patientId]);
        if (!$exists->fetchColumn()) {
            $this->db->prepare('INSERT INTO patient_profiles (patient_user_id,gender,date_of_birth) VALUES (?,?,?)')
                ->execute([$patientId, $gender, $dob]);
        } else {
            $this->db->prepare('UPDATE patient_profiles SET gender=?,date_of_birth=?,updated_at=NOW() WHERE patient_user_id=?')
                ->execute([$gender, $dob, $patientId]);
        }
    }

    private function assertActor(int $actorUserId, string $actorRole): void
    {
        if ($actorUserId <= 0 || !in_array($actorRole, ['admin','patient'], true)) {
            throw new DomainException('The authenticated account is not allowed to perform this patient operation.');
        }
        $stmt = $this->db->prepare('SELECT id FROM users WHERE id=? AND role=? AND is_active=1 LIMIT 1');
        $stmt->execute([$actorUserId, $actorRole]);
        if (!$stmt->fetchColumn()) { throw new DomainException('The authenticated account is not active.'); }
    }

    private function writeAudit(
        int $actorUserId, string $actorRole, string $action, string $entityType, int $entityId,
        mixed $oldValue, mixed $newValue, string $description, ?int $patientId = null, ?int $doctorId = null
    ): void {
        $oldJson = $oldValue === null ? null : (is_string($oldValue) ? $oldValue : json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $newJson = $newValue === null ? null : (is_string($newValue) ? $newValue : json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stmt = $this->db->prepare(
            'INSERT INTO audit_events
             (actor_user_id,actor_role_snapshot,action_code,entity_type,entity_id,patient_user_id,doctor_user_id,old_value,new_value,description,ip_address,user_agent)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt->execute([
            $actorUserId,$actorRole,$action,$entityType,(string)$entityId,$patientId,$doctorId,
            $oldJson,$newJson,$description,$_SERVER['REMOTE_ADDR'] ?? null,$_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function writeAppointmentEvent(int $appointmentId, int $actorUserId, string $eventType, ?string $fromStatus, array $details = []): void
    {
        $roleStmt = $this->db->prepare('SELECT role FROM users WHERE id=? LIMIT 1');
        $roleStmt->execute([$actorUserId]);
        $role = (string)($roleStmt->fetchColumn() ?: 'admin');
        $stmt = $this->db->prepare(
            'INSERT INTO appointment_events
             (appointment_id,actor_user_id,actor_role_snapshot,event_type,from_status,to_status,event_details,created_at)
             VALUES (?,?,?,?,?,\'cancelled\',?,NOW())'
        );
        $stmt->execute([
            $appointmentId,$actorUserId,$role,$eventType,$fromStatus,
            $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function notify(int $userId, string $type, string $title, string $message, ?string $refType, ?int $refId): void
    {
        (new NotificationService($this->db))->create($userId,$type,$title,$message,$refType,$refId);
    }

    private function withTransaction(callable $operation): mixed
    {
        $owns = !$this->db->inTransaction();
        if ($owns) { $this->db->beginTransaction(); }
        try {
            $result = $operation();
            if ($owns) { $this->db->commit(); }
            return $result;
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[\s()\-.+]/u', '', trim($phone)) ?? trim($phone);
    }
}
