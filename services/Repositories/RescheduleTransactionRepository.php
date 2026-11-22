<?php
declare(strict_types=1);

require_once __DIR__ . '/../ScheduleService.php';
require_once __DIR__ . '/../NotificationService.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';

/**
 * Transaction-aware reschedule lifecycle adapter for v1 and v2.
 *
 * Lock order: doctor account -> doctor profile -> appointment -> active reschedule row.
 * Patient identity is read from the locked appointment but the patient account is not
 * locked; this avoids reversing the patient-archive lock order.
 *
 * V1 stores state on appointments. V2 stores every request as an immutable-history
 * child row in appointment_reschedule_requests and moves appointments.starts_at only
 * when the request is approved/accepted.
 */
final class RescheduleTransactionRepository
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('RescheduleTransactionRepository schema must be explicitly v1 or v2.');
        }
        $this->db = $db;
        $this->schema = $schema;
    }



    /**
     * Existing API DTO for a doctor's pending/suggested reschedule list.
     * V2 child rows are flattened back into the v1 response keys.
     */
    public function getPendingRequestsForDoctor(int $doctorUserId): array
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }

        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare(
                "SELECT a.*, u.name AS patient_name
                 FROM appointments a
                 JOIN users u ON a.user_id = u.id
                 WHERE a.doctor_id = ?
                   AND a.status = 'Reschedule Requested'
                   AND a.reschedule_status IN ('pending','suggested')
                 ORDER BY a.reschedule_requested_at DESC"
            );
            $stmt->execute([$doctorUserId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->db->prepare($this->v2PendingRequestsSql() . '
            WHERE a.doctor_user_id = ?
              AND a.booking_status = \'confirmed\'
              AND r.request_status IN (\'requested\',\'suggested\')
            ORDER BY r.requested_at DESC, r.id DESC');
        $stmt->execute([$doctorUserId]);
        return $this->hydrateV2PendingRequests($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Existing API DTO for the administrator pending/suggested reschedule list. */
    public function getAllPendingRequests(): array
    {
        if ($this->schema === self::SCHEMA_V1) {
            return $this->db->query(
                "SELECT a.*, u.name AS patient_name, doc.name AS doctor_name
                 FROM appointments a
                 JOIN users u ON a.user_id = u.id
                 JOIN users doc ON a.doctor_id = doc.id
                 WHERE a.status = 'Reschedule Requested'
                   AND a.reschedule_status IN ('pending','suggested')
                 ORDER BY a.reschedule_requested_at DESC"
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        $stmt = $this->db->query($this->v2PendingRequestsSql() . "
            WHERE a.booking_status = 'confirmed'
              AND r.request_status IN ('requested','suggested')
            ORDER BY r.requested_at DESC, r.id DESC");
        return $this->hydrateV2PendingRequests($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Existing API DTO for the most recent reschedule request attached to an appointment. */
    public function getRescheduleHistory(int $appointmentId): ?array
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }

        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare(
                "SELECT id, pending_reschedule_date, pending_reschedule_time,
                        requested_reschedule_date, requested_reschedule_time,
                        reschedule_reason, reschedule_requested_at, reschedule_requested_by,
                        reschedule_status, reschedule_responded_at, reschedule_responded_by,
                        reschedule_response_notes
                 FROM appointments WHERE id = ? LIMIT 1"
            );
            $stmt->execute([$appointmentId]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $stmt = $this->db->prepare(
            "SELECT r.*, a.starts_at, a.duration_minutes
             FROM appointment_reschedule_requests r
             JOIN appointments a ON a.id = r.appointment_id
             WHERE r.appointment_id = ?
             ORDER BY r.id DESC LIMIT 1"
        );
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $exists = $this->db->prepare('SELECT id FROM appointments WHERE id=? LIMIT 1');
            $exists->execute([$appointmentId]);
            if (!$exists->fetchColumn()) {
                return null;
            }

            // Match the legacy API's default row for an appointment that has
            // never had a reschedule request.
            return [
                'id' => $appointmentId,
                'pending_reschedule_date' => null,
                'pending_reschedule_time' => null,
                'requested_reschedule_date' => null,
                'requested_reschedule_time' => null,
                'reschedule_reason' => null,
                'reschedule_requested_at' => null,
                'reschedule_requested_by' => null,
                'reschedule_status' => 'none',
                'reschedule_responded_at' => null,
                'reschedule_responded_by' => null,
                'reschedule_response_notes' => null,
            ];
        }

        return $this->hydrateV2RequestFields($row, $appointmentId);
    }

    private function v2PendingRequestsSql(): string
    {
        return "SELECT a.*, p.name AS patient_name, doc.name AS doctor_name,
                       r.id AS reschedule_request_id, r.request_status,
                       r.original_starts_at, r.requested_starts_at, r.proposed_starts_at,
                       r.requested_by_user_id, r.responded_by_user_id,
                       r.reason AS request_reason, r.requested_at, r.responded_at,
                       r.response_notes
                FROM appointments a
                JOIN appointment_reschedule_requests r ON r.appointment_id = a.id
                JOIN users p ON p.id = a.patient_user_id
                JOIN users doc ON doc.id = a.doctor_user_id";
    }

    private function hydrateV2PendingRequests(array $rows): array
    {
        foreach ($rows as &$row) {
            $appointmentId = (int)$row['id'];
            $this->hydrateV2RequestFields($row, $appointmentId);
        }
        unset($row);
        return $rows;
    }

    private function hydrateV2RequestFields(array &$row, int $appointmentId): array
    {
        $requested = $row['requested_starts_at'] ?? null;
        $proposed = $row['proposed_starts_at'] ?? null;
        $rawStatus = (string)($row['request_status'] ?? '');
        $status = $rawStatus === 'cancelled' ? 'none' : $rawStatus;
        $pending = match ($rawStatus) {
            'suggested' => $proposed ?: $requested,
            'approved', 'rejected' => $proposed ?: $requested,
            'cancelled' => null,
            default => $requested,
        };
        $base = (string)($row['starts_at'] ?? $row['original_starts_at'] ?? '');

        $row['id'] = $appointmentId;
        $row['user_id'] = (int)($row['patient_user_id'] ?? 0);
        $row['patient_id'] = (int)($row['patient_user_id'] ?? 0);
        $row['doctor_id'] = (int)($row['doctor_user_id'] ?? 0);
        $row['doctor'] = (string)($row['doctor_name_snapshot'] ?? $row['doctor_name'] ?? '');
        $row['department'] = (string)($row['department_name_snapshot'] ?? '');
        $row['status'] = 'Reschedule Requested';
        $row['date'] = $base !== '' ? substr($base, 0, 10) : null;
        $row['time'] = $base !== '' ? substr($base, 11, 5) : null;
        $row['appointment_time_range'] = $base !== ''
            ? AppointmentTime::range(substr($base, 11, 5), max(1, (int)($row['duration_minutes'] ?? 30)))
            : null;
        $row['reschedule_status'] = $status;
        $row['pending_reschedule_date'] = $pending ? substr((string)$pending, 0, 10) : null;
        $row['pending_reschedule_time'] = $pending ? substr((string)$pending, 11, 5) : null;
        $row['requested_reschedule_date'] = $requested ? substr((string)$requested, 0, 10) : null;
        $row['requested_reschedule_time'] = $requested ? substr((string)$requested, 11, 5) : null;
        $row['reschedule_reason'] = $row['request_reason'] ?? $row['reason'] ?? null;
        $row['reschedule_requested_at'] = $row['requested_at'] ?? null;
        $row['reschedule_requested_by'] = $row['requested_by_user_id'] ?? null;
        $row['reschedule_responded_at'] = $row['responded_at'] ?? null;
        $row['reschedule_responded_by'] = $row['responded_by_user_id'] ?? null;
        $row['reschedule_response_notes'] = $row['response_notes'] ?? null;
        $row['reschedule_request_id'] = isset($row['reschedule_request_id'])
            ? (int)$row['reschedule_request_id']
            : null;

        return $row;
    }

    public function requestReschedule(
        int $appointmentId,
        int $actorUserId,
        string $actorRole,
        string $newDate,
        string $newTime,
        string $reason = ''
    ): array {
        $newStartsAt = $this->normalizeStartsAt($newDate, $newTime);
        if (!in_array($actorRole, ['patient', 'admin'], true)) {
            throw new DomainException('Only patients and administrators can request reschedules.');
        }

        return $this->withTransaction(function () use ($appointmentId, $actorUserId, $actorRole, $newStartsAt, $reason): array {
            $context = $this->lockContext($appointmentId);
            $this->assertActor($context, $actorUserId, $actorRole, ['patient', 'admin']);

            if ($this->schema === self::SCHEMA_V1) {
                if ($context['booking_status'] !== 'confirmed'
                    || !in_array($context['legacy_reschedule_status'], ['none', 'approved', 'rejected'], true)) {
                    throw new DomainException('Only confirmed appointments without an active request can be rescheduled.');
                }
            } else {
                if ($context['active_request'] !== null || $context['booking_status'] !== 'confirmed') {
                    throw new DomainException('An active reschedule request already exists or the appointment is not confirmed.');
                }
            }

            if ($context['starts_at'] === $newStartsAt) {
                throw new DomainException('The new appointment time must be different from the current time.');
            }
            $this->validateTargetSlot($context, $newStartsAt);

            if ($this->schema === self::SCHEMA_V1) {
                $date = substr($newStartsAt, 0, 10);
                $time = substr($newStartsAt, 11, 5);
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        status = 'Reschedule Requested',
                        reschedule_status = 'pending',
                        pending_reschedule_date = ?,
                        pending_reschedule_time = ?,
                        requested_reschedule_date = ?,
                        requested_reschedule_time = ?,
                        reschedule_reason = ?,
                        reschedule_requested_at = NOW(),
                        reschedule_requested_by = ?,
                        reschedule_responded_at = NULL,
                        reschedule_responded_by = NULL,
                        reschedule_response_notes = NULL
                     WHERE id = ? AND status = 'Confirmed'
                       AND reschedule_status IN ('none','approved','rejected')"
                );
                $stmt->execute([$date, $time, $date, $time, $reason, $actorUserId, $appointmentId]);
                $this->assertOneRowChanged($stmt, 'The appointment changed before the request could be saved.');
            } else {
                $stmt = $this->db->prepare(
                    'INSERT INTO appointment_reschedule_requests
                        (appointment_id, requested_by_user_id, original_starts_at,
                         requested_starts_at, request_status, reason, requested_at, created_at)
                     VALUES (?, ?, ?, ?, \'requested\', ?, NOW(), NOW())'
                );
                $stmt->execute([
                    $appointmentId, $actorUserId, $context['starts_at'], $newStartsAt, $reason,
                ]);
            }

            $this->writeEvent(
                $appointmentId, $actorUserId, $actorRole, 'reschedule_requested',
                'confirmed', 'reschedule_requested',
                ['original_starts_at' => $context['starts_at'], 'requested_starts_at' => $newStartsAt, 'reason' => $reason]
            );
            $this->writeAudit(
                $actorUserId, $actorRole, 'reschedule_request', $appointmentId,
                ['starts_at' => $context['starts_at']],
                ['requested_starts_at' => $newStartsAt, 'reason' => $reason],
                'Patient requested appointment reschedule.',
                $context['patient_user_id'], $context['doctor_user_id']
            );
            $this->notifyRequestCreated($context, $appointmentId, $newStartsAt, $reason);

            return ['success' => true, 'appointment_id' => $appointmentId, 'request_status' => 'requested'];
        });
    }

    public function suggestReschedule(
        int $appointmentId,
        int $actorUserId,
        string $actorRole,
        string $suggestedDate,
        string $suggestedTime,
        string $notes = ''
    ): array {
        $proposedStartsAt = $this->normalizeStartsAt($suggestedDate, $suggestedTime);
        if (!in_array($actorRole, ['doctor', 'admin'], true)) {
            throw new DomainException('Only doctors and administrators can suggest reschedule times.');
        }

        return $this->withTransaction(function () use ($appointmentId, $actorUserId, $actorRole, $proposedStartsAt, $notes): array {
            $context = $this->lockContext($appointmentId);
            $this->assertActor($context, $actorUserId, $actorRole, ['doctor', 'admin']);
            $state = $this->currentRequestStatus($context);
            if ($state !== 'requested') {
                throw new DomainException('No pending reschedule request for this appointment.');
            }

            $this->validateTargetSlot($context, $proposedStartsAt);
            $oldRequested = $context['requested_starts_at'];

            if ($this->schema === self::SCHEMA_V1) {
                $note = ' | Doctor suggested: ' . $proposedStartsAt . ($notes !== '' ? '. Notes: ' . $notes : '');
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        pending_reschedule_date = ?,
                        pending_reschedule_time = ?,
                        reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?),
                        reschedule_status = 'suggested',
                        reschedule_responded_at = NOW(),
                        reschedule_responded_by = ?
                     WHERE id = ? AND status = 'Reschedule Requested' AND reschedule_status = 'pending'"
                );
                $stmt->execute([
                    substr($proposedStartsAt, 0, 10), substr($proposedStartsAt, 11, 5),
                    $note, $actorUserId, $appointmentId,
                ]);
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE appointment_reschedule_requests
                     SET request_status = 'suggested', proposed_starts_at = ?,
                         responded_by_user_id = ?, responded_at = NOW(),
                         response_notes = ?
                     WHERE id = ? AND request_status = 'requested'"
                );
                $stmt->execute([$proposedStartsAt, $actorUserId, $notes, (int)$context['active_request']['id']]);
            }
            $this->assertOneRowChanged($stmt, 'The reschedule request changed before the suggestion could be saved.');

            $this->writeEvent(
                $appointmentId, $actorUserId, $actorRole, 'reschedule_suggested',
                'requested', 'suggested',
                ['requested_starts_at' => $oldRequested, 'proposed_starts_at' => $proposedStartsAt, 'notes' => $notes]
            );
            $this->writeAudit(
                $actorUserId, $actorRole, 'reschedule_suggested', $appointmentId,
                ['requested_starts_at' => $oldRequested],
                ['proposed_starts_at' => $proposedStartsAt, 'notes' => $notes],
                'Doctor suggested an alternative appointment time.',
                $context['patient_user_id'], $context['doctor_user_id']
            );
            $this->notifySuggested($context, $appointmentId, $proposedStartsAt, $notes);

            return ['success' => true, 'appointment_id' => $appointmentId, 'request_status' => 'suggested'];
        });
    }

    public function acceptSuggestion(int $appointmentId, int $actorUserId, string $actorRole): array
    {
        if (!in_array($actorRole, ['patient', 'admin'], true)) {
            throw new DomainException('Only patients and administrators can accept suggestions.');
        }

        return $this->withTransaction(function () use ($appointmentId, $actorUserId, $actorRole): array {
            $context = $this->lockContext($appointmentId);
            $this->assertActor($context, $actorUserId, $actorRole, ['patient', 'admin']);
            if ($this->currentRequestStatus($context) !== 'suggested') {
                throw new DomainException('No pending suggestion for this appointment.');
            }
            $newStartsAt = $this->currentProposedStartsAt($context);
            if ($newStartsAt === null) {
                throw new DomainException('Suggestion data is incomplete.');
            }
            $this->validateTargetSlot($context, $newStartsAt);
            $this->moveAppointment($context, $newStartsAt);

            if ($this->schema === self::SCHEMA_V1) {
                $this->updateLegacyTerminalRequest($appointmentId, 'approved', $actorUserId, 'Patient accepted suggested time.');
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE appointment_reschedule_requests
                     SET request_status='approved', responded_by_user_id=?, responded_at=NOW()
                     WHERE id=? AND request_status='suggested'"
                );
                $stmt->execute([$actorUserId, (int)$context['active_request']['id']]);
                $this->assertOneRowChanged($stmt, 'The suggestion changed before it could be accepted.');
            }

            $this->writeEvent(
                $appointmentId, $actorUserId, $actorRole, 'reschedule_suggestion_accepted',
                'confirmed', 'confirmed', ['starts_at' => $newStartsAt]
            );
            $this->writeAudit(
                $actorUserId, $actorRole, 'reschedule_suggestion_accepted', $appointmentId,
                ['starts_at' => $context['starts_at']], ['starts_at' => $newStartsAt],
                'Patient accepted the doctor suggested time.',
                $context['patient_user_id'], $context['doctor_user_id']
            );
            $this->notifyAccepted($context, $appointmentId, $newStartsAt);

            return ['success' => true, 'appointment_id' => $appointmentId, 'request_status' => 'approved'];
        });
    }

    public function declineSuggestion(int $appointmentId, int $actorUserId, string $actorRole): array
    {
        if (!in_array($actorRole, ['patient', 'admin'], true)) {
            throw new DomainException('Only patients and administrators can decline suggestions.');
        }

        return $this->withTransaction(function () use ($appointmentId, $actorUserId, $actorRole): array {
            $context = $this->lockContext($appointmentId);
            $this->assertActor($context, $actorUserId, $actorRole, ['patient', 'admin']);
            if ($this->currentRequestStatus($context) !== 'suggested') {
                throw new DomainException('No pending suggestion for this appointment.');
            }

            $requestedStartsAt = $context['requested_starts_at'];
            if ($requestedStartsAt === null) {
                throw new DomainException('The original requested time was not retained.');
            }

            if ($this->schema === self::SCHEMA_V1) {
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        pending_reschedule_date=?, pending_reschedule_time=?,
                        reschedule_status='pending',
                        reschedule_responded_at=NOW(), reschedule_responded_by=?,
                        reschedule_response_notes=CONCAT(COALESCE(reschedule_response_notes,''), ?)
                     WHERE id=? AND status='Reschedule Requested' AND reschedule_status='suggested'"
                );
                $stmt->execute([
                    substr($requestedStartsAt, 0, 10), substr($requestedStartsAt, 11, 5),
                    $actorUserId, ' | Patient declined suggested time.', $appointmentId,
                ]);
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE appointment_reschedule_requests
                     SET request_status='requested', proposed_starts_at=NULL,
                         responded_by_user_id=?, responded_at=NOW(),
                         response_notes=CONCAT(COALESCE(response_notes,''), ?)
                     WHERE id=? AND request_status='suggested'"
                );
                $stmt->execute([
                    $actorUserId, 'Patient declined suggested time.',
                    (int)$context['active_request']['id'],
                ]);
            }
            $this->assertOneRowChanged($stmt, 'The suggestion changed before it could be declined.');

            $this->writeEvent(
                $appointmentId, $actorUserId, $actorRole, 'reschedule_suggestion_declined',
                'suggested', 'requested', ['requested_starts_at' => $requestedStartsAt]
            );
            $this->writeAudit(
                $actorUserId, $actorRole, 'reschedule_suggestion_declined', $appointmentId,
                ['proposed_starts_at' => $context['proposed_starts_at']],
                ['request_status' => 'requested', 'requested_starts_at' => $requestedStartsAt],
                'Patient declined the doctor suggested time.',
                $context['patient_user_id'], $context['doctor_user_id']
            );
            $this->notifyDeclined($context, $appointmentId);

            return ['success' => true, 'appointment_id' => $appointmentId, 'request_status' => 'requested'];
        });
    }

    public function cancelRequest(int $appointmentId, int $actorUserId, string $actorRole): array
    {
        if (!in_array($actorRole, ['patient', 'admin'], true)) {
            throw new DomainException('Only patients and administrators can cancel reschedule requests.');
        }

        return $this->withTransaction(function () use ($appointmentId, $actorUserId, $actorRole): array {
            $context = $this->lockContext($appointmentId);
            $this->assertActor($context, $actorUserId, $actorRole, ['patient', 'admin']);
            $state = $this->currentRequestStatus($context);
            if (!in_array($state, ['requested', 'suggested'], true)) {
                throw new DomainException('No active reschedule request to cancel.');
            }

            if ($this->schema === self::SCHEMA_V1) {
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET status='Confirmed', reschedule_status='none',
                         pending_reschedule_date=NULL, pending_reschedule_time=NULL,
                         reschedule_responded_at=NOW(), reschedule_responded_by=?,
                         reschedule_response_notes=CONCAT(COALESCE(reschedule_response_notes,''), ?)
                     WHERE id=? AND status='Reschedule Requested'
                       AND reschedule_status IN ('pending','suggested')"
                );
                $stmt->execute([$actorUserId, ' | Patient cancelled reschedule request.', $appointmentId]);
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE appointment_reschedule_requests
                     SET request_status='cancelled', responded_by_user_id=?, responded_at=NOW(),
                         response_notes=CONCAT(COALESCE(response_notes,''), ?)
                     WHERE id=? AND request_status IN ('requested','suggested')"
                );
                $stmt->execute([
                    $actorUserId, 'Patient cancelled reschedule request.',
                    (int)$context['active_request']['id'],
                ]);
            }
            $this->assertOneRowChanged($stmt, 'The reschedule request changed before it could be cancelled.');

            $this->writeEvent(
                $appointmentId, $actorUserId, $actorRole, 'reschedule_cancelled',
                'reschedule_requested', 'confirmed', ['previous_request_status' => $state]
            );
            $this->writeAudit(
                $actorUserId, $actorRole, 'reschedule_cancelled', $appointmentId,
                ['request_status' => $state],
                ['request_status' => 'cancelled'],
                'Patient cancelled the reschedule request.',
                $context['patient_user_id'], $context['doctor_user_id']
            );
            $this->notifyCancelled($context, $appointmentId);

            return ['success' => true, 'appointment_id' => $appointmentId, 'request_status' => 'cancelled'];
        });
    }

    public function approveRequest(int $appointmentId, int $actorUserId, string $actorRole, string $doctorNotes = ''): array
    {
        if (!in_array($actorRole, ['doctor', 'admin'], true)) {
            throw new DomainException('Only doctors and administrators can approve reschedules.');
        }

        return $this->withTransaction(function () use ($appointmentId, $actorUserId, $actorRole, $doctorNotes): array {
            $context = $this->lockContext($appointmentId);
            $this->assertActor($context, $actorUserId, $actorRole, ['doctor', 'admin']);
            if ($this->currentRequestStatus($context) !== 'requested') {
                throw new DomainException('Only a pending patient request can be approved. Resolve a suggested time first.');
            }
            $newStartsAt = $context['requested_starts_at'];
            if ($newStartsAt === null) {
                throw new DomainException('Reschedule request data is incomplete.');
            }
            $this->validateTargetSlot($context, $newStartsAt);
            $this->moveAppointment($context, $newStartsAt);

            if ($this->schema === self::SCHEMA_V1) {
                $this->updateLegacyTerminalRequest($appointmentId, 'approved', $actorUserId, ' | Approved: ' . ($doctorNotes ?: 'Approved by doctor.'));
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE appointment_reschedule_requests
                     SET request_status='approved', responded_by_user_id=?, responded_at=NOW(),
                         response_notes=?
                     WHERE id=? AND request_status='requested'"
                );
                $stmt->execute([$actorUserId, $doctorNotes, (int)$context['active_request']['id']]);
                $this->assertOneRowChanged($stmt, 'The reschedule request changed before approval.');
            }

            $this->writeEvent(
                $appointmentId, $actorUserId, $actorRole, 'reschedule_approved',
                'confirmed', 'confirmed', ['starts_at' => $newStartsAt, 'notes' => $doctorNotes]
            );
            $this->writeAudit(
                $actorUserId, $actorRole, 'reschedule_approved', $appointmentId,
                ['starts_at' => $context['starts_at']], ['starts_at' => $newStartsAt, 'notes' => $doctorNotes],
                'Doctor approved appointment reschedule.',
                $context['patient_user_id'], $context['doctor_user_id']
            );
            $this->notifyApproved($context, $appointmentId, $newStartsAt, $doctorNotes);

            return ['success' => true, 'appointment_id' => $appointmentId, 'request_status' => 'approved'];
        });
    }

    public function rejectRequest(int $appointmentId, int $actorUserId, string $actorRole, string $doctorNotes = ''): array
    {
        if (!in_array($actorRole, ['doctor', 'admin'], true)) {
            throw new DomainException('Only doctors and administrators can reject reschedules.');
        }

        return $this->withTransaction(function () use ($appointmentId, $actorUserId, $actorRole, $doctorNotes): array {
            $context = $this->lockContext($appointmentId);
            $this->assertActor($context, $actorUserId, $actorRole, ['doctor', 'admin']);
            $state = $this->currentRequestStatus($context);
            if (!in_array($state, ['requested', 'suggested'], true)) {
                throw new DomainException('No pending reschedule request for this appointment.');
            }

            if ($this->schema === self::SCHEMA_V1) {
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET status='Confirmed', reschedule_status='rejected',
                         reschedule_responded_at=NOW(), reschedule_responded_by=?,
                         reschedule_response_notes=CONCAT(COALESCE(reschedule_response_notes,''), ?)
                     WHERE id=? AND status='Reschedule Requested'
                       AND reschedule_status IN ('pending','suggested')"
                );
                $stmt->execute([$actorUserId, ' | Rejected: ' . ($doctorNotes ?: 'Rejected by doctor.'), $appointmentId]);
            } else {
                $stmt = $this->db->prepare(
                    "UPDATE appointment_reschedule_requests
                     SET request_status='rejected', responded_by_user_id=?, responded_at=NOW(),
                         response_notes=?
                     WHERE id=? AND request_status IN ('requested','suggested')"
                );
                $stmt->execute([$actorUserId, $doctorNotes, (int)$context['active_request']['id']]);
            }
            $this->assertOneRowChanged($stmt, 'The reschedule request changed before rejection.');

            $this->writeEvent(
                $appointmentId, $actorUserId, $actorRole, 'reschedule_rejected',
                'reschedule_requested', 'confirmed',
                ['previous_request_status' => $state, 'notes' => $doctorNotes]
            );
            $this->writeAudit(
                $actorUserId, $actorRole, 'reschedule_rejected', $appointmentId,
                ['request_status' => $state], ['request_status' => 'rejected', 'notes' => $doctorNotes],
                'Doctor rejected appointment reschedule.',
                $context['patient_user_id'], $context['doctor_user_id']
            );
            $this->notifyRejected($context, $appointmentId, $doctorNotes);

            return ['success' => true, 'appointment_id' => $appointmentId, 'request_status' => 'rejected'];
        });
    }

    private function lockContext(int $appointmentId): array
    {
        if ($appointmentId <= 0) {
            throw new InvalidArgumentException('Appointment ID must be positive.');
        }

        $lookupSql = $this->schema === self::SCHEMA_V1
            ? 'SELECT doctor_id FROM appointments WHERE id=? LIMIT 1'
            : 'SELECT doctor_user_id FROM appointments WHERE id=? LIMIT 1';
        $lookup = $this->db->prepare($lookupSql);
        $lookup->execute([$appointmentId]);
        $doctorUserId = (int)$lookup->fetchColumn();
        if ($doctorUserId <= 0) {
            throw new DomainException('Appointment not found.');
        }

        $doctor = $this->db->prepare(
            "SELECT id FROM users WHERE id=? AND role='doctor' AND is_active=1 LIMIT 1 FOR UPDATE"
        );
        $doctor->execute([$doctorUserId]);
        if (!$doctor->fetchColumn()) {
            throw new DomainException('Assigned doctor account is not active.');
        }

        $profileSql = $this->schema === self::SCHEMA_V1
            ? 'SELECT user_id FROM doctors WHERE user_id=? LIMIT 1 FOR UPDATE'
            : 'SELECT doctor_user_id FROM doctor_profiles WHERE doctor_user_id=? LIMIT 1 FOR UPDATE';
        $profile = $this->db->prepare($profileSql);
        $profile->execute([$doctorUserId]);
        if (!$profile->fetchColumn()) {
            throw new DomainException('Assigned doctor profile was not found.');
        }

        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare('SELECT * FROM appointments WHERE id=? LIMIT 1 FOR UPDATE');
            $stmt->execute([$appointmentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || (int)$row['doctor_id'] !== $doctorUserId) {
                throw new DomainException('Appointment or assigned doctor changed.');
            }
            $startsAt = (string)$row['date'] . ' ' . AppointmentTime::normalize((string)$row['time']) . ':00';
            $activeRequest = null;
            $requested = !empty($row['requested_reschedule_date']) && !empty($row['requested_reschedule_time'])
                ? (string)$row['requested_reschedule_date'] . ' ' . AppointmentTime::normalize((string)$row['requested_reschedule_time']) . ':00'
                : null;
            $proposed = !empty($row['pending_reschedule_date']) && !empty($row['pending_reschedule_time'])
                ? (string)$row['pending_reschedule_date'] . ' ' . AppointmentTime::normalize((string)$row['pending_reschedule_time']) . ':00'
                : null;
            $status = $row['status'] === 'Confirmed' || $row['status'] === 'Reschedule Requested'
                ? 'confirmed'
                : strtolower((string)$row['status']);

            return [
                'id' => $appointmentId,
                'patient_user_id' => (int)$row['user_id'],
                'doctor_user_id' => $doctorUserId,
                'starts_at' => $startsAt,
                'booking_status' => $status,
                'legacy_status' => (string)$row['status'],
                'legacy_reschedule_status' => (string)$row['reschedule_status'],
                'requested_starts_at' => $requested,
                'proposed_starts_at' => $proposed,
                'active_request' => $activeRequest,
                'duration_minutes' => $this->legacyAppointmentDuration($row),
                'patient_name' => (string)$row['patient_name'],
                'doctor_name' => (string)$row['doctor'],
            ];
        }

        $stmt = $this->db->prepare('SELECT * FROM appointments WHERE id=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['doctor_user_id'] !== $doctorUserId) {
            throw new DomainException('Appointment or assigned doctor changed.');
        }

        $requestStmt = $this->db->prepare(
            "SELECT * FROM appointment_reschedule_requests
             WHERE appointment_id=? AND request_status IN ('requested','suggested')
             ORDER BY id DESC LIMIT 1 FOR UPDATE"
        );
        $requestStmt->execute([$appointmentId]);
        $activeRequest = $requestStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        return [
            'id' => $appointmentId,
            'patient_user_id' => (int)$row['patient_user_id'],
            'doctor_user_id' => $doctorUserId,
            'starts_at' => (string)$row['starts_at'],
            'booking_status' => (string)$row['booking_status'],
            'legacy_status' => null,
            'legacy_reschedule_status' => null,
            'requested_starts_at' => $activeRequest['requested_starts_at'] ?? null,
            'proposed_starts_at' => $activeRequest['proposed_starts_at'] ?? null,
            'active_request' => $activeRequest,
            'duration_minutes' => (int)$row['duration_minutes'],
            'patient_name' => (string)$row['patient_name_snapshot'],
            'doctor_name' => (string)$row['doctor_name_snapshot'],
        ];
    }

    private function currentRequestStatus(array $context): ?string
    {
        // Legacy cancelled appointments can retain stale embedded reschedule flags.
        // V2 deactivation normally closes child requests, but an active request
        // must never be actionable once its parent booking has been cancelled.
        if (($context['booking_status'] ?? null) !== 'confirmed') {
            return null;
        }

        if ($this->schema === self::SCHEMA_V1) {
            if (($context['legacy_status'] ?? null) !== 'Reschedule Requested') {
                return null;
            }
            return match ($context['legacy_reschedule_status']) {
                'pending' => 'requested',
                'suggested' => 'suggested',
                default => null,
            };
        }

        return $context['active_request']['request_status'] ?? null;
    }

    private function currentProposedStartsAt(array $context): ?string
    {
        if ($this->schema === self::SCHEMA_V1) {
            if (!$context['proposed_starts_at']) {
                return null;
            }
            return $context['proposed_starts_at'];
        }
        return $context['proposed_starts_at'];
    }

    private function validateTargetSlot(array $context, string $startsAt): void
    {
        $date = substr($startsAt, 0, 10);
        $time = substr($startsAt, 11, 5);
        $schedule = new ScheduleService($this->db, $this->schema);
        $error = $schedule->validateBookingSlot(
            (int)$context['doctor_user_id'],
            $date,
            $time,
            (int)$context['id']
        );
        if ($error !== null) {
            throw new DomainException($error);
        }
    }

    private function moveAppointment(array $context, string $newStartsAt): void
    {
        $date = substr($newStartsAt, 0, 10);
        $time = substr($newStartsAt, 11, 5);
        if ($this->schema === self::SCHEMA_V1) {
            $range = AppointmentTime::range($time, (int)$context['duration_minutes']);
            $stmt = $this->db->prepare(
                "UPDATE appointments SET date=?, time=?, appointment_time_range=?
                 WHERE id=? AND status='Reschedule Requested'"
            );
            $stmt->execute([$date, $time, $range, (int)$context['id']]);
        } else {
            $stmt = $this->db->prepare(
                "UPDATE appointments SET starts_at=?, booking_status='confirmed'
                 WHERE id=? AND booking_status='confirmed'"
            );
            $stmt->execute([$newStartsAt, (int)$context['id']]);
        }
        $this->assertOneRowChanged($stmt, 'Appointment time changed before the reschedule could be applied.');
    }

    private function updateLegacyTerminalRequest(int $appointmentId, string $status, int $actorUserId, string $note): void
    {
        $stmt = $this->db->prepare(
            "UPDATE appointments SET status='Confirmed', reschedule_status=?, reschedule_responded_at=NOW(),
                 reschedule_responded_by=?,
                 reschedule_response_notes=CONCAT(COALESCE(reschedule_response_notes,''), ?)
             WHERE id=? AND status='Reschedule Requested'"
        );
        $stmt->execute([$status, $actorUserId, $note, $appointmentId]);
        $this->assertOneRowChanged($stmt, 'Reschedule request changed before its terminal state was stored.');
    }

    private function legacyAppointmentDuration(array $row): int
    {
        $range = (string)($row['appointment_time_range'] ?? '');
        if ($range !== '' && preg_match('/[-–—]\s*(\d{1,2}):(\d{2})\s*(AM|PM)\s*$/i', $range, $matches)) {
            $hour = (int)$matches[1];
            $minute = (int)$matches[2];
            $period = strtoupper($matches[3]);
            if ($period === 'PM' && $hour !== 12) { $hour += 12; }
            if ($period === 'AM' && $hour === 12) { $hour = 0; }
            $endMinutes = $hour * 60 + $minute;
            $startMinutes = AppointmentTime::toMinutes((string)$row['time']);
            if ($endMinutes > $startMinutes) {
                return $endMinutes - $startMinutes;
            }
        }
        return AppointmentTime::duration($this->db, (int)$row['doctor_id']);
    }

    private function normalizeStartsAt(string $date, string $time): string
    {
        $date = trim($date);
        $time = AppointmentTime::normalize(trim($time));
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
            throw new InvalidArgumentException('Time must be a valid HH:MM value.');
        }
        return $date . ' ' . $time . ':00';
    }

    private function assertActor(array $context, int $actorUserId, string $actorRole, array $allowedRoles): void
    {
        if ($actorUserId <= 0 || !in_array($actorRole, $allowedRoles, true)) {
            throw new DomainException('The authenticated account is not allowed to perform this reschedule action.');
        }
        if ($actorRole === 'patient' && (int)$context['patient_user_id'] !== $actorUserId) {
            throw new DomainException('This appointment does not belong to the authenticated patient.');
        }
        if ($actorRole === 'doctor' && (int)$context['doctor_user_id'] !== $actorUserId) {
            throw new DomainException('This appointment is not assigned to the authenticated doctor.');
        }

        $actor = $this->db->prepare(
            'SELECT id FROM users WHERE id=? AND role=? AND is_active=1 LIMIT 1'
        );
        $actor->execute([$actorUserId, $actorRole]);
        if (!$actor->fetchColumn()) {
            throw new DomainException('The authenticated account is not active.');
        }
    }

    private function writeEvent(
        int $appointmentId,
        int $actorUserId,
        string $actorRole,
        string $eventType,
        ?string $fromStatus,
        ?string $toStatus,
        array $details = []
    ): void {
        if ($this->schema !== self::SCHEMA_V2) {
            return;
        }
        $stmt = $this->db->prepare(
            'INSERT INTO appointment_events
                (appointment_id, actor_user_id, actor_role_snapshot, event_type,
                 from_status, to_status, event_details, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([
            $appointmentId, $actorUserId, $actorRole, $eventType, $fromStatus, $toStatus,
            $details ? json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function writeAudit(
        int $actorUserId,
        string $actorRole,
        string $action,
        int $appointmentId,
        mixed $oldValue,
        mixed $newValue,
        string $description,
        int $patientUserId,
        int $doctorUserId
    ): void {
        $oldJson = $oldValue === null ? null : json_encode($oldValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $newJson = $newValue === null ? null : json_encode($newValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($this->schema === self::SCHEMA_V1) {
            $stmt = $this->db->prepare(
                'INSERT INTO admin_audit
                    (actor_id, actor_role, action, entity_type, entity_id, patient_id, doctor_id,
                     old_value, new_value, description, ip_address, user_agent)
                 VALUES (?, ?, ?, \'appointment\', ?, ?, ?, ?, ?, ?, ?, ?)'
            );
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO audit_events
                    (actor_user_id, actor_role_snapshot, action_code, entity_type, entity_id,
                     patient_user_id, doctor_user_id, old_value, new_value, description, ip_address, user_agent)
                 VALUES (?, ?, ?, \'appointment\', ?, ?, ?, ?, ?, ?, ?, ?)'
            );
        }
        $stmt->execute([
            $actorUserId, $actorRole, $action, (string)$appointmentId,
            $patientUserId, $doctorUserId, $oldJson, $newJson, $description,
            $_SERVER['REMOTE_ADDR'] ?? null, $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);
    }

    private function assertOneRowChanged(PDOStatement $stmt, string $message): void
    {
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException($message);
        }
    }

    private function notifyRequestCreated(array $context, int $appointmentId, string $requestedStartsAt, string $reason): void
    {
        $date = substr($requestedStartsAt, 0, 10);
        $time = AppointmentTime::formatDisplay(substr($requestedStartsAt, 11, 5));
        $patientName = $context['patient_name'] ?: 'Patient';
        $message = "{$patientName} requested to reschedule Appointment #{$appointmentId} to {$date} {$time}.";
        if ($reason !== '') { $message .= " Reason: {$reason}"; }
        $this->notify((int)$context['doctor_user_id'], NotificationService::TYPE_RESCHEDULE_REQUEST, 'Reschedule Request', $message, $appointmentId);
        $this->notifyAdmins(NotificationService::TYPE_RESCHEDULE_REQUEST, 'Reschedule Notification', $message, $appointmentId);
        $this->notify((int)$context['patient_user_id'], NotificationService::TYPE_RESCHEDULE_SUBMITTED, 'Reschedule Request Submitted',
            "Your reschedule request for Appointment #{$appointmentId} has been submitted.", $appointmentId);
    }

    private function notifySuggested(array $context, int $appointmentId, string $proposedStartsAt, string $notes): void
    {
        $date = substr($proposedStartsAt, 0, 10);
        $time = AppointmentTime::formatDisplay(substr($proposedStartsAt, 11, 5));
        $message = "Dr. {$context['doctor_name']} suggested a new appointment time for Appointment #{$appointmentId}: {$date} {$time}.";
        if ($notes !== '') { $message .= " Note: {$notes}"; }
        $this->notify((int)$context['patient_user_id'], NotificationService::TYPE_RESCHEDULE_SUGGESTED, 'New Time Suggested', $message, $appointmentId);
        $this->notifyAdmins(NotificationService::TYPE_RESCHEDULE_SUGGESTED, 'Reschedule Notification', $message, $appointmentId);
    }

    private function notifyAccepted(array $context, int $appointmentId, string $startsAt): void
    {
        $time = AppointmentTime::formatDisplay(substr($startsAt, 11, 5));
        $message = "The suggested time for Appointment #{$appointmentId} was accepted: " . substr($startsAt, 0, 10) . " {$time}.";
        $this->notify((int)$context['doctor_user_id'], NotificationService::TYPE_RESCHEDULE_SUGGESTION_ACCEPTED, 'Suggested Time Accepted', $message, $appointmentId);
        $this->notify((int)$context['patient_user_id'], NotificationService::TYPE_RESCHEDULE_APPROVED, 'Suggestion Accepted', $message, $appointmentId);
    }

    private function notifyDeclined(array $context, int $appointmentId): void
    {
        $message = "The suggested time for Appointment #{$appointmentId} was declined. The original requested time is restored for review.";
        $this->notify((int)$context['doctor_user_id'], NotificationService::TYPE_RESCHEDULE_SUGGESTION_DECLINED, 'Suggested Time Declined', $message, $appointmentId);
        $this->notify((int)$context['patient_user_id'], NotificationService::TYPE_RESCHEDULE_SUGGESTION_DECLINED, 'Suggestion Declined', $message, $appointmentId);
    }

    private function notifyCancelled(array $context, int $appointmentId): void
    {
        $message = "The reschedule request for Appointment #{$appointmentId} was cancelled. The original appointment remains scheduled.";
        $this->notify((int)$context['doctor_user_id'], NotificationService::TYPE_RESCHEDULE_REQUEST_CANCELLED, 'Reschedule Cancelled', $message, $appointmentId);
        $this->notify((int)$context['patient_user_id'], NotificationService::TYPE_RESCHEDULE_REQUEST_CANCELLED, 'Reschedule Cancelled', $message, $appointmentId);
    }

    private function notifyApproved(array $context, int $appointmentId, string $startsAt, string $notes): void
    {
        $time = AppointmentTime::formatDisplay(substr($startsAt, 11, 5));
        $message = "Reschedule request for Appointment #{$appointmentId} approved: " . substr($startsAt, 0, 10) . " {$time}.";
        if ($notes !== '') { $message .= " Note: {$notes}"; }
        $this->notify((int)$context['patient_user_id'], NotificationService::TYPE_RESCHEDULE_APPROVED, 'Reschedule Approved', $message, $appointmentId);
        $this->notifyAdmins(NotificationService::TYPE_RESCHEDULE_APPROVED, 'Reschedule Notification', $message, $appointmentId);
    }

    private function notifyRejected(array $context, int $appointmentId, string $notes): void
    {
        $message = "Reschedule request for Appointment #{$appointmentId} was declined. The original appointment remains scheduled.";
        if ($notes !== '') { $message .= " Note: {$notes}"; }
        $this->notify((int)$context['patient_user_id'], NotificationService::TYPE_RESCHEDULE_REJECTED, 'Reschedule Declined', $message, $appointmentId);
        $this->notifyAdmins(NotificationService::TYPE_RESCHEDULE_REJECTED, 'Reschedule Notification', $message, $appointmentId);
    }

    private function notify(int $userId, string $type, string $title, string $message, int $appointmentId): void
    {
        (new NotificationService($this->db))->create($userId, $type, $title, $message, 'appointment', $appointmentId);
    }

    private function notifyAdmins(string $type, string $title, string $message, int $appointmentId): void
    {
        $admins = $this->db->query("SELECT id FROM users WHERE role='admin' AND is_active=1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            $this->notify((int)$adminId, $type, $title, $message, $appointmentId);
        }
    }

    private function withTransaction(callable $operation): mixed
    {
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) { $this->db->beginTransaction(); }
        try {
            $result = $operation();
            if ($ownsTransaction) { $this->db->commit(); }
            return $result;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
