<?php
/**
 * HealthBridge — RescheduleService
 *
 * Handles the complete appointment rescheduling workflow.
 * A reschedule is treated as a multi-step workflow, NOT an automatic update.
 *
 * Workflow:
 *   1. Patient requests a new date/time (status → 'Reschedule Requested')
 *   2. Doctor approves or rejects the request
 *   3. Doctor can suggest an alternative time
 *   4. Patient can accept or decline the suggested alternative
 *   5. Patient can cancel a pending reschedule request
 *   6. On approval: appointment_date/appointment_time are updated
 *   7. On rejection: appointment returns to original schedule
 *
 * This service integrates with:
 *   - ScheduleService (slot validation)
 *   - NotificationService (alerts to all parties)
 *   - AuditService (full audit trail)
 *   - AdminAuditService (admin-facing audit)
 *
 * Usage:
 *   $rs = new RescheduleService(getDB(), $actorId, $actorRole);
 *   $rs->requestReschedule($appointmentId, $newDate, $newTime, $reason);
 *   $rs->suggestReschedule($appointmentId, $suggestedDate, $suggestedTime, $notes);
 *   $rs->approveReschedule($appointmentId, $doctorNotes);
 *   $rs->rejectReschedule($appointmentId, $doctorNotes);
 *   $rs->acceptRescheduleSuggestion($appointmentId);
 *   $rs->declineRescheduleSuggestion($appointmentId);
 *   $rs->cancelRescheduleRequest($appointmentId);
 */

require_once __DIR__ . '/ScheduleService.php';
require_once __DIR__ . '/NotificationService.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/../includes/AppointmentTime.php';
require_once __DIR__ . '/Repositories/RescheduleTransactionRepository.php';

class RescheduleService
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    /**
     * Resolve the opt-in schema for HTTP reschedule routes.
     *
     * Existing deployments remain on V1 unless the server environment
     * explicitly sets HEALTHBRIDGE_RESCHEDULE_SCHEMA=v2. Invalid values fail
     * closed instead of silently selecting a schema.
     */
    public static function schemaFromEnvironment(): string
    {
        $configured = $_ENV['HEALTHBRIDGE_RESCHEDULE_SCHEMA']
            ?? $_SERVER['HEALTHBRIDGE_RESCHEDULE_SCHEMA']
            ?? getenv('HEALTHBRIDGE_RESCHEDULE_SCHEMA');

        if ($configured === false || $configured === null || trim((string)$configured) === '') {
            return self::SCHEMA_V1;
        }

        $schema = strtolower(trim((string)$configured));
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('HEALTHBRIDGE_RESCHEDULE_SCHEMA must be v1 or v2.');
        }

        return $schema;
    }

    private PDO $db;
    private int $actorId;
    private string $actorRole;
    private ?RescheduleTransactionRepository $repository = null;

    public function __construct(PDO $db, int $actorId, string $actorRole = 'patient', string $schema = self::SCHEMA_V1)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('RescheduleService schema must be explicitly v1 or v2.');
        }

        $this->db = $db;
        $this->actorId = $actorId;
        $this->actorRole = in_array($actorRole, ['patient', 'doctor', 'admin'], true) ? $actorRole : 'patient';

        if ($schema === self::SCHEMA_V2) {
            $this->repository = new RescheduleTransactionRepository($db, $schema);
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 1: PATIENT REQUESTS RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function requestReschedule(int $appointmentId, string $newDate, string $newTime, string $reason = ''): array
    {
        if ($this->repository !== null) {
            return $this->runRepositoryMutation(
                fn(): array => $this->repository->requestReschedule($appointmentId, $this->actorId, $this->actorRole, $newDate, $newTime, $reason),
                'Reschedule request submitted successfully. Awaiting doctor approval.',
                'Failed to submit reschedule request.',
                __FUNCTION__
            );
        }
        try {
            if (!in_array($this->actorRole, ['patient', 'admin'], true)) {
                return ['success' => false, 'message' => 'Only patients and administrators can request reschedules.'];
            }

            $dateValue = DateTime::createFromFormat('!Y-m-d', $newDate);
            if (!$dateValue || $dateValue->format('Y-m-d') !== $newDate) {
                return ['success' => false, 'message' => 'Invalid date. Use YYYY-MM-DD.'];
            }

            $normalizedNewTime = AppointmentTime::normalize($newTime);
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $normalizedNewTime)) {
                return ['success' => false, 'message' => 'Invalid time. Use HH:MM or a valid AM/PM time.'];
            }

            // Lock in a consistent order: doctor schedule row first, then appointment.
            $appt = $this->beginLockedRescheduleAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment or assigned doctor not found.'];
            }

            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return $this->rollbackFailure('This appointment does not belong to you.');
            }
            if ($appt['status'] !== 'Confirmed') {
                return $this->rollbackFailure('Only confirmed appointments can be rescheduled.');
            }
            if (!in_array($appt['reschedule_status'], ['none', 'approved', 'rejected'], true)) {
                return $this->rollbackFailure('A reschedule request is already active for this appointment.');
            }

            $normalizedCurrentTime = AppointmentTime::normalize((string)$appt['time']);
            if ($newDate === $appt['date'] && $normalizedNewTime === $normalizedCurrentTime) {
                return $this->rollbackFailure('The new appointment time must be different from the current time.');
            }

            $ss = new ScheduleService($this->db);
            $doctorId = (int)$appt['doctor_id'];
            $validationError = $ss->validateBookingSlot($doctorId, $newDate, $normalizedNewTime, $appointmentId);
            if ($validationError !== null) {
                return $this->rollbackFailure($validationError);
            }

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
                 WHERE id = ?
                   AND status = 'Confirmed'
                   AND reschedule_status IN ('none', 'approved', 'rejected')"
            );
            $stmt->execute([
                $newDate,
                $normalizedNewTime,
                $newDate,
                $normalizedNewTime,
                $reason,
                $this->actorId,
                $appointmentId,
            ]);

            if ($stmt->rowCount() !== 1) {
                return $this->rollbackFailure('The appointment changed before the request could be saved. Refresh and try again.');
            }

                        $this->logRescheduleRequest($appt, $newDate, $normalizedNewTime, $reason);

            $this->sendRescheduleRequestNotifications($appt, $newDate, $normalizedNewTime, $reason);

$this->db->commit();
            $this->dispatchNotificationsAfterCommit('request reschedule');

            return ['success' => true, 'message' => 'Reschedule request submitted successfully. Awaiting doctor approval.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RescheduleService::requestReschedule Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to submit reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2a: DOCTOR SUGGESTS ALTERNATIVE TIME
    // ═══════════════════════════════════════════════════════════

    /**
     * Doctor suggests an alternative time for a pending reschedule.
     * Stores the suggestion in pending_reschedule_* fields.
     * Sets reschedule_status to 'suggested'.
     *
     * @param int    $appointmentId
     * @param string $suggestedDate  YYYY-MM-DD
     * @param string $suggestedTime  HH:MM (24-hour)
     * @param string $notes          Optional notes from doctor
     * @return array{success: bool, message: string}
     */
    public function suggestReschedule(int $appointmentId, string $suggestedDate, string $suggestedTime, string $notes = ''): array
    {
        if ($this->repository !== null) {
            return $this->runRepositoryMutation(
                fn(): array => $this->repository->suggestReschedule($appointmentId, $this->actorId, $this->actorRole, $suggestedDate, $suggestedTime, $notes),
                'Alternative time suggested. Awaiting patient response.',
                'Failed to suggest alternative time.',
                __FUNCTION__
            );
        }
        try {
            if (!in_array($this->actorRole, ['doctor', 'admin'], true)) {
                return ['success' => false, 'message' => 'Only doctors and administrators can suggest reschedule times.'];
            }

            $dateValue = DateTime::createFromFormat('!Y-m-d', $suggestedDate);
            if (!$dateValue || $dateValue->format('Y-m-d') !== $suggestedDate) {
                return ['success' => false, 'message' => 'Invalid suggested date. Use YYYY-MM-DD.'];
            }

            $normalizedTime = AppointmentTime::normalize($suggestedTime);
            if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $normalizedTime)) {
                return ['success' => false, 'message' => 'Invalid suggested time. Use HH:MM or a valid AM/PM time.'];
            }

            $appt = $this->beginLockedRescheduleAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment or assigned doctor not found.'];
            }
            if ($appt['status'] !== 'Reschedule Requested' || $appt['reschedule_status'] !== 'pending') {
                return $this->rollbackFailure('No pending reschedule request for this appointment.');
            }
            if ($this->actorRole === 'doctor' && (int)$appt['doctor_id'] !== $this->actorId) {
                return $this->rollbackFailure('This appointment does not belong to you.');
            }

            $doctorId = (int)$appt['doctor_id'];
            $ss = new ScheduleService($this->db);
            $validationError = $ss->validateBookingSlot($doctorId, $suggestedDate, $normalizedTime, $appointmentId);
            if ($validationError !== null) {
                return $this->rollbackFailure('Suggested time is not available: ' . $validationError);
            }

            $suggestionNote = ' | Doctor suggested: ' . $suggestedDate . ' ' . $normalizedTime . ($notes ? '. Notes: ' . $notes : '');
            $stmt = $this->db->prepare(
                "UPDATE appointments SET
                    pending_reschedule_date = ?,
                    pending_reschedule_time = ?,
                    reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?),
                    reschedule_status = 'suggested',
                    reschedule_responded_at = NOW(),
                    reschedule_responded_by = ?
                 WHERE id = ?
                   AND status = 'Reschedule Requested'
                   AND reschedule_status = 'pending'"
            );
            $stmt->execute([$suggestedDate, $normalizedTime, $suggestionNote, $this->actorId, $appointmentId]);

            if ($stmt->rowCount() !== 1) {
                return $this->rollbackFailure('The reschedule request changed before the suggestion could be saved.');
            }

                        $this->logRescheduleSuggested($appt, $suggestedDate, $normalizedTime, $notes);

            $this->sendRescheduleSuggestedNotifications($appt, $suggestedDate, $normalizedTime, $notes);

$this->db->commit();
            $this->dispatchNotificationsAfterCommit('reschedule suggestion');

            return ['success' => true, 'message' => 'Alternative time suggested. Awaiting patient response.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RescheduleService::suggestReschedule Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to suggest alternative time.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2b: PATIENT ACCEPTS DOCTOR'S SUGGESTION
    // ═══════════════════════════════════════════════════════════

    /**
     * Patient accepts the doctor's suggested alternative time.
     * Updates the appointment to the suggested date/time.
     *
     * @param int $appointmentId
     * @return array{success: bool, message: string}
     */
    public function acceptRescheduleSuggestion(int $appointmentId): array
    {
        if ($this->repository !== null) {
            return $this->runRepositoryMutation(
                fn(): array => $this->repository->acceptSuggestion($appointmentId, $this->actorId, $this->actorRole),
                'Suggested time accepted. Appointment has been updated.',
                'Failed to accept suggested time.',
                __FUNCTION__
            );
        }
        try {
            if (!in_array($this->actorRole, ['patient', 'admin'], true)) {
                return ['success' => false, 'message' => 'Only patients and administrators can accept suggestions.'];
            }

            $appt = $this->beginLockedRescheduleAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment or assigned doctor not found.'];
            }
            if ($appt['status'] !== 'Reschedule Requested' || $appt['reschedule_status'] !== 'suggested') {
                return $this->rollbackFailure('No pending suggestion for this appointment.');
            }
            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return $this->rollbackFailure('This appointment does not belong to you.');
            }

            $newDate = (string)($appt['pending_reschedule_date'] ?? '');
            $newTime = (string)($appt['pending_reschedule_time'] ?? '');
            if ($newDate === '' || $newTime === '') {
                return $this->rollbackFailure('Suggestion data is incomplete.');
            }

            $doctorId = (int)$appt['doctor_id'];
            $normalizedTime = AppointmentTime::normalize($newTime);
            $ss = new ScheduleService($this->db);
            $validationError = $ss->validateBookingSlot($doctorId, $newDate, $normalizedTime, $appointmentId);
            if ($validationError !== null) {
                return $this->rollbackFailure('The suggested time is no longer available: ' . $validationError);
            }

            $duration = AppointmentTime::duration($this->db, $doctorId);
            $newTimeRange = AppointmentTime::range($normalizedTime, $duration);

            $stmt = $this->db->prepare(
                "UPDATE appointments SET
                    date = ?,
                    time = ?,
                    appointment_time_range = ?,
                    status = 'Confirmed',
                    reschedule_status = 'approved',
                    reschedule_responded_at = NOW(),
                    reschedule_responded_by = ?,
                    reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?)
                 WHERE id = ?
                   AND status = 'Reschedule Requested'
                   AND reschedule_status = 'suggested'"
            );
            $acceptNote = ' | Patient accepted suggested time.';
            $stmt->execute([$newDate, $normalizedTime, $newTimeRange, $this->actorId, $acceptNote, $appointmentId]);

            if ($stmt->rowCount() !== 1) {
                return $this->rollbackFailure('The suggestion changed before it could be accepted. Refresh and try again.');
            }

                        $this->logSuggestionAccepted($appt, $newDate, $normalizedTime);

            $this->sendSuggestionAcceptedNotifications($appt, $newDate, $normalizedTime);

$this->db->commit();
            $this->dispatchNotificationsAfterCommit('accepted reschedule suggestion');

            return ['success' => true, 'message' => 'Suggested time accepted. Appointment has been updated.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RescheduleService::acceptRescheduleSuggestion Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to accept suggested time.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2c: PATIENT DECLINES DOCTOR'S SUGGESTION
    // ═══════════════════════════════════════════════════════════

    /**
     * Patient declines the doctor's suggested alternative time.
     * Returns to pending status so doctor can suggest another time or approve/reject original.
     *
     * @param int $appointmentId
     * @return array{success: bool, message: string}
     */
    public function declineRescheduleSuggestion(int $appointmentId): array
    {
        if ($this->repository !== null) {
            return $this->runRepositoryMutation(
                fn(): array => $this->repository->declineSuggestion($appointmentId, $this->actorId, $this->actorRole),
                'Suggested time declined. The original request is restored for the doctor to review.',
                'Failed to decline suggestion.',
                __FUNCTION__
            );
        }
        try {
            if (!in_array($this->actorRole, ['patient', 'admin'], true)) {
                return ['success' => false, 'message' => 'Only patients and administrators can decline suggestions.'];
            }

            $appt = $this->beginLockedRescheduleAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment or assigned doctor not found.'];
            }
            if ($appt['status'] !== 'Reschedule Requested' || $appt['reschedule_status'] !== 'suggested') {
                return $this->rollbackFailure('No pending suggestion for this appointment.');
            }
            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return $this->rollbackFailure('This appointment does not belong to you.');
            }

            // Restore the patient's original request after declining the doctor's alternative.
            // Legacy suggested rows may not have retained the original request; never
            // misrepresent the suggestion itself as the patient's original request.
            $originalDate = $appt['requested_reschedule_date'] ?? null;
            $originalTime = $appt['requested_reschedule_time'] ?? null;
            if (!$originalDate || !$originalTime) {
                return $this->rollbackFailure(
                    'The original requested time was not retained for this older suggestion. Cancel this request and submit a new one.'
                );
            }

            $declineNote = ' | Patient declined suggested time.';
            $stmt = $this->db->prepare(
                "UPDATE appointments SET
                    pending_reschedule_date = ?,
                    pending_reschedule_time = ?,
                    reschedule_status = 'pending',
                    reschedule_responded_at = NOW(),
                    reschedule_responded_by = ?,
                    reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?)
                 WHERE id = ?
                   AND status = 'Reschedule Requested'
                   AND reschedule_status = 'suggested'"
            );
            $stmt->execute([$originalDate, $originalTime, $this->actorId, $declineNote, $appointmentId]);

            if ($stmt->rowCount() !== 1) {
                return $this->rollbackFailure('The suggestion changed before it could be declined. Refresh and try again.');
            }

                        $this->logSuggestionDeclined($appt);

            $this->sendSuggestionDeclinedNotifications($appt);

$this->db->commit();
            $this->dispatchNotificationsAfterCommit('declined reschedule suggestion');

            return ['success' => true, 'message' => 'Suggested time declined. The original request is restored for the doctor to review.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RescheduleService::declineRescheduleSuggestion Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to decline suggested time.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2d: PATIENT CANCELS PENDING RESCHEDULE REQUEST
    // ═══════════════════════════════════════════════════════════

    /**
     * Patient cancels a pending reschedule request before the doctor responds.
     * Restores appointment to Confirmed status with original schedule.
     *
     * @param int $appointmentId
     * @return array{success: bool, message: string}
     */
    public function cancelRescheduleRequest(int $appointmentId): array
    {
        if ($this->repository !== null) {
            return $this->runRepositoryMutation(
                fn(): array => $this->repository->cancelRequest($appointmentId, $this->actorId, $this->actorRole),
                'Reschedule request cancelled. Appointment remains at original schedule.',
                'Failed to cancel reschedule request.',
                __FUNCTION__
            );
        }
        try {
            if (!in_array($this->actorRole, ['patient', 'admin'], true)) {
                return ['success' => false, 'message' => 'Only patients and administrators can cancel reschedule requests.'];
            }

            $appt = $this->beginLockedRescheduleAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment or assigned doctor not found.'];
            }
            if (
                $appt['status'] !== 'Reschedule Requested'
                || !in_array($appt['reschedule_status'], ['pending', 'suggested'], true)
            ) {
                return $this->rollbackFailure('No active reschedule request to cancel.');
            }
            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return $this->rollbackFailure('This appointment does not belong to you.');
            }

            $cancelNote = ' | Patient cancelled reschedule request.';
            $stmt = $this->db->prepare(
                "UPDATE appointments SET
                    status = 'Confirmed',
                    reschedule_status = 'none',
                    pending_reschedule_date = NULL,
                    pending_reschedule_time = NULL,
                    reschedule_responded_at = NOW(),
                    reschedule_responded_by = ?,
                    reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?)
                 WHERE id = ?
                   AND status = 'Reschedule Requested'
                   AND reschedule_status IN ('pending', 'suggested')"
            );
            $stmt->execute([$this->actorId, $cancelNote, $appointmentId]);

            if ($stmt->rowCount() !== 1) {
                return $this->rollbackFailure('The reschedule request changed before it could be cancelled.');
            }

                        $this->logRescheduleCancelled($appt);

            $this->sendRescheduleCancelledNotifications($appt);

$this->db->commit();
            $this->dispatchNotificationsAfterCommit('reschedule cancellation');

            return ['success' => true, 'message' => 'Reschedule request cancelled. Appointment remains at original schedule.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RescheduleService::cancelRescheduleRequest Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to cancel reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2e: DOCTOR APPROVES RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function approveReschedule(int $appointmentId, string $doctorNotes = ''): array
    {
        if ($this->repository !== null) {
            return $this->runRepositoryMutation(
                fn(): array => $this->repository->approveRequest($appointmentId, $this->actorId, $this->actorRole, $doctorNotes),
                'Reschedule request approved. Appointment has been updated.',
                'Failed to approve reschedule request.',
                __FUNCTION__
            );
        }
        try {
            if (!in_array($this->actorRole, ['doctor', 'admin'], true)) {
                return ['success' => false, 'message' => 'Only doctors and administrators can approve reschedules.'];
            }

            $appt = $this->beginLockedRescheduleAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment or assigned doctor not found.'];
            }
            if ($appt['status'] !== 'Reschedule Requested' || $appt['reschedule_status'] !== 'pending') {
                if ($appt['reschedule_status'] === 'suggested') {
                    return $this->rollbackFailure('The patient must accept or decline the suggested time before the reschedule can be approved.');
                }
                return $this->rollbackFailure('No pending reschedule request for this appointment.');
            }
            if ($this->actorRole === 'doctor' && (int)$appt['doctor_id'] !== $this->actorId) {
                return $this->rollbackFailure('This appointment does not belong to you.');
            }

            $newDate = (string)($appt['pending_reschedule_date'] ?? '');
            $newTime = (string)($appt['pending_reschedule_time'] ?? '');
            if ($newDate === '' || $newTime === '') {
                return $this->rollbackFailure('Reschedule data is incomplete.');
            }

            $doctorId = (int)$appt['doctor_id'];
            $normalizedNewTime = AppointmentTime::normalize($newTime);
            $ss = new ScheduleService($this->db);
            $validationError = $ss->validateBookingSlot($doctorId, $newDate, $normalizedNewTime, $appointmentId);
            if ($validationError !== null) {
                $this->db->rollBack();
                try {
                    $this->notifySlotNoLongerAvailable($appt, $newDate, $newTime);
                } catch (\Throwable $notificationError) {
                    error_log('Could not notify about unavailable reschedule slot: ' . $notificationError->getMessage());
                }
                return ['success' => false, 'message' => 'The requested time slot is no longer available. Please ask the patient to request a new time.'];
            }

            $duration = AppointmentTime::duration($this->db, $doctorId);
            $newTimeRange = AppointmentTime::range($normalizedNewTime, $duration);
            $approvalNote = ' | Approved: ' . ($doctorNotes ?: 'Approved by doctor.');
            $stmt = $this->db->prepare(
                "UPDATE appointments SET
                    date = ?,
                    time = ?,
                    appointment_time_range = ?,
                    status = 'Confirmed',
                    reschedule_status = 'approved',
                    reschedule_responded_at = NOW(),
                    reschedule_responded_by = ?,
                    reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?)
                 WHERE id = ?
                   AND status = 'Reschedule Requested'
                   AND reschedule_status = 'pending'"
            );
            $stmt->execute([$newDate, $normalizedNewTime, $newTimeRange, $this->actorId, $approvalNote, $appointmentId]);

            if ($stmt->rowCount() !== 1) {
                return $this->rollbackFailure('The reschedule request changed before approval. Refresh and try again.');
            }

                        $this->logRescheduleApproved($appt, $newDate, $normalizedNewTime);

            $this->sendRescheduleApprovedNotifications($appt, $newDate, $normalizedNewTime);

$this->db->commit();
            $this->dispatchNotificationsAfterCommit('reschedule approval');

            return ['success' => true, 'message' => 'Reschedule request approved. Appointment has been updated.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RescheduleService::approveReschedule Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to approve reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2f: DOCTOR REJECTS RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function rejectReschedule(int $appointmentId, string $doctorNotes = ''): array
    {
        if ($this->repository !== null) {
            return $this->runRepositoryMutation(
                fn(): array => $this->repository->rejectRequest($appointmentId, $this->actorId, $this->actorRole, $doctorNotes),
                'Reschedule request rejected. The appointment remains at its original schedule.',
                'Failed to reject reschedule request.',
                __FUNCTION__
            );
        }
        try {
            if (!in_array($this->actorRole, ['doctor', 'admin'], true)) {
                return ['success' => false, 'message' => 'Only doctors and administrators can reject reschedules.'];
            }

            $appt = $this->beginLockedRescheduleAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment or assigned doctor not found.'];
            }
            if (
                $appt['status'] !== 'Reschedule Requested'
                || !in_array($appt['reschedule_status'], ['pending', 'suggested'], true)
            ) {
                return $this->rollbackFailure('No pending reschedule request for this appointment.');
            }
            if ($this->actorRole === 'doctor' && (int)$appt['doctor_id'] !== $this->actorId) {
                return $this->rollbackFailure('This appointment does not belong to you.');
            }

            $rejectionNote = ' | Rejected: ' . ($doctorNotes ?: 'Rejected by doctor.');
            $stmt = $this->db->prepare(
                "UPDATE appointments SET
                    status = 'Confirmed',
                    reschedule_status = 'rejected',
                    reschedule_responded_at = NOW(),
                    reschedule_responded_by = ?,
                    reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?)
                 WHERE id = ?
                   AND status = 'Reschedule Requested'
                   AND reschedule_status IN ('pending', 'suggested')"
            );
            $stmt->execute([$this->actorId, $rejectionNote, $appointmentId]);

            if ($stmt->rowCount() !== 1) {
                return $this->rollbackFailure('The reschedule request changed before rejection. Refresh and try again.');
            }

                        $this->logRescheduleRejected($appt, $doctorNotes);

            $this->sendRescheduleRejectedNotifications($appt, $doctorNotes);

$this->db->commit();
            $this->dispatchNotificationsAfterCommit('reschedule rejection');

            return ['success' => true, 'message' => 'Reschedule request rejected. The appointment remains at its original schedule.'];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log('RescheduleService::rejectReschedule Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to reject reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  GET RESCHEDULE REQUESTS
    // ═══════════════════════════════════════════════════════════

    public function getPendingRequestsForDoctor(int $doctorId): array
    {
        if ($this->repository !== null) {
            return $this->repository->getPendingRequestsForDoctor($doctorId);
        }
        $stmt = $this->db->prepare(
            "SELECT a.*, u.name AS patient_name
             FROM appointments a
             JOIN users u ON a.user_id = u.id
             WHERE a.doctor_id = ?
               AND (a.reschedule_status = 'pending' OR a.reschedule_status = 'suggested')
             ORDER BY a.reschedule_requested_at DESC"
        );
        $stmt->execute([$doctorId]);
        return $stmt->fetchAll();
    }

    public function getAllPendingRequests(): array
    {
        if ($this->repository !== null) {
            return $this->repository->getAllPendingRequests();
        }
        $stmt = $this->db->query(
            "SELECT a.*, u.name AS patient_name, doc.name AS doctor_name
             FROM appointments a
             JOIN users u ON a.user_id = u.id
             JOIN users doc ON a.doctor_id = doc.id
             WHERE a.reschedule_status IN ('pending', 'suggested')
             ORDER BY a.reschedule_requested_at DESC"
        );
        return $stmt->fetchAll();
    }

    public function getRescheduleHistory(int $appointmentId): ?array
    {
        if ($this->repository !== null) {
            return $this->repository->getRescheduleHistory($appointmentId);
        }
        $stmt = $this->db->prepare(
            "SELECT id, pending_reschedule_date, pending_reschedule_time,
                    requested_reschedule_date, requested_reschedule_time,
                    reschedule_reason, reschedule_requested_at, reschedule_requested_by,
                    reschedule_status, reschedule_responded_at, reschedule_responded_by,
                    reschedule_response_notes
             FROM appointments
             WHERE id = ?"
        );
        $stmt->execute([$appointmentId]);
        return $stmt->fetch() ?: null;
    }

    // ═══════════════════════════════════════════════════════════
    //  INTERNAL HELPERS
    // ═══════════════════════════════════════════════════════════

    /**
     * Lock the doctor schedule row before the appointment row.
     * Booking and reschedule mutations use the same order to serialize
     * concurrent requests for a doctor without table-wide locks.
     */
    private function beginLockedRescheduleAppointment(int $appointmentId): ?array
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('A reschedule operation cannot start inside an existing transaction.');
        }

        $lookup = $this->db->prepare('SELECT doctor_id FROM appointments WHERE id = ? LIMIT 1');
        $lookup->execute([$appointmentId]);
        $doctorId = (int)$lookup->fetchColumn();

        if ($doctorId <= 0) {
            return null;
        }

        $this->db->beginTransaction();

        try {
            $doctorLock = $this->db->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'doctor' FOR UPDATE"
            );
            $doctorLock->execute([$doctorId]);
            if (!$doctorLock->fetchColumn()) {
                $this->db->rollBack();
                return null;
            }

            $appointment = $this->getAppointment($appointmentId, true);
            if (!$appointment || (int)$appointment['doctor_id'] !== $doctorId) {
                $this->db->rollBack();
                return null;
            }

            return $appointment;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function rollbackFailure(string $message): array
    {
        if ($this->db->inTransaction()) {
            $this->db->rollBack();
        }

        return ['success' => false, 'message' => $message];
    }

    /**
     * Run a V2 repository mutation through the legacy HTTP response contract.
     * The repository queues notifications transactionally; dispatch only after
     * its transaction has committed. A caller-owned transaction remains pending
     * for its owner and is never dispatched from this facade.
     */
    private function runRepositoryMutation(callable $operation, string $successMessage, string $failureMessage, string $operationName): array
    {
        $ownsTransaction = !$this->db->inTransaction();

        try {
            $result = $operation();
            if (!is_array($result) || ($result['success'] ?? false) !== true) {
                throw new RuntimeException('The reschedule repository returned an invalid result.');
            }

            if ($ownsTransaction && !$this->db->inTransaction()) {
                try {
                    (new NotificationService($this->db))->processOutbox(50);
                } catch (Throwable $dispatchError) {
                    error_log('RescheduleService notification dispatch deferred after ' . $operationName . ': ' . $dispatchError->getMessage());
                }
            }

            return ['success' => true, 'message' => $successMessage];
        } catch (DomainException | InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('RescheduleService::' . $operationName . ' Error: ' . $e->getMessage());
            return ['success' => false, 'message' => $failureMessage];
        }
    }
    private function getAppointment(int $appointmentId, bool $forUpdate = false): ?array
    {
        // Lock only the appointment row. A locking JOIN to users would also lock
        // the patient account, reversing the patient-archive order (patient, then
        // appointments) and allowing a reschedule/archive deadlock.
        $sql = "SELECT a.*
                FROM appointments a
                WHERE a.id = ?
                LIMIT 1";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$appointmentId]);
        $appointment = $stmt->fetch();
        if (!$appointment) {
            return null;
        }

        // The display name is informational; read it without taking a patient
        // account lock so appointment mutations do not acquire cross-account locks.
        $patientStmt = $this->db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
        $patientStmt->execute([(int)$appointment['user_id']]);
        $appointment['patient_name'] = (string)($patientStmt->fetchColumn() ?: '');

        return $appointment;
    }

    // ═══════════════════════════════════════════════════════════
    /**
     * Dispatch notification events only after the reschedule transaction commits.
     * Enqueue failures occur before commit and therefore roll back the appointment
     * mutation; dispatch failures leave the committed outbox events for the worker.
     */
    private function dispatchNotificationsAfterCommit(string $operation): void
    {
        try {
            (new NotificationService($this->db))->processOutbox(50);
        } catch (\Throwable $error) {
            error_log(
                'Reschedule ' . $operation . ' committed; outbox dispatch deferred ('
                . get_class($error) . ').'
            );
        }
    }
    //  NOTIFICATIONS
    // ═══════════════════════════════════════════════════════════

    private function sendRescheduleRequestNotifications(array $appt, string $newDate, string $newTime, string $reason): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $patientName = $appt['patient_name'] ?? 'Patient';
        $oldDateFormatted = date('M j', strtotime($appt['date']));
        $oldTimeFormatted = AppointmentTime::formatDisplay($appt['time']);
        $newDateFormatted = date('M j', strtotime($newDate));
        $newTimeFormatted = AppointmentTime::formatDisplay($newTime);

        $doctorMessage = "{$patientName} requested to reschedule Appointment #{$appointmentId} from {$oldDateFormatted} {$oldTimeFormatted} to {$newDateFormatted} {$newTimeFormatted}.";
        if ($reason) $doctorMessage .= " Reason: {$reason}";
        $ns->create($doctorId, NotificationService::TYPE_RESCHEDULE_REQUEST, 'Reschedule Request', $doctorMessage, 'appointment', $appointmentId);

        $adminMessage = "Patient {$patientName} requested to reschedule Appointment #{$appointmentId}.\nOld: {$oldDateFormatted} {$oldTimeFormatted}\nNew: {$newDateFormatted} {$newTimeFormatted}";
        $this->notifyAllAdmins($adminMessage, NotificationService::TYPE_RESCHEDULE_REQUEST, $appointmentId);

        $patientMessage = "Your reschedule request for Appointment #{$appointmentId} has been submitted. The doctor will review your request.";
        $ns->create($patientId, NotificationService::TYPE_RESCHEDULE_SUBMITTED, 'Reschedule Request Submitted', $patientMessage, 'appointment', $appointmentId);
    }

    private function sendRescheduleSuggestedNotifications(array $appt, string $suggestedDate, string $suggestedTime, string $notes): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $doctorName = $this->getUserName($doctorId);
        $newDateFormatted = date('M j, Y', strtotime($suggestedDate));
        $newTimeFormatted = AppointmentTime::formatDisplay($suggestedTime);

        $patientMessage = "Dr. {$doctorName} suggested a new appointment time: {$newDateFormatted} at {$newTimeFormatted}.";
        if ($notes) $patientMessage .= " Note: {$notes}";
        $ns->create($patientId, NotificationService::TYPE_RESCHEDULE_SUGGESTED, 'New Time Suggested', $patientMessage, 'appointment', $appointmentId);

        $adminMessage = "Doctor {$doctorName} suggested an alternative time for Appointment #{$appointmentId}: {$newDateFormatted} {$newTimeFormatted}.";
        $this->notifyAllAdmins($adminMessage, NotificationService::TYPE_RESCHEDULE_SUGGESTED, $appointmentId);
    }

    private function sendSuggestionAcceptedNotifications(array $appt, string $newDate, string $newTime): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $doctorName = $this->getUserName($doctorId);
        $patientName = $appt['patient_name'] ?? 'Patient';
        $newDateFormatted = date('M j, Y', strtotime($newDate));
        $newTimeFormatted = AppointmentTime::formatDisplay($newTime);

        $doctorMessage = "{$patientName} accepted your suggested time for Appointment #{$appointmentId}: {$newDateFormatted} at {$newTimeFormatted}.";
        $ns->create($doctorId, NotificationService::TYPE_RESCHEDULE_SUGGESTION_ACCEPTED, 'Patient Accepted Suggested Time', $doctorMessage, 'appointment', $appointmentId);

        $patientMessage = "You accepted Dr. {$doctorName}'s suggested time for Appointment #{$appointmentId}. Your appointment has been updated.";
        $ns->create($patientId, NotificationService::TYPE_RESCHEDULE_APPROVED, 'Suggestion Accepted', $patientMessage, 'appointment', $appointmentId);
    }

    private function sendSuggestionDeclinedNotifications(array $appt): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $doctorName = $this->getUserName($doctorId);
        $patientName = $appt['patient_name'] ?? 'Patient';

        $doctorMessage = "{$patientName} declined your suggested time for Appointment #{$appointmentId}.";
        $ns->create($doctorId, NotificationService::TYPE_RESCHEDULE_SUGGESTION_DECLINED, 'Patient Declined Suggested Time', $doctorMessage, 'appointment', $appointmentId);

        $patientMessage = "You declined the suggested time from Dr. {$doctorName}. The appointment remains pending.";
        $ns->create($patientId, NotificationService::TYPE_RESCHEDULE_SUGGESTION_DECLINED, 'Suggestion Declined', $patientMessage, 'appointment', $appointmentId);
    }

    private function sendRescheduleCancelledNotifications(array $appt): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $doctorId = (int)$appt['doctor_id'];
        $patientId = (int)$appt['user_id'];
        $patientName = $appt['patient_name'] ?? 'Patient';

        $doctorMessage = "{$patientName} cancelled the reschedule request for Appointment #{$appointmentId}.";
        $ns->create($doctorId, NotificationService::TYPE_RESCHEDULE_REQUEST_CANCELLED, 'Reschedule Cancelled', $doctorMessage, 'appointment', $appointmentId);

        $patientMessage = "You cancelled your reschedule request for Appointment #{$appointmentId}. Your appointment remains at its original schedule.";
        $ns->create($patientId, NotificationService::TYPE_RESCHEDULE_REQUEST_CANCELLED, 'Reschedule Cancelled', $patientMessage, 'appointment', $appointmentId);
    }

    private function sendRescheduleApprovedNotifications(array $appt, string $newDate, string $newTime): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $doctorName = $this->getUserName($doctorId);
        $newDateFormatted = date('M j, Y', strtotime($newDate));
        $newTimeFormatted = AppointmentTime::formatDisplay($newTime);

        $patientMessage = "Your reschedule request for Appointment #{$appointmentId} has been approved. New appointment: {$newDateFormatted} at {$newTimeFormatted}.";
        $ns->create($patientId, NotificationService::TYPE_RESCHEDULE_APPROVED, 'Reschedule Approved', $patientMessage, 'appointment', $appointmentId);

        $adminMessage = "Doctor {$doctorName} approved the reschedule request for Appointment #{$appointmentId}.";
        $this->notifyAllAdmins($adminMessage, NotificationService::TYPE_RESCHEDULE_APPROVED, $appointmentId);
    }

    private function sendRescheduleRejectedNotifications(array $appt, string $doctorNotes): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $doctorName = $this->getUserName($doctorId);

        $patientMessage = "Your reschedule request for Appointment #{$appointmentId} has been declined. Your original appointment remains scheduled.";
        if ($doctorNotes) $patientMessage .= " Doctor's note: {$doctorNotes}";
        $ns->create($patientId, NotificationService::TYPE_RESCHEDULE_REJECTED, 'Reschedule Declined', $patientMessage, 'appointment', $appointmentId);

        $adminMessage = "Doctor {$doctorName} rejected the reschedule request for Appointment #{$appointmentId}.";
        if ($doctorNotes) $adminMessage .= " Reason: {$doctorNotes}";
        $this->notifyAllAdmins($adminMessage, NotificationService::TYPE_RESCHEDULE_REJECTED, $appointmentId);
    }

    private function notifySlotNoLongerAvailable(array $appt, string $newDate, string $newTime): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $patientMessage = "The time slot you requested for Appointment #{$appointmentId} is no longer available. Please submit a new reschedule request with a different time.";
        $ns->create($patientId, NotificationService::TYPE_RESCHEDULE_REJECTED, 'Reschedule Slot Unavailable', $patientMessage, 'appointment', $appointmentId);
    }

    private function getUserName(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?: 'Unknown';
    }

    private function getAdminUserIds(): array
    {
        $stmt = $this->db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function notifyAllAdmins(string $message, string $type, int $refId): void
    {
        $ns = new NotificationService($this->db);
        $admins = $this->getAdminUserIds();
        foreach ($admins as $adminId) {
            $ns->create((int)$adminId, $type, 'Reschedule Notification', $message, 'appointment', $refId);
        }
    }


    // ═══════════════════════════════════════════════════════════
    //  AUDIT LOGGING
    // ═══════════════════════════════════════════════════════════

    private function logRescheduleRequest(array $appt, string $newDate, string $newTime, string $reason): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $logged = $audit->log(
            'reschedule_request',
            'appointment',
            (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['date' => $newDate, 'time' => $newTime, 'reason' => $reason],
            "Patient requested appointment reschedule. Old: {$appt['date']} {$appt['time']}. New: {$newDate} {$newTime}.",
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
        if (!$logged) {
            throw new RuntimeException('Could not record the reschedule request audit event.');
        }
    }

    private function logRescheduleSuggested(array $appt, string $date, string $time, string $notes): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $logged = $audit->log(
            'reschedule_suggested',
            'appointment',
            (int)$appt['id'],
            ['date' => $appt['pending_reschedule_date'], 'time' => $appt['pending_reschedule_time']],
            ['suggested_date' => $date, 'suggested_time' => $time, 'notes' => $notes],
            "Doctor suggested alternative time. New: {$date} {$time}.",
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
        if (!$logged) {
            throw new RuntimeException('Could not record the reschedule suggestion audit event.');
        }
    }

    private function logSuggestionAccepted(array $appt, string $newDate, string $newTime): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $logged = $audit->log(
            'reschedule_suggestion_accepted',
            'appointment',
            (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['date' => $newDate, 'time' => $newTime],
            "Patient accepted doctor's suggested reschedule time.",
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
        if (!$logged) {
            throw new RuntimeException('Could not record the accepted suggestion audit event.');
        }
    }

    private function logSuggestionDeclined(array $appt): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $logged = $audit->log(
            'reschedule_suggestion_declined',
            'appointment',
            (int)$appt['id'],
            ['suggested_date' => $appt['pending_reschedule_date'], 'suggested_time' => $appt['pending_reschedule_time']],
            ['status' => 'pending'],
            "Patient declined doctor's suggested reschedule time.",
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
        if (!$logged) {
            throw new RuntimeException('Could not record the declined suggestion audit event.');
        }
    }

    private function logRescheduleCancelled(array $appt): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $logged = $audit->log(
            'reschedule_cancelled',
            'appointment',
            (int)$appt['id'],
            ['reschedule_status' => $appt['reschedule_status'], 'requested_date' => $appt['pending_reschedule_date']],
            ['status' => 'cancelled_by_patient'],
            "Patient cancelled the reschedule request.",
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
        if (!$logged) {
            throw new RuntimeException('Could not record the reschedule cancellation audit event.');
        }
    }

    private function logRescheduleApproved(array $appt, string $newDate, string $newTime): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $logged = $audit->log(
            'reschedule_approved',
            'appointment',
            (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['date' => $newDate, 'time' => $newTime],
            "Doctor approved appointment reschedule.",
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
        if (!$logged) {
            throw new RuntimeException('Could not record the reschedule approval audit event.');
        }
    }

    private function logRescheduleRejected(array $appt, string $doctorNotes): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $logged = $audit->log(
            'reschedule_rejected',
            'appointment',
            (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['status' => 'rejected'],
            "Doctor rejected appointment reschedule. Notes: {$doctorNotes}",
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
        if (!$logged) {
            throw new RuntimeException('Could not record the reschedule rejection audit event.');
        }
    }
}