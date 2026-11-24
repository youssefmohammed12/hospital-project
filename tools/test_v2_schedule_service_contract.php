<?php
declare(strict_types=1);

/**
 * End-to-end ScheduleService read contract on two disposable schema fixtures.
 *
 * This is read-only. It does not load .env or use the application's DB bootstrap.
 * Exact loopback host and exact fixture allowlist are enforced before connection.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const HB_SERVICE_V1_FIXTURE = 'healthbridge_hb_auth_email_verify_20261114';
const HB_SERVICE_V2_FIXTURE = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;

if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback database host.\n");
    exit(11);
}
if (!preg_match('/^[0-9]{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
    fwrite(STDERR, "Invalid local database port.\n");
    exit(12);
}

require_once __DIR__ . '/../services/ScheduleService.php';

function connectScheduleServiceFixture(
    string $database,
    string $host,
    string $port,
    string $user,
    string $password
): PDO {
    if (!in_array($database, [HB_SERVICE_V1_FIXTURE, HB_SERVICE_V2_FIXTURE], true)) {
        throw new RuntimeException('Database is not on the exact disposable fixture allowlist.');
    }

    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Connected schema does not match the exact disposable database.');
    }
    return $db;
}

function canonicalWeekly(array $weekly): array
{
    $result = [];
    foreach ($weekly as $row) {
        $day = (int)$row['day_of_week'];
        $result[$day] = [
            'day_of_week' => $day,
            'start_time' => (string)$row['start_time'],
            'end_time' => (string)$row['end_time'],
            'is_working' => (int)$row['is_working'],
        ];
    }
    ksort($result);
    return $result;
}

function canonicalSchedule(?array $schedule): ?array
{
    if ($schedule === null) {
        return null;
    }

    $settings = $schedule['settings'];
    return [
        'settings' => [
            'doctor_id' => (int)$settings['doctor_id'],
            'appointment_duration' => (int)$settings['appointment_duration'],
            'max_appointments_per_day' => (int)$settings['max_appointments_per_day'],
            'break_start' => $settings['break_start'] === null ? null : substr((string)$settings['break_start'], 0, 5),
            'break_end' => $settings['break_end'] === null ? null : substr((string)$settings['break_end'], 0, 5),
            'is_available' => (int)$settings['is_available'],
            'doctor_name' => (string)$settings['doctor_name'],
            'doctor_email' => (string)$settings['doctor_email'],
        ],
        'weekly' => canonicalWeekly($schedule['weekly']),
    ];
}

function canonicalRangesForService(array $ranges): array
{
    $result = [];
    foreach ($ranges as $range) {
        $result[] = [
            (int)$range['appointment_id'],
            (int)$range['start'],
            (int)$range['end'],
        ];
    }
    usort($result, static fn(array $a, array $b): int => $a <=> $b);
    return $result;
}

function sameJson(mixed $left, mixed $right): bool
{
    return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function buildReservationDatePairs(PDO $db, string $schema): array
{
    if ($schema === 'v1') {
        $sql = "SELECT doctor_id AS doctor_user_id, date AS slot_date
                FROM appointments
                WHERE status IN ('Pending','Confirmed','Reschedule Requested')
                UNION
                SELECT doctor_id, pending_reschedule_date
                FROM appointments
                WHERE status='Reschedule Requested'
                  AND reschedule_status IN ('pending','suggested')
                  AND pending_reschedule_date IS NOT NULL
                UNION
                SELECT doctor_id, requested_reschedule_date
                FROM appointments
                WHERE status='Reschedule Requested'
                  AND reschedule_status='suggested'
                  AND requested_reschedule_date IS NOT NULL";
    } else {
        $sql = "SELECT doctor_user_id, DATE(starts_at) AS slot_date
                FROM appointments
                WHERE booking_status IN ('pending','confirmed')
                UNION
                SELECT a.doctor_user_id, DATE(r.requested_starts_at)
                FROM appointments a
                JOIN appointment_reschedule_requests r ON r.appointment_id=a.id
                WHERE r.request_status IN ('requested','suggested')
                UNION
                SELECT a.doctor_user_id, DATE(r.proposed_starts_at)
                FROM appointments a
                JOIN appointment_reschedule_requests r ON r.appointment_id=a.id
                WHERE r.request_status='suggested' AND r.proposed_starts_at IS NOT NULL";
    }

    $pairs = [];
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!$row['doctor_user_id'] || !$row['slot_date']) {
            continue;
        }
        $key = (int)$row['doctor_user_id'] . '|' . (string)$row['slot_date'];
        $pairs[$key] = [(int)$row['doctor_user_id'], (string)$row['slot_date']];
    }
    ksort($pairs);
    return array_values($pairs);
}

$checks = 0;
$failures = [];
$assert = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

try {
    echo "=== HealthBridge ScheduleService v1/v2 contract ===\n";
    $v1db = connectScheduleServiceFixture(HB_SERVICE_V1_FIXTURE, $host, $port, $user, $password);
    $v2db = connectScheduleServiceFixture(HB_SERVICE_V2_FIXTURE, $host, $port, $user, $password);

    // Existing one-argument callers must continue defaulting to v1.
    $defaultService = new ScheduleService($v1db);
    $serviceV1 = new ScheduleService($v1db, 'v1');
    $serviceV2 = new ScheduleService($v2db, 'v2');
    $assert(sameJson($defaultService->getHospitalHours(), $serviceV1->getHospitalHours()), 'One-argument constructor remains v1');

    $hoursV1 = $serviceV1->getHospitalHours();
    $hoursV2 = $serviceV2->getHospitalHours();
    $assert(sameJson($hoursV1, $hoursV2), 'Hospital opening/closing times normalize to identical HH:MM DTO');

    $allV1 = $serviceV1->getAllSchedules();
    $allV2 = $serviceV2->getAllSchedules();
    $allV1ByDoctor = [];
    $allV2ByDoctor = [];
    foreach ($allV1 as $schedule) {
        $allV1ByDoctor[(int)$schedule['doctor_id']] = $schedule;
    }
    foreach ($allV2 as $schedule) {
        $allV2ByDoctor[(int)$schedule['doctor_id']] = $schedule;
    }
    $doctorIdsV1 = array_keys($allV1ByDoctor);
    $doctorIdsV2 = array_keys($allV2ByDoctor);
    sort($doctorIdsV1);
    sort($doctorIdsV2);
    $assert($doctorIdsV1 === $doctorIdsV2, 'Admin all-schedules doctor identities match');

    $scheduleMismatches = [];
    $publicMismatches = [];
    foreach ($doctorIdsV1 as $doctorId) {
        $left = canonicalSchedule($serviceV1->getSchedule($doctorId));
        $right = canonicalSchedule($serviceV2->getSchedule($doctorId));
        if (!sameJson($left, $right)) {
            $scheduleMismatches[] = $doctorId;
        }

        $workingLeft = $serviceV1->getWorkingSchedule($doctorId);
        $workingRight = $serviceV2->getWorkingSchedule($doctorId);
        if (!sameJson(canonicalWeekly($workingLeft['weekly'] ?? []), canonicalWeekly($workingRight['weekly'] ?? []))) {
            $scheduleMismatches[] = "working:{$doctorId}";
        }

        $publicLeft = $serviceV1->getPublicAvailability($doctorId);
        $publicRight = $serviceV2->getPublicAvailability($doctorId);
        if (!sameJson($publicLeft, $publicRight)) {
            $publicMismatches[] = $doctorId;
        }

        $adminLeft = $allV1ByDoctor[$doctorId];
        $adminRight = $allV2ByDoctor[$doctorId];
        foreach ([
            'appointment_duration', 'max_appointments_per_day',
            'break_start', 'break_end', 'is_available',
            'doctor_name', 'doctor_email', 'working_days_count',
        ] as $field) {
            $a = $adminLeft[$field] ?? null;
            $b = $adminRight[$field] ?? null;
            if ($field === 'break_start' || $field === 'break_end') {
                $a = $a === null ? null : substr((string)$a, 0, 5);
                $b = $b === null ? null : substr((string)$b, 0, 5);
            } elseif ($a !== null && $b !== null && is_numeric($a) && is_numeric($b)) {
                $a = (int)$a;
                $b = (int)$b;
            }
            if (!sameJson($a, $b)) {
                $scheduleMismatches[] = "admin:{$doctorId}:{$field}";
            }
        }
    }
    $assert($scheduleMismatches === [], 'getSchedule/getWorkingSchedule/getAllSchedules match for all doctors');
    $assert($publicMismatches === [], 'Public availability and next-slot output match for all doctors');
    if ($scheduleMismatches) {
        echo "SCHEDULE_MISMATCHES=" . implode(',', $scheduleMismatches) . "\n";
    }
    if ($publicMismatches) {
        echo "PUBLIC_MISMATCHES=" . implode(',', $publicMismatches) . "\n";
    }

    $pairsV1 = buildReservationDatePairs($v1db, 'v1');
    $pairsV2 = buildReservationDatePairs($v2db, 'v2');
    $keysV1 = array_map(static fn(array $row): string => $row[0] . '|' . $row[1], $pairsV1);
    $keysV2 = array_map(static fn(array $row): string => $row[0] . '|' . $row[1], $pairsV2);
    $assert($keysV1 === $keysV2, 'Slot-service reservation date coverage matches');

    $rangeMismatches = [];
    $slotMismatches = [];
    $validationMismatches = [];
    foreach ($pairsV1 as [$doctorId, $date]) {
        $rangesV1 = canonicalRangesForService($serviceV1->getBookedRanges($doctorId, $date));
        $rangesV2 = canonicalRangesForService($serviceV2->getBookedRanges($doctorId, $date));
        if ($rangesV1 !== $rangesV2) {
            $rangeMismatches[] = $doctorId . '|' . $date;
        }

        $slotsV1 = $serviceV1->getAvailableSlots($doctorId, $date);
        $slotsV2 = $serviceV2->getAvailableSlots($doctorId, $date);
        if (!sameJson($slotsV1, $slotsV2)) {
            $slotMismatches[] = $doctorId . '|' . $date;
        }

        $schedule = $serviceV1->getSchedule($doctorId);
        $candidateTime = '09:00';
        if ($schedule) {
            $dayOfWeek = (int)(new DateTimeImmutable($date))->format('N');
            foreach ($schedule['weekly'] as $day) {
                if ((int)$day['day_of_week'] === $dayOfWeek && (int)$day['is_working'] === 1) {
                    $candidateTime = (string)$day['start_time'];
                    break;
                }
            }
        }
        $validationV1 = $serviceV1->validateBookingSlot($doctorId, $date, $candidateTime);
        $validationV2 = $serviceV2->validateBookingSlot($doctorId, $date, $candidateTime);
        if ($validationV1 !== $validationV2) {
            $validationMismatches[] = $doctorId . '|' . $date;
        }
    }
    $assert($rangeMismatches === [], 'ScheduleService booked-range output matches for all reserved doctor/date pairs');
    $assert($slotMismatches === [], 'Available-slot generation output matches for all reserved doctor/date pairs');
    $assert($validationMismatches === [], 'Booking-slot validation decisions match for all reserved doctor/date pairs');

    echo "DOCTORS=" . count($doctorIdsV1) . "\n";
    echo "RESERVATION_DATE_PAIRS=" . count($pairsV1) . "\n";
    echo "CHECKS={$checks}\n";
    echo "FAILURES=" . count($failures) . "\n";
    if ($failures) {
        foreach ($failures as $failure) {
            echo "FAILED|{$failure}\n";
        }
        exit(1);
    }
    echo "RESULT=PASS (read-only; current app constructor defaults to v1)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "SCHEDULE SERVICE CONTRACT FAILED/BLOCKED: " . get_class($e) . "\n");
    fwrite(STDERR, "This script performs no database writes.\n");
    exit(2);
}
