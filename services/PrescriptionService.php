<?php
/**
 * HealthBridge — PrescriptionService
 *
 * Centralized service for creating, fetching, and managing prescriptions.
 * All prescription operations go through this class.
 *
 * A prescription is a separate medical document linked to an appointment.
 * It is NOT stored inside Visit Notes. Each prescription can contain
 * multiple medications (prescription_items).
 *
 * Usage:
 *   $ps = new PrescriptionService(getDB());
 *   $ps->create($patientId, $doctorId, $appointmentId, $items, $notes);
 */

class PrescriptionService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Create ──────────────────────────────────────────────

    /**
     * Create a new prescription with multiple medication items.
     *
     * @param int   $patientId
     * @param int   $doctorId
     * @param int   $appointmentId
     * @param array $items  Array of items, each with:
     *     medication_name, strength, dosage, frequency, duration, instructions (optional)
     * @param string|null $notes  Optional general notes for the prescription
     * @return int  The new prescription ID
     * @throws Exception
     */
    public function create(int $patientId, int $doctorId, int $appointmentId, array $items, ?string $notes = null): int
    {
        // Begin transaction
        $this->db->beginTransaction();

        try {
            // Insert the prescription header
            $stmt = $this->db->prepare(
                'INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, notes, status, created_at)
                 VALUES (?, ?, ?, ?, \'Active\', NOW())'
            );
            $stmt->execute([$patientId, $doctorId, $appointmentId, $notes]);
            $prescriptionId = (int) $this->db->lastInsertId();

            // Insert each medication item
            $itemStmt = $this->db->prepare(
                'INSERT INTO prescription_items (prescription_id, medication_name, strength, dosage, frequency, duration, instructions, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            foreach ($items as $index => $item) {
                $itemStmt->execute([
                    $prescriptionId,
                    $item['medication_name'] ?? '',
                    $item['strength'] ?? '',
                    $item['dosage'] ?? '',
                    $item['frequency'] ?? '',
                    $item['duration'] ?? '',
                    $item['instructions'] ?? null,
                    $index + 1,
                ]);
            }

            $this->db->commit();
            return $prescriptionId;

        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Update ──────────────────────────────────────────────

    /**
     * Update an Active prescription: replace items and notes.
     * Only Active prescriptions can be updated.
     *
     * @param int      $prescriptionId
     * @param array    $items       New medication items
     * @param string|null $notes    New general notes
     * @param int      $doctorId    The doctor making the update (for permission check)
     * @return bool
     * @throws Exception
     */
    public function update(int $prescriptionId, array $items, ?string $notes, int $doctorId): bool
    {
        $rx = $this->getForUpdate($prescriptionId, $doctorId);
        if (!$rx) {
            return false;
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'UPDATE prescriptions SET notes = ?, updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute([$notes, $prescriptionId]);

            $delStmt = $this->db->prepare('DELETE FROM prescription_items WHERE prescription_id = ?');
            $delStmt->execute([$prescriptionId]);

            $this->insertItems($prescriptionId, $items);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ── Complete ────────────────────────────────────────────

    /**
     * Mark an Active prescription as Completed.
     * Only the prescribing doctor can complete it.
     */
    public function complete(int $prescriptionId, int $doctorId): bool
    {
        $rx = $this->getForUpdate($prescriptionId, $doctorId);
        if (!$rx) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE prescriptions SET status = \'Completed\', updated_at = NOW() WHERE id = ? AND status = \'Active\''
        );
        $stmt->execute([$prescriptionId]);
        return $stmt->rowCount() > 0;
    }

    // ── Cancel ──────────────────────────────────────────────

    /**
     * Cancel an Active prescription with a reason.
     * Only the prescribing doctor can cancel it.
     */
    public function cancel(int $prescriptionId, int $doctorId, string $reason): bool
    {
        $rx = $this->getForUpdate($prescriptionId, $doctorId);
        if (!$rx) {
            return false;
        }

        $stmt = $this->db->prepare(
            'UPDATE prescriptions SET status = \'Cancelled\', cancellation_reason = ?, updated_at = NOW() WHERE id = ? AND status = \'Active\''
        );
        $stmt->execute([$reason, $prescriptionId]);
        return $stmt->rowCount() > 0;
    }

    // ── Read ────────────────────────────────────────────────

    /**
     * Get a single prescription with all its items.
     *
     * @param int $prescriptionId
     * @return array|null  Prescription with 'items' sub-array, or null if not found
     */
    public function get(int $prescriptionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, 
                    u_patient.name AS patient_name, 
                    u_doctor.name AS doctor_name,
                    a.date AS appt_date,
                    a.time AS appt_time,
                    a.department AS appt_department,
                    a.doctor AS appt_doctor_name,
                    a.doctor_id
             FROM prescriptions p
             JOIN users u_patient ON p.patient_id = u_patient.id
             JOIN users u_doctor  ON p.doctor_id = u_doctor.id
             JOIN appointments a  ON p.appointment_id = a.id
             WHERE p.id = ?
             LIMIT 1'
        );
        $stmt->execute([$prescriptionId]);
        $prescription = $stmt->fetch();

        if (!$prescription) {
            return null;
        }

        // Add appointment_time_range
        $duration = getAppointmentDuration((int)$prescription['doctor_id']);
        $prescription['appointment_time_range'] = computeAppointmentTimeRange($prescription['appt_time'], $duration);

        // Get items
        $itemStmt = $this->db->prepare(
            'SELECT * FROM prescription_items 
             WHERE prescription_id = ? 
             ORDER BY sort_order ASC'
        );
        $itemStmt->execute([$prescriptionId]);
        $prescription['items'] = $itemStmt->fetchAll();

        return $prescription;
    }

    /**
     * Get all prescriptions for a patient (patients view their own).
     *
     * @param int $patientId
     * @return array
     */
    public function getByPatient(int $patientId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, 
                    u_doctor.name AS doctor_name,
                    a.date AS appt_date,
                    a.doctor AS appt_doctor_name,
                    a.department AS appt_department
             FROM prescriptions p
             JOIN users u_doctor  ON p.doctor_id = u_doctor.id
             JOIN appointments a  ON p.appointment_id = a.id
             WHERE p.patient_id = ?
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$patientId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all prescriptions created by a specific doctor.
     *
     * @param int $doctorId
     * @return array
     */
    public function getByDoctor(int $doctorId): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, 
                    u_patient.name AS patient_name,
                    a.date AS appt_date,
                    a.doctor AS appt_doctor_name,
                    a.department AS appt_department
             FROM prescriptions p
             JOIN users u_patient ON p.patient_id = u_patient.id
             JOIN appointments a  ON p.appointment_id = a.id
             WHERE p.doctor_id = ?
             ORDER BY p.created_at DESC'
        );
        $stmt->execute([$doctorId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all prescriptions (admin view - for auditing).
     *
     * @return array
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, 
                    u_patient.name AS patient_name,
                    u_doctor.name AS doctor_name,
                    a.date AS appt_date,
                    a.doctor AS appt_doctor_name,
                    a.department AS appt_department
             FROM prescriptions p
             JOIN users u_patient ON p.patient_id = u_patient.id
             JOIN users u_doctor  ON p.doctor_id = u_doctor.id
             JOIN appointments a  ON p.appointment_id = a.id
             ORDER BY p.created_at DESC'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Get the prescription for a specific appointment, if one exists.
     *
     * @param int $appointmentId
     * @return array|null  Prescription with items, or null if none exists
     */
    public function getByAppointment(int $appointmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id FROM prescriptions WHERE appointment_id = ? LIMIT 1'
        );
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->get((int)$row['id']);
    }

    /**
     * Internal: verify prescription is updatable by this doctor.
     */
    private function getForUpdate(int $prescriptionId, int $doctorId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, status, doctor_id FROM prescriptions WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$prescriptionId]);
        $rx = $stmt->fetch();

        if (!$rx) return null;
        if ($rx['status'] !== 'Active') return null;
        if ((int)$rx['doctor_id'] !== $doctorId) return null;

        return $rx;
    }

    /**
     * Insert medication items for a prescription.
     */
    private function insertItems(int $prescriptionId, array $items): void
    {
        $itemStmt = $this->db->prepare(
            'INSERT INTO prescription_items (prescription_id, medication_name, strength, dosage, frequency, duration, instructions, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($items as $index => $item) {
            $itemStmt->execute([
                $prescriptionId,
                $item['medication_name'] ?? '',
                $item['strength'] ?? '',
                $item['dosage'] ?? '',
                $item['frequency'] ?? '',
                $item['duration'] ?? '',
                $item['instructions'] ?? null,
                $index + 1,
            ]);
        }
    }

    /**
     * Get the count of prescriptions for a specific appointment.
     */
    public function countByAppointment(int $appointmentId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as c FROM prescriptions WHERE appointment_id = ?');
        $stmt->execute([$appointmentId]);
        return (int) $stmt->fetch()['c'];
    }

    // ── Search / Filter ─────────────────────────────────────

    /**
     * Get prescriptions with filtering and search.
     * Used by admin for advanced search/filter.
     *
     * @param array $filters  Optional keys: status, search, date_from, date_to, doctor_id, patient_id
     * @return array
     */
    public function search(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = 'p.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $conditions[] = '(u_patient.name LIKE ? OR u_doctor.name LIKE ?)';
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        if (!empty($filters['date_from'])) {
            $conditions[] = 'p.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $conditions[] = 'p.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }
        if (!empty($filters['doctor_id'])) {
            $conditions[] = 'p.doctor_id = ?';
            $params[] = (int)$filters['doctor_id'];
        }
        if (!empty($filters['patient_id'])) {
            $conditions[] = 'p.patient_id = ?';
            $params[] = (int)$filters['patient_id'];
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT p.*, 
                       u_patient.name AS patient_name,
                       u_doctor.name AS doctor_name,
                       a.date AS appt_date,
                       a.doctor AS appt_doctor_name,
                       a.department AS appt_department
                FROM prescriptions p
                JOIN users u_patient ON p.patient_id = u_patient.id
                JOIN users u_doctor  ON p.doctor_id = u_doctor.id
                JOIN appointments a  ON p.appointment_id = a.id
                {$where}
                ORDER BY p.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->withItemCounts($stmt->fetchAll());
    }

    /**
     * Check if a prescription is editable (Active status only).
     */
    public static function isEditable(string $status): bool
    {
        return $status === 'Active';
    }

    /**
     * Attach item counts to prescription rows.
     */
    private function withItemCounts(array $prescriptions): array
    {
        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) as c FROM prescription_items WHERE prescription_id = ?'
        );
        foreach ($prescriptions as &$rx) {
            $countStmt->execute([(int)$rx['id']]);
            $rx['items_count'] = (int) $countStmt->fetch()['c'];
        }
        return $prescriptions;
    }

    // ── Validation ──────────────────────────────────────────

    /**
     * Validate prescription item data.
     *
     * @param array $item
     * @return string|null  Error message or null if valid
     */
    public static function validateItem(array $item): ?string
    {
        if (empty(trim($item['medication_name'] ?? ''))) {
            return 'Medication name is required.';
        }
        if (empty(trim($item['strength'] ?? ''))) {
            return 'Strength is required for each medication.';
        }
        if (empty(trim($item['dosage'] ?? ''))) {
            return 'Dosage is required for each medication.';
        }
        if (empty(trim($item['frequency'] ?? ''))) {
            return 'Frequency is required for each medication.';
        }
        if (empty(trim($item['duration'] ?? ''))) {
            return 'Duration is required for each medication.';
        }
        return null;
    }

    /**
     * Validate an array of prescription items.
     *
     * @param array $items
     * @return string|null  Error message or null if valid
     */
    public static function validateItems(array $items): ?string
    {
        if (empty($items)) {
            return 'At least one medication is required.';
        }

        foreach ($items as $index => $item) {
            $error = self::validateItem($item);
            if ($error) {
                return "Item #" . ($index + 1) . ": " . $error;
            }
        }

        return null;
    }
}