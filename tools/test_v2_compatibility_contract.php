<?php
declare(strict_types=1);

/**
 * Read-only v1/v2 compatibility contract test.
 *
 * Connects only to the exact disposable synthetic source and v2 prototype.
 * Does not load the application's .env file and performs no database writes.
 *
 * Optional connection environment variables: DB_HOST, DB_PORT, DB_USER, DB_PASS.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const HB_V1_FIXTURE = 'healthbridge_hb_auth_email_verify_20261114';
const HB_V2_FIXTURE = 'healthbridge_v2_prototype_20261118';

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

require_once __DIR__ . '/../services/Repositories/DoctorProfileRepository.php';
require_once __DIR__ . '/../services/Repositories/DoctorScheduleRepository.php';
require_once __DIR__ . '/../services/Repositories/AppointmentReservationRepository.php';

function connectFixture(string $database, string $host, string $port, string $user, string $password): PDO
{
    if (!in_array($database, [HB_V1_FIXTURE, HB_V2_FIXTURE], true)) {
        throw new RuntimeException('Database is not on the exact disposable-fixture allowlist.');
    }

    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $db = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 3,
    ]);

    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Connected schema does not match the exact fixture name.');
    }

    return $db;
}

function sameValue(mixed $left, mixed $right): bool
{
    if ($left === null || $right === null) {
        return $left === $right;
    }
    return (string)$left === (string)$right;
}

function canonicalRanges(array $ranges): array
{
    $normalized = [];
    foreach ($ranges as $range) {
        $normalized[] = [
            (int)$range['appointment_id'],
            (int)$range['start'],
            (int)$range['end'],
        ];
    }
    usort($normalized, static fn(array $a, array $b): int => $a <=> $b);
    return $normalized;
}

function datePairs(PDO $db, string $schema): array
{
    if ($schema === DoctorScheduleRepository::SCHEMA_V1) {
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
$warnings = [];

$assert = static function (bool $condition, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$condition) {
        $failures[] = $label;
    }
};

try {
    echo "=== HealthBridge v1/v2 read-only compatibility contract ===\n";
    $v1 = connectFixture(HB_V1_FIXTURE, $host, $port, $user, $password);
    $v2 = connectFixture(HB_V2_FIXTURE, $host, $port, $user, $password);
    echo "Connected only to the two exact loopback disposable fixtures.\n";

    $profilesV1 = new DoctorProfileRepository($v1, DoctorProfileRepository::SCHEMA_V1);
    $profilesV2 = new DoctorProfileRepository($v2, DoctorProfileRepository::SCHEMA_V2);
    $schedulesV1 = new DoctorScheduleRepository($v1, DoctorScheduleRepository::SCHEMA_V1);
    $schedulesV2 = new DoctorScheduleRepository($v2, DoctorScheduleRepository::SCHEMA_V2);
    $reservationsV1 = new AppointmentReservationRepository($v1, AppointmentReservationRepository::SCHEMA_V1);
    $reservationsV2 = new AppointmentReservationRepository($v2, AppointmentReservationRepository::SCHEMA_V2);

    $adminV1 = $profilesV1->listForAdmin();
    $adminV2 = $profilesV2->listForAdmin();
    $assert(count($adminV1) === count($adminV2), 'Admin doctor directory row counts match');

    $idsV1 = array_map(static fn(array $row): int => (int)$row['doctor_id'], $adminV1);
    $idsV2 = array_map(static fn(array $row): int => (int)$row['doctor_id'], $adminV2);
    sort($idsV1);
    sort($idsV2);
    $assert($idsV1 === $idsV2, 'Doctor directory identity is users.id in both schemas');

    $publicV1 = $profilesV1->listPublic();
    $publicV2 = $profilesV2->listPublic();
    $publicIdsV1 = array_map(static fn(array $row): int => (int)$row['doctor_id'], $publicV1);
    $publicIdsV2 = array_map(static fn(array $row): int => (int)$row['doctor_id'], $publicV2);
    sort($publicIdsV1);
    sort($publicIdsV2);
    $assert($publicIdsV1 === $publicIdsV2, 'Public doctor visibility/department filters match');

    $publicV1ByUser = [];
    foreach ($publicV1 as $doctor) {
        $publicV1ByUser[(int)$doctor['user_id']] = $doctor;
    }
    $publicV2ByUser = [];
    foreach ($publicV2 as $doctor) {
        $publicV2ByUser[(int)$doctor['user_id']] = $doctor;
    }
    $publicIdMismatches = [];
    foreach ($publicV1ByUser as $doctorId => $left) {
        $right = $publicV2ByUser[$doctorId] ?? null;
        if (
            !$right
            || !sameValue($left['id'] ?? null, $right['id'] ?? null)
            || !sameValue($left['user_id'] ?? null, $right['user_id'] ?? null)
            || !sameValue($left['id'] ?? null, $profilesV1->findByUserId($doctorId)['id'] ?? null)
        ) {
            $publicIdMismatches[] = (string)$doctorId;
        }
    }
    $assert($publicIdMismatches === [], 'Public legacy profile ID and canonical user_id API fields are preserved');

    $profileMismatches = [];
    foreach ($idsV1 as $doctorId) {
        $a = $profilesV1->findByUserId($doctorId);
        $b = $profilesV2->findByUserId($doctorId);
        if (!$a || !$b) {
            $profileMismatches[] = "profile_missing:{$doctorId}";
            continue;
        }

        foreach ([
            'doctor_id', 'name', 'email', 'phone', 'is_active', 'specialty',
            'experience_years', 'profile_icon', 'department_id',
            'accepts_new_patients', 'profile_visible', 'available', 'is_available',
            'rating_count', 'department_name',
        ] as $field) {
            if (!sameValue($a[$field] ?? null, $b[$field] ?? null)) {
                $profileMismatches[] = "{$doctorId}:{$field}";
            }
        }

        if ((int)($a['rating_count'] ?? 0) > 0
            && !sameValue($a['average_rating'] ?? null, $b['average_rating'] ?? null)) {
            $profileMismatches[] = "{$doctorId}:average_rating";
        } elseif ((int)($a['rating_count'] ?? 0) === 0
            && !sameValue($a['average_rating'] ?? null, $b['average_rating'] ?? null)) {
            $warnings[] = "doctor {$doctorId}: legacy cached rating is intentionally not reused when there are no rating facts";
        }
    }
    $assert($profileMismatches === [], 'Doctor profile fields match (excluding intentionally retired zero-review cache)');
    if ($profileMismatches) {
        echo "PROFILE_MISMATCH|" . implode(',', $profileMismatches) . "\n";
    }

    $allSchedulesV1 = $schedulesV1->getAllSchedules();
    $allSchedulesV2 = $schedulesV2->getAllSchedules();
    $assert(count($allSchedulesV1) === count($allSchedulesV2), 'Schedule overview row counts match');

    $scheduleMismatches = [];
    $scheduleV2ByDoctor = [];
    foreach ($allSchedulesV2 as $schedule) {
        $scheduleV2ByDoctor[(int)$schedule['doctor_id']] = $schedule;
    }
    foreach ($allSchedulesV1 as $left) {
        $doctorId = (int)$left['doctor_id'];
        $right = $scheduleV2ByDoctor[$doctorId] ?? null;
        if (!$right) {
            $scheduleMismatches[] = "schedule_missing:{$doctorId}";
            continue;
        }

        foreach ([
            'appointment_duration', 'max_appointments_per_day', 'break_start',
            'break_end', 'is_available', 'doctor_name', 'doctor_email',
            'working_days_count',
        ] as $field) {
            if (!sameValue($left[$field] ?? null, $right[$field] ?? null)) {
                $scheduleMismatches[] = "{$doctorId}:settings.{$field}";
            }
        }

        $leftDays = [];
        foreach ($left['weekly'] as $day) {
            $leftDays[(int)$day['day_of_week']] = $day;
        }
        $rightDays = [];
        foreach ($right['weekly'] as $day) {
            $rightDays[(int)$day['day_of_week']] = $day;
        }
        for ($day = 1; $day <= 7; $day++) {
            $a = $leftDays[$day] ?? null;
            $b = $rightDays[$day] ?? null;
            if (!$a || !$b) {
                $scheduleMismatches[] = "{$doctorId}:day_missing:{$day}";
                continue;
            }
            foreach (['start_time', 'end_time', 'is_working'] as $field) {
                if (!sameValue($a[$field] ?? null, $b[$field] ?? null)) {
                    $scheduleMismatches[] = "{$doctorId}:day{$day}.{$field}";
                }
            }
            if (($b['id'] ?? null) !== null) {
                $scheduleMismatches[] = "{$doctorId}:v2 weekly id should be nullable compatibility field";
            }
        }
    }
    $assert($scheduleMismatches === [], 'Seven-day schedule DTOs and settings match');
    if ($scheduleMismatches) {
        echo "SCHEDULE_MISMATCH|" . implode(',', $scheduleMismatches) . "\n";
    }

    $pairsV1 = datePairs($v1, DoctorScheduleRepository::SCHEMA_V1);
    $pairsV2 = datePairs($v2, DoctorScheduleRepository::SCHEMA_V2);
    $pairKeysV1 = array_map(static fn(array $row): string => $row[0] . '|' . $row[1], $pairsV1);
    $pairKeysV2 = array_map(static fn(array $row): string => $row[0] . '|' . $row[1], $pairsV2);
    $allPairKeys = array_values(array_unique(array_merge($pairKeysV1, $pairKeysV2)));
    sort($allPairKeys);
    $assert($pairKeysV1 === $pairKeysV2, 'Doctor/date reservation coverage matches between schemas');

    $reservationMismatches = [];
    foreach ($allPairKeys as $key) {
        [$doctorId, $date] = explode('|', $key, 2);
        $left = canonicalRanges($reservationsV1->getBookedRanges((int)$doctorId, $date));
        $right = canonicalRanges($reservationsV2->getBookedRanges((int)$doctorId, $date));
        if ($left !== $right) {
            $reservationMismatches[] = $key
                . ':v1=' . json_encode($left, JSON_UNESCAPED_SLASHES)
                . ':v2=' . json_encode($right, JSON_UNESCAPED_SLASHES);
        }
    }
    $assert($reservationMismatches === [], 'Booked/original/requested/suggested slot ranges match');
    if ($reservationMismatches) {
        foreach (array_slice($reservationMismatches, 0, 15) as $mismatch) {
            echo "RESERVATION_MISMATCH|{$mismatch}\n";
        }
    }

    $stateV1 = [];
    foreach ($v1->query(
        "SELECT reschedule_status, COUNT(*) AS row_count
         FROM appointments WHERE reschedule_status <> 'none'
         GROUP BY reschedule_status"
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mapped = $row['reschedule_status'] === 'pending' ? 'requested' : $row['reschedule_status'];
        $stateV1[$mapped] = (int)$row['row_count'];
    }
    $stateV2 = [];
    foreach ($v2->query(
        "SELECT request_status, COUNT(*) AS row_count
         FROM appointment_reschedule_requests GROUP BY request_status"
    )->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $stateV2[(string)$row['request_status']] = (int)$row['row_count'];
    }
    ksort($stateV1);
    ksort($stateV2);
    $assert($stateV1 === $stateV2, 'Legacy reschedule states map to v2 request states one-for-one');
    echo "RESCHEDULE_STATES_V1=" . json_encode($stateV1) . "\n";
    echo "RESCHEDULE_STATES_V2=" . json_encode($stateV2) . "\n";
    if (empty($stateV1['suggested'])) {
        $warnings[] = 'Synthetic fixture has no suggested reschedule rows; suggested-slot transition needs a dedicated seeded fixture';
    }

    $excludeMismatches = [];
    $excludeSamples = 0;
    foreach ($allPairKeys as $key) {
        [$doctorId, $date] = explode('|', $key, 2);
        $baseRanges = $reservationsV1->getBookedRanges((int)$doctorId, $date);
        $appointmentIds = array_values(array_unique(array_map(
            static fn(array $range): int => (int)$range['appointment_id'],
            $baseRanges
        )));
        foreach ($appointmentIds as $excludeId) {
            $left = canonicalRanges($reservationsV1->getBookedRanges((int)$doctorId, $date, $excludeId));
            $right = canonicalRanges($reservationsV2->getBookedRanges((int)$doctorId, $date, $excludeId));
            if ($left !== $right) {
                $excludeMismatches[] = "{$key}:exclude={$excludeId}";
            }
            $excludeSamples++;
            if ($excludeSamples >= 50) {
                break 2;
            }
        }
    }
    $assert($excludeMismatches === [], 'Appointment exclusion preserves reservation parity');
    if ($excludeMismatches) {
        echo "EXCLUDE_MISMATCH|" . implode(',', $excludeMismatches) . "\n";
    }

    echo "CHECKS={$checks}\n";
    echo "FAILURES=" . count($failures) . "\n";
    echo "RESERVATION_DATE_PAIRS=" . count($allPairKeys) . "\n";
    echo "WARNINGS=" . count($warnings) . "\n";
    foreach ($warnings as $warning) {
        echo "WARNING|{$warning}\n";
    }

    if ($failures) {
        foreach ($failures as $failure) {
            echo "FAILED|{$failure}\n";
        }
        exit(1);
    }

    echo "RESULT=PASS (read-only; no database writes)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "CONTRACT TEST BLOCKED/FAILED: " . get_class($e) . "\n");
    fwrite(STDERR, "No database writes were issued by this test.\n");
    exit(2);
}
