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

class RescheduleService
{
    private PDO $db;
    private int $actorId;
    private string $actorRole;

    public function __construct(PDO $db, int $actorId, string $actorRole = 'patient')
    {
        $this->db = $db;
        $this->actorId = $actorId;
        $this->actorRole = in_array($actorRole, ['patient', 'doctor', 'admin'], true) ? $actorRole : 'patient';
    }

    public function getDB(): PDO
    {
        return $this->db;
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 1: PATIENT REQUESTS RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function requestReschedule(int $appointmentId, string $newDate, string $newTime, string $reason = ''): array
    {
        try {
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }
            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }
            if ($appt['status'] !== 'Confirmed') {
                return ['success' => false, 'message' => 'Only confirmed appointments can be rescheduled.'];
            }
            if ($appt['reschedule_status'] === 'pending') {
                return ['success' => false, 'message' => 'A reschedule request is already pending for this appointment.'];
            }

            $normalizedNewTime = $this->normalizeTime($newTime);
            $normalizedCurrentTime = $this->normalizeTime($appt['time']);
            if ($newDate === $appt['date'] && $normalizedNewTime === $normalizedCurrentTime) {
                return ['success' => false, 'message' => 'The new appointment time must be different from the current time.'];
            }

            $ss = new ScheduleService($this->db);
            $doctorId = (int)$appt['doctor_id'];
            $validationError = $ss->validateBookingSlot($doctorId, $newDate, $normalizedNewTime);
            if ($validationError !== null) {
                return ['success' => false, 'message' => $validationError];
            }

            if ($newDate === $appt['date']) {
                $duration = (int)($appt['appointment_duration'] ?? 30);
                if (!$duration) {
                    $schedule = $ss->getSchedule($doctorId);
                    $duration = (int)($schedule['settings']['appointment_duration'] ?? 30);
                }
                $bookedRanges = $ss->getBookedRanges($doctorId, $newDate);
                foreach ($bookedRanges as $range) {
                    $currentStartMin = $this->timeToMinutes($normalizedCurrentTime);
                    $currentEndMin = $currentStartMin + $duration;
                    $newStartMin = $this->timeToMinutes($normalizedNewTime);
                    $newEndMin = $newStartMin + $duration;
                    if ($newStartMin < $currentEndMin && $newEndMin > $currentStartMin) {
                        return ['success' => false, 'message' => 'You are already booked at this time. Please select a different slot.'];
                    }
                }
            }

            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        status = 'Reschedule Requested',
                        reschedule_status = 'pending',
                        pending_reschedule_date = ?,
                        pending_reschedule_time = ?,
                        reschedule_reason = ?,
                        reschedule_requested_at = NOW(),
                        reschedule_requested_by = ?,
                        reschedule_responded_at = NULL,
                        reschedule_responded_by = NULL,
                        reschedule_response_notes = NULL
                     WHERE id = ?"
                );
                $stmt->execute([$newDate, $newTime, $reason, $this->actorId, $appointmentId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->sendRescheduleRequestNotifications($appt, $newDate, $newTime, $reason);
            $this->logRescheduleRequest($appt, $newDate, $newTime, $reason);

            return ['success' => true, 'message' => 'Reschedule request submitted successfully. Awaiting doctor approval.'];
        } catch (\Throwable $e) {
            error_log('RescheduleService::requestReschedule Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
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
        try {
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }
            if ($appt['reschedule_status'] !== 'pending') {
                return ['success' => false, 'message' => 'No pending reschedule request for this appointment.'];
            }
            if ($this->actorRole === 'doctor' && (int)$appt['doctor_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            $normalizedTime = $this->normalizeTime($suggestedTime);
            $ss = new ScheduleService($this->db);
            $doctorId = (int)$appt['doctor_id'];
            $validationError = $ss->validateBookingSlot($doctorId, $suggestedDate, $normalizedTime);
            if ($validationError !== null) {
                return ['success' => false, 'message' => 'Suggested time is not available: ' . $validationError];
            }

            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        pending_reschedule_date = ?,
                        pending_reschedule_time = ?,
                        reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?),
                        reschedule_status = 'suggested',
                        reschedule_responded_at = NOW(),
                        reschedule_responded_by = ?
                     WHERE id = ?"
                );
                $suggestionNote = ' | Doctor suggested: ' . $suggestedDate . ' ' . $normalizedTime . ($notes ? '. Notes: ' . $notes : '');
                $stmt->execute([$suggestedDate, $normalizedTime, $suggestionNote, $this->actorId, $appointmentId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->sendRescheduleSuggestedNotifications($appt, $suggestedDate, $normalizedTime, $notes);
            $this->logRescheduleSuggested($appt, $suggestedDate, $normalizedTime, $notes);

            return ['success' => true, 'message' => 'Alternative time suggested. Awaiting patient response.'];
        } catch (\Throwable $e) {
            error_log('RescheduleService::suggestReschedule Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
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
        try {
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }
            if ($appt['reschedule_status'] !== 'suggested') {
                return ['success' => false, 'message' => 'No pending suggestion for this appointment.'];
            }
            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            $newDate = $appt['pending_reschedule_date'];
            $newTime = $appt['pending_reschedule_time'];
            if (!$newDate || !$newTime) {
                return ['success' => false, 'message' => 'Suggestion data is incomplete.'];
            }

            $doctorId = (int)$appt['doctor_id'];
            $normalizedTime = $this->normalizeTime($newTime);
            $duration = $this->getAppointmentDuration($doctorId);
            $newTimeRange = $this->computeTimeRange($newTime, $duration);

            $this->db->beginTransaction();
            try {
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
                     WHERE id = ?"
                );
                $acceptNote = ' | Patient accepted suggested time.';
                $stmt->execute([$newDate, $newTime, $newTimeRange, $this->actorId, $acceptNote, $appointmentId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->sendSuggestionAcceptedNotifications($appt, $newDate, $newTime);
            $this->logSuggestionAccepted($appt, $newDate, $newTime);

            return ['success' => true, 'message' => 'Suggested time accepted. Appointment has been updated.'];
        } catch (\Throwable $e) {
            error_log('RescheduleService::acceptRescheduleSuggestion Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
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
        try {
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }
            if ($appt['reschedule_status'] !== 'suggested') {
                return ['success' => false, 'message' => 'No pending suggestion for this appointment.'];
            }
            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        reschedule_status = 'pending',
                        reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?)
                     WHERE id = ?"
                );
                $declineNote = ' | Patient declined suggested time.';
                $stmt->execute([$declineNote, $appointmentId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->sendSuggestionDeclinedNotifications($appt);
            $this->logSuggestionDeclined($appt);

            return ['success' => true, 'message' => 'Suggested time declined. The doctor can suggest another time.'];
        } catch (\Throwable $e) {
            error_log('RescheduleService::declineRescheduleSuggestion Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
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
        try {
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }
            if ($appt['reschedule_status'] === 'none' || $appt['reschedule_status'] === 'approved' || $appt['reschedule_status'] === 'rejected') {
                return ['success' => false, 'message' => 'No active reschedule request to cancel.'];
            }
            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        status = 'Confirmed',
                        reschedule_status = 'none',
                        pending_reschedule_date = NULL,
                        pending_reschedule_time = NULL,
                        reschedule_reason = NULL,
                        reschedule_requested_at = NULL,
                        reschedule_requested_by = NULL,
                        reschedule_responded_at = NULL,
                        reschedule_responded_by = NULL,
                        reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?)
                     WHERE id = ?"
                );
                $cancelNote = ' | Patient cancelled reschedule request.';
                $stmt->execute([$cancelNote, $appointmentId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->sendRescheduleCancelledNotifications($appt);
            $this->logRescheduleCancelled($appt);

            return ['success' => true, 'message' => 'Reschedule request cancelled. Appointment remains at original schedule.'];
        } catch (\Throwable $e) {
            error_log('RescheduleService::cancelRescheduleRequest Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            return ['success' => false, 'message' => 'Failed to cancel reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2e: DOCTOR APPROVES RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function approveReschedule(int $appointmentId, string $doctorNotes = ''): array
    {
        try {
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }
            if ($appt['reschedule_status'] !== 'pending' && $appt['reschedule_status'] !== 'suggested') {
                return ['success' => false, 'message' => 'No pending reschedule request for this appointment.'];
            }
            if ($this->actorRole === 'doctor' && (int)$appt['doctor_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            $newDate = $appt['pending_reschedule_date'];
            $newTime = $appt['pending_reschedule_time'];
            if (!$newDate || !$newTime) {
                return ['success' => false, 'message' => 'Reschedule data is incomplete.'];
            }

            $ss = new ScheduleService($this->db);
            $doctorId = (int)$appt['doctor_id'];
            $normalizedNewTime = $this->normalizeTime($newTime);
            $validationError = $ss->validateBookingSlot($doctorId, $newDate, $normalizedNewTime);
            if ($validationError !== null) {
                $this->notifySlotNoLongerAvailable($appt, $newDate, $newTime);
                return ['success' => false, 'message' => 'The requested time slot is no longer available. Please ask the patient to request a new time.'];
            }

            $this->db->beginTransaction();
            try {
                $duration = $this->getAppointmentDuration((int)$doctorId);
                $newTimeRange = $this->computeTimeRange($newTime, $duration);

                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        date = ?,
                        time = ?,
                        appointment_time_range = ?,
                        status = 'Confirmed',
                        reschedule_status = 'approved',
                        reschedule_responded_at = NOW(),
                        reschedule_responded_by = ?,
                        reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?),
                        reschedule_requested_at = reschedule_requested_at,
                        reschedule_requested_by = reschedule_requested_by
                     WHERE id = ?"
                );
                $approvalNote = ' | Approved: ' . ($doctorNotes ?: 'Approved by doctor.');
                $stmt->execute([$newDate, $newTime, $newTimeRange, $this->actorId, $approvalNote, $appointmentId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->sendRescheduleApprovedNotifications($appt, $newDate, $newTime);
            $this->logRescheduleApproved($appt, $newDate, $newTime);

            return ['success' => true, 'message' => 'Reschedule request approved. Appointment has been updated.'];
        } catch (\Throwable $e) {
            error_log('RescheduleService::approveReschedule Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            return ['success' => false, 'message' => 'Failed to approve reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2f: DOCTOR REJECTS RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    public function rejectReschedule(int $appointmentId, string $doctorNotes = ''): array
    {
        try {
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }
            if ($appt['reschedule_status'] !== 'pending' && $appt['reschedule_status'] !== 'suggested') {
                return ['success' => false, 'message' => 'No pending reschedule request for this appointment.'];
            }
            if ($this->actorRole === 'doctor' && (int)$appt['doctor_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            $this->db->beginTransaction();
            try {
                $stmt = $this->db->prepare(
                    "UPDATE appointments SET
                        status = 'Confirmed',
                        reschedule_status = 'rejected',
                        reschedule_responded_at = NOW(),
                        reschedule_responded_by = ?,
                        reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?)
                     WHERE id = ?"
                );
                $rejectionNote = ' | Rejected: ' . ($doctorNotes ?: 'Rejected by doctor.');
                $stmt->execute([$this->actorId, $rejectionNote, $appointmentId]);
                $this->db->commit();
            } catch (\Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            $this->sendRescheduleRejectedNotifications($appt, $doctorNotes);
            $this->logRescheduleRejected($appt, $doctorNotes);

            return ['success' => true, 'message' => 'Reschedule request rejected. The appointment remains at its original schedule.'];
        } catch (\Throwable $e) {
            error_log('RescheduleService::rejectReschedule Error: ' . $e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
            return ['success' => false, 'message' => 'Failed to reject reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  GET RESCHEDULE REQUESTS
    // ═══════════════════════════════════════════════════════════

    public function getPendingRequestsForDoctor(int $doctorId): array
    {
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
        $stmt = $this->db->prepare(
            "SELECT id, pending_reschedule_date, pending_reschedule_time,
                    reschedule_reason, reschedule_requested_at,
                    reschedule_status, reschedule_responded_at,
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

    private function getAppointment(int $appointmentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, u.name AS patient_name
             FROM appointments a
             JOIN users u ON a.user_id = u.id
             WHERE a.id = ?
             LIMIT 1"
        );
        $stmt->execute([$appointmentId]);
        return $stmt->fetch() ?: null;
    }

    private function normalizeTime(string $time): string
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return $time;
        }
        $parsed = date_parse_from_format('h:i A', strtoupper($time));
        if ($parsed['error_count'] === 0 && $parsed['warning_count'] === 0) {
            return sprintf('%02d:%02d', $parsed['hour'], $parsed['minute']);
        }
        $parsed = date_parse_from_format('h:iA', strtoupper($time));
        if ($parsed['error_count'] === 0 && $parsed['warning_count'] === 0) {
            return sprintf('%02d:%02d', $parsed['hour'], $parsed['minute']);
        }
        return $time;
    }

    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    }

    private function getAppointmentDuration(int $doctorId): int
    {
        $stmt = $this->db->prepare("SELECT appointment_duration FROM doctor_schedule_settings WHERE doctor_id = ? LIMIT 1");
        $stmt->execute([$doctorId]);
        $duration = $stmt->fetchColumn();
        return $duration ? (int)$duration : 30;
    }

    private function computeTimeRange(string $time, int $duration): string
    {
        $parts = explode(':', $time);
        $startMin = (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
        $endMin = $startMin + $duration;
        $endH = (int)($endMin / 60) % 24;
        $endM = $endMin % 60;
        $ampm1 = (int)$parts[0] >= 12 ? 'PM' : 'AM';
        $h1 = (int)$parts[0] % 12;
        if ($h1 === 0) $h1 = 12;
        $startStr = "{$h1}:" . str_pad($parts[1] ?? '00', 2, '0', STR_PAD_LEFT) . " {$ampm1}";
        $ampm2 = $endH >= 12 ? 'PM' : 'AM';
        $h2 = $endH % 12;
        if ($h2 === 0) $h2 = 12;
        $endStr = "{$h2}:" . str_pad($endM, 2, '0', STR_PAD_LEFT) . " {$ampm2}";
        return "{$startStr} – {$endStr}";
    }

    // ═══════════════════════════════════════════════════════════
    //  NOTIFICATIONS
    // ═══════════════════════════════════════════════════════════

    private function sendRescheduleRequestNotifications(array $appt, string $newDate, string $newTime, string $reason): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $patientName = $appt['patient_name'] ?? $appt['patient_name'];
        $oldDateFormatted = date('M j', strtotime($appt['date']));
        $oldTimeFormatted = $this->formatTimeForDisplay($appt['time']);
        $newDateFormatted = date('M j', strtotime($newDate));
        $newTimeFormatted = $this->formatTimeForDisplay($newTime);

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
        $newTimeFormatted = $this->formatTimeForDisplay($suggestedTime);

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
        $newTimeFormatted = $this->formatTimeForDisplay($newTime);

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
        $newTimeFormatted = $this->formatTimeForDisplay($newTime);

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

    private function formatTimeForDisplay(string $time): string
    {
        $normalized = $this->normalizeTime($time);
        $parts = explode(':', $normalized);
        $hour = (int)$parts[0];
        $min = $parts[1] ?? '00';
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour % 12;
        if ($displayHour === 0) $displayHour = 12;
        return "{$displayHour}:{$min} {$ampm}";
    }

    // ═══════════════════════════════════════════════════════════
    //  AUDIT LOGGING
    // ═══════════════════════════════════════════════════════════

    private function logRescheduleRequest(array $appt, string $newDate, string $newTime, string $reason): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $audit->log('reschedule_request', 'appointment', (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['date' => $newDate, 'time' => $newTime, 'reason' => $reason],
            "Patient requested appointment reschedule. Old: {$appt['date']} {$appt['time']}. New: {$newDate} {$newTime}.",
            (int)$appt['user_id'], (int)$appt['doctor_id']);
    }

    private function logRescheduleSuggested(array $appt, string $date, string $time, string $notes): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $audit->log('reschedule_suggested', 'appointment', (int)$appt['id'],
            ['date' => $appt['pending_reschedule_date'], 'time' => $appt['pending_reschedule_time']],
            ['suggested_date' => $date, 'suggested_time' => $time, 'notes' => $notes],
            "Doctor suggested alternative time. New: {$date} {$time}.",
            (int)$appt['user_id'], (int)$appt['doctor_id']);
    }

    private function logSuggestionAccepted(array $appt, string $newDate, string $newTime): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $audit->log('reschedule_suggestion_accepted', 'appointment', (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['date' => $newDate, 'time' => $newTime],
            "Patient accepted doctor's suggested reschedule time.",
            (int)$appt['user_id'], (int)$appt['doctor_id']);
    }

    private function logSuggestionDeclined(array $appt): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $audit->log('reschedule_suggestion_declined', 'appointment', (int)$appt['id'],
            ['suggested_date' => $appt['pending_reschedule_date'], 'suggested_time' => $appt['pending_reschedule_time']],
            ['status' => 'pending'],
            "Patient declined doctor's suggested reschedule time.",
            (int)$appt['user_id'], (int)$appt['doctor_id']);
    }

    private function logRescheduleCancelled(array $appt): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $audit->log('reschedule_cancelled', 'appointment', (int)$appt['id'],
            ['reschedule_status' => $appt['reschedule_status'], 'requested_date' => $appt['pending_reschedule_date']],
            ['status' => 'cancelled_by_patient'],
            "Patient cancelled the reschedule request.",
            (int)$appt['user_id'], (int)$appt['doctor_id']);
    }

    private function logRescheduleApproved(array $appt, string $newDate, string $newTime): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $audit->log('reschedule_approved', 'appointment', (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['date' => $newDate, 'time' => $newTime],
            "Doctor approved appointment reschedule.",
            (int)$appt['user_id'], (int)$appt['doctor_id']);
    }

    private function logRescheduleRejected(array $appt, string $doctorNotes): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $audit->log('reschedule_rejected', 'appointment', (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['status' => 'rejected'],
            "Doctor rejected appointment reschedule.",
            (int)$appt['user_id'], (int)$appt['doctor_id']);
    }
}