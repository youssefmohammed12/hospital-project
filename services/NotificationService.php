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

    /**
     * Map each notification type to its corresponding preference column
     * in the user_preferences table. This reuses the existing preference
     * system without schema changes.
     *
     * @var array<string, string>  type => preference column name
     */
    private const TYPE_TO_PREF = [
        self::TYPE_APPOINTMENT_CONFIRMED  => 'notif_appointment',
        self::TYPE_APPOINTMENT_DECLINED   => 'notif_appointment',
        self::TYPE_APPOINTMENT_REQUEST    => 'notif_appointment',
        self::TYPE_APPOINTMENT_CANCELLED  => 'notif_appointment',
        self::TYPE_APPOINTMENT_CHANGED    => 'notif_appointment',
        self::TYPE_APPOINTMENT_COMPLETED  => 'notif_appointment',
        self::TYPE_RATING_RECEIVED        => 'notif_ratings',
        self::TYPE_REVIEW_RECEIVED        => 'notif_ratings',
        self::TYPE_SUPPORT_REPLY          => 'notif_messages',
        self::TYPE_PASSWORD_CHANGED       => 'notif_appointment',
        self::TYPE_PROFILE_UPDATED        => 'notif_appointment',
        self::TYPE_ACCOUNT_STATUS_CHANGED => 'notif_appointment',
        self::TYPE_NEW_PATIENT            => 'notif_announcements',
        self::TYPE_NEW_DOCTOR             => 'notif_announcements',
        self::TYPE_NEW_SUPPORT_TICKET     => 'notif_messages',
        self::TYPE_MEDICAL_RECORD_UPDATED => 'notif_appointment',
        self::TYPE_VISIT_NOTE_ADDED       => 'notif_appointment',
        self::TYPE_PRESCRIPTION_ISSUED    => 'notif_appointment',
        self::TYPE_PRESCRIPTION_UPDATED   => 'notif_appointment',
        self::TYPE_PRESCRIPTION_COMPLETED => 'notif_appointment',
        self::TYPE_PRESCRIPTION_CANCELLED => 'notif_appointment',
    ];

    /**
     * All notification types with their human-readable labels.
     * Used by the Settings UI to let users toggle notification categories.
     *
     * @return array<string, string>  type => label
     */
    public static function getAllTypes(): array
    {
        return [
            self::TYPE_APPOINTMENT_CONFIRMED  => 'Appointment Confirmed',
            self::TYPE_APPOINTMENT_DECLINED   => 'Appointment Declined',
            self::TYPE_APPOINTMENT_REQUEST    => 'New Appointment Request',
            self::TYPE_APPOINTMENT_CANCELLED  => 'Appointment Cancelled',
            self::TYPE_APPOINTMENT_CHANGED    => 'Appointment Time Changed',
            self::TYPE_APPOINTMENT_COMPLETED  => 'Appointment Completed',
            self::TYPE_RATING_RECEIVED        => 'Rating Received',
            self::TYPE_REVIEW_RECEIVED        => 'Review Received',
            self::TYPE_SUPPORT_REPLY          => 'Support Reply',
            self::TYPE_PASSWORD_CHANGED       => 'Password Changed',
            self::TYPE_PROFILE_UPDATED        => 'Profile Updated',
            self::TYPE_ACCOUNT_STATUS_CHANGED => 'Account Status Changed',
            self::TYPE_NEW_PATIENT            => 'New Patient Registered',
            self::TYPE_NEW_DOCTOR             => 'New Doctor Registered',
            self::TYPE_NEW_SUPPORT_TICKET     => 'New Support Ticket',
            self::TYPE_MEDICAL_RECORD_UPDATED => 'Medical Record Updated',
            self::TYPE_VISIT_NOTE_ADDED       => 'Visit Notes Added',
            self::TYPE_PRESCRIPTION_ISSUED    => 'Prescription Issued',
            self::TYPE_PRESCRIPTION_UPDATED   => 'Prescription Updated',
            self::TYPE_PRESCRIPTION_COMPLETED => 'Prescription Completed',
            self::TYPE_PRESCRIPTION_CANCELLED => 'Prescription Cancelled',
        ];
    }

    /**
     * Notification types that are relevant for each role.
     * Used to filter which toggles appear in the Settings UI.
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
     * @return int  The new notification ID (0 if skipped due to preferences)
     */
    public function create(int $userId, string $type, string $title, string $message, ?string $refType = null, ?int $refId = null): int
    {
        // Check if user has this notification type enabled in preferences
        if (!$this->isTypeEnabled($userId, $type)) {
            return 0; // Silently skip — user opted out
        }

        $stmt = $this->db->prepare(
            'INSERT INTO notifications (user_id, type, title, message, ref_type, ref_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$userId, $type, $title, $message, $refType, $refId]);
        return (int) $this->db->lastInsertId();
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

        // Paginated results
        $stmt = $this->db->prepare(
            'SELECT id, type, title, message, ref_type, ref_id, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT ? OFFSET ?'
        );
        $stmt->execute([$userId, $perPage, $offset]);
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