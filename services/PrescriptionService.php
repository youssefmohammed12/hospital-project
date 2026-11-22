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

require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/ClinicalAccessService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/../includes/AppointmentTime.php';

class PrescriptionService
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema = self::SCHEMA_V1)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('PrescriptionService schema must be explicitly v1 or v2.');
        }
        $this->db = $db;
        $this->schema = $schema;
    }

    private function patientColumn(): string
    {
        return $this->schema === 'v2' ? 'patient_user_id' : 'patient_id';
    }

    private function doctorColumn(): string
    {
        return $this->schema === 'v2' ? 'doctor_user_id' : 'doctor_id';
    }

    private function storedStatus(string $legacyStatus): string
    {
        return $this->schema === 'v2' ? strtolower($legacyStatus) : $legacyStatus;
    }

    private function normalizePrescriptionRow(array $row): array
    {
        if ($this->schema === 'v2') {
            $row['patient_id'] = (int)($row['patient_user_id'] ?? $row['patient_id'] ?? 0);
            $row['doctor_id'] = (int)($row['doctor_user_id'] ?? $row['doctor_id'] ?? 0);
            $status = strtolower((string)($row['status'] ?? ''));
            $row['status'] = match ($status) {
                'active' => 'Active',
                'completed' => 'Completed',
                'cancelled' => 'Cancelled',
                default => (string)($row['status'] ?? ''),
            };
        }
        if (array_key_exists('appt_department', $row)) {
            $row['department'] = $row['appt_department'];
        }
        return $row;
    }

    private function normalizePrescriptionRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $row = $this->normalizePrescriptionRow($row);
        }
        unset($row);
        return $rows;
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
        $validationError = self::validateItems($items);
        if ($validationError !== null) {
            throw new InvalidArgumentException($validationError);
        }
        if ($notes !== null && mb_strlen($notes) > 5000) {
            throw new InvalidArgumentException('Prescription notes must not exceed 5000 characters.');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Prescription creation cannot start inside an existing transaction.');
        }

        $this->db->beginTransaction();

        try {
            // Match booking and clinical writers: doctor account, patient account,
            // then appointment. Prescription foreign keys must not be the first
            // account locks taken after an appointment lock.
            $accountLocks = new ClinicalAccessService($this->db, $this->schema);
            if (!$accountLocks->lockDoctorAndPatient($doctorId, $patientId)) {
                throw new DomainException('The doctor or patient account is no longer available.');
            }

            // Serialize prescription creation against other writers for this appointment.
            if ($this->schema === 'v2') {
                $appointmentStmt = $this->db->prepare(
                    'SELECT id, patient_user_id AS user_id, doctor_user_id AS doctor_id,
                            booking_status AS status, doctor_name_snapshot AS doctor,
                            starts_at, duration_minutes
                     FROM appointments WHERE id = ? LIMIT 1 FOR UPDATE'
                );
            } else {
                $appointmentStmt = $this->db->prepare(
                    'SELECT id, user_id, doctor_id, status, doctor
                     FROM appointments WHERE id = ? LIMIT 1 FOR UPDATE'
                );
            }
            $appointmentStmt->execute([$appointmentId]);
            $appointment = $appointmentStmt->fetch();

            if (!$appointment) {
                throw new DomainException('Appointment not found.');
            }
            if ((int)$appointment['doctor_id'] !== $doctorId) {
                throw new DomainException('This appointment is not assigned to the requesting doctor.');
            }
            if ((int)$appointment['user_id'] !== $patientId) {
                throw new DomainException('The selected patient does not match this appointment.');
            }
            $confirmed = $this->schema === 'v2'
                ? $appointment['status'] === 'confirmed'
                : $appointment['status'] === 'Confirmed';
            if (!$confirmed) {
                throw new DomainException('Prescriptions can only be issued for confirmed appointments.');
            }

            // In v2 a reschedule request is a child record and does not change the
            // confirmed parent booking. Preserve the v1 rule: do not issue while
            // a reschedule is actively awaiting a response.
            if ($this->schema === 'v2') {
                $rescheduleStmt = $this->db->prepare(
                    "SELECT id FROM appointment_reschedule_requests
                     WHERE appointment_id = ? AND request_status IN ('requested','suggested')
                     LIMIT 1"
                );
                $rescheduleStmt->execute([$appointmentId]);
                if ($rescheduleStmt->fetchColumn()) {
                    throw new DomainException('Prescriptions cannot be issued while an appointment reschedule is pending.');
                }
            }

            // The unique appointment_id constraint is the final duplicate guard.
            $existingStmt = $this->db->prepare(
                'SELECT id FROM prescriptions WHERE appointment_id = ? LIMIT 1'
            );
            $existingStmt->execute([$appointmentId]);
            if ($existingStmt->fetchColumn()) {
                throw new DomainException('A prescription already exists for this appointment.');
            }

            if ($this->schema === 'v2') {
                $stmt = $this->db->prepare(
                    "INSERT INTO prescriptions (patient_user_id, doctor_user_id, appointment_id, notes, status, created_at)
                     VALUES (?, ?, ?, ?, 'active', NOW())"
                );
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO prescriptions (patient_id, doctor_id, appointment_id, notes, status, created_at)
                     VALUES (?, ?, ?, ?, 'Active', NOW())"
                );
            }
            $stmt->execute([$patientId, $doctorId, $appointmentId, $notes]);
            $prescriptionId = (int)$this->db->lastInsertId();

            $this->insertItems($prescriptionId, $items);
$this->recordAudit(
    'create', $prescriptionId, null,
    ['patient_id' => $patientId, 'doctor_id' => $doctorId],
    "Prescription created for patient ID {$patientId}", $patientId, $doctorId
);

$this->enqueuePatientNotification(
    $patientId,
    NotificationService::TYPE_PRESCRIPTION_ISSUED,
    'New Prescription Issued',
    "Dr. {$appointment['doctor']} has issued a new prescription for your recent appointment. You can now view it in your Prescriptions section.",
    $prescriptionId
);

$this->db->commit();
$this->dispatchNotificationOutbox();
            return $prescriptionId;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
        $validationError = self::validateItems($items);
        if ($validationError !== null) {
            throw new InvalidArgumentException($validationError);
        }
        if ($notes !== null && mb_strlen($notes) > 5000) {
            throw new InvalidArgumentException('Prescription notes must not exceed 5000 characters.');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Prescription update cannot start inside an existing transaction.');
        }

        $this->db->beginTransaction();
        try {
            $rx = $this->getForUpdateAfterAccountLocks($prescriptionId, $doctorId);
            if (!$rx) {
                $this->db->rollBack();
                return false;
            }

            $doctorColumn = $this->doctorColumn();
            $activeStatus = $this->storedStatus('Active');
            $stmt = $this->db->prepare(
                "UPDATE prescriptions SET notes = ?, updated_at = NOW()
                 WHERE id = ? AND {$doctorColumn} = ? AND status = ?"
            );
            $stmt->execute([$notes, $prescriptionId, $doctorId, $activeStatus]);

            // The row is locked and already verified as Active and owned by this doctor.
            // Do not rely on rowCount: unchanged notes/timestamps may report zero affected rows.
            $delStmt = $this->db->prepare('DELETE FROM prescription_items WHERE prescription_id = ?');
            $delStmt->execute([$prescriptionId]);
            $this->insertItems($prescriptionId, $items);
$this->recordAudit(
    'update', $prescriptionId, null, ['notes' => $notes, 'items' => $items],
    'Prescription medication and notes updated.', (int)$rx['patient_id'], $doctorId
);

$this->enqueuePatientNotification(
    (int)$rx['patient_id'],
    NotificationService::TYPE_PRESCRIPTION_UPDATED,
    'Prescription Updated',
    "Your prescription has been updated by Dr. {$rx['appt_doctor_name']}. Please review the changes in your Prescriptions section.",
    $prescriptionId
);

$this->db->commit();
$this->dispatchNotificationOutbox();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
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
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Prescription completion cannot start inside an existing transaction.');
        }

        $this->db->beginTransaction();
        try {
            $rx = $this->getForUpdateAfterAccountLocks($prescriptionId, $doctorId);
            if (!$rx) {
                $this->db->rollBack();
                return false;
            }

            $doctorColumn = $this->doctorColumn();
            $activeStatus = $this->storedStatus('Active');
            $completedStatus = $this->storedStatus('Completed');
            $stmt = $this->db->prepare(
                "UPDATE prescriptions SET status = ?, updated_at = NOW()
                 WHERE id = ? AND {$doctorColumn} = ? AND status = ?"
            );
            $stmt->execute([$completedStatus, $prescriptionId, $doctorId, $activeStatus]);

            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

                        $this->recordAudit(
                'complete', $prescriptionId, 'Active', 'Completed',
                'Prescription marked as completed.', (int)$rx['patient_id'], $doctorId
            );

            $this->enqueuePatientNotification(
                (int)$rx['patient_id'],
                NotificationService::TYPE_PRESCRIPTION_COMPLETED,
                'Prescription Completed',
                "Your prescription treatment has been marked as completed by Dr. {$rx['appt_doctor_name']}.",
                $prescriptionId
            );

            $this->db->commit();
            $this->dispatchNotificationOutbox();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ── Cancel ──────────────────────────────────────────────

    /**
     * Cancel an Active prescription with a reason.
     * Only the prescribing doctor can cancel it.
     */
    public function cancel(int $prescriptionId, int $doctorId, string $reason): bool
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('Prescription cancellation cannot start inside an existing transaction.');
        }

        $this->db->beginTransaction();
        try {
            $rx = $this->getForUpdateAfterAccountLocks($prescriptionId, $doctorId);
            if (!$rx) {
                $this->db->rollBack();
                return false;
            }

            $doctorColumn = $this->doctorColumn();
            $activeStatus = $this->storedStatus('Active');
            $cancelledStatus = $this->storedStatus('Cancelled');
            $stmt = $this->db->prepare(
                "UPDATE prescriptions SET status = ?, cancellation_reason = ?, updated_at = NOW()
                 WHERE id = ? AND {$doctorColumn} = ? AND status = ?"
            );
            $stmt->execute([$cancelledStatus, $reason, $prescriptionId, $doctorId, $activeStatus]);

            if ($stmt->rowCount() !== 1) {
                $this->db->rollBack();
                return false;
            }

                        $this->recordAudit(
                'cancel', $prescriptionId, 'Active', 'Cancelled',
                'Prescription cancelled: ' . $reason, (int)$rx['patient_id'], $doctorId
            );

            $this->enqueuePatientNotification(
                (int)$rx['patient_id'],
                NotificationService::TYPE_PRESCRIPTION_CANCELLED,
                'Prescription Cancelled',
                "Your prescription has been cancelled by Dr. {$rx['appt_doctor_name']}. Reason: {$reason}",
                $prescriptionId
            );

            $this->db->commit();
            $this->dispatchNotificationOutbox();
            return true;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
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
        if ($this->schema === 'v2') {
            $stmt = $this->db->prepare(
                "SELECT p.*, p.patient_user_id AS patient_id, p.doctor_user_id AS doctor_id,
                        u_patient.name AS patient_name, u_patient.email AS patient_email,
                        u_doctor.name AS doctor_name,
                        DATE(a.starts_at) AS appt_date, TIME_FORMAT(a.starts_at, '%H:%i') AS appt_time,
                        a.department_name_snapshot AS appt_department,
                        a.doctor_name_snapshot AS appt_doctor_name,
                        a.doctor_user_id, a.duration_minutes AS appt_duration_minutes
                 FROM prescriptions p
                 JOIN users u_patient ON p.patient_user_id = u_patient.id
                 JOIN users u_doctor ON p.doctor_user_id = u_doctor.id
                 JOIN appointments a ON p.appointment_id = a.id
                 WHERE p.id = ? LIMIT 1"
            );
        } else {
            $stmt = $this->db->prepare(
                'SELECT p.*, u_patient.name AS patient_name, u_patient.email AS patient_email,
                        u_doctor.name AS doctor_name, a.date AS appt_date, a.time AS appt_time,
                        a.department AS appt_department, a.doctor AS appt_doctor_name, a.doctor_id
                 FROM prescriptions p
                 JOIN users u_patient ON p.patient_id = u_patient.id
                 JOIN users u_doctor ON p.doctor_id = u_doctor.id
                 JOIN appointments a ON p.appointment_id = a.id
                 WHERE p.id = ? LIMIT 1'
            );
        }
        $stmt->execute([$prescriptionId]);
        $prescription = $stmt->fetch();

        if (!$prescription) {
            return null;
        }
        $prescription = $this->normalizePrescriptionRow($prescription);

        // Keep the legacy print/API DTO while respecting the normalized appointment snapshot.
        $duration = $this->schema === 'v2'
            ? (int)$prescription['appt_duration_minutes']
            : AppointmentTime::duration($this->db, (int)$prescription['doctor_id']);
        $prescription['appointment_time_range'] = AppointmentTime::range(
            (string)$prescription['appt_time'],
            $duration
        );

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
    public function getByPatient(int $patientId, ?int $limit = null): array
    {
        $sql = $this->listSelectSql()
            . ' WHERE p.' . $this->patientColumn() . ' = ? ORDER BY p.created_at DESC, p.id DESC';
        if ($limit !== null) {
            if ($limit < 1 || $limit > 500) {
                throw new InvalidArgumentException('Prescription list limit must be between 1 and 500.');
            }
            $sql .= ' LIMIT ' . $limit;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$patientId]);
        return $this->normalizePrescriptionRows($stmt->fetchAll());
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
            $this->listSelectSql() . ' WHERE p.' . $this->doctorColumn() . ' = ? ORDER BY p.created_at DESC'
        );
        $stmt->execute([$doctorId]);
        return $this->normalizePrescriptionRows($stmt->fetchAll());
    }

    /**
     * Get all prescriptions (admin view - for auditing).
     *
     * @return array
     */
    public function getAll(): array
    {
        $stmt = $this->db->prepare($this->listSelectSql() . ' ORDER BY p.created_at DESC');
        $stmt->execute();
        return $this->normalizePrescriptionRows($stmt->fetchAll());
    }

    private function listSelectSql(): string
    {
        if ($this->schema === 'v2') {
            return "SELECT p.*, p.patient_user_id AS patient_id, p.doctor_user_id AS doctor_id,
                           u_patient.name AS patient_name, u_doctor.name AS doctor_name,
                           DATE(a.starts_at) AS appt_date,
                           a.doctor_name_snapshot AS appt_doctor_name,
                           a.department_name_snapshot AS appt_department
                    FROM prescriptions p
                    JOIN users u_patient ON p.patient_user_id = u_patient.id
                    JOIN users u_doctor ON p.doctor_user_id = u_doctor.id
                    JOIN appointments a ON p.appointment_id = a.id";
        }

        return "SELECT p.*, u_patient.name AS patient_name, u_doctor.name AS doctor_name,
                       a.date AS appt_date, a.doctor AS appt_doctor_name,
                       a.department AS appt_department
                FROM prescriptions p
                JOIN users u_patient ON p.patient_id = u_patient.id
                JOIN users u_doctor ON p.doctor_id = u_doctor.id
                JOIN appointments a ON p.appointment_id = a.id";
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
     * Resolve prescription ownership without locks, then acquire account rows
     * before locking the prescription. This keeps prescription mutations
     * compatible with doctor/patient account archival and audit foreign keys.
     */
    private function getForUpdateAfterAccountLocks(int $prescriptionId, int $doctorId): ?array
    {
        if ($this->schema === 'v2') {
            $identityStmt = $this->db->prepare(
                'SELECT p.patient_user_id AS patient_id, p.doctor_user_id AS doctor_id,
                        a.doctor_name_snapshot AS appt_doctor_name
                 FROM prescriptions p JOIN appointments a ON a.id = p.appointment_id
                 WHERE p.id = ? LIMIT 1'
            );
        } else {
            $identityStmt = $this->db->prepare(
                'SELECT p.patient_id, p.doctor_id, a.doctor AS appt_doctor_name
                 FROM prescriptions p JOIN appointments a ON a.id = p.appointment_id
                 WHERE p.id = ? LIMIT 1'
            );
        }
        $identityStmt->execute([$prescriptionId]);
        $identity = $identityStmt->fetch();

        if (!$identity || (int)$identity['doctor_id'] !== $doctorId) {
            return null;
        }

        $patientId = (int)$identity['patient_id'];
        $accountLocks = new ClinicalAccessService($this->db, $this->schema);
        if (!$accountLocks->lockDoctorAndPatient($doctorId, $patientId)) {
            return null;
        }

        $rx = $this->getForUpdate($prescriptionId, $doctorId);
        if (!$rx || (int)$rx['patient_id'] !== $patientId) {
            return null;
        }

        // The display label came from the earlier non-locking identity read.
        // Keep that read before account/prescription locks; it is presentation
        // context only and does not participate in the lock order.
        $rx['appt_doctor_name'] = (string)$identity['appt_doctor_name'];

        return $rx;
    }

    private function recordAudit(
        string $action,
        int $prescriptionId,
        mixed $oldValue,
        mixed $newValue,
        string $description,
        int $patientId,
        int $doctorId
    ): void {
        if ($this->schema === 'v1') {
            $audit = new AuditService($this->db, $doctorId, 'doctor', $this->schema);
            if (!$audit->log($action, 'prescription', $prescriptionId, $oldValue, $newValue, $description, $patientId, $doctorId)) {
                throw new RuntimeException('Could not record the prescription audit event.');
            }
            return;
        }

        $encode = static function ($value): ?string {
            if ($value === null) return null;
            if (is_string($value)) return $value;
            return json_encode($value, JSON_THROW_ON_ERROR);
        };
        $stmt = $this->db->prepare(
            'INSERT INTO audit_events
                (actor_user_id, actor_role_snapshot, action_code, entity_type, entity_id,
                 patient_user_id, doctor_user_id, old_value, new_value, description, ip_address, user_agent)
             VALUES (?, \'doctor\', ?, \'prescription\', ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $doctorId, $action, (string)$prescriptionId, $patientId, $doctorId,
            $encode($oldValue), $encode($newValue), $description,
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    /**
     * Queue a patient-facing prescription event on the active business transaction.
     */
    private function enqueuePatientNotification(
        int $patientId,
        string $type,
        string $title,
        string $message,
        int $prescriptionId
    ): void {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Prescription notifications must be queued inside the prescription transaction.');
        }

        (new NotificationService($this->db))->create(
            $patientId,
            $type,
            $title,
            $message,
            'prescription',
            $prescriptionId
        );
    }

    /**
     * Attempt prompt delivery only after the prescription transaction commits.
     * A dispatch failure leaves the durable outbox event for the CLI worker.
     */
    protected function dispatchNotificationOutbox(): void
    {
        try {
            (new NotificationService($this->db))->processOutbox(50);
        } catch (Throwable $error) {
            error_log('Prescription outbox dispatch failed: ' . get_class($error));
        }
    }

    /**
     * Internal: verify prescription is updatable by this doctor.
     */
    private function getForUpdate(int $prescriptionId, int $doctorId): ?array
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Prescription row locks require an active transaction.');
        }

        $doctorColumn = $this->doctorColumn();
        $patientColumn = $this->patientColumn();
        $stmt = $this->db->prepare(
            "SELECT id, status, {$doctorColumn} AS doctor_id, {$patientColumn} AS patient_id
             FROM prescriptions WHERE id = ? LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$prescriptionId]);
        $rx = $stmt->fetch();

        if (!$rx) return null;
        if (strtolower((string)$rx['status']) !== 'active') return null;
        if ((int)$rx['doctor_id'] !== $doctorId) return null;

        return $this->normalizePrescriptionRow($rx);
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
     * Finalize active prescriptions inside an already-open clinical completion
     * transaction. The appointment is expected to be locked by the caller.
     * No nested transaction is opened and the existing completion endpoint's
     * audit/notification behavior is preserved.
     */
    public function completeForAppointmentInTransaction(int $appointmentId, int $doctorId): int
    {
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Appointment prescription completion requires an active transaction.');
        }

        if ($appointmentId <= 0 || $doctorId <= 0) {
            throw new InvalidArgumentException('Appointment and doctor IDs must be positive integers.');
        }

        $doctorColumn = $this->doctorColumn();
        $activeStatus = $this->storedStatus('Active');
        $completedStatus = $this->storedStatus('Completed');
        $stmt = $this->db->prepare(
            "UPDATE prescriptions
             SET status = ?, updated_at = NOW()
             WHERE appointment_id = ? AND {$doctorColumn} = ? AND status = ?"
        );
        $stmt->execute([$completedStatus, $appointmentId, $doctorId, $activeStatus]);
        return $stmt->rowCount();
    }

    public function countActiveByDoctor(int $doctorId): int
    {
        if ($doctorId <= 0) {
            return 0;
        }

        $doctorColumn = $this->doctorColumn();
        $activeStatus = $this->storedStatus('Active');
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM prescriptions WHERE {$doctorColumn} = ? AND status = ?"
        );
        $stmt->execute([$doctorId, $activeStatus]);
        return (int)$stmt->fetchColumn();
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
            $params[] = $this->storedStatus((string)$filters['status']);
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
            $conditions[] = 'p.' . $this->doctorColumn() . ' = ?';
            $params[] = (int)$filters['doctor_id'];
        }
        if (!empty($filters['patient_id'])) {
            $conditions[] = 'p.' . $this->patientColumn() . ' = ?';
            $params[] = (int)$filters['patient_id'];
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = $this->listSelectSql() . " {$where} ORDER BY p.created_at DESC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $this->withItems($this->normalizePrescriptionRows($stmt->fetchAll()));
    }

    /**
     * Check if a prescription is editable (Active status only).
     */
    public static function isEditable(string $status): bool
    {
        return $status === 'Active';
    }

    /**
     * Attach medication items to a prescription list without an N+1 query.
     *
     * IDs are queried in bounded batches so large, currently unpaginated
     * result sets stay below the database driver's placeholder limit.
     * Pass a count key to include the number of hydrated items in each row.
     */
    private function withItems(array $prescriptions): array
    {
        return $this->attachItems($prescriptions, 'items_count');
    }

    /**
     * Reusable prescription-item hydration for API read models.
     *
     * @param array $prescriptions Prescription rows containing an id field.
     * @param string|null $countKey Optional output field for the item count.
     * @return array The same prescription rows with ordered items attached.
     */
    public function attachItems(array $prescriptions, ?string $countKey = null): array
    {
        if ($prescriptions === []) {
            return [];
        }

        $itemsByPrescription = [];
        $prescriptionIds = [];

        foreach ($prescriptions as &$prescription) {
            $prescriptionId = (int)($prescription['id'] ?? 0);
            $prescription['items'] = [];
            if ($countKey !== null) {
                $prescription[$countKey] = 0;
            }

            if ($prescriptionId > 0) {
                $prescriptionIds[$prescriptionId] = $prescriptionId;
                $itemsByPrescription[$prescriptionId] = [];
            }
        }
        unset($prescription);

        foreach (array_chunk(array_values($prescriptionIds), 500) as $idBatch) {
            $placeholders = implode(',', array_fill(0, count($idBatch), '?'));
            $stmt = $this->db->prepare(
                "SELECT *
                 FROM prescription_items
                 WHERE prescription_id IN ({$placeholders})
                 ORDER BY prescription_id ASC, sort_order ASC"
            );
            $stmt->execute($idBatch);

            foreach ($stmt->fetchAll() as $item) {
                $prescriptionId = (int)$item['prescription_id'];
                $itemsByPrescription[$prescriptionId][] = $item;
            }
        }

        foreach ($prescriptions as &$prescription) {
            $prescriptionId = (int)($prescription['id'] ?? 0);
            $items = $itemsByPrescription[$prescriptionId] ?? [];
            $prescription['items'] = $items;
            if ($countKey !== null) {
                $prescription[$countKey] = count($items);
            }
        }
        unset($prescription);

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
        $requiredFields = [
            'medication_name' => ['Medication name is required.', 200],
            'strength' => ['Strength is required for each medication.', 100],
            'dosage' => ['Dosage is required for each medication.', 100],
            'frequency' => ['Frequency is required for each medication.', 100],
            'duration' => ['Duration is required for each medication.', 100],
        ];

        foreach ($requiredFields as $field => [$requiredMessage, $maxLength]) {
            $value = $item[$field] ?? null;
            if (!is_string($value) && !is_numeric($value)) {
                return $requiredMessage;
            }

            $value = trim((string)$value);
            if ($value === '') {
                return $requiredMessage;
            }
            if (mb_strlen($value) > $maxLength) {
                return ucfirst(str_replace('_', ' ', $field)) . " must not exceed {$maxLength} characters.";
            }
        }

        if (isset($item['instructions']) && !is_string($item['instructions'])) {
            return 'Medication instructions must be text.';
        }
        if (isset($item['instructions']) && mb_strlen($item['instructions']) > 5000) {
            return 'Medication instructions must not exceed 5000 characters.';
        }

        return null;
    }

    /**
     * Validate a list of prescription items.
     *
     * @param array $items
     * @return string|null  Error message or null if valid
     */
    public static function validateItems(array $items): ?string
    {
        if (empty($items)) {
            return 'At least one medication is required.';
        }
        // Keep compatibility with the documented PHP 8.0 minimum (array_is_list is PHP 8.1+).
        if (array_keys($items) !== range(0, count($items) - 1)) {
            return 'Medication items must be provided as a list.';
        }
        if (count($items) > 50) {
            return 'A prescription cannot contain more than 50 medication items.';
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                return "Item #" . ($index + 1) . " must be a medication object.";
            }
            $error = self::validateItem($item);
            if ($error) {
                return "Item #" . ($index + 1) . ": " . $error;
            }
        }

        return null;
    }
}