<?php
/**
 * HealthBridge — General Helper Functions & Formatters
 */

require_once __DIR__ . '/AppointmentTime.php';

/**
 * Compute an appointment time range string like "09:00 AM – 09:30 AM"
 * given a time string (e.g. "09:00" or "09:00 AM") and an optional duration in minutes.
 * Falls back to formatting with AM/PM if duration is not available.
 *
 * @param string $time     The appointment time (24-hour "HH:MM" or 12-hour "HH:MM AM/PM")
 * @param int    $duration Appointment duration in minutes (default 30)
 * @return string Formatted time range, e.g. "9:00 AM – 9:30 AM"
 */
function computeAppointmentTimeRange(string $time, int $duration = 30): string
{
    $normalized = AppointmentTime::normalize($time);
    if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $normalized)) {
        return formatTimeAmPm($time);
    }

    return AppointmentTime::range($normalized, $duration);
}

/**
 * Format a time string to AM/PM (fallback for computeAppointmentTimeRange)
 */
function formatTimeAmPm(string $time): string
{
    return AppointmentTime::formatDisplay($time);
}

/**
 * Get default appointment duration for a doctor.
 *
 * Results and the supporting PDO connection are cached for this request so
 * rendering multiple appointments for the same doctor does not repeat queries.
 * Falls back to 30 minutes when the setting cannot be read.
 */
function getAppointmentDuration(int $doctorId): int
{
    static $durationCache = [];
    static $durationDb = null;

    if ($doctorId <= 0) {
        return 30;
    }

    if (array_key_exists($doctorId, $durationCache)) {
        return $durationCache[$doctorId];
    }

    try {
        if (!$durationDb instanceof PDO) {
            $durationDb = getDB();
        }

        $durationCache[$doctorId] = AppointmentTime::duration($durationDb, $doctorId);
    } catch (Throwable $e) {
        $durationCache[$doctorId] = 30;
    }

    return $durationCache[$doctorId];
}
