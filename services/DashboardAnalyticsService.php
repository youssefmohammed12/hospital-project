<?php
/**
 * HealthBridge — Dashboard Analytics Service
 *
 * Single source of truth for all admin dashboard analytics.
 * Every method returns a structured array ready for JSON serialization.
 * All aggregation is performed inside SQL for maximum efficiency.
 *
 * Methods are modular so future widgets can reuse individual queries
 * without loading the entire dashboard.
 */

class DashboardAnalyticsService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all dashboard data in one response.
     * Assembles results from individual methods for modularity.
     */
    public function getAll(): array
    {
        return [
            'kpi'                    => $this->getKPIs(),
            'appointment_analytics'  => $this->getAppointmentAnalytics(),
            'patient_analytics'      => $this->getPatientAnalytics(),
            'doctor_analytics'       => $this->getDoctorAnalytics(),
            'department_analytics'   => $this->getDepartmentAnalytics(),
            'recent_activity'        => $this->getRecentActivity(10),
            'system_status'          => $this->getSystemStatus(),
        ];
    }

    // ─────────── KPI CARDS ───────────

    /**
     * All KPI values in a single optimized query using a UNION/derived-table approach.
     * This is more efficient than 8 separate SELECTs because MySQL optimizes
     * the single-pass aggregation.
     */
    public function getKPIs(): array
    {
        $sql = "
            SELECT
                (SELECT COUNT(*) FROM users WHERE role = 'patient') AS total_patients,
                (SELECT COUNT(*) FROM doctors) AS total_doctors,
                (SELECT COUNT(*) FROM departments WHERE status = 'active') AS total_departments,
                (SELECT COUNT(*) FROM appointments WHERE date = CURDATE()) AS today_appointments,
                (SELECT COUNT(*) FROM appointments WHERE status = 'Pending') AS pending_appointments,
                (SELECT COUNT(*) FROM appointments WHERE status = 'Confirmed') AS confirmed_appointments,
                (SELECT COUNT(*) FROM doctors WHERE available = 1) AS active_doctors,
                COALESCE((SELECT ROUND(AVG(rating), 1) FROM doctors), 0) AS avg_rating,
                (SELECT COUNT(*) FROM users WHERE role = 'patient' AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())) AS new_patients_this_month
        ";
        $stmt = $this->db->query($sql);
        return $stmt->fetch() ?: [];
    }

    // ─────────── APPOINTMENT ANALYTICS ───────────

    public function getAppointmentAnalytics(): array
    {
        // Weekly appointments (last 7 days including today)
        $weeklySql = "
            SELECT DAYNAME(date) AS day, DAYOFWEEK(date) AS day_num, COUNT(*) AS count
            FROM appointments
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DAYOFWEEK(date), DAYNAME(date)
            ORDER BY DAYOFWEEK(date)
        ";
        $weekly = $this->db->query($weeklySql)->fetchAll();

        // Monthly appointments (last 6 months)
        $monthlySql = "
            SELECT DATE_FORMAT(date, '%Y-%m') AS month, COUNT(*) AS count
            FROM appointments
            WHERE date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
            GROUP BY DATE_FORMAT(date, '%Y-%m')
            ORDER BY month ASC
        ";
        $monthly = $this->db->query($monthlySql)->fetchAll();

        // Status distribution
        $statusSql = "
            SELECT COALESCE(status, 'Pending') AS status, COUNT(*) AS count
            FROM appointments
            GROUP BY status
        ";
        $statusDist = $this->db->query($statusSql)->fetchAll();

        // Completion rate: completed visits / total confirmed+completed appointments
        $rateSql = "
            SELECT
                COUNT(*) AS total_confirmed,
                SUM(CASE WHEN vw.status = 'Completed' THEN 1 ELSE 0 END) AS completed
            FROM appointments a
            LEFT JOIN visit_workflow vw ON vw.appointment_id = a.id
            WHERE a.status IN ('Confirmed')
        ";
        $rateData = $this->db->query($rateSql)->fetch();
        $completionRate = 0;
        if ($rateData && (int)$rateData['total_confirmed'] > 0) {
            $completionRate = round(((int)$rateData['completed'] / (int)$rateData['total_confirmed']) * 100, 1);
        }

        return [
            'weekly'              => $weekly,
            'monthly'             => $monthly,
            'status_distribution' => $statusDist,
            'completion_rate'     => $completionRate,
        ];
    }

    // ─────────── PATIENT ANALYTICS ───────────

    public function getPatientAnalytics(): array
    {
        // Gender distribution
        $genderSql = "
            SELECT COALESCE(NULLIF(gender, ''), 'Not specified') AS gender, COUNT(*) AS count
            FROM medical_records
            GROUP BY gender
            ORDER BY count DESC
        ";
        $genderDist = $this->db->query($genderSql)->fetchAll();

        // Age distribution using date_of_birth
        $ageSql = "
            SELECT
                CASE
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 0 AND 18 THEN '0-18'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 19 AND 30 THEN '19-30'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 31 AND 45 THEN '31-45'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) BETWEEN 46 AND 60 THEN '46-60'
                    WHEN TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()) > 60 THEN '60+'
                    ELSE 'Unknown'
                END AS age_range,
                COUNT(*) AS count
            FROM medical_records
            WHERE date_of_birth IS NOT NULL
            GROUP BY age_range
            ORDER BY MIN(TIMESTAMPDIFF(YEAR, date_of_birth, CURDATE()))
        ";
        $ageDist = $this->db->query($ageSql)->fetchAll();

        // Most active patients (top 5 by appointment count)
        $activeSql = "
            SELECT u.name, u.email, COUNT(a.id) AS appointment_count
            FROM users u
            JOIN appointments a ON a.user_id = u.id
            WHERE u.role = 'patient'
            GROUP BY u.id, u.name, u.email
            ORDER BY appointment_count DESC
            LIMIT 5
        ";
        $activePatients = $this->db->query($activeSql)->fetchAll();

        return [
            'gender_distribution' => $genderDist,
            'age_distribution'    => $ageDist,
            'most_active'         => $activePatients,
        ];
    }

    // ─────────── DOCTOR ANALYTICS ───────────

    public function getDoctorAnalytics(): array
    {
        // Highest rated doctors
        $ratedSql = "
            SELECT d.id, d.name, d.specialty, d.rating, d.exp,
                   COALESCE((SELECT COUNT(*) FROM ratings r WHERE r.doctor_id = d.id), 0) AS review_count,
                   COALESCE((SELECT COUNT(*) FROM appointments a WHERE a.doctor_id = d.id), 0) AS total_appointments
            FROM doctors d
            ORDER BY d.rating DESC
            LIMIT 5
        ";
        $highestRated = $this->db->query($ratedSql)->fetchAll();

        // Busiest doctors (most appointments)
        $busySql = "
            SELECT d.id, d.name, d.specialty, COUNT(a.id) AS appointment_count
            FROM doctors d
            LEFT JOIN appointments a ON a.doctor_id = d.id
            GROUP BY d.id, d.name, d.specialty
            ORDER BY appointment_count DESC
            LIMIT 5
        ";
        $busiestDoctors = $this->db->query($busySql)->fetchAll();

        // Available/unavailable counts (single query)
        $availSql = "
            SELECT
                SUM(CASE WHEN available = 1 THEN 1 ELSE 0 END) AS available_today,
                SUM(CASE WHEN available = 0 THEN 1 ELSE 0 END) AS unavailable_today
            FROM doctors
        ";
        $availData = $this->db->query($availSql)->fetch();

        return [
            'highest_rated'     => $highestRated,
            'busiest_doctors'   => $busiestDoctors,
            'available_today'   => (int)($availData['available_today'] ?? 0),
            'unavailable_today' => (int)($availData['unavailable_today'] ?? 0),
        ];
    }

    // ─────────── DEPARTMENT ANALYTICS ───────────

    public function getDepartmentAnalytics(): array
    {
        $sql = "
            SELECT
                d.id,
                d.name,
                d.status,
                COUNT(DISTINCT doc.id) AS doctor_count,
                COUNT(DISTINCT a.id) AS appointment_count,
                COUNT(DISTINCT a.user_id) AS patient_count
            FROM departments d
            LEFT JOIN doctors doc ON doc.department_id = d.id
            LEFT JOIN appointments a ON a.department_id = d.id
            GROUP BY d.id, d.name, d.status
            ORDER BY appointment_count DESC
        ";
        return $this->db->query($sql)->fetchAll();
    }

    // ─────────── RECENT ACTIVITY (from admin_audit) ───────────

    public function getRecentActivity(int $limit = 10): array
    {
        $sql = "
            SELECT
                a.id,
                a.action,
                a.entity_type,
                a.entity_id,
                a.description,
                a.created_at,
                a.actor_role,
                COALESCE(u.name, CONCAT('Deleted ', UPPER(SUBSTRING(a.actor_role, 1, 1)), SUBSTRING(a.actor_role, 2))) AS actor_name,
                a.patient_id,
                a.doctor_id
            FROM admin_audit a
            LEFT JOIN users u ON a.actor_id = u.id
            ORDER BY a.created_at DESC
            LIMIT ?
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        $activities = $stmt->fetchAll();

        // Add relative timestamps for frontend rendering
        foreach ($activities as &$activity) {
            $activity['time_ago'] = $this->relativeTime($activity['created_at']);
        }
        unset($activity);

        return $activities;
    }

    // ─────────── SYSTEM STATUS ───────────

    public function getSystemStatus(): array
    {
        // Determine if hospital is open based on settings and current time
        $hospitalSql = "
            SELECT hospital_name, appointment_open_time, appointment_close_time
            FROM hospital_settings
            LIMIT 1
        ";
        $stmt = $this->db->query($hospitalSql);
        $hospital = $stmt->fetch();

        $currentHour = (int)date('G');
        $currentMin  = (int)date('i');
        $currentTimeMinutes = $currentHour * 60 + $currentMin;

        $isOpen = false;
        $openTimeDisplay = '08:00';
        $closeTimeDisplay = '22:00';

        if ($hospital) {
            $openTimeDisplay = $hospital['appointment_open_time'] ?? '08:00';
            $closeTimeDisplay = $hospital['appointment_close_time'] ?? '22:00';

            if (preg_match('/^(\d{1,2}):(\d{2})$/', $openTimeDisplay, $om)
                && preg_match('/^(\d{1,2}):(\d{2})$/', $closeTimeDisplay, $cm)) {
                $openMinutes = (int)$om[1] * 60 + (int)$om[2];
                $closeMinutes = (int)$cm[1] * 60 + (int)$cm[2];
                $isOpen = $currentTimeMinutes >= $openMinutes && $currentTimeMinutes < $closeMinutes;
            }
        }

        // Unread notification count (for admin user — passed as context)
        // We'll compute a general count; frontend will get per-user count
        $notifSql = "SELECT COUNT(*) AS count FROM notifications WHERE is_read = 0";
        $notifCount = (int)$this->db->query($notifSql)->fetchColumn();

        return [
            'hospital_name'     => $hospital['hospital_name'] ?? 'HealthBridge Hospital',
            'is_open'           => $isOpen,
            'open_time'         => $openTimeDisplay,
            'close_time'        => $closeTimeDisplay,
            'current_time'      => date('Y-m-d H:i:s'),
            'current_time_formatted' => date('l, F j, Y · g:i A'),
            'unread_count'      => $notifCount,
        ];
    }

    // ─────────── HELPERS ───────────

    /**
     * Convert a MySQL datetime to a human-readable relative time string.
     */
    private function relativeTime(string $datetime): string
    {
        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return 'Unknown';
        }

        $diff = time() - $timestamp;

        if ($diff < 60) {
            return $diff <= 5 ? 'Just now' : $diff . ' seconds ago';
        }
        if ($diff < 3600) {
            $mins = floor($diff / 60);
            return $mins . ' minute' . ($mins !== 1 ? 's' : '') . ' ago';
        }
        if ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours !== 1 ? 's' : '') . ' ago';
        }
        if ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days !== 1 ? 's' : '') . ' ago';
        }

        return date('M j, Y', $timestamp);
    }
}