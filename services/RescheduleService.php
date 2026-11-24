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
    private PDO $db;
    private int $actorId;
    private string $actorRole;
    private RescheduleTransactionRepository $repository;

    public function __construct(PDO $db, int $actorId, string $actorRole = 'patient')
    {
        $this->db = $db;
        $this->actorId = $actorId;
        $this->actorRole = in_array($actorRole, ['patient', 'doctor', 'admin'], true) ? $actorRole : 'patient';

        $this->repository = new RescheduleTransactionRepository($db);
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 1: PATIENT REQUESTS RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function requestReschedule(int $appointmentId, string $newDate, string $newTime, string $reason = ''): array
    {
        return $this->runRepositoryMutation(
            fn(): array => $this->repository->requestReschedule($appointmentId, $this->actorId, $this->actorRole, $newDate, $newTime, $reason),
            'Reschedule request submitted successfully. Awaiting doctor approval.',
            'Failed to submit reschedule request.',
            __FUNCTION__
        );
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2a: DOCTOR SUGGESTS ALTERNATIVE TIME
    // ═══════════════════════════════════════════════════════════

    /**
     * Doctor suggests an alternative time for a pending reschedule.
     * Stores the suggestion on the reschedule request.
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
        return $this->runRepositoryMutation(
            fn(): array => $this->repository->suggestReschedule($appointmentId, $this->actorId, $this->actorRole, $suggestedDate, $suggestedTime, $notes),
            'Alternative time suggested. Awaiting patient response.',
            'Failed to suggest alternative time.',
            __FUNCTION__
        );
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
        return $this->runRepositoryMutation(
            fn(): array => $this->repository->acceptSuggestion($appointmentId, $this->actorId, $this->actorRole),
            'Suggested time accepted. Appointment has been updated.',
            'Failed to accept suggested time.',
            __FUNCTION__
        );
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
        return $this->runRepositoryMutation(
            fn(): array => $this->repository->declineSuggestion($appointmentId, $this->actorId, $this->actorRole),
            'Suggested time declined. The original request is restored for the doctor to review.',
            'Failed to decline suggestion.',
            __FUNCTION__
        );
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2d: PATIENT CANCELS PENDING RESCHEDULE REQUEST
    // ═══════════════════════════════════════════════════════════

    public function cancelRescheduleRequest(int $appointmentId): array
    {
        return $this->runRepositoryMutation(
            fn(): array => $this->repository->cancelRequest($appointmentId, $this->actorId, $this->actorRole),
            'Reschedule request cancelled. Appointment remains at original schedule.',
            'Failed to cancel reschedule request.',
            __FUNCTION__
        );
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2e: DOCTOR APPROVES RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function approveReschedule(int $appointmentId, string $doctorNotes = ''): array
    {
        return $this->runRepositoryMutation(
            fn(): array => $this->repository->approveRequest($appointmentId, $this->actorId, $this->actorRole, $doctorNotes),
            'Reschedule request approved. Appointment has been updated.',
            'Failed to approve reschedule request.',
            __FUNCTION__
        );
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2f: DOCTOR REJECTS RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function rejectReschedule(int $appointmentId, string $doctorNotes = ''): array
    {
        return $this->runRepositoryMutation(
            fn(): array => $this->repository->rejectRequest($appointmentId, $this->actorId, $this->actorRole, $doctorNotes),
            'Reschedule request rejected. The appointment remains at its original schedule.',
            'Failed to reject reschedule request.',
            __FUNCTION__
        );
    }

    // ═══════════════════════════════════════════════════════════
    //  GET RESCHEDULE REQUESTS
    // ═══════════════════════════════════════════════════════════

    public function getPendingRequestsForDoctor(int $doctorId): array
    {
        return $this->repository->getPendingRequestsForDoctor($doctorId);
    }

    public function getAllPendingRequests(): array
    {
        return $this->repository->getAllPendingRequests();
    }

    public function getRescheduleHistory(int $appointmentId): ?array
    {
        return $this->repository->getRescheduleHistory($appointmentId);
    }

    // ═══════════════════════════════════════════════════════════
    //  INTERNAL HELPERS
    // ═══════════════════════════════════════════════════════════

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
}
