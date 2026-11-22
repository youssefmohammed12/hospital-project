<?php
declare(strict_types=1);

require_once __DIR__ . '/../ScheduleService.php';

/**
 * Schema-aware doctor account preference adapter.
 *
 * The legacy settings UI exposes one global working-hours range. V2 deliberately
 * stores weekly hours per day, so that legacy range is rejected in v2 rather
 * than being copied across all seven days and overwriting the real schedule.
 */
final class DoctorSettingsRepository
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('DoctorSettingsRepository schema must be explicitly v1 or v2.');
        }
        $this->db = $db;
        $this->schema = $schema;
    }

    public function getDoctorSettings(int $doctorUserId): array
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare(
                'SELECT accept_new_patients, consultation_duration, working_hours_start,
                        working_hours_end, profile_visible
                 FROM user_preferences WHERE user_id=? LIMIT 1'
            );
            $stmt->execute([$doctorUserId]);
            $preferences = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
                'accept_new_patients' => 1,
                'consultation_duration' => 30,
                'working_hours_start' => '09:00',
                'working_hours_end' => '17:00',
                'profile_visible' => 1,
            ];
            $availabilityStmt = $this->db->prepare('SELECT available FROM doctors WHERE user_id=? LIMIT 1');
            $availabilityStmt->execute([$doctorUserId]);
            $acceptance = $availabilityStmt->fetchColumn();
            if ($acceptance !== false) {
                $preferences['accept_new_patients'] = (int)$acceptance;
            }
            $schedule = (new ScheduleService($this->db, self::SCHEMA_V1))->getSchedule($doctorUserId);
            $preferences['operational_available'] = (int)($schedule['settings']['is_available'] ?? 1);

            return [
                'preferences' => $preferences,
                'weekly_schedule' => null,
            ];
        }

        $profileStmt = $this->db->prepare(
            'SELECT accepts_new_patients, profile_visible
             FROM doctor_profiles WHERE doctor_user_id=? LIMIT 1'
        );
        $profileStmt->execute([$doctorUserId]);
        $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
        if (!$profile) {
            throw new DomainException('Doctor profile not found.');
        }

        $schedule = (new ScheduleService($this->db, self::SCHEMA_V2))->getSchedule($doctorUserId);
        $settings = $schedule['settings'] ?? [];
        $preferences = [
            'accept_new_patients' => (int)$profile['accepts_new_patients'],
            'profile_visible' => (int)$profile['profile_visible'],
            'consultation_duration' => (int)($settings['appointment_duration'] ?? 30),
            'max_appointments_per_day' => (int)($settings['max_appointments_per_day'] ?? 25),
            'break_start' => $settings['break_start'] ?? null,
            'break_end' => $settings['break_end'] ?? null,
            'operational_available' => (int)($settings['is_available'] ?? 1),
        ];

        $weekly = $schedule['weekly'] ?? [];
        if (!$weekly) {
            for ($day = 1; $day <= 7; $day++) {
                $weekly[] = [
                    'day_of_week' => $day,
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                    'is_working' => $day <= 5 ? 1 : 0,
                ];
            }
        }

        return [
            'preferences' => $preferences,
            'weekly_schedule' => $weekly,
        ];
    }

    /** Toggle operational schedule availability without changing V2 profile acceptance. */
    public function toggleOperationalAvailability(int $doctorUserId, int $actorUserId): array
    {
        if ($doctorUserId <= 0 || $actorUserId !== $doctorUserId) {
            throw new InvalidArgumentException('The authenticated doctor identity is required.');
        }

        return $this->withTransaction(function () use ($doctorUserId, $actorUserId): array {
            $actor = $this->db->prepare(
                "SELECT id,name FROM users WHERE id=? AND role='doctor' AND is_active=1 LIMIT 1 FOR UPDATE"
            );
            $actor->execute([$actorUserId]);
            $user = $actor->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                throw new DomainException('The authenticated doctor account is not active.');
            }

            if ($this->schema === self::SCHEMA_V1) {
                $profile = $this->db->prepare(
                    'SELECT available FROM doctors WHERE user_id=? LIMIT 1 FOR UPDATE'
                );
                $profile->execute([$doctorUserId]);
                $value = $profile->fetchColumn();
                if ($value === false) {
                    throw new DomainException('Doctor profile not found.');
                }
                $oldAvailable = (int)$value;
            } else {
                $profile = $this->db->prepare(
                    'SELECT accepts_new_patients FROM doctor_profiles WHERE doctor_user_id=? LIMIT 1 FOR UPDATE'
                );
                $profile->execute([$doctorUserId]);
                $acceptsPatients = $profile->fetchColumn();
                if ($acceptsPatients === false) {
                    throw new DomainException('Doctor profile not found.');
                }
                $schedule = $this->db->prepare(
                    'INSERT INTO doctor_schedule_settings (doctor_user_id) VALUES (?)
                     ON DUPLICATE KEY UPDATE doctor_user_id=VALUES(doctor_user_id)'
                );
                $schedule->execute([$doctorUserId]);
                $availability = $this->db->prepare(
                    'SELECT is_available FROM doctor_schedule_settings WHERE doctor_user_id=? LIMIT 1 FOR UPDATE'
                );
                $availability->execute([$doctorUserId]);
                $oldAvailable = (int)$availability->fetchColumn();
            }

            if ($this->schema === self::SCHEMA_V1) {
                $schedule = $this->db->prepare(
                    'INSERT INTO doctor_schedule_settings (doctor_id) VALUES (?)
                     ON DUPLICATE KEY UPDATE doctor_id=VALUES(doctor_id)'
                );
                $schedule->execute([$doctorUserId]);
                $availability = $this->db->prepare(
                    'SELECT is_available FROM doctor_schedule_settings WHERE doctor_id=? LIMIT 1 FOR UPDATE'
                );
                $availability->execute([$doctorUserId]);
            }

            $newAvailable = $oldAvailable === 1 ? 0 : 1;
            (new ScheduleService($this->db, $this->schema))->setAvailability($doctorUserId, $newAvailable === 1);
            $oldLabel = $oldAvailable === 1 ? 'Available' : 'Unavailable';
            $newLabel = $newAvailable === 1 ? 'Available' : 'Unavailable';

            $this->writeAudit(
                $actorUserId,
                $newAvailable === 1 ? 'activate' : 'deactivate',
                'doctor',
                $doctorUserId,
                ['operational_available' => $oldAvailable],
                ['operational_available' => $newAvailable],
                "Availability changed: {$oldLabel} → {$newLabel}",
                $doctorUserId
            );

            return [
                'doctor_user_id' => $doctorUserId,
                'doctor_name' => (string)$user['name'],
                'available' => $newAvailable,
                'changed' => true,
            ];
        });
    }

    public function updateDoctorSettings(int $doctorUserId, array $changes, int $actorUserId): array
    {
        if ($doctorUserId <= 0 || $actorUserId !== $doctorUserId) {
            throw new InvalidArgumentException('The authenticated doctor identity is required.');
        }
        $allowed = ['accept_new_patients', 'consultation_duration', 'working_hours_start', 'working_hours_end', 'profile_visible'];
        if ($this->schema === self::SCHEMA_V2) {
            $allowed[] = 'weekly_schedule';
        }
        foreach (array_keys($changes) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('Unsupported doctor setting: ' . $key);
            }
        }
        if ($this->schema === self::SCHEMA_V2
            && (array_key_exists('working_hours_start', $changes) || array_key_exists('working_hours_end', $changes))) {
            throw new DomainException(
                'V2 uses a separate schedule for each weekday. Update the weekly schedule instead of using one global working-hours range.'
            );
        }

        return $this->withTransaction(function () use ($doctorUserId, $changes, $actorUserId): array {
            $actor = $this->db->prepare(
                "SELECT id FROM users WHERE id=? AND role='doctor' AND is_active=1 LIMIT 1 FOR UPDATE"
            );
            $actor->execute([$actorUserId]);
            if (!$actor->fetchColumn()) {
                throw new DomainException('The authenticated doctor account is not active.');
            }

            if ($this->schema === self::SCHEMA_V1) {
                return $this->updateV1($doctorUserId, $changes, $actorUserId);
            }
            return $this->updateV2($doctorUserId, $changes, $actorUserId);
        });
    }

    private function updateV1(int $doctorUserId, array $changes, int $actorUserId): array
    {
        $profile = $this->db->prepare('SELECT available FROM doctors WHERE user_id=? LIMIT 1 FOR UPDATE');
        $profile->execute([$doctorUserId]);
        $available = $profile->fetchColumn();
        if ($available === false) {
            throw new DomainException('Doctor profile not found.');
        }

        $prefs = $this->db->prepare(
            'SELECT accept_new_patients, consultation_duration, working_hours_start,
                    working_hours_end, profile_visible
             FROM user_preferences WHERE user_id=? LIMIT 1 FOR UPDATE'
        );
        $prefs->execute([$doctorUserId]);
        $old = $prefs->fetch(PDO::FETCH_ASSOC) ?: [
            'accept_new_patients' => (int)$available,
            'consultation_duration' => 30,
            'working_hours_start' => '09:00',
            'working_hours_end' => '17:00',
            'profile_visible' => 1,
        ];
        $new = $old;

        foreach (['accept_new_patients', 'profile_visible'] as $field) {
            if (array_key_exists($field, $changes)) {
                $new[$field] = (int)$changes[$field];
            }
        }
        if (array_key_exists('consultation_duration', $changes)) {
            $new['consultation_duration'] = (int)$changes['consultation_duration'];
        }
        foreach (['working_hours_start', 'working_hours_end'] as $field) {
            if (array_key_exists($field, $changes)) {
                $new[$field] = (string)$changes[$field];
            }
        }

        $this->validateNormalizedValues($new, true);
        $exists = $this->db->prepare('SELECT 1 FROM user_preferences WHERE user_id=? LIMIT 1');
        $exists->execute([$doctorUserId]);
        if (!$exists->fetchColumn()) {
            $this->db->prepare('INSERT INTO user_preferences (user_id) VALUES (?)')->execute([$doctorUserId]);
        }

        $this->db->prepare(
            'UPDATE user_preferences
             SET accept_new_patients=?, consultation_duration=?,
                 working_hours_start=?, working_hours_end=?, profile_visible=?, updated_at=NOW()
             WHERE user_id=?'
        )->execute([
            (int)$new['accept_new_patients'],
            (int)$new['consultation_duration'],
            $new['working_hours_start'],
            $new['working_hours_end'],
            (int)$new['profile_visible'],
            $doctorUserId,
        ]);

        if (array_key_exists('accept_new_patients', $changes)) {
            $this->db->prepare('UPDATE doctors SET available=? WHERE user_id=?')
                ->execute([(int)$new['accept_new_patients'], $doctorUserId]);
        }
        if (array_key_exists('consultation_duration', $changes)) {
            $this->db->prepare(
                'INSERT INTO doctor_schedule_settings (doctor_id, appointment_duration)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE appointment_duration=VALUES(appointment_duration)'
            )->execute([$doctorUserId, (int)$new['consultation_duration']]);
        }

        $this->writeAudit(
            $actorUserId,
            'update',
            'doctor',
            $doctorUserId,
            array_merge($old, ['doctor_available' => (int)$available]),
            array_merge($new, ['doctor_available' => (int)($new['accept_new_patients'] ?? $available)]),
            'Doctor account and availability settings updated.',
            $doctorUserId
        );

        return ['doctor_user_id' => $doctorUserId, 'changed' => $new !== $old, 'settings' => $new];
    }

    private function updateV2(int $doctorUserId, array $changes, int $actorUserId): array
    {
        $profile = $this->db->prepare(
            'SELECT accepts_new_patients, profile_visible
             FROM doctor_profiles WHERE doctor_user_id=? LIMIT 1 FOR UPDATE'
        );
        $profile->execute([$doctorUserId]);
        $oldProfile = $profile->fetch(PDO::FETCH_ASSOC);
        if (!$oldProfile) {
            throw new DomainException('Doctor profile not found.');
        }

        $this->db->prepare('INSERT IGNORE INTO doctor_schedule_settings (doctor_user_id) VALUES (?)')
            ->execute([$doctorUserId]);
        $schedule = $this->db->prepare(
            'SELECT appointment_duration_min FROM doctor_schedule_settings
             WHERE doctor_user_id=? LIMIT 1 FOR UPDATE'
        );
        $schedule->execute([$doctorUserId]);
        $duration = (int)$schedule->fetchColumn();
        if ($duration <= 0) { $duration = 30; }

        $scheduleService = new ScheduleService($this->db, self::SCHEMA_V2);
        $oldSchedule = $scheduleService->getSchedule($doctorUserId);
        $oldWeekly = $oldSchedule['weekly'] ?? [];
        $weeklySchedule = $changes['weekly_schedule'] ?? null;
        if ($weeklySchedule !== null) {
            if (!is_array($weeklySchedule) || count($weeklySchedule) !== 7) {
                throw new InvalidArgumentException('Weekly schedule must include all seven weekdays.');
            }
            $seenDays = [];
            foreach ($weeklySchedule as $day) {
                if (!is_array($day)) {
                    throw new InvalidArgumentException('Each weekly schedule entry must be an object.');
                }
                $dayNumber = filter_var($day['day_of_week'] ?? null, FILTER_VALIDATE_INT);
                if ($dayNumber === false || $dayNumber < 1 || $dayNumber > 7 || in_array($dayNumber, $seenDays, true)) {
                    throw new InvalidArgumentException('Weekly schedule must include each weekday exactly once.');
                }
                $seenDays[] = $dayNumber;
                foreach (['start_time', 'end_time'] as $field) {
                    if (!is_string($day[$field] ?? null)
                        || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $day[$field])) {
                        throw new InvalidArgumentException('Weekly schedule times must use HH:MM format.');
                    }
                }
                $workingValue = $day['is_working'] ?? null;
                if (!in_array($workingValue, [0, 1, '0', '1', false, true], true)) {
                    throw new InvalidArgumentException('Weekly schedule working-day flags must be 0 or 1.');
                }
            }
            sort($seenDays);
            if ($seenDays !== [1, 2, 3, 4, 5, 6, 7]) {
                throw new InvalidArgumentException('Weekly schedule must include each weekday exactly once.');
            }
            $hours = $scheduleService->getHospitalHours();
            $weeklyError = ScheduleService::validateWeekly($weeklySchedule, $hours['open'], $hours['close']);
            if ($weeklyError !== null) {
                throw new DomainException($weeklyError);
            }
        }

        $newProfile = $oldProfile;
        if (array_key_exists('accept_new_patients', $changes)) {
            $newProfile['accepts_new_patients'] = (int)$changes['accept_new_patients'];
        }
        if (array_key_exists('profile_visible', $changes)) {
            $newProfile['profile_visible'] = (int)$changes['profile_visible'];
        }
        $newDuration = array_key_exists('consultation_duration', $changes)
            ? (int)$changes['consultation_duration'] : $duration;

        if ($newDuration < 15 || $newDuration > 120 || $newDuration % 5 !== 0) {
            throw new InvalidArgumentException('Consultation duration must be between 15 and 120 minutes in 5-minute steps.');
        }
        foreach (['accepts_new_patients', 'profile_visible'] as $field) {
            if (!in_array((int)$newProfile[$field], [0, 1], true)) {
                throw new InvalidArgumentException('Doctor settings must be boolean values.');
            }
        }

        if ($newProfile !== $oldProfile) {
            $this->db->prepare(
                'UPDATE doctor_profiles
                 SET accepts_new_patients=?, profile_visible=?, updated_at=NOW()
                 WHERE doctor_user_id=?'
            )->execute([
                (int)$newProfile['accepts_new_patients'],
                (int)$newProfile['profile_visible'],
                $doctorUserId,
            ]);
        }
        if ($newDuration !== $duration) {
            $this->db->prepare(
                'UPDATE doctor_schedule_settings
                 SET appointment_duration_min=?, updated_at=NOW()
                 WHERE doctor_user_id=?'
            )->execute([$newDuration, $doctorUserId]);
        }
        if ($weeklySchedule !== null) {
            $scheduleService->updateSchedule($doctorUserId, $weeklySchedule);
        }

        $newAudit = [
            'accept_new_patients' => (int)$newProfile['accepts_new_patients'],
            'profile_visible' => (int)$newProfile['profile_visible'],
            'consultation_duration' => $newDuration,
        ];
        $oldAudit = [
            'accept_new_patients' => (int)$oldProfile['accepts_new_patients'],
            'profile_visible' => (int)$oldProfile['profile_visible'],
            'consultation_duration' => $duration,
        ];
        if ($weeklySchedule !== null) {
            $normalizeWeekly = static function (array $days): array {
                $normalized = [];
                foreach ($days as $day) {
                    $normalized[] = [
                        'day_of_week' => (int)$day['day_of_week'],
                        'start_time' => (string)$day['start_time'],
                        'end_time' => (string)$day['end_time'],
                        'is_working' => (int)$day['is_working'],
                    ];
                }
                usort($normalized, static fn(array $a, array $b): int => $a['day_of_week'] <=> $b['day_of_week']);
                return $normalized;
            };
            $oldAudit['weekly_schedule'] = $normalizeWeekly($oldWeekly);
            $newAudit['weekly_schedule'] = $normalizeWeekly($weeklySchedule);
        }

        if ($newAudit !== $oldAudit) {
            $this->writeAudit(
                $actorUserId,
                'update',
                'doctor',
                $doctorUserId,
                $oldAudit,
                $newAudit,
                'Doctor account and availability settings updated.',
                $doctorUserId
            );
        }

        return ['doctor_user_id' => $doctorUserId, 'changed' => $newAudit !== $oldAudit, 'settings' => $newAudit];
    }

    private function validateNormalizedValues(array $values, bool $validateHours): void
    {
        $duration = (int)($values['consultation_duration'] ?? 30);
        if ($duration < 15 || $duration > 120 || $duration % 5 !== 0) {
            throw new InvalidArgumentException('Consultation duration must be between 15 and 120 minutes in 5-minute steps.');
        }
        foreach (['accept_new_patients', 'profile_visible'] as $field) {
            if (array_key_exists($field, $values) && !in_array((int)$values[$field], [0, 1], true)) {
                throw new InvalidArgumentException('Doctor settings must be boolean values.');
            }
        }
        if ($validateHours) {
            foreach (['working_hours_start', 'working_hours_end'] as $field) {
                if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', (string)($values[$field] ?? ''))) {
                    throw new InvalidArgumentException('Working hours must be valid HH:MM times.');
                }
            }
            if ((string)$values['working_hours_start'] >= (string)$values['working_hours_end']) {
                throw new InvalidArgumentException('Working-hours start must be before working-hours end.');
            }
        }
    }

    private function writeAudit(
        int $actorUserId,
        string $action,
        string $entityType,
        int $entityId,
        mixed $oldValue,
        mixed $newValue,
        string $description,
        ?int $doctorId = null
    ): void {
        $oldJson = json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $newJson = json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare(
                "INSERT INTO admin_audit
                 (actor_id,actor_role,action,entity_type,entity_id,doctor_id,old_value,new_value,description,ip_address,user_agent)
                 VALUES (?, 'doctor', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO audit_events
                 (actor_user_id,actor_role_snapshot,action_code,entity_type,entity_id,doctor_user_id,old_value,new_value,description,ip_address,user_agent)
                 VALUES (?, 'doctor', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }
        $stmt->execute([
            $actorUserId, $action, $entityType, (string)$entityId, $doctorId,
            $oldJson, $newJson, $description,
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
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
}
