<?php
/**
 * Shared appointment time utilities.
 *
 * Keeps schedule generation, rescheduling, patient views and API formatters
 * consistent without duplicating parsing or duration queries.
 */
final class AppointmentTime
{
    /**
     * Normalize HH:MM, h:mm AM/PM and h:mmAM/PM values to 24-hour HH:MM.
     * Invalid values are returned unchanged so callers can retain their
     * existing validation/fallback behavior.
     */
    public static function normalize(string $time): string
    {
        $time = trim($time);

        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time)) {
            return $time;
        }

        $compact = strtoupper((string)preg_replace('/\s+/', '', $time));
        foreach (['h:i A', 'h:iA'] as $format) {
            $parsed = date_parse_from_format($format, $format === 'h:i A' ? strtoupper($time) : $compact);
            if (
                $parsed['error_count'] === 0
                && $parsed['warning_count'] === 0
                && isset($parsed['hour'], $parsed['minute'])
            ) {
                return sprintf('%02d:%02d', $parsed['hour'], $parsed['minute']);
            }
        }

        return $time;
    }

    /**
     * Convert a time string to minutes since midnight.
     * Callers use this for already validated schedule values.
     */
    public static function toMinutes(string $time): int
    {
        $normalized = self::normalize($time);
        $parts = explode(':', $normalized, 2);

        return (int)($parts[0] ?? 0) * 60 + (int)($parts[1] ?? 0);
    }

    /**
     * Read a doctor's configured appointment length using the caller's
     * connection, defaulting to 30 minutes when no setting exists.
     */
    public static function duration(PDO $db, int $doctorId): int
    {
        $stmt = $db->prepare(
            'SELECT appointment_duration FROM doctor_schedule_settings WHERE doctor_id = ? LIMIT 1'
        );
        $stmt->execute([$doctorId]);
        $duration = $stmt->fetchColumn();

        return $duration !== false && $duration !== null && (int)$duration > 0
            ? (int)$duration
            : 30;
    }

    /**
     * Format 24-hour or 12-hour input for display as h:mm AM/PM.
     * Invalid input is returned unchanged.
     */
    public static function formatDisplay(string $time): string
    {
        $normalized = self::normalize($time);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $normalized, $parts)) {
            return $time;
        }

        $hour = (int)$parts[1];
        $minute = $parts[2];
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour % 12 ?: 12;

        return $displayHour . ':' . $minute . ' ' . $ampm;
    }

    /**
     * Format an appointment range in the same display style used by the
     * rescheduling flow (for example, 9:00 AM – 9:30 AM).
     */
    public static function range(string $time, int $duration): string
    {
        $startMinutes = self::toMinutes($time);
        $endMinutes = $startMinutes + max(0, $duration);

        return self::formatMinutes($startMinutes) . ' – ' . self::formatMinutes($endMinutes);
    }

    private static function formatMinutes(int $minutes): string
    {
        $minutes = (($minutes % 1440) + 1440) % 1440;
        $hour = intdiv($minutes, 60);
        $minute = $minutes % 60;
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $displayHour = $hour % 12 ?: 12;

        return $displayHour . ':' . str_pad((string)$minute, 2, '0', STR_PAD_LEFT) . ' ' . $ampm;
    }
}
