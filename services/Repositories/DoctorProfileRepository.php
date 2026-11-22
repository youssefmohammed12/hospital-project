<?php
declare(strict_types=1);

/**
 * Canonical doctor-profile read adapter for v1 and v2.
 *
 * Canonical doctor identity is always users.id (doctor_id/user_id).
 * The public directory also retains its legacy profile-row "id" field for
 * existing clients; that surrogate is never used as the booking identity.
 */
final class DoctorProfileRepository
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('Schema mode must be explicitly v1 or v2.');
        }

        $this->db = $db;
        $this->schema = $schema;
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
        $visibility = $this->schema === self::SCHEMA_V1
            ? 'COALESCE(p.profile_visible, 1) = 1'
            : 'dp.profile_visible = 1';
        $departmentColumn = $this->schema === self::SCHEMA_V1 ? 'd.department_id' : 'dp.department_id';

        $sql = $this->selectSql()
            . " WHERE u.role = 'doctor'
                 AND u.is_active = 1
                 AND {$visibility}
                 AND ({$departmentColumn} IS NULL OR dep.status = 'active' OR dep.id IS NULL)
               ORDER BY average_rating DESC, u.name ASC";

        return $this->db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function listForAdmin(): array
    {
        $sql = $this->schema === self::SCHEMA_V1
            ? "SELECT u.id AS id, u.id AS user_id, u.id AS doctor_id,
                      u.name, u.email, u.phone, u.is_active, u.created_at,
                      d.specialty, d.rating, d.exp, d.emoji, d.department_id
               FROM users u
               LEFT JOIN doctors d ON d.user_id = u.id
               WHERE u.role = 'doctor'
               ORDER BY u.created_at DESC"
            : "SELECT u.id AS id, u.id AS user_id, u.id AS doctor_id,
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
        if ($this->schema === self::SCHEMA_V1) {
            return "SELECT d.id AS id, u.id AS user_id, u.id AS doctor_id,
                           d.name, u.email, u.phone, u.is_active, u.created_at,
                           d.specialty, d.exp AS exp, d.exp AS experience_years,
                           d.emoji AS emoji, d.emoji AS profile_icon, d.department_id,
                           COALESCE(p.accept_new_patients, 1) AS accepts_new_patients,
                           COALESCE(p.profile_visible, 1) AS profile_visible,
                           COALESCE(dss.is_available, d.available) AS available,
                           COALESCE(dss.is_available, d.available) AS is_available,
                           COALESCE(
                               (SELECT ROUND(AVG(r.stars), 1) FROM ratings r WHERE r.doctor_id = u.id),
                               d.rating
                           ) AS rating,
                           COALESCE(
                               (SELECT ROUND(AVG(r.stars), 1) FROM ratings r WHERE r.doctor_id = u.id),
                               d.rating
                           ) AS average_rating,
                           (SELECT COUNT(*) FROM ratings r WHERE r.doctor_id = u.id) AS rating_count,
                           COALESCE(dep.name, d.specialty) AS department_name
                    FROM users u
                    INNER JOIN doctors d ON d.user_id = u.id
                    LEFT JOIN user_preferences p ON p.user_id = u.id
                    LEFT JOIN doctor_schedule_settings dss ON dss.doctor_id = u.id
                    LEFT JOIN departments dep ON dep.id = d.department_id";
        }

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
