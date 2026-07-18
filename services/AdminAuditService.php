<?php
/**
 * HealthBridge — AdminAuditService
 *
 * Backward-compatible wrapper for admin audit logging.
 * Extends the universal AuditService with 'admin' role preset.
 *
 * All existing callers continue to work unchanged.
 *
 * Usage:
 *   $audit = new AdminAuditService(getDB(), $adminId);
 *   $audit->log('update', 'hospital_settings', 1, $oldData, $newData);
 *   $audit->logDoctorReassignment(...);
 */

require_once __DIR__ . '/AuditService.php';

class AdminAuditService extends AuditService
{
    public function __construct(PDO $db, int $adminId)
    {
        parent::__construct($db, $adminId, 'admin');
    }

    /**
     * Log an administrative action (backward-compatible signature).
     * Accepts optional description, patientId, and doctorId context.
     */
    public function log(string $action, string $entityType, ?int $entityId, $oldValue = null, $newValue = null, ?string $description = null, ?int $patientId = null, ?int $doctorId = null): bool
    {
        return parent::log($action, $entityType, $entityId, $oldValue, $newValue, $description, $patientId, $doctorId);
    }

    /**
     * Log hospital settings change.
     *
     * @param array $oldSettings
     * @param array $newSettings
     * @return bool
     */
    public function logHospitalSettingsChange(array $oldSettings, array $newSettings): bool
    {
        return $this->log('update', 'hospital_settings', 1, $oldSettings, $newSettings);
    }

    /**
     * Log department creation.
     *
     * @param int $departmentId
     * @param array $departmentData
     * @return bool
     */
    public function logDepartmentCreate(int $departmentId, array $departmentData): bool
    {
        return $this->log('create', 'department', $departmentId, null, $departmentData);
    }

    /**
     * Log department update.
     *
     * @param int $departmentId
     * @param array $oldData
     * @param array $newData
     * @return bool
     */
    public function logDepartmentUpdate(int $departmentId, array $oldData, array $newData): bool
    {
        return $this->log('update', 'department', $departmentId, $oldData, $newData);
    }

    /**
     * Log department activation.
     *
     * @param int $departmentId
     * @param string $departmentName
     * @return bool
     */
    public function logDepartmentActivate(int $departmentId, string $departmentName): bool
    {
        return $this->log('activate', 'department', $departmentId, 'inactive', 'active');
    }

    /**
     * Log department deactivation.
     *
     * @param int $departmentId
     * @param string $departmentName
     * @return bool
     */
    public function logDepartmentDeactivate(int $departmentId, string $departmentName): bool
    {
        return $this->log('deactivate', 'department', $departmentId, 'active', 'inactive');
    }

    /**
     * Log department deletion.
     *
     * @param int $departmentId
     * @param array $departmentData
     * @return bool
     */
    public function logDepartmentDelete(int $departmentId, array $departmentData): bool
    {
        return $this->log('delete', 'department', $departmentId, $departmentData, null);
    }

    /**
     * Log doctor department reassignment.
     *
     * @param int $doctorId
     * @param string $doctorName
     * @param int|null $oldDepartmentId
     * @param int|null $newDepartmentId
     * @return bool
     */
    /**
     * Log doctor department reassignment.
     * Resolves department IDs to names and logs with a human-readable description.
     */
    public function logDoctorReassignment(int $doctorUserId, string $doctorName, ?int $oldDepartmentId, ?int $newDepartmentId): bool
    {
        $oldDeptName = 'No department';
        if ($oldDepartmentId) {
            $stmt = $this->db->prepare('SELECT name FROM departments WHERE id = ?');
            $stmt->execute([$oldDepartmentId]);
            $oldDeptName = $stmt->fetchColumn() ?: 'No department';
        }
        
        $newDeptName = 'No department';
        if ($newDepartmentId) {
            $stmt = $this->db->prepare('SELECT name FROM departments WHERE id = ?');
            $stmt->execute([$newDepartmentId]);
            $newDeptName = $stmt->fetchColumn() ?: 'No department';
        }
        
        $description = "Department reassigned: {$oldDeptName} → {$newDeptName}";
        
        return $this->log(
            'reassign',
            'doctor',
            $doctorUserId,
            $oldDeptName,
            $newDeptName,
            $description,
            null,
            $doctorUserId
        );
    }

    /**
     * Get audit log entries with optional filtering, search, and pagination.
     * Delegates to parent class to ensure consistent filtering (including patient_id and doctor_id).
     */
    public function getAuditLog(array $filters = []): array
    {
        return parent::getAuditLog($filters);
    }

    /**
     * Get distinct entity types that exist in the audit log.
     *
     * @return array
     */
    public function getDistinctEntityTypes(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT entity_type FROM admin_audit ORDER BY entity_type ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get distinct actions that exist in the audit log.
     *
     * @return array
     */
    public function getDistinctActions(): array
    {
        $stmt = $this->db->query("SELECT DISTINCT action FROM admin_audit ORDER BY action ASC");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Get audit log for a specific entity.
     *
     * @param string $entityType
     * @param int $entityId
     * @return array
     */
    public function getEntityAuditLog(string $entityType, int $entityId): array
    {
        return $this->getAuditLog([
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);
    }
}
