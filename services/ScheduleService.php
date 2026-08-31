<?php
/**
 * HealthBridge — ScheduleService
 *
 * Centralized service for managing doctor working schedules.
 * All schedule operations go through this class.
 *
 * This service handles:
 * - Weekly working days and hours
 * - Appointment duration configuration
 * - Break time configuration
 * - Maximum appointments per day
 * - Master availability toggle
 * - Slot generation (Phase 2)
 * - Slot validation (Phase 2)
 * - Hospital operating hours enforcement (Phase 6.1)
 *
 * Designed for Phase 3 expansion: vacation periods, multiple shifts, emergency closures.
 *
 * Usage:
 *   $ss = new ScheduleService(getDB());
 *   $ss->getSchedule($doctorId);
 *   $ss->updateSchedule($doctorId, $weeklyData, $settingsData);
 *   $ss->getAvailableSlots($doctorId, '2026-07-15');
 *
 * @method array getBatchPublicAvailability(array $doctorIds) Get public availability for ALL doctors in a single batch
 */

class ScheduleService
{
    private PDO $db;

    /**
     * @var array Valid appointment durations in minutes
     */
    public const VALID_DURATIONS = [15, 20, 30, 45, 60];

    /**
     * @var string Hospital operating hours (enforced for all doctors)
     * These are fallback defaults; actual values are loaded from hospital_settings table.
     */
    public const HOSPITAL_START = '06:00';
    public const HOSPITAL_END   = '23:00';

    /**
     * @var array Day names indexed by day_of_week (1-7)
     */
    public const DAY_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    /**
     * @var array Short day names indexed by day_of_week (1-7)
     */
    public const DAY_NAMES_SHORT = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    /**
     * @var array|null Cached hospital settings
     */
    private ?array $hospitalSettingsCache = null;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get hospital appointment hours from the database.
     * Falls back to class constants if not configured.
     *
     * @return array ['open' => 'HH:MM', 'close' => 'HH:MM']
     */
    public function getHospitalHours(): array
    {
        if ($this->hospitalSettingsCache !== null) {
            return $this->hospitalSettingsCache;
        }

        try {
            $stmt = $this->db->query("SELECT appointment_open_time, appointment_close_time FROM hospital_settings WHERE id = 1 LIMIT 1");
            $row = $stmt->fetch();
            if ($row) {
                $this->hospitalSettingsCache = [
                    'open'  => $row['appointment_open_time'] ?? self::HOSPITAL_START,
                    'close' => $row['appointment_close_time'] ?? self::HOSPITAL_END,
                ];
                return $this->hospitalSettingsCache;
            }
        } catch (\Exception $e) {
            // Fall through to defaults
        }

        $this->hospitalSettingsCache = [
            'open'  => self::HOSPITAL_START,
            'close' => self::HOSPITAL_END,
        ];
        return $this->hospitalSettingsCache;
    }

    // ── Read ─────────────────────────────────────────────────

    /**
     * Get a doctor's full schedule including settings and weekly days.
     *
     * @param int $doctorId
     * @return array|null  Array with 'settings' and 'weekly' keys, or null if doctor not found
     */
    public function getSchedule(int $doctorId): ?array
    {
        // Get settings
        $stmt = $this->db->prepare(
            "SELECT dss.*, u.name AS doctor_name, u.email AS doctor_email
             FROM doctor_schedule_settings dss
             JOIN users u ON dss.doctor_id = u.id
             WHERE dss.doctor_id = ?
             LIMIT 1"
        );
        $stmt->execute([$doctorId]);
        $settings = $stmt->fetch();

        if (!$settings) {
            return null;
        }

        // Get weekly schedule
        $stmt = $this->db->prepare(
            "SELECT id, day_of_week, start_time, end_time, is_working
             FROM doctor_schedule_weekly
             WHERE doctor_id = ?
             ORDER BY day_of_week ASC"
        );
        $stmt->execute([$doctorId]);
        $weekly = $stmt->fetchAll();

        // Ensure all 7 days exist (fill defaults for missing days)
        $weekly = $this->ensureAllDays($doctorId, $weekly);

        return [
            'settings' => $settings,
            'weekly'   => $weekly,
        ];
    }

    /**
     * Get schedule for all doctors (admin view).
     *
     * @return array
     */
    public function getAllSchedules(): array
    {
        $stmt = $this->db->prepare(
            "SELECT dss.*, u.name AS doctor_name, u.email AS doctor_email
             FROM doctor_schedule_settings dss
             JOIN users u ON dss.doctor_id = u.id
             WHERE u.role = 'doctor'
             ORDER BY u.name ASC"
        );
        $stmt->execute();
        $schedules = $stmt->fetchAll();

        // Attach weekly schedule summary for each doctor
        foreach ($schedules as &$schedule) {
            $stmt = $this->db->prepare(
                "SELECT day_of_week, start_time, end_time, is_working
                 FROM doctor_schedule_weekly
                 WHERE doctor_id = ?
                 ORDER BY day_of_week ASC"
            );
            $stmt->execute([(int)$schedule['doctor_id']]);
            $weekly = $stmt->fetchAll();
            $schedule['weekly'] = $this->ensureAllDays((int)$schedule['doctor_id'], $weekly);
            $schedule['working_days_count'] = count(array_filter($weekly, function($d) {
                return (int)$d['is_working'] === 1;
            }));
        }

        return $schedules;
    }

    /**
     * Get minimal schedule info (used by booking phase in future).
     * Only returns working days with times.
     *
     * @param int $doctorId
     * @return array|null
     */
    public function getWorkingSchedule(int $doctorId): ?array
    {
        $schedule = $this->getSchedule($doctorId);
        if (!$schedule) {
            return null;
        }

        // Filter only working days
        $schedule['weekly'] = array_values(
            array_filter($schedule['weekly'], function($d) {
                return (int)$d['is_working'] === 1;
            })
        );

        return $schedule;
    }

    // ── Create / Update ──────────────────────────────────────

    /**
     * Update a doctor's full schedule.
     * Uses a transaction to update settings and all weekly days.
     *
     * @param int   $doctorId
     * @param array $weeklyData  Array of day data, each with:
     *     day_of_week (1-7), start_time, end_time, is_working (0|1)
     * @param array $settingsData  Associative array with optional keys:
     *     appointment_duration, max_appointments_per_day, break_start, break_end, is_available
     * @return bool
     * @throws Exception
     */
    public function updateSchedule(int $doctorId, array $weeklyData, array $settingsData = []): bool
    {
        $this->db->beginTransaction();

        try {
            // 1. Update or create settings row
            $this->upsertSettings($doctorId, $settingsData);

            // 2. Upsert each weekly day
            $this->upsertWeeklyDays($doctorId, $weeklyData);

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Update doctor availability toggle only.
     *
     * @param int  $doctorId
     * @param bool $isAvailable
     * @return bool
     */
    public function setAvailability(int $doctorId, bool $isAvailable): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE doctor_schedule_settings SET is_available = ?, updated_at = NOW() WHERE doctor_id = ?"
        );
        $stmt->execute([$isAvailable ? 1 : 0, $doctorId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Reset a doctor's schedule to factory defaults.
     *
     * @param int $doctorId
     * @return bool
     */
    public function resetToDefault(int $doctorId): bool
    {
        $this->db->beginTransaction();

        try {
            // Reset settings
            $stmt = $this->db->prepare(
                "UPDATE doctor_schedule_settings
                 SET appointment_duration = 30,
                     max_appointments_per_day = 25,
                     break_start = NULL,
                     break_end = NULL,
                     is_available = 1,
                     updated_at = NOW()
                 WHERE doctor_id = ?"
            );
            $stmt->execute([$doctorId]);

            // Reset all days to default
            $defaults = [
                1 => ['09:00', '17:00', 1], // Monday
                2 => ['09:00', '17:00', 1], // Tuesday
                3 => ['09:00', '17:00', 1], // Wednesday
                4 => ['09:00', '17:00', 1], // Thursday
                5 => ['09:00', '17:00', 1], // Friday
                6 => ['09:00', '17:00', 0], // Saturday
                7 => ['09:00', '17:00', 0], // Sunday
            ];

            $dayStmt = $this->db->prepare(
                "INSERT INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     start_time = VALUES(start_time),
                     end_time = VALUES(end_time),
                     is_working = VALUES(is_working),
                     updated_at = NOW()"
            );

            foreach ($defaults as $day => $config) {
                $dayStmt->execute([$doctorId, $day, $config[0], $config[1], $config[2]]);
            }

            $this->db->commit();
            return true;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Validation ───────────────────────────────────────────

    /**
     * Validate weekly schedule data for a single day.
     *
     * @param array  $day  Must contain: day_of_week, start_time, end_time
     * @param string $hospitalOpen  Hospital opening time (HH:MM) for boundary validation
     * @param string $hospitalClose Hospital closing time (HH:MM) for boundary validation
     * @return string|null  Error message or null if valid
     */
    public static function validateDay(array $day, string $hospitalOpen = '', string $hospitalClose = ''): ?string
    {
        $dow = (int)($day['day_of_week'] ?? 0);
        if ($dow < 1 || $dow > 7) {
            return 'Day of week must be between 1 (Monday) and 7 (Sunday).';
        }

        $start = $day['start_time'] ?? '';
        $end = $day['end_time'] ?? '';

        // Use defaults if not provided
        if (empty($hospitalOpen)) $hospitalOpen = self::HOSPITAL_START;
        if (empty($hospitalClose)) $hospitalClose = self::HOSPITAL_END;

        // Validate working days have times
        $isWorking = (int)($day['is_working'] ?? 1);
        if ($isWorking === 1) {
            if (empty($start) || empty($end)) {
                return self::DAY_NAMES[$dow] . ': Start and end times are required for working days.';
            }

            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $start)) {
                return self::DAY_NAMES[$dow] . ': Start time must be in HH:MM format (24-hour).';
            }

            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $end)) {
                return self::DAY_NAMES[$dow] . ': End time must be in HH:MM format (24-hour).';
            }

            if ($start >= $end) {
                return self::DAY_NAMES[$dow] . ': Start time must be before end time.';
            }

            // Validate against hospital operating hours (dynamically loaded from DB)
            if ($start < $hospitalOpen) {
                return self::DAY_NAMES[$dow] . ': Start time cannot be before hospital opening (' . $hospitalOpen . ').';
            }
            if ($end > $hospitalClose) {
                return self::DAY_NAMES[$dow] . ': End time cannot be after hospital closing (' . $hospitalClose . ').';
            }
        }

        return null;
    }

    /**
     * Validate all weekly schedule data.
     *
     * @param array  $weeklyData  Array of day data
     * @param string $hospitalOpen  Hospital opening time (HH:MM) for boundary validation
     * @param string $hospitalClose Hospital closing time (HH:MM) for boundary validation
     * @return string|null  Error message or null if valid
     */
    public static function validateWeekly(array $weeklyData, string $hospitalOpen = '', string $hospitalClose = ''): ?string
    {
        if (empty($weeklyData)) {
            return 'At least one day must be configured.';
        }

        $seenDays = [];
        foreach ($weeklyData as $day) {
            $error = self::validateDay($day, $hospitalOpen, $hospitalClose);
            if ($error) {
                return $error;
            }

            $dow = (int)($day['day_of_week'] ?? 0);
            if (in_array($dow, $seenDays)) {
                return self::DAY_NAMES[$dow] . ': Duplicate day entry.';
            }
            $seenDays[] = $dow;
        }

        // Ensure at least one working day
        $workingDays = array_filter($weeklyData, function($d) {
            return (int)($d['is_working'] ?? 0) === 1;
        });
        if (empty($workingDays)) {
            return 'At least one working day must be selected.';
        }

        return null;
    }

    /**
     * Validate schedule settings.
     *
     * @param array $settings
     * @return string|null  Error message or null if valid
     */
    public static function validateSettings(array $settings): ?string
    {
        if (isset($settings['appointment_duration'])) {
            $duration = (int)$settings['appointment_duration'];
            if (!in_array($duration, self::VALID_DURATIONS)) {
                return 'Appointment duration must be one of: ' . implode(', ', self::VALID_DURATIONS) . ' minutes.';
            }
        }

        if (isset($settings['max_appointments_per_day'])) {
            $max = (int)$settings['max_appointments_per_day'];
            if ($max < 1) {
                return 'Maximum appointments per day must be a positive integer.';
            }
        }

        if (!empty($settings['break_start']) || !empty($settings['break_end'])) {
            $breakStart = $settings['break_start'] ?? '';
            $breakEnd = $settings['break_end'] ?? '';

            if (empty($breakStart) || empty($breakEnd)) {
                return 'Both break start and end times are required when configuring a break.';
            }

            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $breakStart)) {
                return 'Break start time must be in HH:MM format (24-hour).';
            }

            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $breakEnd)) {
                return 'Break end time must be in HH:MM format (24-hour).';
            }

            if ($breakStart >= $breakEnd) {
                return 'Break start time must be before break end time.';
            }
        }

        return null;
    }

    /**
     * Validate that break time falls within working hours.
     * Checks against all working days to ensure break is within each.
     *
     * @param array       $weeklyData
     * @param string|null $breakStart
     * @param string|null $breakEnd
     * @return string|null  Error message or null if valid
     */
    public static function validateBreakWithinHours(array $weeklyData, ?string $breakStart, ?string $breakEnd): ?string
    {
        if (empty($breakStart) || empty($breakEnd)) {
            return null; // Break is optional
        }

        foreach ($weeklyData as $day) {
            if ((int)($day['is_working'] ?? 0) !== 1) {
                continue;
            }

            $start = $day['start_time'] ?? '';
            $end = $day['end_time'] ?? '';

            if ($breakStart < $start || $breakEnd > $end) {
                $dayName = self::DAY_NAMES[(int)$day['day_of_week']] ?? 'Unknown';
                return "Break time ($breakStart - $breakEnd) must be within working hours on $dayName ($start - $end).";
            }
        }

        return null;
    }

    // ── Internal Helpers ─────────────────────────────────────

    /**
     * Ensure all 7 days exist in the weekly array.
     * Fills missing days with default non-working entries.
     *
     * @param int   $doctorId
     * @param array $weekly
     * @return array
     */
    private function ensureAllDays(int $doctorId, array $weekly): array
    {
        $existingDays = array_map(function($d) {
            return (int)$d['day_of_week'];
        }, $weekly);

        for ($dow = 1; $dow <= 7; $dow++) {
            if (!in_array($dow, $existingDays)) {
                // Insert default entry (non-working)
                $stmt = $this->db->prepare(
                    "INSERT INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
                     VALUES (?, ?, '09:00', '17:00', 0)
                     ON DUPLICATE KEY UPDATE
                         start_time = VALUES(start_time),
                         end_time = VALUES(end_time),
                         is_working = VALUES(is_working)"
                );
                $stmt->execute([$doctorId, $dow]);

                $weekly[] = [
                    'id'          => 0,
                    'day_of_week' => (string)$dow,
                    'start_time'  => '09:00',
                    'end_time'    => '17:00',
                    'is_working'  => '0',
                ];
            }
        }

        // Sort by day_of_week
        usort($weekly, function($a, $b) {
            return (int)$a['day_of_week'] - (int)$b['day_of_week'];
        });

        return $weekly;
    }

    /**
     * Upsert schedule settings for a doctor.
     * Creates row if not exists, updates specified fields.
     *
     * @param int   $doctorId
     * @param array $data
     */
    private function upsertSettings(int $doctorId, array $data): void
    {
        // First ensure settings row exists
        $stmt = $this->db->prepare(
            "INSERT INTO doctor_schedule_settings (doctor_id) VALUES (?)
             ON DUPLICATE KEY UPDATE doctor_id = doctor_id"
        );
        $stmt->execute([$doctorId]);

        // Build update fields dynamically
        $allowedFields = ['appointment_duration', 'max_appointments_per_day', 'break_start', 'break_end', 'is_available'];
        $updates = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "$field = ?";
                $params[] = $data[$field];
            }
        }

        if (!empty($updates)) {
            $updates[] = 'updated_at = NOW()';
            $params[] = $doctorId;

            $sql = 'UPDATE doctor_schedule_settings SET ' . implode(', ', $updates) . ' WHERE doctor_id = ?';
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
        }
    }

    /**
     * Upsert all weekly days for a doctor.
     *
     * @param int   $doctorId
     * @param array $weeklyData
     */
    private function upsertWeeklyDays(int $doctorId, array $weeklyData): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 start_time = VALUES(start_time),
                 end_time = VALUES(end_time),
                 is_working = VALUES(is_working),
                 updated_at = NOW()"
        );

        foreach ($weeklyData as $day) {
            $stmt->execute([
                $doctorId,
                (int)($day['day_of_week'] ?? 0),
                $day['start_time'] ?? '09:00',
                $day['end_time'] ?? '17:00',
                (int)($day['is_working'] ?? 0),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  PHASE 2 — SLOT GENERATION & BOOKING VALIDATION
    // ═══════════════════════════════════════════════════════════

    /**
     * Get available time slots for a doctor on a specific date.
     * Generates slots dynamically based on the intersection of:
     *   Doctor's working hours ∩ Hospital operating hours
     *
     * Excludes break periods, past times (for today), and already-booked slots.
     *
     * @param int    $doctorId
     * @param string $date  YYYY-MM-DD
     * @return array  Available time slots with 'time' (HH:MM) and 'label' (formatted display)
     */
    public function getAvailableSlots(int $doctorId, string $date): array
    {
        // 1. Get the doctor's schedule
        $schedule = $this->getSchedule($doctorId);
        if (!$schedule) {
            return [];
        }

        $settings = $schedule['settings'];

        // 2. Check if doctor is available
        if ((int)$settings['is_available'] !== 1) {
            return [];
        }

        // 3. Determine day of week for the requested date
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return [];
        }

        // PHP: 1 (Mon) - 7 (Sun), DateTime::N: 1 (Mon) - 7 (Sun)
        $dayOfWeek = (int)date('N', $timestamp);

        // 4. Find the working day entry
        $dayEntry = null;
        foreach ($schedule['weekly'] as $day) {
            if ((int)$day['day_of_week'] === $dayOfWeek) {
                $dayEntry = $day;
                break;
            }
        }

        // 5. If not a working day, return empty
        if (!$dayEntry || (int)$dayEntry['is_working'] !== 1) {
            return [];
        }

        // ── Hospital Hours Enforcements ──────────────────────────
        // Get current hospital operating hours
        $hospitalHours = $this->getHospitalHours();
        $hospitalOpen = $hospitalHours['open'];   // HH:MM
        $hospitalClose = $hospitalHours['close']; // HH:MM

        // Intersect doctor's working hours with hospital operating hours.
        // Bookable slots are only available in the overlap region.
        $doctorStart = $dayEntry['start_time']; // HH:MM
        $doctorEnd = $dayEntry['end_time'];     // HH:MM

        // Effective start = max(doctor_start, hospital_open)
        // Effective end   = min(doctor_end,   hospital_close)
        $effectiveStart = ($doctorStart > $hospitalOpen) ? $doctorStart : $hospitalOpen;
        $effectiveEnd   = ($doctorEnd < $hospitalClose) ? $doctorEnd : $hospitalClose;

        // No overlap at all — no slots available
        if ($effectiveStart >= $effectiveEnd) {
            return [];
        }

        $startTime = $effectiveStart;
        $endTime = $effectiveEnd;
        // ── End Hospital Hours Enforcement ──────────────────────

        $duration = (int)($settings['appointment_duration'] ?? 30);
        $maxPerDay = (int)($settings['max_appointments_per_day'] ?? 25);
        $breakStart = $settings['break_start'] ?? null;
        $breakEnd = $settings['break_end'] ?? null;

        // 6. Get already-booked appointment ranges for this doctor on this date
        // Uses stored appointment_time_range to block the entire duration
        $bookedRanges = $this->getBookedRanges($doctorId, $date);

        // 7. Generate all possible slots (using the effective hospital-intersected hours)
        $allSlots = $this->generateTimeSlots($startTime, $endTime, $duration, $breakStart, $breakEnd);

        // 8. Filter out past times and overlapping slots
        $isToday = ($date === date('Y-m-d'));
        $currentTime = date('H:i');

        $availableSlots = [];
        foreach ($allSlots as $slot) {
            $slotMinutes = $this->timeToMinutes($slot);

            // Skip past times for today
            if ($isToday && $slot < $currentTime) {
                continue;
            }

            // Skip if this slot overlaps any existing booked appointment's full time range
            if ($this->isSlotOverlapping($slotMinutes, $duration, $bookedRanges)) {
                continue;
            }

            $availableSlots[] = $slot;
        }

        // 9. Respect max appointments per day
        $maxCount = (int)($settings['max_appointments_per_day'] ?? 25);
        $remainingCapacity = $maxCount - count($bookedRanges);
        if ($remainingCapacity <= 0) {
            return []; // Fully booked
        }

        // Limit to remaining capacity
        $availableSlots = array_slice($availableSlots, 0, $remainingCapacity);

        // 10. Format for display
        return array_map(function($time) {
            return [
                'time'  => $time,
                'label' => $this->formatTimeDisplay($time),
            ];
        }, $availableSlots);
    }

    /**
     * Check if a doctor is available on a given date and time.
     * Validates against schedule, hospital hours, availability, break, existing bookings, and past times.
     *
     * @param int    $doctorId
     * @param string $date  YYYY-MM-DD
     * @param string $time  HH:MM (24-hour format)
     * @return bool
     */
    public function isTimeSlotAvailable(int $doctorId, string $date, string $time): bool
    {
        // 1. Get the doctor's schedule
        $schedule = $this->getSchedule($doctorId);
        if (!$schedule) {
            return false;
        }

        $settings = $schedule['settings'];

        // 2. Check if doctor is available
        if ((int)$settings['is_available'] !== 1) {
            return false;
        }

        // 3. Validate date is not in the past
        $timestamp = strtotime($date);
        if (!$timestamp) {
            return false;
        }

        // 4. Check if date is in the past
        if ($date < date('Y-m-d')) {
            return false;
        }

        // 5. Determine day of week
        $dayOfWeek = (int)date('N', $timestamp);

        // 6. Find the working day entry
        $dayEntry = null;
        foreach ($schedule['weekly'] as $day) {
            if ((int)$day['day_of_week'] === $dayOfWeek) {
                $dayEntry = $day;
                break;
            }
        }

        // 7. Must be a working day
        if (!$dayEntry || (int)$dayEntry['is_working'] !== 1) {
            return false;
        }

        // ── Hospital Hours Enforcement ──────────────────────────
        $hospitalHours = $this->getHospitalHours();
        $hospitalOpen = $hospitalHours['open'];
        $hospitalClose = $hospitalHours['close'];

        // Time must be within hospital operating hours
        if ($time < $hospitalOpen) {
            return false;
        }

        // The entire appointment must fit within hospital hours
        $duration = (int)($settings['appointment_duration'] ?? 30);
        $timeMinutes = $this->timeToMinutes($time);
        $closeMinutes = $this->timeToMinutes($hospitalClose);
        if ($timeMinutes + $duration > $closeMinutes) {
            return false;
        }
        // ── End Hospital Hours Enforcement ──────────────────────

        // 8. Time must be within doctor's working hours
        if ($time < $dayEntry['start_time'] || $time >= $dayEntry['end_time']) {
            return false;
        }

        // ── Break Overlap Check (Interval-Based) ────────────────
        // Use proper interval overlap: slotStart < breakEnd AND slotEnd > breakStart
        // This catches ANY overlap between the appointment interval and break interval,
        // regardless of whether the start time happens to fall within the break.
        $breakStart = $settings['break_start'] ?? null;
        $breakEnd = $settings['break_end'] ?? null;
        if ($breakStart && $breakEnd) {
            $slotStart = $this->timeToMinutes($time);
            $slotEnd = $slotStart + $duration;
            $breakStartMin = $this->timeToMinutes($breakStart);
            $breakEndMin = $this->timeToMinutes($breakEnd);
            
            if ($slotStart < $breakEndMin && $slotEnd > $breakStartMin) {
                return false;
            }
        }
        // ── End Break Overlap Check ─────────────────────────────

        // 9. Time must align with appointment duration
        $timeMinutes = $this->timeToMinutes($time);
        $startMinutes = $this->timeToMinutes($dayEntry['start_time']);
        if (($timeMinutes - $startMinutes) % $duration !== 0) {
            return false;
        }

        // 10. If today, time must not be in the past
        $isToday = ($date === date('Y-m-d'));
        if ($isToday && $time <= date('H:i')) {
            return false;
        }

        // 11. Must not overlap any existing booking's full time range
        $bookedRanges = $this->getBookedRanges($doctorId, $date);
        $timeMinutes = $this->timeToMinutes($time);
        if ($this->isSlotOverlapping($timeMinutes, $duration, $bookedRanges)) {
            return false;
        }

        // 12. Must not exceed max appointments per day
        $maxPerDay = (int)($settings['max_appointments_per_day'] ?? 25);
        if (count($bookedRanges) >= $maxPerDay) {
            return false;
        }

        return true;
    }

    /**
     * Validate a booking request against all schedule rules.
     * Returns an error message string if invalid, or null if valid.
     *
     * @param int    $doctorId
     * @param string $date  YYYY-MM-DD
     * @param string $time  HH:MM (24-hour format)
     * @return string|null  Error message or null if valid
     */
    public function validateBookingSlot(int $doctorId, string $date, string $time): ?string
    {
        // 1. Validate date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return 'Invalid date format.';
        }

        // 2. Validate time format
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return 'Invalid time format. Use HH:MM (24-hour).';
        }

        // 3. Date must not be in the past
        if ($date < date('Y-m-d')) {
            return 'Cannot book appointments in the past.';
        }

        // 4. Get schedule
        $schedule = $this->getSchedule($doctorId);
        if (!$schedule) {
            return 'Doctor schedule not found.';
        }

        $settings = $schedule['settings'];

        // 5. Doctor must be available
        if ((int)$settings['is_available'] !== 1) {
            return 'This doctor is currently unavailable.';
        }

        // 6. Must be a working day
        $timestamp = strtotime($date);
        $dayOfWeek = (int)date('N', $timestamp);

        $dayEntry = null;
        foreach ($schedule['weekly'] as $day) {
            if ((int)$day['day_of_week'] === $dayOfWeek) {
                $dayEntry = $day;
                break;
            }
        }

        if (!$dayEntry || (int)$dayEntry['is_working'] !== 1) {
            return 'The doctor does not work on this day.';
        }

        // 7. Time within doctor's working hours
        if ($time < $dayEntry['start_time'] || $time >= $dayEntry['end_time']) {
            return 'Selected time is outside working hours.';
        }

        // ── Hospital Hours Enforcement ──────────────────────────
        $hospitalHours = $this->getHospitalHours();
        $hospitalOpen = $hospitalHours['open'];
        $hospitalClose = $hospitalHours['close'];

        // Time must be at or after hospital opening
        if ($time < $hospitalOpen) {
            return 'Selected time is before hospital opening (' . $hospitalOpen . ').';
        }

        // The entire appointment duration must fit within hospital hours.
        // E.g. if hospital closes at 17:00 and duration is 30min, the latest
        // bookable start time is 16:30 (16:30 + 30min = 17:00).
        $duration = (int)($settings['appointment_duration'] ?? 30);
        $timeMinutes = $this->timeToMinutes($time);
        $closeMinutes = $this->timeToMinutes($hospitalClose);
        if ($timeMinutes + $duration > $closeMinutes) {
            $latestStart = $this->minutesToTime($closeMinutes - $duration);
            return 'This appointment would end after hospital closing (' . $hospitalClose . '). Latest start time is ' . $latestStart . '.';
        }
        // ── End Hospital Hours Enforcement ──────────────────────

        // ── Break Overlap Check (Interval-Based) ────────────────
        // Use proper interval overlap: slotStart < breakEnd AND slotEnd > breakStart
        // This catches ANY overlap between the appointment interval and break interval,
        // regardless of whether the start time happens to fall within the break.
        $breakStart = $settings['break_start'] ?? null;
        $breakEnd = $settings['break_end'] ?? null;
        if ($breakStart && $breakEnd) {
            $slotStart = $this->timeToMinutes($time);
            $slotEnd = $slotStart + $duration;
            $breakStartMin = $this->timeToMinutes($breakStart);
            $breakEndMin = $this->timeToMinutes($breakEnd);
            
            if ($slotStart < $breakEndMin && $slotEnd > $breakStartMin) {
                return 'Selected time overlaps with the doctor\'s break period.';
            }
        }
        // ── End Break Overlap Check ─────────────────────────────

        // 8. Must align with duration
        $timeMinutes = $this->timeToMinutes($time);
        $startMinutes = $this->timeToMinutes($dayEntry['start_time']);
        if (($timeMinutes - $startMinutes) % $duration !== 0) {
            return 'Selected time does not align with appointment duration.';
        }

        // 9. If today, must be in the future
        $isToday = ($date === date('Y-m-d'));
        if ($isToday && $time <= date('H:i')) {
            return 'Selected time has already passed.';
        }

        // 10. Not overlapping any existing booking's full time range
        $bookedRanges = $this->getBookedRanges($doctorId, $date);
        if ($this->isSlotOverlapping($timeMinutes, $duration, $bookedRanges)) {
            return 'This time slot overlaps an existing booking. Please choose another.';
        }

        // 11. Max capacity not exceeded
        $maxPerDay = (int)($settings['max_appointments_per_day'] ?? 25);
        if (count($bookedRanges) >= $maxPerDay) {
            return 'This day is fully booked.';
        }

        return null;
    }

    // ── Slot Generation Helpers ──────────────────────────────

    /**
     * Generate time slots between start and end times at a given duration,
     * excluding the break period.
     *
     * Uses interval-overlap logic to check whether any generated slot
     * overlaps the break: slotStart < breakEnd AND slotEnd > breakStart.
     * This works correctly for all appointment durations (15, 20, 30, 45, 60 min)
     * regardless of whether break boundaries align with slot boundaries.
     *
     * The slot end boundary ensures the appointment fully fits:
     *   last slot starts at `endTime - duration`.
     *
     * Example: endTime=17:00, duration=30 → last slot at 16:30.
     * A slot at 17:00 would start too late because 17:00 + 30min > 17:00.
     *
     * @param string      $startTime   HH:MM
     * @param string      $endTime     HH:MM
     * @param int         $duration    Minutes
     * @param string|null $breakStart  HH:MM or null
     * @param string|null $breakEnd    HH:MM or null
     * @return array  Array of time strings (HH:MM)
     */
    private function generateTimeSlots(string $startTime, string $endTime, int $duration, ?string $breakStart = null, ?string $breakEnd = null): array
    {
        $slots = [];
        $current = $this->timeToMinutes($startTime);
        $end = $this->timeToMinutes($endTime);
        $breakStartMin = $breakStart ? $this->timeToMinutes($breakStart) : null;
        $breakEndMin = $breakEnd ? $this->timeToMinutes($breakEnd) : null;

        // The condition `current + duration <= end` ensures the entire
        // appointment fits before endTime. This handles boundary correctly:
        //   endTime=17:00, duration=30 → 16:30 is last valid (16:30+30=17:00)
        //   17:00 is excluded (17:00+30=17:30 > 17:00)
        while ($current + $duration <= $end) {
            $slotTime = $this->minutesToTime($current);

            // Check if this slot interval overlaps the break interval.
            // slotStart < breakEnd AND slotEnd > breakStart
            // This catches ANY overlap, regardless of alignment.
            if ($breakStartMin !== null && $breakEndMin !== null) {
                $slotEnd = $current + $duration;
                if ($current < $breakEndMin && $slotEnd > $breakStartMin) {
                    // This slot overlaps the break. Skip to end of break,
                    // then round UP to the next duration-aligned boundary.
                    // This prevents misaligned slots like "10:50" when
                    // the cadence is 09:00, 10:00, 11:00 (60min steps).
                    $current = $breakEndMin;
                    // Round up to the next aligned slot boundary
                    $startMinutes = $this->timeToMinutes($startTime);
                    $offset = $current - $startMinutes;
                    $remainder = $offset % $duration;
                    if ($remainder > 0) {
                        $current += ($duration - $remainder);
                    }
                    continue;
                }
            }

            $slots[] = $slotTime;
            $current += $duration;
        }

        return $slots;
    }

    /**
     * Get already-booked appointment time ranges for a doctor on a specific date.
     * Uses the stored appointment_time_range if available, otherwise computes
     * from the doctor's current appointment duration. Returns ranges in minutes.
     *
     * @param int    $doctorId
     * @param string $date  YYYY-MM-DD
     * @return array  Array of ['start' => minutes, 'end' => minutes] for each booking
     */
    public function getBookedRanges(int $doctorId, string $date): array
    {
        $stmt = $this->db->prepare(
            "SELECT time, appointment_time_range FROM appointments
             WHERE doctor_id = ?
               AND date = ?
               AND status IN ('Pending', 'Confirmed')
             ORDER BY time ASC"
        );
        $stmt->execute([$doctorId, $date]);
        $rows = $stmt->fetchAll();

        $ranges = [];
        foreach ($rows as $row) {
            $normalizedTime = $this->normalizeTime($row['time']);
            $startMin = $this->timeToMinutes($normalizedTime);
            
            // Determine duration from stored range or fallback to current doctor duration
            if (!empty($row['appointment_time_range'])) {
                // Parse stored range to get duration: "9:00 AM – 9:30 AM"
                $endMin = $startMin + 30; // default
                if (preg_match('/–\s*(\d{1,2}):(\d{2})\s*(AM|PM)/i', $row['appointment_time_range'], $m)) {
                    $eh = (int)$m[1];
                    $emin = (int)$m[2];
                    $eampm = strtoupper($m[3]);
                    if ($eampm === 'PM' && $eh !== 12) $eh += 12;
                    if ($eampm === 'AM' && $eh === 12) $eh = 0;
                    $endMin = $eh * 60 + $emin;
                }
            } else {
                // No stored range — use current doctor's duration
                $duration = (int)($this->getSchedule($doctorId)['settings']['appointment_duration'] ?? 30);
                $endMin = $startMin + $duration;
            }
            
            $ranges[] = ['start' => $startMin, 'end' => $endMin];
        }

        return $ranges;
    }

    /**
     * Check if a candidate slot (in minutes) overlaps with any booked range.
     *
     * @param int $slotStart  Candidate slot start in minutes
     * @param int $duration   Candidate slot duration in minutes
     * @param array $bookedRanges  Array of ['start' => minutes, 'end' => minutes]
     * @return bool  True if overlapping
     */
    private function isSlotOverlapping(int $slotStart, int $duration, array $bookedRanges): bool
    {
        $slotEnd = $slotStart + $duration;
        foreach ($bookedRanges as $range) {
            // Check overlap: slot starts before existing ends AND slot ends after existing starts
            if ($slotStart < $range['end'] && $slotEnd > $range['start']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Convert a time string to minutes since midnight.
     *
     * @param string $time HH:MM
     * @return int
     */
    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    }

    /**
     * Convert minutes since midnight to HH:MM format.
     *
     * @param int $minutes
     * @return string
     */
    private function minutesToTime(int $minutes): string
    {
        return sprintf('%02d:%02d', (int)($minutes / 60), $minutes % 60);
    }

    /**
     * Normalize a time string to 24-hour HH:MM format.
     * Handles both "09:00 AM" and "09:00" formats.
     *
     * @param string $time
     * @return string
     */
    private function normalizeTime(string $time): string
    {
        // If already in 24-hour format (HH:MM), return as-is
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return $time;
        }

        // Try parsing 12-hour format (e.g., "09:00 AM")
        $parsed = date_parse_from_format('h:i A', $time);
        if ($parsed['error_count'] === 0 && $parsed['warning_count'] === 0) {
            return sprintf('%02d:%02d', $parsed['hour'], $parsed['minute']);
        }

        // Fallback: return as-is
        return $time;
    }

    /**
     * Format a 24-hour time string for display (e.g., "09:00" → "9:00 AM").
     *
     * @param string $time HH:MM
     * @return string
     */
    private function formatTimeDisplay(string $time): string
    {
        $parts = explode(':', $time);
        $hour = (int)$parts[0];
        $min = $parts[1] ?? '00';
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour % 12;
        if ($displayHour === 0) $displayHour = 12;
        return "{$displayHour}:{$min} {$ampm}";
    }

    // ── Phase 3 Extension Hooks ──────────────────────────────

    /**
     * Check if a date is a holiday/vacation period for a doctor.
     * (Placeholder for Phase 3 — vacation periods, hospital holidays)
     *
     * @param int    $doctorId
     * @param string $date  YYYY-MM-DD
     * @return bool
     */
    public function isDateExcluded(int $doctorId, string $date): bool
    {
        // TODO: Phase 3 — check doctor_schedule_exceptions table
        return false;
    }

    /**
     * Get the next available appointment slot for a doctor.
     * Scans forward up to 30 days, checking the weekly schedule,
     * break hours, booked appointments, and capacity.
     *
     * @param int    $doctorId
     * @param string $fromDate  YYYY-MM-DD (defaults to today if empty)
     * @return array|null  ['date' => ..., 'time' => ..., 'day_label' => ...] or null
     */
    public function getNextAvailableSlot(int $doctorId, string $fromDate = ''): ?array
    {
        $start = $fromDate ?: date('Y-m-d');

        // Scan up to 30 days forward
        $maxDays = 30;
        for ($i = 0; $i < $maxDays; $i++) {
            $date = date('Y-m-d', strtotime($start . " +{$i} days"));

            // Skip past dates
            if ($date < date('Y-m-d')) {
                continue;
            }

            $slots = $this->getAvailableSlots($doctorId, $date);
            if (!empty($slots)) {
                $slot = $slots[0];
                $timestamp = strtotime($date);
                $today = date('Y-m-d');
                $tomorrow = date('Y-m-d', strtotime('+1 day'));

                if ($date === $today) {
                    $dayLabel = 'Today';
                } elseif ($date === $tomorrow) {
                    $dayLabel = 'Tomorrow';
                } else {
                    $dayLabel = date('D', $timestamp);
                }

                return [
                    'date'     => $date,
                    'time'     => $slot['time'],
                    'day_label' => $dayLabel,
                    'display'  => $dayLabel === 'Today' || $dayLabel === 'Tomorrow'
                        ? $dayLabel . ' • ' . $slot['label']
                        : date('M j', $timestamp) . ' • ' . $slot['label'],
                ];
            }
        }

        return null;
    }

    /**
     * Get availability info for public display (used by doctors page).
     * Returns only what patients should see.
     *
     * @param int $doctorId
     * @return array  Public availability data
     */
    public function getPublicAvailability(int $doctorId): array
    {
        $schedule = $this->getSchedule($doctorId);
        if (!$schedule) {
            return [
                'available'        => false,
                'accepting_patients' => false,
                'next_available'   => null,
                'working_days'     => [],
                'weekday_names'    => [],
                'today_available'  => false,
            ];
        }

        $settings = $schedule['settings'];
        $weekly = $schedule['weekly'];
        $isAvailable = (int)$settings['is_available'] === 1;

        // Collect working day names
        $workingDays = [];
        $dayMap = [
            1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
            5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
        ];
        $fullDayMap = [
            1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
            5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
        ];

        foreach ($weekly as $day) {
            if ((int)$day['is_working'] === 1) {
                $dow = (int)$day['day_of_week'];
                $workingDays[] = $dayMap[$dow] ?? '?';
            }
        }

        // Check if today is a working day
        $todayDOW = (int)date('N');
        $todayWorking = false;
        foreach ($weekly as $day) {
            if ((int)$day['day_of_week'] === $todayDOW && (int)$day['is_working'] === 1) {
                $todayWorking = true;
                break;
            }
        }

        // Check if today has available slots
        $todaySlots = [];
        if ($isAvailable && $todayWorking) {
            $todaySlots = $this->getAvailableSlots($doctorId, date('Y-m-d'));
        }

        // Get next available slot
        $nextSlot = $isAvailable ? $this->getNextAvailableSlot($doctorId) : null;

        return [
            'available'             => $isAvailable && !empty($workingDays),
            'accepting_patients'    => $isAvailable,
            'next_available'        => $nextSlot,
            'working_days'          => $workingDays,
            'today_available'       => !empty($todaySlots),
            'appointment_duration'  => (int)($settings['appointment_duration'] ?? 30),
        ];
    }

    /**
     * Get public availability for ALL doctors in a single batch.
     * Caches results during a single request to avoid repeated DB queries.
     *
     * @param array $doctorIds  Array of user IDs (users.id)
     * @return array  Doctor ID → public availability data
     */
    public function getBatchPublicAvailability(array $doctorIds): array
    {
        $results = [];
        foreach ($doctorIds as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            $results[$id] = $this->getPublicAvailability($id);
        }
        return $results;
    }
}