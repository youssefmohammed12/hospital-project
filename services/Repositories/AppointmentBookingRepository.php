<?php
declare(strict_types=1);

require_once __DIR__ . '/../ScheduleService.php';
require_once __DIR__ . '/../NotificationService.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';

/**
 * Transactional adapter for creating appointments (V2 schema).
 *
 * Every operation uses users.id for the doctor identity and locks in this order:
 * doctor account -> doctor profile -> department -> schedule validation -> patient.
 * The repository respects caller-owned transactions so tests or higher-level
 * workflows can compose booking with other transactional operations.
 */
final class AppointmentBookingRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Create a booking after validating all authoritative identities and slot rules.
     *
     * Required input keys:
     *  doctor_user_id, patient_user_id, department_id, date (Y-m-d), time (HH:MM)
     * Optional:
     *  notes, actor_user_id, actor_role
     *
     * actor_role is patient/admin. The actor is the authenticated caller; patient_user_id
     * may differ only for an admin-assisted booking.
     */
    public function book(array $input): int
    {
        $doctorUserId = (int)($input['doctor_user_id'] ?? 0);
        $patientUserId = (int)($input['patient_user_id'] ?? 0);
        $departmentId = (int)($input['department_id'] ?? 0);
        $date = (string)($input['date'] ?? '');
        $time = AppointmentTime::normalize((string)($input['time'] ?? ''));
        $notes = trim((string)($input['notes'] ?? ''));
        $actorUserId = (int)($input['actor_user_id'] ?? 0);
        $actorRole = (string)($input['actor_role'] ?? '');

        if ($doctorUserId <= 0 || $patientUserId <= 0 || $departmentId <= 0 || $actorUserId <= 0) {
            throw new InvalidArgumentException('Doctor, patient, department and actor IDs must be positive.');
        }
        if (!in_array($actorRole, ['patient', 'admin'], true)) {
            throw new DomainException('Only patients and administrators can book appointments.');
        }
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            !$dateValue
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $dateValue->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('Date must be a valid YYYY-MM-DD value.');
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            throw new InvalidArgumentException('Time must be a valid 24-hour HH:MM value.');
        }
        if (mb_strlen($notes) > 10000) {
            throw new InvalidArgumentException('Appointment notes are too long.');
        }

        return $this->withTransaction(function () use (
            $doctorUserId, $patientUserId, $departmentId, $date, $time, $notes, $actorUserId, $actorRole
        ): int {
            // Lock the canonical doctor account first. This is the common serialization
            // point used by booking and reschedule operations for the same clinician.
            $doctorStmt = $this->db->prepare(
                "SELECT id, name FROM users
                 WHERE id = ? AND role = 'doctor' AND is_active = 1
                 LIMIT 1 FOR UPDATE"
            );
            $doctorStmt->execute([$doctorUserId]);
            $doctor = $doctorStmt->fetch(PDO::FETCH_ASSOC);
            if (!$doctor) {
                throw new DomainException('The selected doctor account is no longer available.');
            }

            $profileSql = 'SELECT department_id, specialty FROM doctor_profiles WHERE doctor_user_id = ? LIMIT 1 FOR UPDATE';
            $profileStmt = $this->db->prepare($profileSql);
            $profileStmt->execute([$doctorUserId]);
            $profile = $profileStmt->fetch(PDO::FETCH_ASSOC);
            if (!$profile) {
                throw new DomainException('The selected doctor profile is no longer available.');
            }

            $departmentStmt = $this->db->prepare(
                "SELECT id, name, status FROM departments WHERE id = ? LIMIT 1 FOR UPDATE"
            );
            $departmentStmt->execute([$departmentId]);
            $department = $departmentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$department || $department['status'] !== 'active') {
                throw new DomainException('The selected department is no longer accepting bookings.');
            }

            if ($profile['department_id'] !== null) {
                if ((int)$profile['department_id'] !== $departmentId) {
                    throw new DomainException('The selected doctor does not belong to the selected department.');
                }
            } else {
                $specialty = trim((string)($profile['specialty'] ?? ''));
                if ($specialty === '' || mb_strtolower($specialty) !== mb_strtolower(trim((string)$department['name']))) {
                    throw new DomainException('The selected doctor does not belong to the selected department.');
                }
            }

            $this->assertActorCanBook($actorUserId, $actorRole, $patientUserId);

            $schedule = new ScheduleService($this->db, 'v2');
            $validationError = $schedule->validateBookingSlot($doctorUserId, $date, $time);
            if ($validationError !== null) {
                throw new DomainException($validationError);
            }

            // Patient locking follows doctor/profile/department locking. This preserves
            // the existing booking vs patient-archive lock order.
            $patientStmt = $this->db->prepare(
                "SELECT id, name FROM users
                 WHERE id = ? AND role = 'patient' AND is_active = 1
                 LIMIT 1 FOR UPDATE"
            );
            $patientStmt->execute([$patientUserId]);
            $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
            if (!$patient) {
                throw new DomainException('The patient account is unavailable for new bookings.');
            }

            $duration = (int)($schedule->getSchedule($doctorUserId)['settings']['appointment_duration'] ?? 30);
            if ($duration <= 0) {
                $duration = 30;
            }
            $startsAt = $date . ' ' . $time . ':00';
            $timeRange = AppointmentTime::range($time, $duration);
            $doctorName = (string)$doctor['name'];
            $patientName = (string)$patient['name'];
            $departmentName = (string)$department['name'];

            $insert = $this->db->prepare(
                "INSERT INTO appointments
                        (patient_user_id, doctor_user_id, department_id,
                         patient_name_snapshot, doctor_name_snapshot, department_name_snapshot,
                         starts_at, duration_minutes, booking_status, notes, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())"
            );
            $insert->execute([
                $patientUserId, $doctorUserId, $departmentId,
                $patientName, $doctorName, $departmentName,
                $startsAt, $duration, $notes,
            ]);

            $appointmentId = (int)$this->db->lastInsertId();
            if ($appointmentId <= 0) {
                throw new RuntimeException('The appointment ID could not be generated.');
            }

            $this->writeAudit(
                $actorUserId,
                $actorRole,
                'book',
                $appointmentId,
                null,
                [
                    'doctor_user_id' => $doctorUserId,
                    'patient_user_id' => $patientUserId,
                    'department_id' => $departmentId,
                    'starts_at' => $startsAt,
                    'duration_minutes' => $duration,
                    'booking_status' => 'pending',
                ],
                "Appointment booked: {$patientName} with Dr. {$doctorName} on {$date} at {$time}.",
                $patientUserId,
                $doctorUserId
            );

            $this->writeAppointmentEvent(
                $appointmentId,
                $actorUserId,
                $actorRole,
                'booked',
                null,
                'pending',
                ['starts_at' => $startsAt, 'duration_minutes' => $duration]
            );

            $notifications = new NotificationService($this->db);
            $displayRange = $timeRange;
            $notifications->create(
                $doctorUserId,
                NotificationService::TYPE_APPOINTMENT_REQUEST,
                'New Booking Request',
                "{$patientName} booked an appointment on {$date} ({$displayRange}) for {$departmentName}.",
                'appointment',
                $appointmentId
            );

            if ($actorRole === 'admin' && $patientUserId !== $actorUserId) {
                $notifications->create(
                    $patientUserId,
                    NotificationService::TYPE_APPOINTMENT_REQUEST,
                    'Appointment Booked for You',
                    "An appointment has been booked for you with Dr. {$doctorName} on {$date} ({$displayRange}) for {$departmentName}.",
                    'appointment',
                    $appointmentId
                );
            }

            return $appointmentId;
        });
    }

    private function assertActorCanBook(int $actorUserId, string $actorRole, int $patientUserId): void
    {
        if ($actorRole === 'patient') {
            if ($actorUserId !== $patientUserId) {
                throw new DomainException('Patients can only book appointments for their own account.');
            }
            return;
        }

        $stmt = $this->db->prepare(
            "SELECT id FROM users WHERE id = ? AND role = 'admin' AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([$actorUserId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('An active administrator is required for an assisted booking.');
        }
    }

    private function writeAudit(
        int $actorUserId,
        string $actorRole,
        string $action,
        int $appointmentId,
        mixed $oldValue,
        mixed $newValue,
        string $description,
        ?int $patientUserId,
        ?int $doctorUserId
    ): void {
        $oldJson = $oldValue === null ? null : (is_string($oldValue) ? $oldValue : json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $newJson = $newValue === null ? null : (is_string($newValue) ? $newValue : json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $stmt = $this->db->prepare(
            'INSERT INTO audit_events
                (actor_user_id, actor_role_snapshot, action_code, entity_type, entity_id,
                 patient_user_id, doctor_user_id, old_value, new_value, description, ip_address, user_agent)
             VALUES (?, ?, ?, \'appointment\', ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $actorUserId, $actorRole, $action, (string)$appointmentId,
            $patientUserId, $doctorUserId, $oldJson, $newJson, $description,
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function writeAppointmentEvent(
        int $appointmentId,
        int $actorUserId,
        string $actorRole,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        array $details = []
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO appointment_events
                (appointment_id, actor_user_id, actor_role_snapshot, event_type,
                 from_status, to_status, event_details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $appointmentId, $actorUserId, $actorRole, $eventType,
            $fromStatus, $toStatus,
            $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
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
