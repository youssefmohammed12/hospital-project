<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/AppointmentTime.php';

/**
 * Read-only adapter for booked-slot reservations across v1 and v2.
 *
 * Active v2 reschedule requests reserve both the current appointment slot
 * and the patient's requested slot. Suggested requests reserve the doctor's
 * alternative as well. Terminal requests do not create additional holds.
 */
final class AppointmentReservationRepository
{
    public const SCHEMA_V1 = 'v1';
    public const SCHEMA_V2 = 'v2';

    private PDO $db;
    private string $schema;

    public function __construct(PDO $db, string $schema)
    {
        if (!in_array($schema, [self::SCHEMA_V1, self::SCHEMA_V2], true)) {
            throw new InvalidArgumentException('Schema mode must be explicitly v1 or v2.');
        }

        $this->db = $db;
        $this->schema = $schema;
    }

    /**
     * Return reservations as minutes since midnight, retaining the current
     * ScheduleService DTO: [start, end, appointment_id].
     */
    public function getBookedRanges(int $doctorUserId, string $date, ?int $excludeAppointmentId = null): array
    {
        if ($doctorUserId <= 0) {
            throw new InvalidArgumentException('Doctor user ID must be positive.');
        }
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            !$dateValue
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $dateValue->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException('Date must be a valid YYYY-MM-DD value.');
        }

        return $this->schema === self::SCHEMA_V1
            ? $this->getV1BookedRanges($doctorUserId, $date, $excludeAppointmentId)
            : $this->getV2BookedRanges($doctorUserId, $date, $excludeAppointmentId);
    }

    private function getV1BookedRanges(int $doctorUserId, string $date, ?int $excludeAppointmentId): array
    {
        $sql = "SELECT id, date, time, appointment_time_range,
                       status, reschedule_status,
                       pending_reschedule_date, pending_reschedule_time,
                       requested_reschedule_date, requested_reschedule_time
                FROM appointments
                WHERE doctor_id = ?
                  AND (
                      (date = ? AND status IN ('Pending', 'Confirmed', 'Reschedule Requested'))
                      OR (
                          pending_reschedule_date = ?
                          AND status = 'Reschedule Requested'
                          AND reschedule_status IN ('pending', 'suggested')
                      )
                      OR (
                          requested_reschedule_date = ?
                          AND status = 'Reschedule Requested'
                          AND reschedule_status = 'suggested'
                      )
                  )";

        $params = [$doctorUserId, $date, $date, $date];
        if ($excludeAppointmentId !== null && $excludeAppointmentId > 0) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeAppointmentId;
        }
        $sql .= ' ORDER BY time ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $duration = $this->fetchV1DefaultDuration($doctorUserId);
        $ranges = [];

        foreach ($rows as $row) {
            $appointmentId = (int)$row['id'];
            $appointmentDuration = $this->durationFromLegacyRange(
                (string)$row['time'],
                $row['appointment_time_range'] !== null ? (string)$row['appointment_time_range'] : null,
                $duration
            );

            if ((string)$row['date'] === $date) {
                $this->appendRange($ranges, $appointmentId, (string)$row['time'], $appointmentDuration);
            }

            if (
                $row['status'] === 'Reschedule Requested'
                && in_array($row['reschedule_status'], ['pending', 'suggested'], true)
                && (string)($row['pending_reschedule_date'] ?? '') === $date
                && !empty($row['pending_reschedule_time'])
            ) {
                $this->appendRange($ranges, $appointmentId, (string)$row['pending_reschedule_time'], $appointmentDuration);
            }

            if (
                $row['status'] === 'Reschedule Requested'
                && $row['reschedule_status'] === 'suggested'
                && (string)($row['requested_reschedule_date'] ?? '') === $date
                && !empty($row['requested_reschedule_time'])
            ) {
                $requestedTime = AppointmentTime::normalize((string)$row['requested_reschedule_time']);
                $pendingTime = AppointmentTime::normalize((string)($row['pending_reschedule_time'] ?? ''));
                if (
                    $requestedTime !== $pendingTime
                    && preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $requestedTime)
                ) {
                    $this->appendRange($ranges, $appointmentId, $requestedTime, $appointmentDuration);
                }
            }
        }

        return $ranges;
    }

    private function getV2BookedRanges(int $doctorUserId, string $date, ?int $excludeAppointmentId): array
    {
        $sql = "SELECT a.id, a.starts_at, a.duration_minutes,
                       r.request_status, r.requested_starts_at, r.proposed_starts_at
                FROM appointments a
                LEFT JOIN appointment_reschedule_requests r
                  ON r.appointment_id = a.id
                 AND r.request_status IN ('requested', 'suggested')
                WHERE a.doctor_user_id = ?
                  AND a.booking_status IN ('pending', 'confirmed')
                  AND (
                      DATE(a.starts_at) = ?
                      OR DATE(r.requested_starts_at) = ?
                      OR (r.request_status = 'suggested' AND DATE(r.proposed_starts_at) = ?)
                  )";

        $params = [$doctorUserId, $date, $date, $date];
        if ($excludeAppointmentId !== null && $excludeAppointmentId > 0) {
            $sql .= ' AND a.id <> ?';
            $params[] = $excludeAppointmentId;
        }
        $sql .= ' ORDER BY a.id ASC, r.created_at ASC, r.id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $ranges = [];
        foreach ($rows as $row) {
            $appointmentId = (int)$row['id'];
            $duration = (int)$row['duration_minutes'];
            if ($duration <= 0) {
                $duration = 30;
            }

            $startsAt = (string)$row['starts_at'];
            if (substr($startsAt, 0, 10) === $date) {
                $this->appendRange($ranges, $appointmentId, substr($startsAt, 11, 5), $duration);
            }

            if (
                in_array($row['request_status'], ['requested', 'suggested'], true)
                && !empty($row['requested_starts_at'])
                && substr((string)$row['requested_starts_at'], 0, 10) === $date
            ) {
                $this->appendRange($ranges, $appointmentId, substr((string)$row['requested_starts_at'], 11, 5), $duration);
            }

            if (
                $row['request_status'] === 'suggested'
                && !empty($row['proposed_starts_at'])
                && substr((string)$row['proposed_starts_at'], 0, 10) === $date
            ) {
                $this->appendRange($ranges, $appointmentId, substr((string)$row['proposed_starts_at'], 11, 5), $duration);
            }
        }

        return $ranges;
    }

    private function fetchV1DefaultDuration(int $doctorUserId): int
    {
        $stmt = $this->db->prepare(
            'SELECT appointment_duration FROM doctor_schedule_settings WHERE doctor_id = ? LIMIT 1'
        );
        $stmt->execute([$doctorUserId]);
        $value = $stmt->fetchColumn();

        return $value !== false && $value !== null && (int)$value > 0
            ? (int)$value
            : 30;
    }

    private function durationFromLegacyRange(string $startTime, ?string $range, int $fallback): int
    {
        if ($range !== null && preg_match('/[-–—]\s*(\d{1,2}):(\d{2})\s*(AM|PM)\s*$/i', $range, $matches)) {
            $endHour = (int)$matches[1];
            $endMinute = (int)$matches[2];
            $period = strtoupper($matches[3]);

            if ($period === 'PM' && $endHour !== 12) {
                $endHour += 12;
            } elseif ($period === 'AM' && $endHour === 12) {
                $endHour = 0;
            }

            $start = AppointmentTime::normalize($startTime);
            if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $start)) {
                $calculated = ($endHour * 60 + $endMinute) - AppointmentTime::toMinutes($start);
                if ($calculated > 0 && $calculated <= 1440) {
                    return $calculated;
                }
            }
        }

        return $fallback;
    }

    private function appendRange(array &$ranges, int $appointmentId, string $time, int $duration): void
    {
        $normalized = AppointmentTime::normalize($time);
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $normalized)) {
            return;
        }

        $start = AppointmentTime::toMinutes($normalized);
        $end = $start + max(1, $duration);
        foreach ($ranges as $existing) {
            if (
                (int)$existing['appointment_id'] === $appointmentId
                && (int)$existing['start'] === $start
                && (int)$existing['end'] === $end
            ) {
                return;
            }
        }

        $ranges[] = [
            'start' => $start,
            'end' => $end,
            'appointment_id' => $appointmentId,
        ];
    }
}
