<?php
declare(strict_types=1);

/**
 * Read/write adapter for the doctor schedule schema (V2 schema).
 */
final class DoctorScheduleRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getSchedule(int $doctorUserId): ?array
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        $settings = $this->fetchSettings($doctorUserId);
        if ($settings === null) {
            return null;
        }

        return [
            'settings' => $settings,
            'weekly' => $this->ensureAllDays($this->fetchWeekly($doctorUserId)),
        ];
    }

    /**
     * Return all configured schedules in the same DTO shape as v1 ScheduleService.
     * Weekly rows are fetched in one query rather than one query per doctor.
     */
    public function getAllSchedules(): array
    {
        $settingsSql = "SELECT s.doctor_user_id AS doctor_id,
                      s.appointment_duration_min AS appointment_duration,
                      s.max_appointments_per_day, TIME_FORMAT(s.break_start, '%H:%i') AS break_start,
                      TIME_FORMAT(s.break_end, '%H:%i') AS break_end,
                      s.is_available, s.created_at, s.updated_at,
                      u.name AS doctor_name, u.email AS doctor_email
               FROM doctor_schedule_settings s
               JOIN users u ON u.id = s.doctor_user_id
               WHERE u.role = 'doctor'
               ORDER BY u.name ASC";

        $schedules = $this->db->query($settingsSql)->fetchAll(PDO::FETCH_ASSOC);
        if (!$schedules) {
            return [];
        }

        $doctorIds = array_map(
            static fn(array $row): int => (int)$row['doctor_id'],
            $schedules
        );
        $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));

        $weeklySql = "SELECT doctor_user_id AS doctor_id, NULL AS id, day_of_week,
                      TIME_FORMAT(start_time, '%H:%i') AS start_time,
                      TIME_FORMAT(end_time, '%H:%i') AS end_time, is_working
               FROM doctor_weekly_schedules
               WHERE doctor_user_id IN ($placeholders)
               ORDER BY doctor_user_id, day_of_week";

        $weeklyStmt = $this->db->prepare($weeklySql);
        $weeklyStmt->execute($doctorIds);
        $weeklyByDoctor = [];
        foreach ($weeklyStmt->fetchAll(PDO::FETCH_ASSOC) as $day) {
            $doctorId = (int)$day['doctor_id'];
            $weeklyByDoctor[$doctorId][] = $day;
        }

        foreach ($schedules as &$schedule) {
            $doctorId = (int)$schedule['doctor_id'];
            $rows = $weeklyByDoctor[$doctorId] ?? [];
            $schedule['weekly'] = $this->ensureAllDays($rows);
            $schedule['working_days_count'] = count(array_filter(
                $rows,
                static fn(array $day): bool => (int)$day['is_working'] === 1
            ));
        }
        unset($schedule);

        return $schedules;
    }

    /**
     * Update schedule fields using the same input shape accepted by ScheduleService.
     * The caller remains responsible for domain validation and authorization.
     *
     * If called inside an existing transaction, transaction ownership stays
     * with the caller. This allows CLI contract tests to roll every write back.
     */
    public function saveSchedule(int $doctorUserId, array $weeklyData, array $settingsData = []): void
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        $allowedSettings = [
            'appointment_duration',
            'max_appointments_per_day',
            'break_start',
            'break_end',
            'is_available',
        ];
        foreach (array_keys($settingsData) as $key) {
            if (!in_array($key, $allowedSettings, true)) {
                throw new InvalidArgumentException('Unsupported schedule setting: ' . $key);
            }
        }

        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $this->ensureSettingsRow($doctorUserId);
            $this->updateSettings($doctorUserId, $settingsData);
            $this->upsertWeekly($doctorUserId, $weeklyData);

            if ($ownsTransaction) {
                $this->db->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function setAvailability(int $doctorUserId, bool $available): void
    {
        $this->saveSchedule($doctorUserId, [], ['is_available' => $available ? 1 : 0]);
    }

    public function getAppointmentDuration(int $doctorUserId): int
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        $sql = 'SELECT appointment_duration_min FROM doctor_schedule_settings WHERE doctor_user_id = ? LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);
        $duration = $stmt->fetchColumn();

        return $duration !== false && $duration !== null && (int)$duration > 0
            ? (int)$duration
            : 30;
    }

    private function fetchSettings(int $doctorUserId): ?array
    {
        $sql = "SELECT s.doctor_user_id AS doctor_id,
                      s.appointment_duration_min AS appointment_duration,
                      s.max_appointments_per_day,
                      TIME_FORMAT(s.break_start, '%H:%i') AS break_start,
                      TIME_FORMAT(s.break_end, '%H:%i') AS break_end,
                      s.is_available, s.created_at, s.updated_at,
                      u.name AS doctor_name, u.email AS doctor_email
               FROM doctor_schedule_settings s
               JOIN users u ON u.id = s.doctor_user_id
               WHERE s.doctor_user_id = ? LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function fetchWeekly(int $doctorUserId): array
    {
        $sql = "SELECT NULL AS id, day_of_week,
                      TIME_FORMAT(start_time, '%H:%i') AS start_time,
                      TIME_FORMAT(end_time, '%H:%i') AS end_time, is_working
               FROM doctor_weekly_schedules
               WHERE doctor_user_id = ? ORDER BY day_of_week ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function ensureAllDays(array $weekly): array
    {
        $existing = [];
        foreach ($weekly as $day) {
            $existing[(int)$day['day_of_week']] = true;
        }

        for ($day = 1; $day <= 7; $day++) {
            if (!isset($existing[$day])) {
                $weekly[] = [
                    'id' => null,
                    'day_of_week' => (string)$day,
                    'start_time' => '09:00',
                    'end_time' => '17:00',
                    'is_working' => '0',
                ];
            }
        }

        usort(
            $weekly,
            static fn(array $a, array $b): int => (int)$a['day_of_week'] <=> (int)$b['day_of_week']
        );

        return $weekly;
    }

    private function ensureSettingsRow(int $doctorUserId): void
    {
        $sql = 'INSERT INTO doctor_schedule_settings (doctor_user_id) VALUES (?)
               ON DUPLICATE KEY UPDATE doctor_user_id = VALUES(doctor_user_id)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);
    }

    private function updateSettings(int $doctorUserId, array $settingsData): void
    {
        $columnMap = ['appointment_duration' => 'appointment_duration_min'];

        foreach (['max_appointments_per_day', 'break_start', 'break_end', 'is_available'] as $field) {
            $columnMap[$field] = $field;
        }

        $updates = [];
        $params = [];
        foreach ($settingsData as $field => $value) {
            $column = $columnMap[$field];
            $updates[] = $column . ' = ?';
            $params[] = $value;
        }

        if (!$updates) {
            return;
        }

        $doctorColumn = 'doctor_user_id';
        $updates[] = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $doctorUserId;

        $sql = 'UPDATE doctor_schedule_settings SET ' . implode(', ', $updates)
            . ' WHERE ' . $doctorColumn . ' = ?';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function upsertWeekly(int $doctorUserId, array $weeklyData): void
    {
        if (!$weeklyData) {
            return;
        }

        $sql = "INSERT INTO doctor_weekly_schedules
                 (doctor_user_id, day_of_week, start_time, end_time, is_working)
               VALUES (?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE
                 start_time = VALUES(start_time),
                 end_time = VALUES(end_time),
                 is_working = VALUES(is_working),
                 updated_at = CURRENT_TIMESTAMP";

        $stmt = $this->db->prepare($sql);
        foreach ($weeklyData as $day) {
            if (!is_array($day)) {
                throw new InvalidArgumentException('Each weekly schedule entry must be an array.');
            }

            $stmt->execute([
                $doctorUserId,
                (int)($day['day_of_week'] ?? 0),
                (string)($day['start_time'] ?? '09:00'),
                (string)($day['end_time'] ?? '17:00'),
                (int)($day['is_working'] ?? 0),
            ]);
        }
    }
}
