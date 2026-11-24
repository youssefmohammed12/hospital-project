<?php
declare(strict_types=1);

/**
 * Clinical encounter adapter for the normalized V2 schema.
 *
 * V2 stores workflow + note fields in clinical_encounters and drafts in
 * encounter_drafts. "uninitialized" represents a note-bearing encounter
 * that does not have a workflow row yet; it is not exposed as a real workflow
 * until getOrCreateWorkflow/create/transition is explicitly requested.
 */
final class ClinicalEncounterRepository
{
    public const STATUS_WAITING = 'Waiting';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_READY_TO_COMPLETE = 'Ready to Complete';
    public const STATUS_COMPLETED = 'Completed';

    private PDO $db;

    private const NOTE_FIELDS = [
        'diagnosis',
        'symptoms',
        'treatment',
        'doctor_notes',
        'follow_up_instructions',
    ];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getWorkflow(int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }

        $stmt = $this->db->prepare(
            "SELECT id, appointment_id, workflow_status, started_at, completed_at, created_at, updated_at
             FROM clinical_encounters
             WHERE appointment_id = ? AND workflow_status <> 'uninitialized'
             LIMIT 1"
        );
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapV2Workflow($row) : null;
    }

    public function getOrCreateWorkflow(int $appointmentId): array
    {
        return $this->withTransaction(function () use ($appointmentId): array {
            $appointment = $this->lockAppointment($appointmentId);
            $this->assertConfirmed($appointment);

            $row = $this->lockV2Encounter($appointmentId);
            if (!$row) {
                $this->insertV2Encounter($appointment);
                $row = $this->lockV2Encounter($appointmentId);
            } elseif ($row['workflow_status'] === 'uninitialized') {
                $this->db->prepare(
                    "UPDATE clinical_encounters
                     SET workflow_status='waiting', updated_at=NOW()
                     WHERE appointment_id=? AND workflow_status='uninitialized'"
                )->execute([$appointmentId]);
                $row = $this->lockV2Encounter($appointmentId);
            }
            $workflow = $row ? $this->mapV2Workflow($row) : null;

            if (!$workflow) {
                throw new RuntimeException('Could not create or retrieve the clinical workflow.');
            }
            return $workflow;
        });
    }

    public function createWorkflow(int $appointmentId, string $status = self::STATUS_WAITING): bool
    {
        if ($status !== self::STATUS_WAITING) {
            throw new InvalidArgumentException('A new visit workflow must begin in Waiting status.');
        }
        $this->getOrCreateWorkflow($appointmentId);
        return true;
    }

    public function transitionWorkflow(int $appointmentId, string $newStatus): bool
    {
        $validStatuses = [
            self::STATUS_WAITING,
            self::STATUS_IN_PROGRESS,
            self::STATUS_READY_TO_COMPLETE,
            self::STATUS_COMPLETED,
        ];
        if (!in_array($newStatus, $validStatuses, true)) {
            throw new InvalidArgumentException('Invalid workflow status.');
        }

        return $this->withTransaction(function () use ($appointmentId, $newStatus): bool {
            $appointment = $this->lockAppointment($appointmentId);
            $this->assertConfirmed($appointment);

            $row = $this->lockV2Encounter($appointmentId);
            if (!$row) {
                $this->insertV2Encounter($appointment);
                $row = $this->lockV2Encounter($appointmentId);
            }
            if (!$row) {
                throw new RuntimeException('Could not create or lock the clinical encounter.');
            }

            $currentStatus = $this->fromV2Status((string)$row['workflow_status']);
            if ($currentStatus === null) {
                // A note-only, not-yet-initialized encounter starts from Waiting
                // when the first explicit workflow transition is requested.
                $currentStatus = self::STATUS_WAITING;
            }
            if ($currentStatus === $newStatus) {
                if ($row['workflow_status'] === 'uninitialized') {
                    $this->db->prepare(
                        "UPDATE clinical_encounters SET workflow_status='waiting',updated_at=NOW()
                         WHERE appointment_id=? AND workflow_status='uninitialized'"
                    )->execute([$appointmentId]);
                }
                return true;
            }
            $this->assertAllowedTransition($currentStatus, $newStatus);

            $updates = ['workflow_status=?'];
            $params = [$this->toV2Status($newStatus)];
            if (
                in_array($newStatus, [self::STATUS_IN_PROGRESS, self::STATUS_READY_TO_COMPLETE], true)
                && empty($row['started_at'])
            ) {
                $updates[] = 'started_at=NOW()';
            }
            if ($newStatus === self::STATUS_COMPLETED && empty($row['completed_at'])) {
                $updates[] = 'completed_at=NOW()';
            }

            $params[] = $appointmentId;
            $params[] = (string)$row['workflow_status'];
            $update = $this->db->prepare(
                'UPDATE clinical_encounters SET ' . implode(',', $updates)
                . ' WHERE appointment_id=? AND workflow_status=?'
            );
            $update->execute($params);
            if ($update->rowCount() !== 1) {
                throw new DomainException('The clinical encounter changed in another request. Refresh and try again.');
            }
            return true;
        });
    }

    public function getWorkflowsForAppointments(array $appointmentIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $appointmentIds),
            static fn(int $id): bool => $id > 0
        )));
        if (!$ids) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM clinical_encounters
             WHERE appointment_id IN ({$placeholders})
               AND workflow_status <> 'uninitialized'"
        );
        $stmt->execute($ids);
        $map = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row = $this->mapV2Workflow($row);
            $map[(int)$row['appointment_id']] = $row;
        }
        return $map;
    }

    public function getNote(int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }
        $stmt = $this->db->prepare(
            "SELECT id,appointment_id,patient_user_id AS patient_id,doctor_user_id AS doctor_id,
                    diagnosis,symptoms,treatment,doctor_notes,follow_up_instructions,
                    created_at,updated_at
             FROM clinical_encounters
             WHERE appointment_id=? LIMIT 1"
        );
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return null;
        }

        // A v2 encounter can exist solely to track workflow. Do not report it as
        // a visit note unless at least one clinical note field has been recorded.
        foreach (self::NOTE_FIELDS as $field) {
            if ($row[$field] !== null && $row[$field] !== '') {
                return $row;
            }
        }
        return null;
    }

    public function getNotesForPatient(int $patientUserId): array
    {
        if ($patientUserId <= 0) {
            throw new InvalidArgumentException('Patient user ID must be positive.');
        }

        $stmt = $this->db->prepare(
            "SELECT ce.id,ce.appointment_id,
                        ce.patient_user_id AS patient_id,ce.doctor_user_id AS doctor_id,
                        ce.diagnosis,ce.symptoms,ce.treatment,ce.doctor_notes,
                        ce.follow_up_instructions,ce.created_at,ce.updated_at,
                        DATE_FORMAT(a.starts_at,'%Y-%m-%d') AS appt_date,
                        DATE_FORMAT(a.starts_at,'%H:%i') AS appt_time,
                        a.source_time_snapshot,a.source_time_range_snapshot,
                        a.duration_minutes,
                        a.source_time_range_snapshot AS appointment_time_range,
                        CASE
                          WHEN a.booking_status='cancelled' THEN 'Cancelled'
                          WHEN a.booking_status='pending' THEN 'Pending'
                          ELSE 'Confirmed'
                        END AS appt_status,
                        a.department_name_snapshot AS department,
                        u.name AS doctor_name,u.name AS doctor_display_name,
                        ce.id AS visit_id,ce.created_at AS visit_date
                 FROM clinical_encounters ce
                 JOIN appointments a ON a.id=ce.appointment_id
                 LEFT JOIN users u ON u.id=ce.doctor_user_id
                 WHERE ce.patient_user_id=?
                   AND (ce.diagnosis IS NOT NULL OR ce.symptoms IS NOT NULL
                        OR ce.treatment IS NOT NULL OR ce.doctor_notes IS NOT NULL
                        OR ce.follow_up_instructions IS NOT NULL)
                 ORDER BY ce.created_at DESC"
        );
        $stmt->execute([$patientUserId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveNote(int $appointmentId, int $patientUserId, int $doctorUserId, array $data): bool
    {
        if ($appointmentId <= 0 || $patientUserId <= 0 || $doctorUserId <= 0) {
            throw new InvalidArgumentException('Appointment, patient and doctor IDs must be positive.');
        }
        $values = $this->normalizeClinicalFields($data);

        return $this->withTransaction(function () use ($appointmentId, $patientUserId, $doctorUserId, $values): bool {
            $appointment = $this->lockAppointment($appointmentId);
            $this->assertConfirmed($appointment);
            if (
                (int)$appointment['patient_user_id'] !== $patientUserId
                || (int)$appointment['doctor_user_id'] !== $doctorUserId
            ) {
                throw new DomainException('Clinical note ownership does not match the appointment.');
            }

            $stmt = $this->db->prepare(
                "INSERT INTO clinical_encounters
                    (appointment_id,patient_user_id,doctor_user_id,workflow_status,
                     diagnosis,symptoms,treatment,doctor_notes,follow_up_instructions)
                 VALUES (?,?,?,'uninitialized',?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    diagnosis=VALUES(diagnosis),symptoms=VALUES(symptoms),treatment=VALUES(treatment),
                    doctor_notes=VALUES(doctor_notes),follow_up_instructions=VALUES(follow_up_instructions),
                    updated_at=NOW()"
            );
            return $stmt->execute([
                $appointmentId,$patientUserId,$doctorUserId,
                $values['diagnosis'],$values['symptoms'],$values['treatment'],
                $values['doctor_notes'],$values['follow_up_instructions'],
            ]);
        });
    }

    public function saveDraft(int $appointmentId, int $doctorUserId, array $data): bool
    {
        if ($appointmentId <= 0 || $doctorUserId <= 0) {
            throw new InvalidArgumentException('Appointment and doctor IDs must be positive.');
        }
        $values = $this->normalizeClinicalFields($data);

        return $this->withTransaction(function () use ($appointmentId, $doctorUserId, $values): bool {
            $appointment = $this->lockAppointment($appointmentId);
            $this->assertConfirmed($appointment);
            if ((int)$appointment['doctor_user_id'] !== $doctorUserId) {
                throw new DomainException('Draft ownership does not match the appointment.');
            }

            $stmt = $this->db->prepare(
                "INSERT INTO encounter_drafts
                    (appointment_id,doctor_user_id,diagnosis,symptoms,treatment,doctor_notes,follow_up_instructions)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE
                    diagnosis=VALUES(diagnosis),symptoms=VALUES(symptoms),treatment=VALUES(treatment),
                    doctor_notes=VALUES(doctor_notes),follow_up_instructions=VALUES(follow_up_instructions),
                    updated_at=NOW()"
            );
            return $stmt->execute([
                $appointmentId,$doctorUserId,$values['diagnosis'],$values['symptoms'],
                $values['treatment'],$values['doctor_notes'],$values['follow_up_instructions'],
            ]);
        });
    }

    public function getDraft(int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }
        $sql = 'SELECT id,appointment_id,doctor_user_id AS doctor_id,diagnosis,symptoms,treatment,
                      doctor_notes,follow_up_instructions,updated_at
               FROM encounter_drafts WHERE appointment_id=? LIMIT 1';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$appointmentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deleteDraft(int $appointmentId): bool
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }
        $stmt = $this->db->prepare("DELETE FROM encounter_drafts WHERE appointment_id=?");
        return $stmt->execute([$appointmentId]);
    }

    public function cleanupOldDrafts(int $days = 7): int
    {
        if ($days < 1 || $days > 365) {
            throw new InvalidArgumentException('Draft retention must be between 1 and 365 days.');
        }
        $stmt = $this->db->prepare(
            "DELETE FROM encounter_drafts WHERE updated_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    /**
     * Read a schema-neutral appointment context before locking account rows.
     * Callers should then lock doctor/patient accounts and re-read with
     * lockAppointmentContext() to prevent reassignment/cancellation races.
     */
    public function getAppointmentContext(int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }

        $sql = "SELECT id,doctor_user_id,patient_user_id,booking_status AS status,
                      doctor_name_snapshot AS doctor_name,
                      DATE_FORMAT(starts_at,'%Y-%m-%d') AS appointment_date,
                      TIME_FORMAT(starts_at,'%H:%i') AS appointment_time
               FROM appointments WHERE id=? LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapAppointmentContext($row) : null;
    }

    /**
     * Lock an appointment after account rows have been locked.
     * The caller must own an active transaction and revalidate the context
     * against the earlier non-locking read.
     */
    public function lockAppointmentContext(int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }
        if (!$this->db->inTransaction()) {
            throw new RuntimeException('Appointment row locks require an active transaction.');
        }

        $sql = "SELECT id,doctor_user_id,patient_user_id,booking_status AS status,
                      doctor_name_snapshot AS doctor_name,
                      DATE_FORMAT(starts_at,'%Y-%m-%d') AS appointment_date,
                      TIME_FORMAT(starts_at,'%H:%i') AS appointment_time
               FROM appointments WHERE id=? LIMIT 1 FOR UPDATE";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->mapAppointmentContext($row) : null;
    }

    private function mapAppointmentContext(array $row): array
    {
        $storedStatus = (string)$row['status'];
        $status = strtolower($storedStatus);

        return [
            'id' => (int)$row['id'],
            'doctor_user_id' => (int)$row['doctor_user_id'],
            'patient_user_id' => $row['patient_user_id'] !== null
                ? (int)$row['patient_user_id']
                : null,
            'status' => $status,
            'doctor_name' => (string)($row['doctor_name'] ?? ''),
            'appointment_date' => $row['appointment_date'] ?? null,
            'appointment_time' => $row['appointment_time'] ?? null,
        ];
    }

    private function lockAppointment(int $appointmentId): array
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }

        $sql = "SELECT id,doctor_user_id,patient_user_id,booking_status AS status
               FROM appointments WHERE id=? LIMIT 1 FOR UPDATE";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('Appointment not found.');
        }
        return $row;
    }

    private function lockV2Encounter(int $appointmentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM clinical_encounters WHERE appointment_id=? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$appointmentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function insertV2Encounter(array $appointment): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO clinical_encounters
                (appointment_id,patient_user_id,doctor_user_id,workflow_status)
             VALUES (?,?,?,'waiting')
             ON DUPLICATE KEY UPDATE appointment_id=VALUES(appointment_id)"
        );
        $stmt->execute([
            (int)$appointment['id'],
            (int)$appointment['patient_user_id'],
            (int)$appointment['doctor_user_id'],
        ]);
    }

    private function assertConfirmed(array $appointment): void
    {
        $confirmed = $appointment['status'] === 'confirmed';
        if (!$confirmed) {
            throw new DomainException('A clinical encounter can only be accessed for a confirmed appointment.');
        }
    }

    private function assertAllowedTransition(string $currentStatus, string $newStatus): void
    {
        $allowed = [
            self::STATUS_WAITING => [self::STATUS_IN_PROGRESS, self::STATUS_READY_TO_COMPLETE],
            self::STATUS_IN_PROGRESS => [self::STATUS_READY_TO_COMPLETE, self::STATUS_COMPLETED],
            self::STATUS_READY_TO_COMPLETE => [self::STATUS_IN_PROGRESS, self::STATUS_COMPLETED],
            self::STATUS_COMPLETED => [],
        ];
        if (!in_array($newStatus, $allowed[$currentStatus] ?? [], true)) {
            throw new DomainException("Invalid visit workflow transition from {$currentStatus} to {$newStatus}.");
        }
    }

    private function mapV2Workflow(array $row): array
    {
        $status = $this->fromV2Status((string)$row['workflow_status']);
        if ($status === null) {
            throw new DomainException('The encounter has not initialized a visit workflow.');
        }

        return [
            'id' => (int)$row['id'],
            'appointment_id' => (int)$row['appointment_id'],
            'status' => $status,
            'started_at' => $row['started_at'],
            'completed_at' => $row['completed_at'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function fromV2Status(string $status): ?string
    {
        return match ($status) {
            'waiting' => self::STATUS_WAITING,
            'in_progress' => self::STATUS_IN_PROGRESS,
            'ready_to_complete' => self::STATUS_READY_TO_COMPLETE,
            'completed' => self::STATUS_COMPLETED,
            default => null,
        };
    }

    private function toV2Status(string $status): string
    {
        return match ($status) {
            self::STATUS_WAITING => 'waiting',
            self::STATUS_IN_PROGRESS => 'in_progress',
            self::STATUS_READY_TO_COMPLETE => 'ready_to_complete',
            self::STATUS_COMPLETED => 'completed',
            default => throw new InvalidArgumentException('Invalid workflow status.'),
        };
    }

    private function normalizeClinicalFields(array $data): array
    {
        $values = [];
        foreach (self::NOTE_FIELDS as $field) {
            $value = $data[$field] ?? null;
            if ($value !== null && !is_string($value)) {
                throw new InvalidArgumentException("Clinical field {$field} must be text or null.");
            }
            $value = $value !== null ? trim($value) : null;
            if ($value !== null && mb_strlen($value) > 10000) {
                throw new InvalidArgumentException("Clinical field {$field} cannot exceed 10000 characters.");
            }
            $values[$field] = $value === '' ? null : $value;
        }
        return $values;
    }

    private function withTransaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }
}
