<?php
/**
 * HealthBridge — Dashboard Analytics Service
 * Schema-aware admin analytics with a stable v1/v2 response DTO.
 * Runtime callers continue to default to v1 until the approved cutover.
 */
require_once __DIR__ . '/../includes/RelativeTimeFormatter.php';

class DashboardAnalyticsService
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';
    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema = self::SCHEMA_V1)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('DashboardAnalyticsService schema must be explicitly v1 or v2.');
        }
        $this->db = $db;
        $this->schema = $schema;
    }

    public function getAll(): array
    {
        return [
            'kpi' => $this->getKPIs(),
            'appointment_analytics' => $this->getAppointmentAnalytics(),
            'patient_analytics' => $this->getPatientAnalytics(),
            'doctor_analytics' => $this->getDoctorAnalytics(),
            'department_analytics' => $this->getDepartmentAnalytics(),
            'recent_activity' => $this->getRecentActivity(10),
            'system_status' => $this->getSystemStatus(),
        ];
    }

    public function getKPIs(): array
    {
        $sql = $this->schema === self::SCHEMA_V1
            ? "SELECT
                (SELECT COUNT(*) FROM users WHERE role='patient') AS total_patients,
                (SELECT COUNT(*) FROM doctors) AS total_doctors,
                (SELECT COUNT(*) FROM departments WHERE status='active') AS total_departments,
                (SELECT COUNT(*) FROM appointments WHERE date=CURDATE()) AS today_appointments,
                (SELECT COUNT(*) FROM appointments WHERE status='Pending') AS pending_appointments,
                (SELECT COUNT(*) FROM appointments WHERE status='Confirmed') AS confirmed_appointments,
                (SELECT COUNT(*) FROM doctors WHERE available=1) AS active_doctors,
                COALESCE((SELECT ROUND(AVG(rating),1) FROM doctors),0) AS avg_rating,
                (SELECT COUNT(*) FROM users WHERE role='patient'
                  AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())) AS new_patients_this_month"
            : "SELECT
                (SELECT COUNT(*) FROM users WHERE role='patient') AS total_patients,
                (SELECT COUNT(*) FROM doctor_profiles) AS total_doctors,
                (SELECT COUNT(*) FROM departments WHERE status='active') AS total_departments,
                (SELECT COUNT(*) FROM appointments WHERE starts_at>=CURDATE()
                  AND starts_at<CURDATE()+INTERVAL 1 DAY) AS today_appointments,
                (SELECT COUNT(*) FROM appointments WHERE booking_status='pending') AS pending_appointments,
                (SELECT COUNT(*) FROM appointments a WHERE a.booking_status='confirmed'
                  AND NOT EXISTS (SELECT 1 FROM appointment_reschedule_requests r
                    WHERE r.appointment_id=a.id AND r.request_status IN ('requested','suggested'))) AS confirmed_appointments,
                (SELECT COUNT(*) FROM doctor_schedule_settings WHERE is_available=1) AS active_doctors,
                COALESCE((SELECT ROUND(AVG(stars),1) FROM ratings),0) AS avg_rating,
                (SELECT COUNT(*) FROM users WHERE role='patient'
                  AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())) AS new_patients_this_month";
        return $this->db->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function getAppointmentAnalytics(): array
    {
        if ($this->schema === self::SCHEMA_V1) {
            $weeklySql = "SELECT DAYNAME(date) AS day,DAYOFWEEK(date) AS day_num,COUNT(*) AS count
                FROM appointments WHERE date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
                GROUP BY DAYOFWEEK(date),DAYNAME(date) ORDER BY DAYOFWEEK(date)";
            $monthlySql = "SELECT DATE_FORMAT(date,'%Y-%m') AS month,COUNT(*) AS count
                FROM appointments WHERE date>=DATE_SUB(CURDATE(),INTERVAL 5 MONTH)
                GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY month ASC";
            $statusSql = "SELECT COALESCE(status,'Pending') AS status,COUNT(*) AS count
                FROM appointments GROUP BY status";
            $rateSql = "SELECT COUNT(*) AS total_confirmed,
                SUM(CASE WHEN vw.status='Completed' THEN 1 ELSE 0 END) AS completed
                FROM appointments a LEFT JOIN visit_workflow vw ON vw.appointment_id=a.id
                WHERE a.status='Confirmed'";
        } else {
            $weeklySql = "SELECT DAYNAME(DATE(starts_at)) AS day,DAYOFWEEK(DATE(starts_at)) AS day_num,COUNT(*) AS count
                FROM appointments WHERE starts_at>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)
                  AND starts_at<CURDATE()+INTERVAL 1 DAY
                GROUP BY DAYOFWEEK(DATE(starts_at)),DAYNAME(DATE(starts_at))
                ORDER BY DAYOFWEEK(DATE(starts_at))";
            $monthlySql = "SELECT DATE_FORMAT(starts_at,'%Y-%m') AS month,COUNT(*) AS count
                FROM appointments WHERE starts_at>=DATE_SUB(CURDATE(),INTERVAL 5 MONTH)
                GROUP BY DATE_FORMAT(starts_at,'%Y-%m') ORDER BY month ASC";
            $statusSql = "SELECT CASE
                    WHEN EXISTS (SELECT 1 FROM appointment_reschedule_requests r
                        WHERE r.appointment_id=appointments.id AND r.request_status IN ('requested','suggested'))
                        THEN 'Reschedule Requested'
                    WHEN booking_status='pending' THEN 'Pending'
                    WHEN booking_status='confirmed' THEN 'Confirmed'
                    WHEN booking_status='cancelled' THEN 'Cancelled'
                    ELSE 'Unknown' END AS status,COUNT(*) AS count
                FROM appointments GROUP BY status";
            $rateSql = "SELECT COUNT(*) AS total_confirmed,
                SUM(CASE WHEN ce.workflow_status='completed' THEN 1 ELSE 0 END) AS completed
                FROM appointments a LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
                WHERE a.booking_status='confirmed'
                  AND NOT EXISTS (SELECT 1 FROM appointment_reschedule_requests r
                    WHERE r.appointment_id=a.id AND r.request_status IN ('requested','suggested'))";
        }
        $weekly = $this->db->query($weeklySql)->fetchAll(PDO::FETCH_ASSOC);
        $monthly = $this->db->query($monthlySql)->fetchAll(PDO::FETCH_ASSOC);
        $status = $this->db->query($statusSql)->fetchAll(PDO::FETCH_ASSOC);
        $rate = $this->db->query($rateSql)->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int)($rate['total_confirmed'] ?? 0);
        $completed = (int)($rate['completed'] ?? 0);
        return [
            'weekly' => $weekly,
            'monthly' => $monthly,
            'status_distribution' => $status,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0.0,
        ];
    }

    public function getPatientAnalytics(): array
    {
        if ($this->schema === self::SCHEMA_V1) {
            $genderSql = "SELECT COALESCE(NULLIF(gender,''),'Not specified') AS gender,COUNT(*) AS count
                FROM medical_records GROUP BY gender ORDER BY count DESC";
            $ageSql = "SELECT CASE
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 0 AND 18 THEN '0-18'
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 19 AND 30 THEN '19-30'
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 31 AND 45 THEN '31-45'
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 46 AND 60 THEN '46-60'
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE())>60 THEN '60+' ELSE 'Unknown' END AS age_range,
                    COUNT(*) AS count FROM medical_records WHERE date_of_birth IS NOT NULL
                GROUP BY age_range ORDER BY MIN(TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()))";
            $activeSql = "SELECT u.name,u.email,COUNT(a.id) AS appointment_count
                FROM users u JOIN appointments a ON a.user_id=u.id WHERE u.role='patient'
                GROUP BY u.id,u.name,u.email ORDER BY appointment_count DESC LIMIT 5";
        } else {
            $genderSql = "SELECT COALESCE(NULLIF(gender,''),'Not specified') AS gender,COUNT(*) AS count
                FROM patient_profiles GROUP BY gender ORDER BY count DESC";
            $ageSql = "SELECT CASE
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 0 AND 18 THEN '0-18'
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 19 AND 30 THEN '19-30'
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 31 AND 45 THEN '31-45'
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) BETWEEN 46 AND 60 THEN '46-60'
                    WHEN TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE())>60 THEN '60+' ELSE 'Unknown' END AS age_range,
                    COUNT(*) AS count FROM patient_profiles WHERE date_of_birth IS NOT NULL
                GROUP BY age_range ORDER BY MIN(TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()))";
            $activeSql = "SELECT u.name,u.email,COUNT(a.id) AS appointment_count
                FROM users u JOIN appointments a ON a.patient_user_id=u.id WHERE u.role='patient'
                GROUP BY u.id,u.name,u.email ORDER BY appointment_count DESC LIMIT 5";
        }
        return [
            'gender_distribution' => $this->db->query($genderSql)->fetchAll(PDO::FETCH_ASSOC),
            'age_distribution' => $this->db->query($ageSql)->fetchAll(PDO::FETCH_ASSOC),
            'most_active' => $this->db->query($activeSql)->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public function getDoctorAnalytics(): array
    {
        if ($this->schema === self::SCHEMA_V1) {
            $ratedSql = "SELECT d.id,d.name,d.specialty,d.rating,d.exp,
                COALESCE((SELECT COUNT(*) FROM ratings r WHERE r.doctor_id=d.id),0) AS review_count,
                COALESCE((SELECT COUNT(*) FROM appointments a WHERE a.doctor_id=d.id),0) AS total_appointments
                FROM doctors d ORDER BY d.rating DESC LIMIT 5";
            $busySql = "SELECT d.id,d.name,d.specialty,COUNT(a.id) AS appointment_count
                FROM doctors d LEFT JOIN appointments a ON a.doctor_id=d.id
                GROUP BY d.id,d.name,d.specialty ORDER BY appointment_count DESC LIMIT 5";
            $availabilitySql = "SELECT
                SUM(CASE WHEN available=1 THEN 1 ELSE 0 END) AS available_today,
                SUM(CASE WHEN available=0 THEN 1 ELSE 0 END) AS unavailable_today FROM doctors";
        } else {
            $ratedSql = "SELECT d.doctor_user_id AS id,u.name,d.specialty,
                COALESCE(r.average_rating,0) AS rating,d.experience_years AS exp,
                COALESCE(r.rating_count,0) AS review_count,COALESCE(a.total_appointments,0) AS total_appointments
                FROM doctor_profiles d JOIN users u ON u.id=d.doctor_user_id
                LEFT JOIN v_doctor_rating_summary r ON r.doctor_user_id=d.doctor_user_id
                LEFT JOIN (SELECT doctor_user_id,COUNT(*) AS total_appointments FROM appointments GROUP BY doctor_user_id) a
                  ON a.doctor_user_id=d.doctor_user_id
                ORDER BY rating DESC,u.name ASC LIMIT 5";
            $busySql = "SELECT d.doctor_user_id AS id,u.name,d.specialty,COUNT(a.id) AS appointment_count
                FROM doctor_profiles d JOIN users u ON u.id=d.doctor_user_id
                LEFT JOIN appointments a ON a.doctor_user_id=d.doctor_user_id
                GROUP BY u.id,d.doctor_user_id,u.name,d.specialty
                ORDER BY appointment_count DESC,u.name ASC LIMIT 5";
            $availabilitySql = "SELECT
                SUM(CASE WHEN s.is_available=1 THEN 1 ELSE 0 END) AS available_today,
                SUM(CASE WHEN s.is_available=0 THEN 1 ELSE 0 END) AS unavailable_today
                FROM doctor_schedule_settings s JOIN doctor_profiles d ON d.doctor_user_id=s.doctor_user_id";
        }
        $availability = $this->db->query($availabilitySql)->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'highest_rated' => $this->db->query($ratedSql)->fetchAll(PDO::FETCH_ASSOC),
            'busiest_doctors' => $this->db->query($busySql)->fetchAll(PDO::FETCH_ASSOC),
            'available_today' => (int)($availability['available_today'] ?? 0),
            'unavailable_today' => (int)($availability['unavailable_today'] ?? 0),
        ];
    }

    public function getDepartmentAnalytics(): array
    {
        $sql = $this->schema === self::SCHEMA_V1
            ? "SELECT d.id,d.name,d.status,COUNT(DISTINCT doc.id) AS doctor_count,
                COUNT(DISTINCT a.id) AS appointment_count,COUNT(DISTINCT a.user_id) AS patient_count
                FROM departments d LEFT JOIN doctors doc ON doc.department_id=d.id
                LEFT JOIN appointments a ON a.department_id=d.id
                GROUP BY d.id,d.name,d.status ORDER BY appointment_count DESC"
            : "SELECT d.id,d.name,d.status,COUNT(DISTINCT doc.doctor_user_id) AS doctor_count,
                COUNT(DISTINCT a.id) AS appointment_count,COUNT(DISTINCT a.patient_user_id) AS patient_count
                FROM departments d LEFT JOIN doctor_profiles doc ON doc.department_id=d.id
                LEFT JOIN appointments a ON a.department_id=d.id
                GROUP BY d.id,d.name,d.status ORDER BY appointment_count DESC";
        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getRecentActivity(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));
        $sql = $this->schema === self::SCHEMA_V1
            ? "SELECT a.id,a.action,a.entity_type,a.entity_id,a.description,a.created_at,a.actor_role,
                COALESCE(u.name,CONCAT('Deleted ',UPPER(SUBSTRING(a.actor_role,1,1)),SUBSTRING(a.actor_role,2))) AS actor_name,
                a.patient_id,a.doctor_id
                FROM admin_audit a LEFT JOIN users u ON a.actor_id=u.id
                ORDER BY a.created_at DESC LIMIT ?"
            : "SELECT a.id,a.action_code AS action,a.entity_type,a.entity_id,a.description,
                a.occurred_at AS created_at,a.actor_role_snapshot AS actor_role,
                COALESCE(u.name,CONCAT('Deleted ',UPPER(SUBSTRING(a.actor_role_snapshot,1,1)),SUBSTRING(a.actor_role_snapshot,2))) AS actor_name,
                a.patient_user_id AS patient_id,a.doctor_user_id AS doctor_id
                FROM audit_events a LEFT JOIN users u ON a.actor_user_id=u.id
                ORDER BY a.occurred_at DESC LIMIT ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['time_ago'] = RelativeTimeFormatter::format($row['created_at']); }
        unset($row);
        return $rows;
    }

    public function getSystemStatus(): array
    {
        $hospital = $this->db->query(
            'SELECT hospital_name,appointment_open_time,appointment_close_time FROM hospital_settings LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $open = $hospital['appointment_open_time'] ?? '08:00';
        $close = $hospital['appointment_close_time'] ?? '22:00';
        $now = ((int)date('G') * 60) + (int)date('i');
        $isOpen = false;
        if (preg_match('/^(\d{1,2}):(\d{2})/', (string)$open, $om)
            && preg_match('/^(\d{1,2}):(\d{2})/', (string)$close, $cm)) {
            $start = (int)$om[1] * 60 + (int)$om[2];
            $end = (int)$cm[1] * 60 + (int)$cm[2];
            $isOpen = $now >= $start && $now < $end;
        }
        $unread = (int)$this->db->query('SELECT COUNT(*) FROM notifications WHERE is_read=0')->fetchColumn();
        return [
            'hospital_name' => $hospital['hospital_name'] ?? 'HealthBridge Hospital',
            'is_open' => $isOpen,
            'open_time' => $open,
            'close_time' => $close,
            'current_time' => date('Y-m-d H:i:s'),
            'current_time_formatted' => date('l, F j, Y \\a\\t g:i A'),
            'unread_count' => $unread,
        ];
    }
}
