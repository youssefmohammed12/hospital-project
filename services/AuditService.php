<?php
/**
 * HealthBridge — Universal Audit Service
 *
 * Records meaningful actions performed by any authenticated user
 * (admin, doctor, patient) with structured old/new values and
 * human-readable descriptions.
 *
 * Usage:
 *   $audit = new AuditService(getDB(), $userId, $userRole);
 *   $audit->log('update_schedule', 'doctor', $doctorId, $old, $new, 'Changed Monday hours');
 *
 * Backward compatible: AdminAuditService extends this class.
 */

class AuditService
{
    protected PDO $db;
    protected int $actorId;
    protected string $actorRole;

    /**
     * @param PDO    $db        Database connection
     * @param int    $actorId   User ID of the acting user (from server session, never from frontend)
     * @param string $actorRole Role of the acting user: 'admin', 'doctor', or 'patient'
     */
    public function __construct(PDO $db, int $actorId, string $actorRole = 'admin')
    {
        $this->db = $db;
        $this->actorId = $actorId;
        $this->actorRole = in_array($actorRole, ['admin', 'doctor', 'patient'], true) ? $actorRole : 'admin';
    }

    /**
     * Log an action to the audit trail.
     *
     * @param string       $action      The action performed (e.g., 'update_schedule', 'activate', 'book')
     * @param string       $entityType  Type of entity (e.g., 'doctor', 'appointment', 'department')
     * @param int|null     $entityId    ID of the entity affected
     * @param mixed|null   $oldValue    Previous value (string, array, or null). Arrays are JSON-encoded.
     * @param mixed|null   $newValue    New value (string, array, or null). Arrays are JSON-encoded.
     * @param string|null  $description Human-readable summary of what changed (never raw JSON)
     * @param int|null     $patientId   Contextual patient user ID (users.id) for cross-entity queries
     * @param int|null     $doctorId    Contextual doctor user ID (users.id) for cross-entity queries
     * @return bool
     */
    public function log(string $action, string $entityType, ?int $entityId, $oldValue = null, $newValue = null, ?string $description = null, ?int $patientId = null, ?int $doctorId = null): bool
    {
        // Strip passwords recursively to prevent exposing credentials
        $oldValue = $this->stripPasswords($oldValue);
        $newValue = $this->stripPasswords($newValue);

        // Convert arrays to JSON for storage
        if (is_array($oldValue)) {
            $oldValue = json_encode($oldValue);
        }
        if (is_array($newValue)) {
            $newValue = json_encode($newValue);
        }

        // Get client info
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        try {
            $stmt = $this->db->prepare(
                "INSERT INTO admin_audit (actor_id, actor_role, action, entity_type, entity_id, patient_id, doctor_id, old_value, new_value, description, ip_address, user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $this->actorId,
                $this->actorRole,
                $action,
                $entityType,
                $entityId,
                $patientId,
                $doctorId,
                $oldValue,
                $newValue,
                $description,
                $ipAddress,
                $userAgent
            ]);

            return true;
        } catch (Exception $e) {
            error_log('Audit Log Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Log a schedule change with normalized comparison to avoid false diffs.
     * Normalizes weekly data by removing IDs, timestamps, and sorting by day_of_week.
     *
     * @param int   $doctorId
     * @param array $oldSchedule Raw old schedule from getSchedule()
     * @param array $newSchedule Raw new schedule data being saved
     * @return bool
     */
    public function logScheduleChange(int $doctorId, array $oldSchedule, array $newSchedule): bool
    {
        // Normalize old weekly: keep only meaningful fields, sort by day
        $oldWeekly = $this->normalizeWeekly($oldSchedule['weekly'] ?? []);
        $newWeekly = $this->normalizeWeekly($newSchedule['weekly'] ?? []);

        // Normalize old settings: keep only meaningful fields
        $oldSettings = $this->normalizeSettings($oldSchedule['settings'] ?? []);
        $newSettings = $this->normalizeSettings($newSchedule['settings'] ?? []);

        // Build human-readable description of actual changes
        $changes = $this->buildScheduleDiffDescription($oldWeekly, $newWeekly, $oldSettings, $newSettings);

        if (empty($changes)) {
            return false; // No meaningful change — don't log
        }

        $description = 'Schedule updated: ' . implode('; ', $changes);

        return $this->log(
            'update_schedule',
            'doctor',
            $doctorId,
            ['weekly' => $oldWeekly, 'settings' => $oldSettings],
            ['weekly' => $newWeekly, 'settings' => $newSettings],
            $description,
            null,
            $doctorId
        );
    }

    /**
     * Normalize weekly data: keep only day_of_week, start_time, end_time, is_working.
     * Sort by day_of_week to ensure consistent comparison.
     */
    private function normalizeWeekly(array $weekly): array
    {
        $normalized = [];
        foreach ($weekly as $day) {
            $normalized[] = [
                'day_of_week' => (int)($day['day_of_week'] ?? 0),
                'start_time'  => $day['start_time'] ?? '09:00',
                'end_time'    => $day['end_time'] ?? '17:00',
                'is_working'  => (int)($day['is_working'] ?? 0),
            ];
        }
        usort($normalized, fn($a, $b) => $a['day_of_week'] - $b['day_of_week']);
        return $normalized;
    }

    /**
     * Normalize settings: keep only meaningful config fields.
     */
    private function normalizeSettings(array $settings): array
    {
        return [
            'appointment_duration'    => (int)($settings['appointment_duration'] ?? 30),
            'max_appointments_per_day' => (int)($settings['max_appointments_per_day'] ?? 25),
            'break_start'             => $settings['break_start'] ?? null,
            'break_end'               => $settings['break_end'] ?? null,
            'is_available'            => (int)($settings['is_available'] ?? 1),
        ];
    }

    /**
     * Build human-readable change descriptions by comparing old and new normalized data.
     * Returns array of change strings (empty if no meaningful changes).
     */
    private function buildScheduleDiffDescription(array $oldWeekly, array $newWeekly, array $oldSettings, array $newSettings): array
    {
        $changes = [];
        $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        // Compare weekly days
        $allDays = array_unique(array_merge(array_column($oldWeekly, 'day_of_week'), array_column($newWeekly, 'day_of_week')));
        sort($allDays);

        foreach ($allDays as $d) {
            $oldDay = $this->findDay($oldWeekly, $d);
            $newDay = $this->findDay($newWeekly, $d);
            $dayName = $dayNames[$d] ?? "Day $d";

            $oldWorking = $oldDay ? $oldDay['is_working'] : 0;
            $newWorking = $newDay ? $newDay['is_working'] : 0;

            if ($oldWorking !== $newWorking) {
                $changes[] = "$dayName: " . ($oldWorking ? 'Working' : 'Off') . ' → ' . ($newWorking ? 'Working' : 'Off');
                continue; // Don't also report time changes for a day that flipped
            }

            if (!$oldWorking && !$newWorking) {
                continue; // Both off — no time to compare
            }

            $oldStart = $oldDay['start_time'] ?? '09:00';
            $newStart = $newDay['start_time'] ?? '09:00';
            $oldEnd = $oldDay['end_time'] ?? '17:00';
            $newEnd = $newDay['end_time'] ?? '17:00';

            if ($oldStart !== $newStart) {
                $changes[] = "$dayName start: $oldStart → $newStart";
            }
            if ($oldEnd !== $newEnd) {
                $changes[] = "$dayName end: $oldEnd → $newEnd";
            }
        }

        // Compare settings
        if (($oldSettings['appointment_duration'] ?? 30) !== ($newSettings['appointment_duration'] ?? 30)) {
            $changes[] = 'Duration: ' . ($oldSettings['appointment_duration'] ?? 30) . ' min → ' . ($newSettings['appointment_duration'] ?? 30) . ' min';
        }
        if (($oldSettings['max_appointments_per_day'] ?? 25) !== ($newSettings['max_appointments_per_day'] ?? 25)) {
            $changes[] = 'Max/day: ' . ($oldSettings['max_appointments_per_day'] ?? 25) . ' → ' . ($newSettings['max_appointments_per_day'] ?? 25);
        }
        $oldBreak = ($oldSettings['break_start'] ?? null) && ($oldSettings['break_end'] ?? null)
            ? ($oldSettings['break_start'] . '-' . $oldSettings['break_end'])
            : 'None';
        $newBreak = ($newSettings['break_start'] ?? null) && ($newSettings['break_end'] ?? null)
            ? ($newSettings['break_start'] . '-' . $newSettings['break_end'])
            : 'None';
        if ($oldBreak !== $newBreak) {
            $changes[] = "Break: $oldBreak → $newBreak";
        }
        if (($oldSettings['is_available'] ?? 1) !== ($newSettings['is_available'] ?? 1)) {
            $avail = fn($v) => ($v ?? 1) ? 'Available' : 'Unavailable';
            $changes[] = 'Availability: ' . $avail($oldSettings['is_available']) . ' → ' . $avail($newSettings['is_available']);
        }

        return $changes;
    }

    private function findDay(array $weekly, int $dayOfWeek): ?array
    {
        foreach ($weekly as $day) {
            if ((int)($day['day_of_week'] ?? 0) === $dayOfWeek) {
                return $day;
            }
        }
        return null;
    }

    // ── Query Methods ──────────────────────────────────────────

    /**
     * Get audit log entries with optional filtering, search, and pagination.
     *
     * @param array $filters Optional: search, action, entity_type, entity_id, actor_id, actor_role, date_from, date_to, page, limit
     * @return array ['entries' => [...], 'total' => int, 'page' => int, 'per_page' => int, 'total_pages' => int]
     */
    public function getAuditLog(array $filters = []): array
    {
        $allowedEntityTypes = ['department', 'hospital_settings', 'doctor', 'patient', 'appointment', 'prescription'];

        // Perform LEFT JOIN users so that logs remain visible and searchable even if the user/actor is deleted
        $countSql = "SELECT COUNT(*) as total FROM admin_audit a LEFT JOIN users u ON a.actor_id = u.id WHERE 1=1";
        $dataSql = "SELECT a.*, COALESCE(u.name, CONCAT('Deleted ', UPPER(SUBSTRING(a.actor_role, 1, 1)), SUBSTRING(a.actor_role, 2))) as actor_name
                    FROM admin_audit a
                    LEFT JOIN users u ON a.actor_id = u.id
                    WHERE 1=1";

        $params = [];

        // Search across multiple fields
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $searchClause = " AND (a.action LIKE ? OR a.entity_type LIKE ? OR u.name LIKE ? OR a.description LIKE ?)";
            $countSql .= $searchClause;
            $dataSql .= $searchClause;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Action filter
        if (!empty($filters['action'])) {
            $action = preg_replace('/[^a-z_]/', '', strtolower($filters['action']));
            if ($action !== '') {
                $countSql .= " AND a.action = ?";
                $dataSql .= " AND a.action = ?";
                $params[] = $action;
            }
        }

        // Entity type filter
        if (!empty($filters['entity_type'])) {
            $et = strtolower($filters['entity_type']);
            if (in_array($et, $allowedEntityTypes, true)) {
                $countSql .= " AND a.entity_type = ?";
                $dataSql .= " AND a.entity_type = ?";
                $params[] = $et;
            }
        }

        // Entity ID filter
        if (!empty($filters['entity_id'])) {
            $entityId = (int)$filters['entity_id'];
            $countSql .= " AND a.entity_id = ?";
            $dataSql .= " AND a.entity_id = ?";
            $params[] = $entityId;
        }

        // Patient ID filter (contextual — shows all activity for a patient)
        if (!empty($filters['patient_id'])) {
            $patientId = (int)$filters['patient_id'];
            $countSql .= " AND a.patient_id = ?";
            $dataSql .= " AND a.patient_id = ?";
            $params[] = $patientId;
        }

        // Doctor ID filter (contextual — shows all activity for a doctor)
        if (!empty($filters['doctor_id'])) {
            $doctorId = (int)$filters['doctor_id'];
            $countSql .= " AND a.doctor_id = ?";
            $dataSql .= " AND a.doctor_id = ?";
            $params[] = $doctorId;
        }

        // Actor filter (supports admin_id alias for backward compatibility)
        $actorId = !empty($filters['actor_id']) ? (int)$filters['actor_id'] : (!empty($filters['admin_id']) ? (int)$filters['admin_id'] : 0);
        if ($actorId > 0) {
            $countSql .= " AND a.actor_id = ?";
            $dataSql .= " AND a.actor_id = ?";
            $params[] = $actorId;
        }

        // Actor role filter
        if (!empty($filters['actor_role'])) {
            $role = strtolower($filters['actor_role']);
            if (in_array($role, ['admin', 'doctor', 'patient'], true)) {
                $countSql .= " AND a.actor_role = ?";
                $dataSql .= " AND a.actor_role = ?";
                $params[] = $role;
            }
        }

        // Date range filters
        if (!empty($filters['date_from'])) {
            $d = trim($filters['date_from']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $countSql .= " AND a.created_at >= ?";
                $dataSql .= " AND a.created_at >= ?";
                $params[] = $d . ' 00:00:00';
            }
        }

        if (!empty($filters['date_to'])) {
            $d = trim($filters['date_to']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $countSql .= " AND a.created_at <= ?";
                $dataSql .= " AND a.created_at <= ?";
                $params[] = $d . ' 23:59:59';
            }
        }

        // Get total count
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Get data with ordering and pagination
        $dataSql .= " ORDER BY a.created_at DESC";

        $limit = !empty($filters['limit']) ? max(1, min(100, (int)$filters['limit'])) : 20;
        $page = !empty($filters['page']) ? max(1, (int)$filters['page']) : 1;
        $offset = ($page - 1) * $limit;

        $dataSql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;

        $stmt = $this->db->prepare($dataSql);
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        return [
            'entries' => $entries,
            'total' => $total,
            'page' => $page,
            'per_page' => $limit,
            'total_pages' => max(1, (int)ceil($total / $limit)),
        ];
    }

    /**
     * Get audit log for a specific entity.
     */
    public function getEntityAuditLog(string $entityType, int $entityId): array
    {
        return $this->getAuditLog([
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);
    }

    /**
     * Get distinct entity types that exist in the audit log.
     */
    public function getDistinctEntityTypes(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT entity_type FROM admin_audit ORDER BY entity_type ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get distinct actions that exist in the audit log.
     */
    public function getDistinctActions(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT action FROM admin_audit ORDER BY action ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Recursively strip passwords and sensitive hashes from audit data values.
     */
    private function stripPasswords($value)
    {
        if (is_array($value)) {
            foreach ($value as $key => $val) {
                if (in_array(strtolower($key), ['password', 'password_hash', 'pass', 'hash'], true)) {
                    $value[$key] = '[REDACTED]';
                } else {
                    $value[$key] = $this->stripPasswords($val);
                }
            }
        } elseif (is_string($value)) {
            // Check if string is a valid JSON string
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $decoded = $this->stripPasswords($decoded);
                return json_encode($decoded);
            }
        }
        return $value;
    }
}