<?php
declare(strict_types=1);

require_once __DIR__ . '/../NotificationService.php';

/**
 * Explicit v1/v2 adapter for doctor account/profile mutations.
 *
 * All doctor-facing identifiers in this repository are users.id values.
 * It never uses doctors.id as an application identity.
 *
 * The caller owns the surrounding transaction when one is already open.
 * This is important for endpoint-level audit/outbox atomicity and for the
 * rollback-only contract tests.
 */
final class DoctorManagementRepository
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('DoctorManagementRepository schema must be explicitly v1 or v2.');
        }

        $this->db = $db;
        $this->schema = $schema;
    }

    /**
     * Create the account, doctor profile, preference row, default schedule,
     * audit event and admin notification outbox entries as one unit.
     *
     * The input must contain a password hash, never a raw password.
     */
    public function createDoctor(
        string $name,
        string $email,
        string $passwordHash,
        int $departmentId,
        int $actorUserId,
        ?string $phone = null
    ): int {
        $name = trim($name);
        $email = trim($email);
        $phone = $phone !== null && trim($phone) !== '' ? trim($phone) : null;

        if ($name === '' || mb_strlen($name) > 100) {
            throw new InvalidArgumentException('Doctor name must contain 1 to 100 characters.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            throw new InvalidArgumentException('A valid email address is required.');
        }
        if ($passwordHash === '' || mb_strlen($passwordHash) > 255) {
            throw new InvalidArgumentException('A valid password hash is required.');
        }
        if ($departmentId <= 0) {
            throw new InvalidArgumentException('A department is required.');
        }

        return $this->withTransaction(function () use ($name, $email, $passwordHash, $departmentId, $actorUserId, $phone): int {
            $this->assertAdminActor($actorUserId);
            $department = $this->lockActiveDepartment($departmentId);

            if ($this->schema === self::SCHEMA_V1) {
                $sql = 'INSERT INTO users (name, email, password, phone, role, is_active, created_at)
                        VALUES (?, ?, ?, ?, \'doctor\', 1, NOW())';
            } else {
                $sql = 'INSERT INTO users (name, email, password_hash, phone, role, is_active, session_version)
                        VALUES (?, ?, ?, ?, \'doctor\', 1, 1)';
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute([$name, $email, $passwordHash, $phone]);
            $doctorUserId = (int)$this->db->lastInsertId();
            if ($doctorUserId <= 0) {
                throw new RuntimeException('The doctor account ID could not be generated.');
            }

            if ($this->schema === self::SCHEMA_V1) {
                $profile = $this->db->prepare(
                    'INSERT INTO doctors (user_id, name, specialty, rating, exp, available, department_id, emoji)
                     VALUES (?, ?, ?, 4.5, 5, 1, ?, \'fa-user-doctor\')'
                );
                $profile->execute([$doctorUserId, $name, $department['name'], $departmentId]);

                $this->db->prepare('INSERT IGNORE INTO user_preferences (user_id) VALUES (?)')
                    ->execute([$doctorUserId]);

                $this->db->prepare(
                    'INSERT INTO doctor_schedule_settings
                        (doctor_id, appointment_duration, max_appointments_per_day, is_available)
                     VALUES (?, 30, 25, 1)'
                )->execute([$doctorUserId]);

                $weekly = $this->db->prepare(
                    'INSERT INTO doctor_schedule_weekly
                        (doctor_id, day_of_week, start_time, end_time, is_working)
                     VALUES (?, ?, \'09:00\', \'17:00\', ?)'
                );
            } else {
                $profile = $this->db->prepare(
                    'INSERT INTO doctor_profiles
                        (doctor_user_id, department_id, specialty, experience_years, profile_icon,
                         accepts_new_patients, profile_visible)
                     VALUES (?, ?, ?, 5, \'fa-user-doctor\', 1, 1)'
                );
                $profile->execute([$doctorUserId, $departmentId, $department['name']]);

                $this->db->prepare('INSERT INTO user_preferences (user_id) VALUES (?)')
                    ->execute([$doctorUserId]);

                $this->db->prepare(
                    'INSERT INTO doctor_schedule_settings
                        (doctor_user_id, appointment_duration_min, max_appointments_per_day, is_available)
                     VALUES (?, 30, 25, 1)'
                )->execute([$doctorUserId]);

                $weekly = $this->db->prepare(
                    'INSERT INTO doctor_weekly_schedules
                        (doctor_user_id, day_of_week, start_time, end_time, is_working)
                     VALUES (?, ?, \'09:00:00\', \'17:00:00\', ?)'
                );
            }

            for ($day = 1; $day <= 7; $day++) {
                $weekly->execute([$doctorUserId, $day, $day <= 5 ? 1 : 0]);
            }

            $this->writeAudit(
                $actorUserId,
                'create',
                $doctorUserId,
                null,
                [
                    'name' => $name,
                    'email' => $email,
                    'department_id' => $departmentId,
                    'department' => $department['name'],
                    'is_active' => 1,
                ],
                "Doctor account created in department {$department['name']}.",
                $doctorUserId
            );

            $notifications = new NotificationService($this->db);
            $admins = $this->db->query(
                "SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id"
            )->fetchAll(PDO::FETCH_COLUMN);

            foreach ($admins as $adminId) {
                $notifications->create(
                    (int)$adminId,
                    NotificationService::TYPE_NEW_DOCTOR,
                    'New Doctor Registered',
                    "Dr. {$name} ({$email}) has been registered in the {$department['name']} department.",
                    'doctor',
                    $doctorUserId
                );
            }

            return $doctorUserId;
        });
    }

    /**
     * Update account identity fields. Missing keys are preserved; phone=null
     * explicitly clears the phone number.
     */
    public function updateDoctor(int $doctorUserId, array $changes, int $actorUserId): array
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        $allowed = ['name', 'email', 'phone'];
        foreach (array_keys($changes) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported doctor account field: ' . $key);
            }
        }
        if (!$changes) {
            throw new InvalidArgumentException('At least one doctor account field is required.');
        }

        return $this->withTransaction(function () use ($doctorUserId, $changes, $actorUserId, $allowed): array {
            $this->assertAdminActor($actorUserId);
            $old = $this->lockDoctor($doctorUserId);
            $new = $old;

            foreach ($changes as $field => $value) {
                if ($field === 'phone') {
                    if ($value !== null && !is_string($value)) {
                        throw new InvalidArgumentException('Phone must be text or null.');
                    }
                    $new[$field] = $value !== null && trim($value) !== '' ? trim($value) : null;
                    continue;
                }
                if (!is_string($value)) {
                    throw new InvalidArgumentException(ucfirst($field) . ' must be text.');
                }
                $value = trim($value);
                if ($value === '') {
                    throw new InvalidArgumentException(ucfirst($field) . ' cannot be empty.');
                }
                $new[$field] = $value;
            }

            if (mb_strlen((string)$new['name']) > 100) {
                throw new InvalidArgumentException('Doctor name cannot exceed 100 characters.');
            }
            if (!filter_var((string)$new['email'], FILTER_VALIDATE_EMAIL) || mb_strlen((string)$new['email']) > 150) {
                throw new InvalidArgumentException('A valid email address is required.');
            }
            if ($new['email'] !== $old['email']) {
                $duplicate = $this->db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
                $duplicate->execute([$new['email'], $doctorUserId]);
                if ($duplicate->fetchColumn()) {
                    throw new DomainException('Another user already uses this email address.');
                }
            }

            $fieldsChanged = [];
            foreach ($allowed as $field) {
                if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                    $fieldsChanged[$field] = ['old' => $old[$field] ?? null, 'new' => $new[$field] ?? null];
                }
            }

            if (!$fieldsChanged) {
                return ['doctor_user_id' => $doctorUserId, 'changed' => false, 'changes' => []];
            }

            $update = $this->db->prepare(
                'UPDATE users SET name = ?, email = ?, phone = ?, updated_at = NOW()
                 WHERE id = ? AND role = \'doctor\''
            );
            $update->execute([$new['name'], $new['email'], $new['phone'], $doctorUserId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Doctor account details were not updated.');
            }

            if ($this->schema === self::SCHEMA_V1 && array_key_exists('name', $fieldsChanged)) {
                $this->db->prepare('UPDATE doctors SET name = ? WHERE user_id = ?')
                    ->execute([$new['name'], $doctorUserId]);
            }

            $this->writeAudit(
                $actorUserId,
                'update',
                $doctorUserId,
                $old,
                ['name' => $new['name'], 'email' => $new['email'], 'phone' => $new['phone']],
                'Doctor account details updated.',
                $doctorUserId
            );

            return ['doctor_user_id' => $doctorUserId, 'changed' => true, 'changes' => $fieldsChanged];
        });
    }

    /**
     * Assign/reassign a doctor using users.id in both schema modes.
     * The specialty string remains synchronized with the department name,
     * matching the existing v1 endpoint's backward-compatible behavior.
     */
    public function assignDoctorToDepartment(int $doctorUserId, int $departmentId, int $actorUserId): array
    {
        if ($doctorUserId <= 0 || $departmentId <= 0) {
            throw new InvalidArgumentException('Positive doctor and department IDs are required.');
        }

        return $this->withTransaction(function () use ($doctorUserId, $departmentId, $actorUserId): array {
            $this->assertAdminActor($actorUserId);

            // Keep the global doctor-management lock order consistent with booking:
            // doctor account -> doctor profile -> department. Locking the department
            // first here would invert book.php's order and create a deadlock cycle.
            $doctor = $this->lockDoctorProfile($doctorUserId);
            $department = $this->lockActiveDepartment($departmentId);
            $oldDepartmentId = $doctor['department_id'] !== null ? (int)$doctor['department_id'] : null;

            if ($oldDepartmentId === $departmentId) {
                return [
                    'doctor_user_id' => $doctorUserId,
                    'old_department_id' => $oldDepartmentId,
                    'department_id' => $departmentId,
                    'changed' => false,
                ];
            }

            if ($this->schema === self::SCHEMA_V1) {
                $stmt = $this->db->prepare(
                    'UPDATE doctors SET department_id = ?, specialty = ? WHERE user_id = ?'
                );
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE doctor_profiles SET department_id = ?, specialty = ? WHERE doctor_user_id = ?'
                );
            }
            $stmt->execute([$departmentId, $department['name'], $doctorUserId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Doctor department assignment was not updated.');
            }

            $this->writeAudit(
                $actorUserId,
                'reassign',
                $doctorUserId,
                ['department_id' => $oldDepartmentId, 'specialty' => $doctor['specialty']],
                ['department_id' => $departmentId, 'specialty' => $department['name']],
                "Department reassigned from {$this->departmentName($oldDepartmentId)} to {$department['name']}.",
                $doctorUserId
            );

            return [
                'doctor_user_id' => $doctorUserId,
                'old_department_id' => $oldDepartmentId,
                'department_id' => $departmentId,
                'changed' => true,
            ];
        });
    }

    /**
     * Toggle a doctor's status atomically under the same doctor-row lock used
     * by the transition. This preserves the endpoint's true toggle semantics
     * when two administrators act at nearly the same time.
     */
    public function toggleDoctorActive(int $doctorUserId, int $actorUserId): array
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        return $this->withTransaction(function () use ($doctorUserId, $actorUserId): array {
            $this->assertAdminActor($actorUserId);
            $doctor = $this->lockDoctor($doctorUserId);
            $targetActive = (int)$doctor['is_active'] !== 1;
            return $this->setDoctorActive($doctorUserId, $targetActive, $actorUserId);
        });
    }

    /**
     * Archive retains the historical audit action and does not send a separate
     * account-status notice to the doctor. Appointment cancellation notices
     * are still written transactionally.
     */
    public function archiveDoctor(int $doctorUserId, int $actorUserId): array
    {
        return $this->transitionDoctorActive($doctorUserId, false, $actorUserId, 'archive', false);
    }

    /**
     * Public enable/disable transition. Audit and notification behavior remain
     * fixed for ordinary status changes; archive uses its dedicated operation.
     */
    public function setDoctorActive(int $doctorUserId, bool $active, int $actorUserId): array
    {
        return $this->transitionDoctorActive($doctorUserId, $active, $actorUserId, null, true);
    }

    private function transitionDoctorActive(
        int $doctorUserId,
        bool $active,
        int $actorUserId,
        ?string $auditActionOverride,
        bool $notifyDoctor
    ): array {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        return $this->withTransaction(function () use (
            $doctorUserId,
            $active,
            $actorUserId,
            $auditActionOverride,
            $notifyDoctor
        ): array {
            $this->assertAdminActor($actorUserId);
            $doctor = $this->lockDoctor($doctorUserId);
            $oldActive = (int)$doctor['is_active'];
            $newActive = $active ? 1 : 0;

            if ($oldActive === $newActive) {
                return [
                    'doctor_user_id' => $doctorUserId,
                    'doctor_name' => (string)$doctor['name'],
                    'is_active' => $newActive,
                    'cancelled_appointments' => 0,
                    'changed' => false,
                ];
            }

            $cancelled = [];
            if ($newActive === 0) {
                $this->assertNoUnfinishedClinicalWork($doctorUserId, $auditActionOverride === 'archive');
                $cancelled = $this->findCancellableAppointments($doctorUserId);
                foreach ($cancelled as $appointment) {
                    $appointmentId = (int)$appointment['id'];
                    $this->cancelAppointment($appointmentId, $actorUserId);

                    if (!empty($appointment['patient_user_id'])) {
                        $this->enqueueNotification(
                            (int)$appointment['patient_user_id'],
                            NotificationService::TYPE_APPOINTMENT_CANCELLED,
                            'Appointment Cancelled',
                            "Your appointment on {$appointment['appointment_date']} at {$appointment['appointment_time']} was cancelled because the doctor is no longer available.",
                            'appointment',
                            $appointmentId
                        );
                    }
                }
            }

            $update = $this->db->prepare(
                'UPDATE users
                 SET is_active = ?, session_version = session_version + 1, updated_at = NOW()
                 WHERE id = ? AND role = \'doctor\''
            );
            $update->execute([$newActive, $doctorUserId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Doctor account status changed during the status transition.');
            }

            $action = $auditActionOverride ?? ($newActive === 1 ? 'activate' : 'deactivate');
            if ($auditActionOverride === 'archive') {
                $description = 'Doctor account archived; sign-in disabled; '
                    . count($cancelled)
                    . ' not-started appointment(s) cancelled; clinical and schedule history retained.';
            } else {
                $description = $newActive === 1
                    ? 'Doctor account reactivated; fresh sign-in required.'
                    : 'Doctor account disabled; ' . count($cancelled) . ' not-started appointment(s) cancelled.';
            }

            $this->writeAudit(
                $actorUserId,
                $action,
                $doctorUserId,
                ['is_active' => $oldActive],
                ['is_active' => $newActive],
                $description,
                $doctorUserId
            );

            if ($notifyDoctor) {
                $statusText = $newActive === 1 ? 'activated' : 'deactivated';
                $this->enqueueNotification(
                    $doctorUserId,
                    NotificationService::TYPE_ACCOUNT_STATUS_CHANGED,
                    'Account Status Changed',
                    "Your account has been {$statusText} by an administrator.",
                    'user',
                    $doctorUserId
                );
            }

            return [
                'doctor_user_id' => $doctorUserId,
                'doctor_name' => (string)$doctor['name'],
                'is_active' => $newActive,
                'cancelled_appointments' => count($cancelled),
                'changed' => true,
            ];
        });
    }

    private function assertNoUnfinishedClinicalWork(int $doctorUserId, bool $archiving = false): void
    {
        if ($this->schema === self::SCHEMA_V1) {
            $activeVisit = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM appointments a
                 INNER JOIN visit_workflow w ON w.appointment_id = a.id
                 WHERE a.doctor_id = ?
                   AND a.status = 'Confirmed'
                   AND w.status IN ('In Progress','Ready to Complete')"
            );
            $activeVisit->execute([$doctorUserId]);

            $activePrescription = $this->db->prepare(
                "SELECT COUNT(*) FROM prescriptions WHERE doctor_id = ? AND status = 'Active'"
            );
            $activePrescription->execute([$doctorUserId]);

            $drafts = $this->db->prepare('SELECT COUNT(*) FROM visit_drafts WHERE doctor_id = ?');
            $drafts->execute([$doctorUserId]);
        } else {
            $activeVisit = $this->db->prepare(
                "SELECT COUNT(*)
                 FROM appointments a
                 INNER JOIN clinical_encounters ce ON ce.appointment_id = a.id
                 WHERE a.doctor_user_id = ?
                   AND a.booking_status = 'confirmed'
                   AND ce.workflow_status IN ('in_progress','ready_to_complete')"
            );
            $activeVisit->execute([$doctorUserId]);

            $activePrescription = $this->db->prepare(
                "SELECT COUNT(*) FROM prescriptions WHERE doctor_user_id = ? AND status = 'active'"
            );
            $activePrescription->execute([$doctorUserId]);

            $drafts = $this->db->prepare('SELECT COUNT(*) FROM encounter_drafts WHERE doctor_user_id = ?');
            $drafts->execute([$doctorUserId]);
        }

        if ((int)$activeVisit->fetchColumn() > 0
            || (int)$activePrescription->fetchColumn() > 0
            || (int)$drafts->fetchColumn() > 0) {
            throw new DomainException(
                'This doctor still has an active visit, unfinished visit draft, or active prescription. Complete or transfer that clinical work before '
                    . ($archiving ? 'archiving' : 'disabling')
                    . ' the account.'
            );
        }
    }

    private function findCancellableAppointments(int $doctorUserId): array
    {
        if ($this->schema === self::SCHEMA_V1) {
            $sql = "SELECT a.id, a.user_id AS patient_user_id,
                           DATE_FORMAT(a.date, '%Y-%m-%d') AS appointment_date,
                           a.time AS appointment_time
                    FROM appointments a
                    LEFT JOIN visit_workflow w ON w.appointment_id = a.id
                    WHERE a.doctor_id = ?
                      AND a.status IN ('Pending','Confirmed','Reschedule Requested')
                      AND (w.status IS NULL OR w.status = 'Waiting')
                      AND NOT EXISTS (SELECT 1 FROM visit_drafts vd WHERE vd.appointment_id = a.id)
                      AND NOT EXISTS (SELECT 1 FROM visit_notes vn WHERE vn.appointment_id = a.id)
                      AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.appointment_id = a.id)
                    FOR UPDATE";
        } else {
            $sql = "SELECT a.id, a.patient_user_id,
                           DATE_FORMAT(a.starts_at, '%Y-%m-%d') AS appointment_date,
                           DATE_FORMAT(a.starts_at, '%H:%i') AS appointment_time
                    FROM appointments a
                    LEFT JOIN clinical_encounters ce ON ce.appointment_id = a.id
                    WHERE a.doctor_user_id = ?
                      AND a.booking_status IN ('pending','confirmed')
                      AND (
                          ce.id IS NULL
                          OR (
                              ce.workflow_status IN ('uninitialized','waiting')
                              AND ce.diagnosis IS NULL
                              AND ce.symptoms IS NULL
                              AND ce.treatment IS NULL
                              AND ce.doctor_notes IS NULL
                              AND ce.follow_up_instructions IS NULL
                          )
                      )
                      AND NOT EXISTS (SELECT 1 FROM encounter_drafts ed WHERE ed.appointment_id = a.id)
                      AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.appointment_id = a.id)
                    FOR UPDATE";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function cancelAppointment(int $appointmentId, int $actorUserId): void
    {
        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare(
                "UPDATE appointments
                 SET status = 'Cancelled'
                 WHERE id = ? AND status IN ('Pending','Confirmed','Reschedule Requested')"
            );
            $stmt->execute([$appointmentId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('An appointment changed while the doctor account was being disabled.');
            }
            return;
        }

        $current = $this->db->prepare(
            'SELECT booking_status FROM appointments WHERE id = ? FOR UPDATE'
        );
        $current->execute([$appointmentId]);
        $fromStatus = $current->fetchColumn();
        if (!in_array($fromStatus, ['pending', 'confirmed'], true)) {
            throw new RuntimeException('An appointment changed while the doctor account was being disabled.');
        }

        $stmt = $this->db->prepare(
            "UPDATE appointments SET booking_status = 'cancelled'
             WHERE id = ? AND booking_status IN ('pending','confirmed')"
        );
        $stmt->execute([$appointmentId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('An appointment changed while the doctor account was being disabled.');
        }

        $this->db->prepare(
            "UPDATE appointment_reschedule_requests
             SET request_status = 'cancelled', responded_by_user_id = ?, responded_at = NOW()
             WHERE appointment_id = ? AND request_status IN ('requested','suggested')"
        )->execute([$actorUserId, $appointmentId]);

        $event = $this->db->prepare(
            'INSERT INTO appointment_events
                (appointment_id, actor_user_id, actor_role_snapshot, event_type,
                 from_status, to_status, event_details, created_at)
             VALUES (?, ?, \'admin\', \'doctor_account_disabled\', ?, \'cancelled\', ?, NOW())'
        );
        $event->execute([
            $appointmentId,
            $actorUserId,
            $fromStatus,
            json_encode(['reason' => 'doctor_account_disabled'], JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function enqueueNotification(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?string $refType = null,
        ?int $refId = null
    ): void {
        $notifications = new NotificationService($this->db);
        $notifications->create($userId, $type, $title, $message, $refType, $refId);
    }

    private function lockDoctor(int $doctorUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, email, phone, is_active
             FROM users
             WHERE id = ? AND role = 'doctor'
             LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$doctorUserId]);
        $doctor = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doctor) {
            throw new DomainException('Doctor account not found.');
        }
        return $doctor;
    }

    private function lockDoctorProfile(int $doctorUserId): array
    {
        $this->lockDoctor($doctorUserId);

        $sql = $this->schema === self::SCHEMA_V1
            ? 'SELECT department_id, specialty FROM doctors WHERE user_id = ? LIMIT 1 FOR UPDATE'
            : 'SELECT department_id, specialty FROM doctor_profiles WHERE doctor_user_id = ? LIMIT 1 FOR UPDATE';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$profile) {
            throw new DomainException('Doctor profile not found.');
        }
        return $profile;
    }

    private function lockActiveDepartment(int $departmentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT id, name, status FROM departments WHERE id = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$departmentId]);
        $department = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$department) {
            throw new DomainException('Department not found.');
        }
        if ($department['status'] !== 'active') {
            throw new DomainException('Cannot assign doctors to inactive departments.');
        }
        return $department;
    }

    private function departmentName(?int $departmentId): string
    {
        if ($departmentId === null || $departmentId <= 0) {
            return 'No department';
        }
        $stmt = $this->db->prepare('SELECT name FROM departments WHERE id = ? LIMIT 1');
        $stmt->execute([$departmentId]);
        return (string)($stmt->fetchColumn() ?: 'Unknown department');
    }

    private function assertAdminActor(int $actorUserId): void
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('A valid admin actor is required.');
        }
        $stmt = $this->db->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$actorUserId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('An active administrator is required for doctor management.');
        }
    }

    private function writeAudit(
        int $actorUserId,
        string $action,
        int $doctorUserId,
        mixed $oldValue,
        mixed $newValue,
        string $description,
        ?int $contextDoctorUserId = null
    ): void {
        $oldJson = $oldValue === null ? null : (is_string($oldValue) ? $oldValue : json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $newJson = $newValue === null ? null : (is_string($newValue) ? $newValue : json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare(
                'INSERT INTO admin_audit
                    (actor_id, actor_role, action, entity_type, entity_id, doctor_id,
                     old_value, new_value, description, ip_address, user_agent)
                 VALUES (?, \'admin\', ?, \'doctor\', ?, ?, ?, ?, ?, ?, ?)'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO audit_events
                    (actor_user_id, actor_role_snapshot, action_code, entity_type, entity_id,
                     doctor_user_id, old_value, new_value, description, ip_address, user_agent)
                 VALUES (?, \'admin\', ?, \'doctor\', ?, ?, ?, ?, ?, ?, ?)'
            );
        }

        $stmt->execute([
            $actorUserId,
            $action,
            (string)$doctorUserId,
            $contextDoctorUserId,
            $oldJson,
            $newJson,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function withTransaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
