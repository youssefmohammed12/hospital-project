<?php
require_once __DIR__ . '/Repositories/DoctorScheduleRepository.php';

/**
 * HealthBridge — HospitalSettingsService
 *
 * Manages global hospital-wide appointment configuration.
 * Single source of truth for appointment open/close times and default duration.
 *
 * Usage:
 *   $hs = new HospitalSettingsService(getDB());
 *   $hs->getSettings();
 *   $hs->updateSettings([...]);
 */

class HospitalSettingsService
{
    private PDO $db;

    /**
     * @var array Cached settings for the current request
     */
    private ?array $cache = null;

    public const VALID_DURATIONS = [10, 15, 20, 30, 45, 60];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get hospital settings (cached per request).
     * @return array
     */
    public function getSettings(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stmt = $this->db->query("SELECT * FROM hospital_settings WHERE id = 1 LIMIT 1");
        $settings = $stmt->fetch();

        if (!$settings) {
            // Create default row
            $this->db->exec(
                "INSERT IGNORE INTO hospital_settings (id, appointment_open_time, appointment_close_time, default_appointment_duration)
                 VALUES (1, '08:00', '22:00', 30)"
            );
            $stmt = $this->db->query("SELECT * FROM hospital_settings WHERE id = 1 LIMIT 1");
            $settings = $stmt->fetch();
        }

        if (is_array($settings)) {
            foreach (['appointment_open_time', 'appointment_close_time'] as $field) {
                if (isset($settings[$field])) {
                    $settings[$field] = substr((string)$settings[$field], 0, 5);
                }
            }
        }

        $this->cache = $settings;
        return $settings;
    }

    /**
     * Get appointment opening time (HH:MM format).
     * @return string
     */
    public function getAppointmentOpenTime(): string
    {
        $settings = $this->getSettings();
        return $settings['appointment_open_time'] ?? '08:00';
    }

    /**
     * Get appointment closing time (HH:MM format).
     * @return string
     */
    public function getAppointmentCloseTime(): string
    {
        $settings = $this->getSettings();
        return $settings['appointment_close_time'] ?? '22:00';
    }

    /**
     * Get default appointment duration in minutes.
     * @return int
     */
    public function getDefaultDuration(): int
    {
        $settings = $this->getSettings();
        return (int)($settings['default_appointment_duration'] ?? 30);
    }

    /**
     * Update hospital settings.
     * @param array $data Keys: appointment_open_time, appointment_close_time, default_appointment_duration,
     *                    hospital_name, hospital_phone, hospital_email, hospital_address, hospital_description
     * @return bool
     * @throws Exception
     */
    public function updateSettings(array $data): bool
    {
        $openTime = $data['appointment_open_time'] ?? null;
        $closeTime = $data['appointment_close_time'] ?? null;
        $duration = isset($data['default_appointment_duration']) ? (int)$data['default_appointment_duration'] : null;

        // Hospital info fields
        $hospitalName = $data['hospital_name'] ?? null;
        $hospitalPhone = $data['hospital_phone'] ?? null;
        $hospitalEmail = $data['hospital_email'] ?? null;
        $hospitalAddress = $data['hospital_address'] ?? null;
        $hospitalDescription = $data['hospital_description'] ?? null;

        // Validate appointment settings
        if ($openTime && !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $openTime)) {
            throw new Exception('Appointment opening time must be in HH:MM format.');
        }
        if ($closeTime && !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $closeTime)) {
            throw new Exception('Appointment closing time must be in HH:MM format.');
        }
        if ($openTime && $closeTime && $openTime >= $closeTime) {
            throw new Exception('Appointment opening time must be before closing time.');
        }
        if ($duration !== null && !in_array($duration, self::VALID_DURATIONS)) {
            throw new Exception('Invalid duration. Must be one of: ' . implode(', ', self::VALID_DURATIONS));
        }

        // Validate hospital info
        if ($hospitalEmail && !filter_var($hospitalEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Hospital email must be a valid email address.');
        }
        if ($hospitalName && strlen($hospitalName) > 200) {
            throw new Exception('Hospital name must not exceed 200 characters.');
        }
        if ($hospitalPhone && !preg_match('/^[\d\s\+\-\(\)]+$/', $hospitalPhone)) {
            throw new Exception('Hospital phone must contain only valid phone number characters.');
        }

        $updates = [];
        $params = [];

        if ($openTime !== null) {
            $updates[] = 'appointment_open_time = ?';
            $params[] = $openTime;
        }
        if ($closeTime !== null) {
            $updates[] = 'appointment_close_time = ?';
            $params[] = $closeTime;
        }
        if ($duration !== null) {
            $updates[] = 'default_appointment_duration = ?';
            $params[] = $duration;
        }
        if ($hospitalName !== null) {
            $updates[] = 'hospital_name = ?';
            $params[] = $hospitalName;
        }
        if ($hospitalPhone !== null) {
            $updates[] = 'hospital_phone = ?';
            $params[] = $hospitalPhone;
        }
        if ($hospitalEmail !== null) {
            $updates[] = 'hospital_email = ?';
            $params[] = $hospitalEmail;
        }
        if ($hospitalAddress !== null) {
            $updates[] = 'hospital_address = ?';
            $params[] = $hospitalAddress;
        }
        if ($hospitalDescription !== null) {
            $updates[] = 'hospital_description = ?';
            $params[] = $hospitalDescription;
        }

        if (empty($updates)) {
            return false;
        }

        $sql = 'UPDATE hospital_settings SET ' . implode(', ', $updates) . ' WHERE id = 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // Clear cache
        $this->cache = null;

        return $stmt->rowCount() > 0;
    }

    /**
     * Check if changing hospital hours would conflict with existing doctor schedules.
     * Returns array of affected doctor schedules if conflicts exist.
     *
     * @param string $newOpenTime New opening time (HH:MM)
     * @param string $newCloseTime New closing time (HH:MM)
     * @return array Array of affected schedules with doctor info
     */
    public function checkScheduleConflicts(string $newOpenTime, string $newCloseTime): array
    {
            $repository = new DoctorScheduleRepository($this->db);
            $conflicts = [];
            foreach ($repository->getAllSchedules() as $schedule) {
                foreach ($schedule['weekly'] ?? [] as $day) {
                    if ((int)($day['is_working'] ?? 0) !== 1) {
                        continue;
                    }
                    $start = substr((string)($day['start_time'] ?? ''), 0, 5);
                    $end = substr((string)($day['end_time'] ?? ''), 0, 5);
                    if ($start < $newOpenTime || $end > $newCloseTime) {
                        $conflicts[] = [
                            'doctor_id' => (int)$schedule['doctor_id'],
                            'doctor_name' => (string)($schedule['doctor_name'] ?? 'Unknown'),
                            'day_of_week' => (int)$day['day_of_week'],
                            'start_time' => $start,
                            'end_time' => $end,
                        ];
                    }
                }
            }
            usort($conflicts, static function (array $a, array $b): int {
                $name = strcmp($a['doctor_name'], $b['doctor_name']);
                return $name !== 0 ? $name : ($a['day_of_week'] <=> $b['day_of_week']);
            });
            return $conflicts;
    }
}