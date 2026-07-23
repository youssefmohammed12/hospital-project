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
 *   3. On approval: appointment_date/appointment_time are updated, old slot released
 *   4. On rejection: appointment returns to original schedule, no data lost
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
 *   $rs->approveReschedule($appointmentId, $doctorNotes);
 *   $rs->rejectReschedule($appointmentId, $doctorNotes);
 */
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

    /**
     * Get the PDO instance (for use by API endpoints).
     */
    public function getDB(): PDO
    {
        return $this->db;
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 1: PATIENT REQUESTS RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    /**
     * Patient requests to reschedule an appointment.
     *
     * Validates:
     *   - Appointment exists and belongs to the patient
     *   - Appointment is in 'Confirmed' status
     *   - No pending reschedule already exists
     *   - New slot is valid and available (via ScheduleService)
     *   - New slot is different from current slot
     *
     * Does NOT update appointment_date/appointment_time.
     * Instead stores the requested new date/time in pending_reschedule_* fields.
     *
     * @param int    $appointmentId
     * @param string $newDate  YYYY-MM-DD
     * @param string $newTime  HH:MM (24-hour format)
     * @param string $reason   Optional reason for rescheduling
     * @return array{success: bool, message: string}
     */
    public function requestReschedule(int $appointmentId, string $newDate, string $newTime, string $reason = ''): array
    {
        try {
            // 1. Get appointment
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }

            // 2. Verify the appointment belongs to the patient (if actor is patient)
            if ($this->actorRole === 'patient' && (int)$appt['user_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            // 3. Check status
            if ($appt['status'] !== 'Confirmed') {
                return ['success' => false, 'message' => 'Only confirmed appointments can be rescheduled.'];
            }

            // 4. Check no pending reschedule exists
            if ($appt['reschedule_status'] === 'pending') {
                return ['success' => false, 'message' => 'A reschedule request is already pending for this appointment.'];
            }

            // 5. New date/time must differ from current
            $currentDate = $appt['date'];
            $currentTime = $appt['time'];

            // Normalize for comparison
            $normalizedNewTime = $this->normalizeTime($newTime);
            $normalizedCurrentTime = $this->normalizeTime($appt['time']);

            if ($newDate === $currentDate && $normalizedNewTime === $normalizedCurrentTime) {
                return ['success' => false, 'message' => 'The new appointment time must be different from the current time.'];
            }

            // 6. Validate the new slot via ScheduleService
            $ss = new ScheduleService($this->db);
            $doctorId = (int)$appt['doctor_id'];

            // The new slot must be available (ScheduleService validates all rules)
            $validationError = $ss->validateBookingSlot($doctorId, $newDate, $normalizedNewTime);
            if ($validationError !== null) {
                return ['success' => false, 'message' => $validationError];
            }

            // 7. Double-check: the current slot must NOT be the same as what we're requesting
            // This is an additional guard beyond the simple date/time comparison
            if ($newDate === $currentDate) {
                $duration = (int)($appt['appointment_duration'] ?? 30);
                if (!$duration) {
                    $schedule = $ss->getSchedule($doctorId);
                    $duration = (int)($schedule['settings']['appointment_duration'] ?? 30);
                }
                $bookedRanges = $ss->getBookedRanges($doctorId, $newDate);
                foreach ($bookedRanges as $range) {
                    // The current appointment's range
                    $currentStartMin = $this->timeToMinutes($normalizedCurrentTime);
                    $currentEndMin = $currentStartMin + $duration;
                    $newStartMin = $this->timeToMinutes($normalizedNewTime);
                    $newEndMin = $newStartMin + $duration;

                    // If they overlap, it's the same slot (or overlapping slot - reject)
                    if ($newStartMin < $currentEndMin && $newEndMin > $currentStartMin) {
                        return ['success' => false, 'message' => 'You are already booked at this time. Please select a different slot.'];
                    }
                }
            }

            // 8. Update the appointment to 'Reschedule Requested' status
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
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

            // 9. Send notifications
            $this->sendRescheduleRequestNotifications($appt, $newDate, $newTime, $reason);

            // 10. Audit log
            $this->logRescheduleRequest($appt, $newDate, $newTime, $reason);

            return [
                'success' => true,
                'message' => 'Reschedule request submitted successfully. Awaiting doctor approval.',
            ];

        } catch (Exception $e) {
            error_log('RescheduleService::requestReschedule Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to submit reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2a: DOCTOR APPROVES RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    /**
     * Doctor approves a reschedule request.
     *
     * On approval:
     *   - appointment_date = pending_reschedule_date
     *   - appointment_time = pending_reschedule_time
     *   - status = 'Confirmed'
     *   - reschedule_status = 'approved'
     *   - Old slot is released (implicitly since no other appointment occupies it)
     *
     * @param int    $appointmentId
     * @param string $doctorNotes Optional notes from the doctor
     * @return array{success: bool, message: string}
     */
    public function approveReschedule(int $appointmentId, string $doctorNotes = ''): array
    {
        try {
            // 1. Get appointment
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }

            // 2. Verify there is a pending reschedule
            if ($appt['reschedule_status'] !== 'pending') {
                return ['success' => false, 'message' => 'No pending reschedule request for this appointment.'];
            }

            // 3. Verify the actor is the appointment's doctor (or admin)
            if ($this->actorRole === 'doctor' && (int)$appt['doctor_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            $newDate = $appt['pending_reschedule_date'];
            $newTime = $appt['pending_reschedule_time'];

            if (!$newDate || !$newTime) {
                return ['success' => false, 'message' => 'Reschedule data is incomplete.'];
            }

            // 4. Validate the new slot is still available (it may have been taken since request)
            $ss = new ScheduleService($this->db);
            $doctorId = (int)$appt['doctor_id'];
            $normalizedNewTime = $this->normalizeTime($newTime);
            $validationError = $ss->validateBookingSlot($doctorId, $newDate, $normalizedNewTime);

            if ($validationError !== null) {
                // Slot is no longer available — notify the patient
                $this->notifySlotNoLongerAvailable($appt, $newDate, $newTime);
                return ['success' => false, 'message' => 'The requested time slot is no longer available. Please ask the patient to request a new time.'];
            }

            // 5. Execute the approval in a transaction
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
                        reschedule_response_notes = CONCAT(COALESCE(reschedule_response_notes, ''), ?),
                        reschedule_requested_at = reschedule_requested_at,
                        reschedule_requested_by = reschedule_requested_by
                     WHERE id = ?"
                );

                // Compute new time range
                $duration = $this->getAppointmentDuration((int)$doctorId);
                $newTimeRange = $this->computeTimeRange($newTime, $duration);
                $approvalNote = ' | Approved: ' . ($doctorNotes ?: 'Approved by doctor.');

                $stmt->execute([$newDate, $newTime, $newTimeRange, $this->actorId, $approvalNote, $appointmentId]);

                $this->db->commit();
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

            // 6. Notifications
            $this->sendRescheduleApprovedNotifications($appt, $newDate, $newTime);

            // 7. Audit log
            $this->logRescheduleApproved($appt, $newDate, $newTime);

            return [
                'success' => true,
                'message' => 'Reschedule request approved. Appointment has been updated.',
            ];

        } catch (Exception $e) {
            error_log('RescheduleService::approveReschedule Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to approve reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  STEP 2b: DOCTOR REJECTS RESCHEDULE
    // ═══════════════════════════════════════════════════════════

    /**
     * Doctor rejects a reschedule request.
     *
     * On rejection:
     *   - appointment_date/appointment_time remain unchanged
     *   - status returns to 'Confirmed'
     *   - reschedule_status = 'rejected'
     *   - No data is lost, pending fields remain for audit trail
     *
     * @param int    $appointmentId
     * @param string $doctorNotes Reason for rejection
     * @return array{success: bool, message: string}
     */
    public function rejectReschedule(int $appointmentId, string $doctorNotes = ''): array
    {
        try {
            // 1. Get appointment
            $appt = $this->getAppointment($appointmentId);
            if (!$appt) {
                return ['success' => false, 'message' => 'Appointment not found.'];
            }

            // 2. Verify there is a pending reschedule
            if ($appt['reschedule_status'] !== 'pending') {
                return ['success' => false, 'message' => 'No pending reschedule request for this appointment.'];
            }

            // 3. Verify the actor is the appointment's doctor (or admin)
            if ($this->actorRole === 'doctor' && (int)$appt['doctor_id'] !== $this->actorId) {
                return ['success' => false, 'message' => 'This appointment does not belong to you.'];
            }

            $newDate = $appt['pending_reschedule_date'];
            $newTime = $appt['pending_reschedule_time'];

            // 4. Execute rejection in a transaction
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
            } catch (Exception $e) {
                $this->db->rollBack();
                throw $e;
            }

            // 5. Notifications
            $this->sendRescheduleRejectedNotifications($appt, $doctorNotes);

            // 6. Audit log
            $this->logRescheduleRejected($appt, $doctorNotes);

            return [
                'success' => true,
                'message' => 'Reschedule request rejected. The appointment remains at its original schedule.',
            ];

        } catch (Exception $e) {
            error_log('RescheduleService::rejectReschedule Error: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to reject reschedule request.'];
        }
    }

    // ═══════════════════════════════════════════════════════════
    //  GET RESCHEDULE REQUESTS (for doctor/admin dashboards)
    // ═══════════════════════════════════════════════════════════

    /**
     * Get all pending reschedule requests for a doctor.
     *
     * @param int $doctorId
     * @return array
     */
    public function getPendingRequestsForDoctor(int $doctorId): array
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, u.name AS patient_name
             FROM appointments a
             JOIN users u ON a.user_id = u.id
             WHERE a.doctor_id = ?
               AND a.reschedule_status = 'pending'
             ORDER BY a.reschedule_requested_at DESC"
        );
        $stmt->execute([$doctorId]);
        return $stmt->fetchAll();
    }

    /**
     * Get all pending reschedule requests (for admin).
     *
     * @return array
     */
    public function getAllPendingRequests(): array
    {
        $stmt = $this->db->query(
            "SELECT a.*, u.name AS patient_name, doc.name AS doctor_name
             FROM appointments a
             JOIN users u ON a.user_id = u.id
             JOIN users doc ON a.doctor_id = doc.id
             WHERE a.reschedule_status = 'pending'
             ORDER BY a.reschedule_requested_at DESC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Get reschedule history for a specific appointment.
     *
     * @param int $appointmentId
     * @return array|null
     */
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

    /**
     * Get an appointment by ID.
     *
     * @param int $appointmentId
     * @return array|null
     */
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

    /**
     * Normalize a time string to 24-hour HH:MM format.
     */
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

    /**
     * Convert time string to minutes since midnight.
     */
    private function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        return (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
    }

    /**
     * Get appointment duration for a doctor.
     */
    private function getAppointmentDuration(int $doctorId): int
    {
        $stmt = $this->db->prepare(
            "SELECT appointment_duration FROM doctor_schedule_settings WHERE doctor_id = ? LIMIT 1"
        );
        $stmt->execute([$doctorId]);
        $duration = $stmt->fetchColumn();
        return $duration ? (int)$duration : 30;
    }

    /**
     * Compute a time range string from time and duration.
     */
    private function computeTimeRange(string $time, int $duration): string
    {
        $parts = explode(':', $time);
        $startMin = (int)$parts[0] * 60 + (int)($parts[1] ?? 0);
        $endMin = $startMin + $duration;
        $endH = (int)($endMin / 60) % 24;
        $endM = $endMin % 60;

        // Format start
        $ampm1 = (int)$parts[0] >= 12 ? 'PM' : 'AM';
        $h1 = (int)$parts[0] % 12;
        if ($h1 === 0) $h1 = 12;
        $startStr = "{$h1}:" . str_pad($parts[1] ?? '00', 2, '0', STR_PAD_LEFT) . " {$ampm1}";

        // Format end
        $ampm2 = $endH >= 12 ? 'PM' : 'AM';
        $h2 = $endH % 12;
        if ($h2 === 0) $h2 = 12;
        $endStr = "{$h2}:" . str_pad($endM, 2, '0', STR_PAD_LEFT) . " {$ampm2}";

        return "{$startStr} – {$endStr}";
    }

    // ═══════════════════════════════════════════════════════════
    //  NOTIFICATIONS
    // ═══════════════════════════════════════════════════════════

    /**
     * Send notifications when a reschedule is requested.
     */
    private function sendRescheduleRequestNotifications(array $appt, string $newDate, string $newTime, string $reason): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $patientName = $appt['patient_name'] ?? $appt['patient_name'];

        // Format dates/times for display
        $oldDateFormatted = date('M j', strtotime($appt['date']));
        $oldTimeFormatted = $this->formatTimeForDisplay($appt['time']);
        $newDateFormatted = date('M j', strtotime($newDate));
        $newTimeFormatted = $this->formatTimeForDisplay($newTime);

        // Notify DOCTOR
        $doctorMessage = "{$patientName} requested to reschedule Appointment #{$appointmentId} from {$oldDateFormatted} {$oldTimeFormatted} to {$newDateFormatted} {$newTimeFormatted}.";
        if ($reason) {
            $doctorMessage .= " Reason: {$reason}";
        }
        $ns->create(
            $doctorId,
            NotificationService::TYPE_RESCHEDULE_REQUEST,
            'Reschedule Request',
            $doctorMessage,
            'appointment',
            $appointmentId
        );

        // Notify ADMIN
        $adminMessage = "Patient {$patientName} requested to reschedule Appointment #{$appointmentId}.\nOld: {$oldDateFormatted} {$oldTimeFormatted}\nNew: {$newDateFormatted} {$newTimeFormatted}";
        $this->notifyAllAdmins($adminMessage, NotificationService::TYPE_RESCHEDULE_REQUEST, $appointmentId);

        // Notify PATIENT (confirmation that request was submitted)
        $patientMessage = "Your reschedule request for Appointment #{$appointmentId} has been submitted. The doctor will review your request.";
        $ns->create(
            $patientId,
            NotificationService::TYPE_RESCHEDULE_SUBMITTED,
            'Reschedule Request Submitted',
            $patientMessage,
            'appointment',
            $appointmentId
        );
    }

    /**
     * Send notifications when a reschedule is approved.
     */
    private function sendRescheduleApprovedNotifications(array $appt, string $newDate, string $newTime): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $doctorName = $this->getUserName($doctorId);

        $newDateFormatted = date('M j, Y', strtotime($newDate));
        $newTimeFormatted = $this->formatTimeForDisplay($newTime);

        // Notify PATIENT
        $patientMessage = "Your reschedule request for Appointment #{$appointmentId} has been approved. New appointment: {$newDateFormatted} at {$newTimeFormatted}.";
        $ns->create(
            $patientId,
            NotificationService::TYPE_RESCHEDULE_APPROVED,
            'Reschedule Approved',
            $patientMessage,
            'appointment',
            $appointmentId
        );

        // Notify ADMIN
        $adminMessage = "Doctor {$doctorName} approved the reschedule request for Appointment #{$appointmentId}.";
        $this->notifyAllAdmins($adminMessage, NotificationService::TYPE_RESCHEDULE_APPROVED, $appointmentId);
    }

    /**
     * Send notifications when a reschedule is rejected.
     */
    private function sendRescheduleRejectedNotifications(array $appt, string $doctorNotes): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];
        $doctorId = (int)$appt['doctor_id'];
        $doctorName = $this->getUserName($doctorId);

        // Notify PATIENT
        $patientMessage = "Your reschedule request for Appointment #{$appointmentId} has been declined. Your original appointment remains scheduled.";
        if ($doctorNotes) {
            $patientMessage .= " Doctor's note: {$doctorNotes}";
        }
        $ns->create(
            $patientId,
            NotificationService::TYPE_RESCHEDULE_REJECTED,
            'Reschedule Declined',
            $patientMessage,
            'appointment',
            $appointmentId
        );

        // Notify ADMIN
        $adminMessage = "Doctor {$doctorName} rejected the reschedule request for Appointment #{$appointmentId}.";
        if ($doctorNotes) {
            $adminMessage .= " Reason: {$doctorNotes}";
        }
        $this->notifyAllAdmins($adminMessage, NotificationService::TYPE_RESCHEDULE_REJECTED, $appointmentId);
    }

    /**
     * Notify when requested slot is no longer available at approval time.
     */
    private function notifySlotNoLongerAvailable(array $appt, string $newDate, string $newTime): void
    {
        $ns = new NotificationService($this->db);
        $appointmentId = (int)$appt['id'];
        $patientId = (int)$appt['user_id'];

        $patientMessage = "The time slot you requested for Appointment #{$appointmentId} is no longer available. Please submit a new reschedule request with a different time.";
        $ns->create(
            $patientId,
            NotificationService::TYPE_RESCHEDULE_REJECTED,
            'Reschedule Slot Unavailable',
            $patientMessage,
            'appointment',
            $appointmentId
        );
    }

    /**
     * Get a user's name by ID.
     */
    private function getUserName(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        return $stmt->fetchColumn() ?: 'Unknown';
    }

    /**
     * Get all admin user IDs.
     */
    private function getAdminUserIds(): array
    {
        $stmt = $this->db->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Notify all admin users.
     */
    private function notifyAllAdmins(string $message, string $type, int $refId): void
    {
        $ns = new NotificationService($this->db);
        $admins = $this->getAdminUserIds();
        foreach ($admins as $adminId) {
            $ns->create(
                (int)$adminId,
                $type,
                'Reschedule Notification',
                $message,
                'appointment',
                $refId
            );
        }
    }

    /**
     * Format time string for display.
     */
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

    /**
     * Log reschedule request to audit trail.
     */
    private function logRescheduleRequest(array $appt, string $newDate, string $newTime, string $reason): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);
        $oldDateFormatted = date('M j', strtotime($appt['date'])) . ' ' . $this->formatTimeForDisplay($appt['time']);
        $newDateFormatted = date('M j', strtotime($newDate)) . ' ' . $this->formatTimeForDisplay($newTime);

        $description = "Patient requested appointment reschedule. Old: {$oldDateFormatted}. New: {$newDateFormatted}.";
        if ($reason) {
            $description .= " Reason: {$reason}";
        }

        $audit->log(
            'reschedule_request',
            'appointment',
            (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['date' => $newDate, 'time' => $newTime, 'reason' => $reason],
            $description,
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
    }

    /**
     * Log reschedule approval to audit trail.
     */
    private function logRescheduleApproved(array $appt, string $newDate, string $newTime): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);

        $description = "Doctor approved appointment reschedule. Appointment #{$appt['id']} moved to {$newDate} at {$newTime}.";
        if ($appt['reschedule_reason']) {
            $description .= " Patient reason: {$appt['reschedule_reason']}";
        }

        $audit->log(
            'reschedule_approved',
            'appointment',
            (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time']],
            ['date' => $newDate, 'time' => $newTime],
            $description,
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
    }

    /**
     * Log reschedule rejection to audit trail.
     */
    private function logRescheduleRejected(array $appt, string $doctorNotes): void
    {
        $audit = new AuditService($this->db, $this->actorId, $this->actorRole);

        $description = "Doctor rejected appointment reschedule. Appointment #{$appt['id']} remains at original schedule.";
        if ($doctorNotes) {
            $description .= " Doctor notes: {$doctorNotes}";
        }

        $audit->log(
            'reschedule_rejected',
            'appointment',
            (int)$appt['id'],
            ['date' => $appt['date'], 'time' => $appt['time'], 'requested_date' => $appt['pending_reschedule_date'], 'requested_time' => $appt['pending_reschedule_time']],
            ['status' => 'rejected'],
            $description,
            (int)$appt['user_id'],
            (int)$appt['doctor_id']
        );
    }
}