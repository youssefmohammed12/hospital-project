<?php
declare(strict_types=1);

/**
 * Allowlist CSS class tokens derived from database-backed print status fields.
 *
 * Display labels remain separately HTML-escaped by the print templates.
 * Unknown or malformed values receive a neutral styling token.
 */
final class PrintOutputContext
{
    private const APPOINTMENT_STATUS_CLASSES = [
        'Pending',
        'Confirmed',
        'Cancelled',
    ];

    private const PRESCRIPTION_STATUS_CLASSES = [
        'active',
        'completed',
        'cancelled',
    ];

    public static function appointmentStatusClass(mixed $status): string
    {
        return is_string($status)
            && in_array($status, self::APPOINTMENT_STATUS_CLASSES, true)
                ? $status
                : 'unknown';
    }

    public static function prescriptionStatusClass(mixed $status): string
    {
        $normalized = is_string($status) ? strtolower(trim($status)) : '';

        return in_array($normalized, self::PRESCRIPTION_STATUS_CLASSES, true)
            ? $normalized
            : 'unknown';
    }
}
