<?php
/**
 * HealthBridge — NotificationService
 *
 * Centralized service for creating, fetching, and managing notifications.
 * All notification operations go through this class.
 *
 * Usage:
 *   $ns = new NotificationService(getDB());
 *   $ns->create($userId, NotificationService::TYPE_APPOINTMENT_CONFIRMED, ...);
 *
 * Designed so the frontend can later switch from polling to SSE/WebSocket
 * without changing the public API (js/notifications.js).
 */

class NotificationService
{
    // ── Notification Type Constants ──────────────────────────
    // These are used as the `type` column in the notifications table.
    // They map to Font Awesome icons in the frontend.

    const TYPE_APPOINTMENT_CONFIRMED  = 'appointment_confirmed';
    const TYPE_APPOINTMENT_DECLINED   = 'appointment_declined';
    const TYPE_APPOINTMENT_REQUEST    = 'appointment_request';
    const TYPE_APPOINTMENT_CANCELLED  = 'appointment_cancelled';
    const TYPE_APPOINTMENT_CHANGED    = 'appointment_time_changed';
    const TYPE_RATING_RECEIVED        = 'rating_received';
    const TYPE_REVIEW_RECEIVED        = 'review_received';
    const TYPE_SUPPORT_REPLY          = 'support_reply';
    const TYPE_PASSWORD_CHANGED       = 'password_changed';
    const TYPE_PROFILE_UPDATED        = 'profile_updated';
    const TYPE_ACCOUNT_STATUS_CHANGED = 'account_status_changed';
    const TYPE_NEW_PATIENT            = 'new_patient_registered';
    const TYPE_NEW_DOCTOR             = 'new_doctor_registered';
    const TYPE_NEW_SUPPORT_TICKET     = 'new_support_ticket';
    const TYPE_MEDICAL_RECORD_UPDATED = 'medical_record_updated';
    const TYPE_VISIT_NOTE_ADDED       = 'visit_note_added';
    const TYPE_PRESCRIPTION_ISSUED    = 'prescription_issued';
    const TYPE_PRESCRIPTION_UPDATED   = 'prescription_updated';
    const TYPE_PRESCRIPTION_COMPLETED = 'prescription_completed';
    const TYPE_PRESCRIPTION_CANCELLED = 'prescription_cancelled';
    const TYPE_APPOINTMENT_COMPLETED  = 'appointment_completed';

    // ── Reschedule Workflow Notification Types ──────────────
    const TYPE_RESCHEDULE_REQUEST             = 'reschedule_request';
    const TYPE_RESCHEDULE_SUBMITTED           = 'reschedule_submitted';
    const TYPE_RESCHEDULE_SUGGESTED           = 'reschedule_suggested';
    const TYPE_RESCHEDULE_SUGGESTION_ACCEPTED = 'reschedule_suggestion_accepted';
    const TYPE_RESCHEDULE_SUGGESTION_DECLINED = 'reschedule_suggestion_declined';
    const TYPE_RESCHEDULE_APPROVED            = 'reschedule_approved';
    const TYPE_RESCHEDULE_REJECTED            = 'reschedule_rejected';
    const TYPE_RESCHEDULE_REQUEST_CANCELLED   = 'reschedule_request_cancelled';

    /**
     * Map each notification type to its corresponding preference column
     * in the user_preferences table. This reuses the existing preference
     * system without schema changes.
     *
     * @var array<string, string>  type => preference column name
     */
    private const TYPE_TO_PREF = [
        self::TYPE_APPOINTMENT_CONFIRMED          => 'notif_appointment',
        self::TYPE_APPOINTMENT_DECLINED           => 'notif_appointment',
        self::TYPE_APPOINTMENT_REQUEST            => 'notif_appointment',
        self::TYPE_APPOINTMENT_CANCELLED          => 'notif_appointment',
        self::TYPE_APPOINTMENT_CHANGED            => 'notif_appointment',
        self::TYPE_APPOINTMENT_COMPLETED          => 'notif_appointment',
        self::TYPE_RESCHEDULE_REQUEST             => 'notif_appointment',
        self::TYPE_RESCHEDULE_SUBMITTED           => 'notif_appointment',
        self::TYPE_RESCHEDULE_SUGGESTED           => 'notif_appointment',
        self::TYPE_RESCHEDULE_SUGGESTION_ACCEPTED => 'notif_appointment',
        self::TYPE_RESCHEDULE_SUGGESTION_DECLINED => 'notif_appointment',
        self::TYPE_RESCHEDULE_APPROVED            => 'notif_appointment',
        self::TYPE_RESCHEDULE_REJECTED            => 'notif_appointment',
        self::TYPE_RESCHEDULE_REQUEST_CANCELLED   => 'notif_appointment',
        self::TYPE_RATING_RECEIVED                => 'notif_ratings',
        self::TYPE_REVIEW_RECEIVED                => 'notif_ratings',
        self::TYPE_SUPPORT_REPLY                  => 'notif_messages',
        self::TYPE_PASSWORD_CHANGED               => 'notif_appointment',
        self::TYPE_PROFILE_UPDATED                => 'notif_appointment',
        self::TYPE_ACCOUNT_STATUS_CHANGED         => 'notif_appointment',
        self::TYPE_NEW_PATIENT                    => 'notif_announcements',
        self::TYPE_NEW_DOCTOR                     => 'notif_announcements',
        self::TYPE_NEW_SUPPORT_TICKET             => 'notif_messages',
        self::TYPE_MEDICAL_RECORD_UPDATED         => 'notif_appointment',
        self::TYPE_VISIT_NOTE_ADDED               => 'notif_appointment',
        self::TYPE_PRESCRIPTION_ISSUED            => 'notif_appointment',
        self::TYPE_PRESCRIPTION_UPDATED           => 'notif_appointment',
        self::TYPE_PRESCRIPTION_COMPLETED         => 'notif_appointment',
        self::TYPE_PRESCRIPTION_CANCELLED         => 'notif_appointment',
    ];

    /**
     * Canonical server-side labels for notification types.
     * The current Settings UI maintains its own presentation map and does not call this helper.
     *
     * @return array<string, string>  type => label
     */
    public static function getAllTypes(): array
    {
        return [
            self::TYPE_APPOINTMENT_CONFIRMED          => 'Appointment Confirmed',
            self::TYPE_APPOINTMENT_DECLINED           => 'Appointment Declined',
            self::TYPE_APPOINTMENT_REQUEST            => 'New Appointment Request',
            self::TYPE_APPOINTMENT_CANCELLED          => 'Appointment Cancelled',
            self::TYPE_APPOINTMENT_CHANGED            => 'Appointment Time Changed',
            self::TYPE_APPOINTMENT_COMPLETED          => 'Appointment Completed',
            self::TYPE_RESCHEDULE_REQUEST             => 'Reschedule Requested',
            self::TYPE_RESCHEDULE_SUBMITTED           => 'Reschedule Request Submitted',
            self::TYPE_RESCHEDULE_SUGGESTED           => 'New Appointment Time Suggested',
            self::TYPE_RESCHEDULE_SUGGESTION_ACCEPTED => 'Reschedule Suggestion Accepted',
            self::TYPE_RESCHEDULE_SUGGESTION_DECLINED => 'Reschedule Suggestion Declined',
            self::TYPE_RESCHEDULE_APPROVED            => 'Reschedule Approved',
            self::TYPE_RESCHEDULE_REJECTED            => 'Reschedule Declined',
            self::TYPE_RESCHEDULE_REQUEST_CANCELLED   => 'Reschedule Request Cancelled',
            self::TYPE_RATING_RECEIVED                => 'Rating Received',
            self::TYPE_REVIEW_RECEIVED                => 'Review Received',
            self::TYPE_SUPPORT_REPLY                  => 'Support Reply',
            self::TYPE_PASSWORD_CHANGED               => 'Password Changed',
            self::TYPE_PROFILE_UPDATED                => 'Profile Updated',
            self::TYPE_ACCOUNT_STATUS_CHANGED         => 'Account Status Changed',
            self::TYPE_NEW_PATIENT                    => 'New Patient Registered',
            self::TYPE_NEW_DOCTOR                     => 'New Doctor Registered',
            self::TYPE_NEW_SUPPORT_TICKET             => 'New Support Ticket',
            self::TYPE_MEDICAL_RECORD_UPDATED         => 'Medical Record Updated',
            self::TYPE_VISIT_NOTE_ADDED               => 'Visit Notes Added',
            self::TYPE_PRESCRIPTION_ISSUED            => 'Prescription Issued',
            self::TYPE_PRESCRIPTION_UPDATED           => 'Prescription Updated',
            self::TYPE_PRESCRIPTION_COMPLETED         => 'Prescription Completed',
            self::TYPE_PRESCRIPTION_CANCELLED         => 'Prescription Cancelled',
        ];
    }

    /**
     * Notification types grouped by the role that can receive them.
     * Available to server-side consumers; the current Settings UI does not call this helper.
     *
     * @return array<string, array<string>>  role => [type, ...]
     */
    public static function getTypesByRole(): array
    {
        return [
            'patient' => [
                self::TYPE_APPOINTMENT_CONFIRMED,
                self::TYPE_APPOINTMENT_DECLINED,
                self::TYPE_APPOINTMENT_CANCELLED,
                self::TYPE_APPOINTMENT_CHANGED,
                self::TYPE_APPOINTMENT_COMPLETED,
                self::TYPE_RESCHEDULE_SUBMITTED,
                self::TYPE_RESCHEDULE_SUGGESTED,
                self::TYPE_RESCHEDULE_APPROVED,
                self::TYPE_RESCHEDULE_REJECTED,
                self::TYPE_RESCHEDULE_REQUEST_CANCELLED,
                self::TYPE_SUPPORT_REPLY,
                self::TYPE_PASSWORD_CHANGED,
                self::TYPE_PROFILE_UPDATED,
                self::TYPE_ACCOUNT_STATUS_CHANGED,
                self::TYPE_MEDICAL_RECORD_UPDATED,
                self::TYPE_VISIT_NOTE_ADDED,
                self::TYPE_PRESCRIPTION_ISSUED,
                self::TYPE_PRESCRIPTION_UPDATED,
                self::TYPE_PRESCRIPTION_COMPLETED,
                self::TYPE_PRESCRIPTION_CANCELLED,
            ],
            'doctor' => [
                self::TYPE_APPOINTMENT_REQUEST,
                self::TYPE_APPOINTMENT_CANCELLED,
                self::TYPE_RESCHEDULE_REQUEST,
                self::TYPE_RESCHEDULE_SUGGESTION_ACCEPTED,
                self::TYPE_RESCHEDULE_SUGGESTION_DECLINED,
                self::TYPE_RESCHEDULE_REQUEST_CANCELLED,
                self::TYPE_RATING_RECEIVED,
                self::TYPE_REVIEW_RECEIVED,
                self::TYPE_SUPPORT_REPLY,
                self::TYPE_PASSWORD_CHANGED,
                self::TYPE_PROFILE_UPDATED,
                self::TYPE_ACCOUNT_STATUS_CHANGED,
            ],
            'admin' => [
                self::TYPE_NEW_PATIENT,
                self::TYPE_NEW_DOCTOR,
                self::TYPE_NEW_SUPPORT_TICKET,
                self::TYPE_RESCHEDULE_REQUEST,
                self::TYPE_RESCHEDULE_SUGGESTED,
                self::TYPE_RESCHEDULE_APPROVED,
                self::TYPE_RESCHEDULE_REJECTED,
                self::TYPE_RATING_RECEIVED,
                self::TYPE_SUPPORT_REPLY,
                self::TYPE_PASSWORD_CHANGED,
                self::TYPE_PROFILE_UPDATED,
            ],
        ];
    }

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── Create ──────────────────────────────────────────────

    /**
     * Create a new notification.
     *
     * @param int    $userId  Recipient user ID
     * @param string $type    One of the TYPE_* constants
     * @param string $title   Short headline
     * @param string $message Body text
     * @param string|null $refType  Related entity type ('appointment', 'rating', 'contact_message', 'doctor', 'user')
     * @param int|null    $refId    Related entity ID
     * @return int Notification ID when delivered immediately; 0 if skipped or queued for later delivery
     */
    public function create(int $userId, string $type, string $title, string $message, ?string $refType = null, ?int $refId = null): int
    {
        // Preserve the existing preference decision at event time.
        if (!$this->isTypeEnabled($userId, $type)) {
            return 0;
        }

        // The outbox has no recipient FK by design. When this connection is
        // inside a business transaction, the queued event commits or rolls
        // back with that mutation without taking a recipient-user FK lock.
        $stmt = $this->db->prepare(
            "INSERT INTO notification_outbox
                (user_id, type, title, message, ref_type, ref_id, status, available_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), NOW())"
        );
        $stmt->execute([$userId, $type, $title, $message, $refType, $refId]);
        $outboxId = (int)$this->db->lastInsertId();

        // A caller with an open transaction must let its transaction finish.
        // The CLI worker will deliver the committed event.
        if ($outboxId <= 0 || $this->db->inTransaction()) {
            return 0;
        }

        try {
            $result = $this->processOutbox(1, $outboxId);
            return (int)($result['delivered_ids'][$outboxId] ?? 0);
        } catch (Throwable $error) {
            // The event is already durable. Leave it pending for the worker.
            error_log(
                'Notification outbox immediate dispatch failed for event '
                . $outboxId . ': ' . get_class($error)
            );
            return 0;
        }
    }

    /**
     * Deliver queued notifications with per-event transactions and bounded retry.
     *
     * The notification insert and outbox completion update commit together,
     * so a worker crash cannot create a duplicate on the next run.
     *
     * @return array{delivered:int,skipped:int,retried:int,failed:int,remaining:int,delivered_ids:array<int,int>}
     */
    public function processOutbox(int $limit = 50, ?int $onlyId = null): array
    {
        $limit = min(max($limit, 1), 100);
        $stats = [
            'delivered' => 0,
            'skipped' => 0,
            'retried' => 0,
            'failed' => 0,
            'remaining' => 0,
            'delivered_ids' => [],
        ];

        // Never open a nested transaction from a business request.
        if ($this->db->inTransaction()) {
            return $stats;
        }

        for ($processed = 0; $processed < $limit; $processed++) {
            $event = null;
            $eventId = null;

            try {
                $this->db->beginTransaction();

                $sql = "SELECT id, user_id, type, title, message, ref_type, ref_id, created_at, attempt_count
                        FROM notification_outbox
                        WHERE status = 'pending'
                          AND available_at <= NOW()";
                $params = [];
                if ($onlyId !== null) {
                    $sql .= ' AND id = ?';
                    $params[] = $onlyId;
                }
                $sql .= ' ORDER BY id ASC LIMIT 1 FOR UPDATE';

                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
                $event = $stmt->fetch();

                if (!$event) {
                    $this->db->commit();
                    break;
                }

                $eventId = (int)$event['id'];

                // The outbox intentionally survives account deletion. Do not
                // retry an event for a recipient that no longer exists.
                $recipientStmt = $this->db->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
                $recipientStmt->execute([(int)$event['user_id']]);
                if (!$recipientStmt->fetchColumn()) {
                    $this->markOutboxSkipped($eventId, 'recipient_missing');
                    $this->db->commit();
                    $stats['skipped']++;
                    continue;
                }

                // Re-check current preferences at delivery time as well.
                if (!$this->isTypeEnabled((int)$event['user_id'], (string)$event['type'])) {
                    $this->markOutboxSkipped($eventId, 'preference_disabled');
                    $this->db->commit();
                    $stats['skipped']++;
                    continue;
                }

                $insert = $this->db->prepare(
                    'INSERT INTO notifications (user_id, type, title, message, ref_type, ref_id, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                );
                $insert->execute([
                    (int)$event['user_id'],
                    (string)$event['type'],
                    (string)$event['title'],
                    (string)$event['message'],
                    $event['ref_type'] !== null ? (string)$event['ref_type'] : null,
                    $event['ref_id'] !== null ? (int)$event['ref_id'] : null,
                    $event['created_at'],
                ]);
                $notificationId = (int)$this->db->lastInsertId();

                $complete = $this->db->prepare(
                    "UPDATE notification_outbox
                     SET status = 'delivered', processed_at = NOW(), last_error_code = NULL
                     WHERE id = ? AND status = 'pending'"
                );
                $complete->execute([$eventId]);
                if ($complete->rowCount() !== 1) {
                    throw new RuntimeException('Notification outbox event state changed during delivery.');
                }

                $this->db->commit();
                $stats['delivered']++;
                $stats['delivered_ids'][$eventId] = $notificationId;
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                if ($eventId === null) {
                    throw $error;
                }

                $nextAttempt = (int)$event['attempt_count'] + 1;
                $errorCode = $error instanceof PDOException
                    ? (string)($error->errorInfo[1] ?? $error->getCode())
                    : get_class($error);
                $errorCode = substr(preg_replace('/[^A-Za-z0-9_.-]/', '_', $errorCode) ?: 'unknown', 0, 64);

                try {
                    $retry = $this->db->prepare(
                        "UPDATE notification_outbox
                         SET status = CASE WHEN attempt_count >= 7 THEN 'failed' ELSE 'pending' END,
                             available_at = DATE_ADD(NOW(), INTERVAL
                                 CASE attempt_count
                                     WHEN 0 THEN 30
                                     WHEN 1 THEN 60
                                     WHEN 2 THEN 120
                                     WHEN 3 THEN 240
                                     WHEN 4 THEN 480
                                     WHEN 5 THEN 960
                                     WHEN 6 THEN 1920
                                     ELSE 1920
                                 END SECOND),
                             processed_at = CASE WHEN attempt_count >= 7 THEN NOW() ELSE NULL END,
                             last_error_code = ?,
                             attempt_count = attempt_count + 1
                         WHERE id = ? AND status = 'pending'"
                    );
                    $retry->execute([$errorCode, $eventId]);
                    if ($nextAttempt >= 8) {
                        $stats['failed']++;
                    } else {
                        $stats['retried']++;
                    }
                } catch (Throwable $retryError) {
                    error_log(
                        'Notification outbox retry-state update failed for event '
                        . $eventId . ': ' . get_class($retryError)
                    );
                }

                // Avoid retrying the same failed event repeatedly in one run.
                if ($onlyId !== null) {
                    break;
                }
            }
        }

        // Bound retention of completed payloads. Terminal failures remain for
        // operator review; delivered/skipped payloads are removed after 30 days.
        $this->db->exec(
            "DELETE FROM notification_outbox
             WHERE status IN ('delivered', 'skipped')
               AND processed_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        $remaining = $this->db->query(
            "SELECT COUNT(*) FROM notification_outbox WHERE status = 'pending'"
        );
        $stats['remaining'] = (int)$remaining->fetchColumn();

        return $stats;
    }

    private function markOutboxSkipped(int $eventId, string $reason): void
    {
        $stmt = $this->db->prepare(
            "UPDATE notification_outbox
             SET status = 'skipped', processed_at = NOW(), last_error_code = ?
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$reason, $eventId]);
    }

    // ── Fetch ───────────────────────────────────────────────

    /**
     * Fetch paginated notifications for a user, newest first.
     *
     * @param int $userId
     * @param int $page   1-based page number
     * @param int $perPage  Items per page (max 50)
     * @return array{notifications: array, unread_count: int, has_more: bool, total: int}
     */
    public function getNotifications(int $userId, int $page = 1, int $perPage = 20): array
    {
        $perPage = min(max($perPage, 1), 50);
        $offset  = max(($page - 1) * $perPage, 0);

        // Total count
        $countStmt = $this->db->prepare('SELECT COUNT(*) as c FROM notifications WHERE user_id = ?');
        $countStmt->execute([$userId]);
        $total = (int) $countStmt->fetch()['c'];

        // Unread count
        $unreadStmt = $this->db->prepare('SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0');
        $unreadStmt->execute([$userId]);
        $unreadCount = (int) $unreadStmt->fetch()['c'];

        // Paginated results (interpolated limit & offset to prevent PDO MySQL type binding errors)
        $limit = max(1, (int) $perPage);
        $off   = max(0, (int) $offset);
        $stmt = $this->db->prepare(
            "SELECT id, type, title, message, ref_type, ref_id, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT {$limit} OFFSET {$off}"
        );
        $stmt->execute([$userId]);
        $notifications = $stmt->fetchAll();

        return [
            'notifications' => $notifications,
            'unread_count'  => $unreadCount,
            'has_more'      => ($offset + $perPage) < $total,
            'total'         => $total,
        ];
    }

    /**
     * Get only the unread count (lightweight, for badge updates).
     */
    public function getUnreadCount(int $userId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$userId]);
        return (int) $stmt->fetch()['c'];
    }

    // ── Mark as Read ────────────────────────────────────────

    /**
     * Mark a single notification as read.
     * @return bool True if a row was updated
     */
    public function markAsRead(int $notifId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? AND is_read = 0'
        );
        $stmt->execute([$notifId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Mark all notifications as read for a user.
     * @return int Number of rows updated
     */
    public function markAllAsRead(int $userId): int
    {
        $stmt = $this->db->prepare(
            'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0'
        );
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    // ── Delete ──────────────────────────────────────────────

    /**
     * Delete a single notification.
     * @return bool True if a row was deleted
     */
    public function delete(int $notifId, int $userId): bool
    {
        $stmt = $this->db->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([$notifId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all notifications for a user.
     * @return int Number of rows deleted
     */
    public function deleteAll(int $userId): int
    {
        $stmt = $this->db->prepare('DELETE FROM notifications WHERE user_id = ?');
        $stmt->execute([$userId]);
        return $stmt->rowCount();
    }

    // ── Preference Check ────────────────────────────────────

    /**
     * Check if a user has a specific notification type enabled.
     * Reuses the existing user_preferences table columns.
     * Falls back to enabled (true) if no preference is set.
     */
    private function isTypeEnabled(int $userId, string $type): bool
    {
        $prefColumn = self::TYPE_TO_PREF[$type] ?? 'notif_appointment';

        $stmt = $this->db->prepare(
            "SELECT {$prefColumn} as val FROM user_preferences WHERE user_id = ?"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return true; // No row = enabled by default
        }

        return (int) $row['val'] === 1;
    }
}