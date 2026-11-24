<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/AppointmentTime.php';

/**
 * Read-only adapter for booked-slot reservations (V2 schema).
 *
 * Active v2 reschedule requests reserve both the current appointment slot
 * and the patient's requested slot. Suggested requests reserve the doctor's
 * alternative as well. Terminal requests do not create additional holds.
 */
final class AppointmentReservationRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
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

        return $this->getV2BookedRanges($doctorUserId, $date, $excludeAppointmentId);
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
