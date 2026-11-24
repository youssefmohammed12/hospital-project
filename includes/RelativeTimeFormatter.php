<?php
/**
 * Shared relative-time formatting for dashboard and notification views.
 */
final class RelativeTimeFormatter
{
    /**
     * @param string $datetime MySQL datetime or any strtotime-compatible value.
     * @param bool $compact Use short labels ("5m ago", "2d ago") for dense UI.
     */
    public static function format(string $datetime, bool $compact = false): string
    {
        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return 'Unknown';
        }

        $difference = time() - $timestamp;
        if ($difference < 60) {
            if ($difference <= 5) {
                return 'Just now';
            }

            return $compact
                ? $difference . 's ago'
                : $difference . ' seconds ago';
        }

        if ($difference < 3600) {
            $minutes = (int)floor($difference / 60);
            return $compact
                ? $minutes . 'm ago'
                : $minutes . ' minute' . ($minutes !== 1 ? 's' : '') . ' ago';
        }

        if ($difference < 86400) {
            $hours = (int)floor($difference / 3600);
            return $compact
                ? $hours . 'h ago'
                : $hours . ' hour' . ($hours !== 1 ? 's' : '') . ' ago';
        }

        if ($difference < 604800) {
            $days = (int)floor($difference / 86400);
            return $compact
                ? $days . 'd ago'
                : $days . ' day' . ($days !== 1 ? 's' : '') . ' ago';
        }

        return date($compact ? 'M j' : 'M j, Y', $timestamp);
    }
}
