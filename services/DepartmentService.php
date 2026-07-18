<?php
/**
 * HealthBridge — DepartmentService
 *
 * Manages department CRUD operations and doctor-department relationships.
 * Handles department activation/deactivation with safety checks for existing data.
 *
 * Usage:
 *   $ds = new DepartmentService(getDB());
 *   $ds->getAllDepartments();
 *   $ds->createDepartment([...]);
 *   $ds->updateDepartment($id, [...]);
 *   $ds->deactivateDepartment($id);
 */

class DepartmentService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Get all departments with doctor count.
     *
     * @param bool $includeInactive Whether to include inactive departments
     * @return array
     */
    public function getAllDepartments(bool $includeInactive = false): array
    {
        $sql = "SELECT d.*,
                       (SELECT COUNT(*) FROM doctors WHERE department_id = d.id) as doctor_count,
                       (SELECT COUNT(*) FROM appointments WHERE department_id = d.id) as appointment_count
                FROM departments d";

        if (!$includeInactive) {
            $sql .= " WHERE d.status = 'active'";
        }

        $sql .= " ORDER BY d.name ASC";

        $stmt = $this->db->query($sql);
        return $stmt->fetchAll();
    }

    /**
     * Get a single department by ID.
     *
     * @param int $id
     * @return array|null
     */
    public function getDepartmentById(int $id): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*,
                    (SELECT COUNT(*) FROM doctors WHERE department_id = d.id) as doctor_count,
                    (SELECT COUNT(*) FROM appointments WHERE department_id = d.id) as appointment_count
             FROM departments d
             WHERE d.id = ?"
        );
        $stmt->execute([$id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Get doctors assigned to a department.
     *
     * @param int $departmentId
     * @return array
     */
    public function getDepartmentDoctors(int $departmentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.id, d.user_id, d.name, d.specialty, d.rating, d.exp, d.available,
                    u.email, u.phone, u.is_active,
                    dss.is_available as schedule_available
             FROM doctors d
             JOIN users u ON d.user_id = u.id
             LEFT JOIN doctor_schedule_settings dss ON d.user_id = dss.doctor_id
             WHERE d.department_id = ?
             ORDER BY d.name ASC"
        );
        $stmt->execute([$departmentId]);
        return $stmt->fetchAll();
    }

    /**
     * Create a new department.
     *
     * @param array $data Keys: name, description, status
     * @return int New department ID
     * @throws Exception
     */
    public function createDepartment(array $data): int
    {
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $status = $data['status'] ?? 'active';

        // Validate
        if (empty($name)) {
            throw new Exception('Department name is required.');
        }
        if (strlen($name) > 100) {
            throw new Exception('Department name must not exceed 100 characters.');
        }
        if (!in_array($status, ['active', 'inactive'])) {
            throw new Exception('Invalid status. Must be active or inactive.');
        }

        // Check for duplicate name
        $stmt = $this->db->prepare("SELECT id FROM departments WHERE name = ?");
        $stmt->execute([$name]);
        if ($stmt->fetch()) {
            throw new Exception('A department with this name already exists.');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO departments (name, description, status) VALUES (?, ?, ?)"
        );
        $stmt->execute([$name, $description ?: null, $status]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Update an existing department.
     *
     * @param int $id
     * @param array $data Keys: name, description, status
     * @return bool
     * @throws Exception
     */
    public function updateDepartment(int $id, array $data): bool
    {
        $department = $this->getDepartmentById($id);
        if (!$department) {
            throw new Exception('Department not found.');
        }

        $name = trim($data['name'] ?? $department['name']);
        $description = trim($data['description'] ?? $department['description']);
        $status = $data['status'] ?? $department['status'];

        // Validate
        if (empty($name)) {
            throw new Exception('Department name is required.');
        }
        if (strlen($name) > 100) {
            throw new Exception('Department name must not exceed 100 characters.');
        }
        if (!in_array($status, ['active', 'inactive'])) {
            throw new Exception('Invalid status. Must be active or inactive.');
        }

        // Check for duplicate name (excluding current department)
        $stmt = $this->db->prepare("SELECT id FROM departments WHERE name = ? AND id != ?");
        $stmt->execute([$name, $id]);
        if ($stmt->fetch()) {
            throw new Exception('A department with this name already exists.');
        }

        $stmt = $this->db->prepare(
            "UPDATE departments SET name = ?, description = ?, status = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$name, $description ?: null, $status, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Deactivate a department (soft delete - preserves historical data).
     *
     * @param int $id
     * @return bool
     * @throws Exception
     */
    public function deactivateDepartment(int $id): bool
    {
        $department = $this->getDepartmentById($id);
        if (!$department) {
            throw new Exception('Department not found.');
        }

        if ($department['status'] === 'inactive') {
            throw new Exception('Department is already inactive.');
        }

        $stmt = $this->db->prepare(
            "UPDATE departments SET status = 'inactive', updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Activate a department.
     *
     * @param int $id
     * @return bool
     * @throws Exception
     */
    public function activateDepartment(int $id): bool
    {
        $department = $this->getDepartmentById($id);
        if (!$department) {
            throw new Exception('Department not found.');
        }

        if ($department['status'] === 'active') {
            throw new Exception('Department is already active.');
        }

        $stmt = $this->db->prepare(
            "UPDATE departments SET status = 'active', updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Permanently delete a department (only if no dependencies).
     *
     * @param int $id
     * @return bool
     * @throws Exception
     */
    public function deleteDepartment(int $id): bool
    {
        $department = $this->getDepartmentById($id);
        if (!$department) {
            throw new Exception('Department not found.');
        }

        // Check for dependencies
        if ((int)$department['doctor_count'] > 0) {
            throw new Exception('Cannot delete department with assigned doctors. Deactivate instead.');
        }
        if ((int)$department['appointment_count'] > 0) {
            throw new Exception('Cannot delete department with appointment history. Deactivate instead.');
        }

        $stmt = $this->db->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Assign a doctor to a department.
     *
     * @param int $doctorId
     * @param int $departmentId
     * @return bool
     * @throws Exception
     */
    public function assignDoctorToDepartment(int $doctorId, int $departmentId): bool
    {
        // Verify department exists and is active
        $department = $this->getDepartmentById($departmentId);
        if (!$department) {
            throw new Exception('Department not found.');
        }
        if ($department['status'] !== 'active') {
            throw new Exception('Cannot assign doctors to inactive departments.');
        }

        // Verify doctor exists
        $stmt = $this->db->prepare("SELECT id FROM doctors WHERE id = ?");
        $stmt->execute([$doctorId]);
        if (!$stmt->fetch()) {
            throw new Exception('Doctor not found.');
        }

        $stmt = $this->db->prepare(
            "UPDATE doctors SET department_id = ? WHERE id = ?"
        );
        $stmt->execute([$departmentId, $doctorId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a doctor from their department (set to NULL).
     *
     * @param int $doctorId
     * @return bool
     * @throws Exception
     */
    public function removeDoctorFromDepartment(int $doctorId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE doctors SET department_id = NULL WHERE id = ?"
        );
        $stmt->execute([$doctorId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Search departments by name or description.
     *
     * @param string $query
     * @param bool $includeInactive
     * @return array
     */
    public function searchDepartments(string $query, bool $includeInactive = false): array
    {
        $sql = "SELECT d.*,
                       (SELECT COUNT(*) FROM doctors WHERE department_id = d.id) as doctor_count
                FROM departments d
                WHERE (d.name LIKE ? OR d.description LIKE ?)";

        if (!$includeInactive) {
            $sql .= " AND d.status = 'active'";
        }

        $sql .= " ORDER BY d.name ASC";

        $searchTerm = "%{$query}%";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$searchTerm, $searchTerm]);

        return $stmt->fetchAll();
    }

    /**
     * Get active departments for booking dropdowns.
     *
     * @return array
     */
    public function getActiveDepartmentsForBooking(): array
    {
        $stmt = $this->db->query(
            "SELECT id, name FROM departments WHERE status = 'active' ORDER BY name ASC"
        );
        return $stmt->fetchAll();
    }
}
