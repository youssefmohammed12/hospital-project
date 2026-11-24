<?php
declare(strict_types=1);

/**
 * Canonical doctor-profile read adapter (V2 schema).
 *
 * Canonical doctor identity is always users.id (doctor_id/user_id).
 * The public directory also retains its legacy profile-row "id" field for
 * existing clients; that surrogate is never used as the booking identity.
 */
final class DoctorProfileRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByUserId(int $doctorUserId): ?array
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        $sql = $this->selectSql() . " WHERE u.id = ? AND u.role = 'doctor' LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$doctorUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function listPublic(): array
    {
        $sql = $this->selectSql()
            . " WHERE u.role = 'doctor'
                 AND u.is_active = 1
                 AND dp.profile_visible = 1
                 AND (dp.department_id IS NULL OR dep.status = 'active' OR dep.id IS NULL)
               ORDER BY average_rating DESC, u.name ASC";

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listForAdmin(): array
    {
        $sql = "SELECT u.id AS id, u.id AS user_id, u.id AS doctor_id,
                      u.name, u.email, u.phone, u.is_active, u.created_at,
                      dp.specialty, COALESCE(rs.average_rating, 0) AS rating,
                      dp.experience_years AS exp, dp.profile_icon AS emoji,
                      dp.department_id
               FROM users u
               LEFT JOIN doctor_profiles dp ON dp.doctor_user_id = u.id
               LEFT JOIN v_doctor_rating_summary rs ON rs.doctor_user_id = u.id
               WHERE u.role = 'doctor'
               ORDER BY u.created_at DESC";

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function selectSql(): string
    {
        return "SELECT COALESCE(legacy.source_doctor_id, dp.doctor_user_id) AS id,
                       u.id AS user_id, u.id AS doctor_id,
                       u.name, u.email, u.phone, u.is_active, u.created_at,
                       dp.specialty, dp.experience_years AS exp,
                       dp.experience_years, dp.profile_icon AS emoji,
                       dp.profile_icon, dp.department_id,
                       dp.accepts_new_patients, dp.profile_visible,
                       COALESCE(dss.is_available, dp.accepts_new_patients) AS available,
                       COALESCE(dss.is_available, 0) AS is_available,
                       COALESCE(rs.average_rating, 0) AS rating,
                       COALESCE(rs.average_rating, 0) AS average_rating,
                       COALESCE(rs.rating_count, 0) AS rating_count,
                       COALESCE(dep.name, dp.specialty) AS department_name
                FROM users u
                INNER JOIN doctor_profiles dp ON dp.doctor_user_id = u.id
                LEFT JOIN legacy_doctor_profile_state legacy ON legacy.doctor_user_id = u.id
                LEFT JOIN doctor_schedule_settings dss ON dss.doctor_user_id = u.id
                LEFT JOIN v_doctor_rating_summary rs ON rs.doctor_user_id = u.id
                LEFT JOIN departments dep ON dep.id = dp.department_id";
    }
}
