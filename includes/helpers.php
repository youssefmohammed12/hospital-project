<?php
/**
 * HealthBridge — General Helper Functions & Formatters
 */

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
    // Normalize to 24-hour format
    $hour = 0;
    $min = 0;
    
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time, $m)) {
        $h = (int)$m[1];
        $ampm = strtoupper($m[3]);
        if ($ampm === 'PM' && $h !== 12) $h += 12;
        if ($ampm === 'AM' && $h === 12) $h = 0;
        $hour = $h;
        $min = (int)$m[2];
    } elseif (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
        $hour = (int)$m[1];
        $min = (int)$m[2];
    } else {
        // Fallback: return formatted time
        return formatTimeAmPm($time);
    }
    
    $startMinutes = $hour * 60 + $min;
    $endMinutes = $startMinutes + $duration;
    
    $startHour = intdiv($startMinutes, 60);
    $startMin = $startMinutes % 60;
    $endHour = intdiv($endMinutes, 60);
    $endMin = $endMinutes % 60;
    
    $startAmpm = $startHour >= 12 ? 'PM' : 'AM';
    $endAmpm = $endHour >= 12 ? 'PM' : 'AM';
    $startDisplay = ($startHour % 12 ?: 12) . ':' . str_pad($startMin, 2, '0', STR_PAD_LEFT);
    $endDisplay = ($endHour % 12 ?: 12) . ':' . str_pad($endMin, 2, '0', STR_PAD_LEFT);
    
    return "{$startDisplay} {$startAmpm} – {$endDisplay} {$endAmpm}";
}

/**
 * Format a time string to AM/PM (fallback for computeAppointmentTimeRange)
 */
function formatTimeAmPm(string $time): string
{
    if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', $time, $m)) {
        return (int)$m[1] . ':' . $m[2] . ' ' . strtoupper($m[3]);
    }
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $m)) {
        $h = (int)$m[1];
        $ampm = $h >= 12 ? 'PM' : 'AM';
        $display = ($h % 12 ?: 12) . ':' . $m[2];
        return "{$display} {$ampm}";
    }
    return $time;
}

/**
 * Get default appointment duration for a doctor.
 * Falls back to 30.
 */
function getAppointmentDuration(int $doctorId): int
{
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT appointment_duration FROM doctor_schedule_settings WHERE doctor_id = ? LIMIT 1");
        $stmt->execute([$doctorId]);
        $row = $stmt->fetch();
        return $row ? (int)$row['appointment_duration'] : 30;
    } catch (\Exception $e) {
        return 30;
    }
}
