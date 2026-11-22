<?php
declare(strict_types=1);

/**
 * Transaction rollback tests through the opt-in ScheduleService façade.
 *
 * Exact loopback disposable fixtures only. All write statements execute inside
 * explicit caller-owned transactions which are rolled back before reconnecting.
 * This script never commits fixture changes and never loads the application's .env.
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

require_once __DIR__ . '/../services/ScheduleService.php';

function connectScheduleFixture(string $database, string $host, string $port, string $user, string $password): PDO
{
    if (!in_array($database, [HB_V1_FIXTURE, HB_V2_FIXTURE], true)) {
        throw new RuntimeException('Database is not on the exact disposable-fixture allowlist.');
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
        throw new RuntimeException('Connected schema does not match exact fixture name.');
    }
    return $db;
}

function connectAndRollbackTest(
    string $database,
    string $mode,
    string $host,
    string $port,
    string $user,
    string $password
): void {
    $db = connectScheduleFixture($database, $host, $port, $user, $password);
    $idQuery = $mode === DoctorScheduleRepository::SCHEMA_V1
        ? "SELECT doctor_id FROM doctor_schedule_settings ORDER BY doctor_id LIMIT 1"
        : "SELECT doctor_user_id FROM doctor_schedule_settings ORDER BY doctor_user_id LIMIT 1";
    $doctorId = (int)$db->query($idQuery)->fetchColumn();
    if ($doctorId <= 0) {
        throw new RuntimeException("No schedule settings fixture row in {$database}.");
    }

    $service = new ScheduleService($db, $mode);
    $before = $service->getSchedule($doctorId);
    if (!$before) {
        throw new RuntimeException("Could not read pre-write schedule in {$database}.");
    }

    $originalAvailability = (int)$before['settings']['is_available'];
    $settings = [
        'appointment_duration' => (int)$before['settings']['appointment_duration'],
        'max_appointments_per_day' => (int)$before['settings']['max_appointments_per_day'],
        'is_available' => $originalAvailability === 1 ? 0 : 1,
    ];

    $weekly = [];
    // v2 weekly rows have a composite key and no AUTO_INCREMENT column. Testing
    // their upsert does not consume a surrogate sequence even on a duplicate key.
    // For v1, only test the settings update here to leave auto-increment counters
    // byte-for-byte untouched on the source fixture.
    if ($mode === DoctorScheduleRepository::SCHEMA_V2) {
        $firstDay = $before['weekly'][0];
        $weekly[] = [
            'day_of_week' => (int)$firstDay['day_of_week'],
            'start_time' => (string)$firstDay['start_time'],
            'end_time' => (string)$firstDay['end_time'],
            'is_working' => (int)$firstDay['is_working'],
        ];
    }

    $db->beginTransaction();
    try {
        $service->updateSchedule($doctorId, $weekly, $settings);
        $service->setAvailability($doctorId, (bool)$settings['is_available']);

        if ($mode === DoctorScheduleRepository::SCHEMA_V1) {
            $syncStmt = $db->prepare(
                'SELECT s.is_available, d.available AS profile_schedule_value
                 FROM doctor_schedule_settings s
                 JOIN doctors d ON d.user_id = s.doctor_id
                 WHERE s.doctor_id = ?'
            );
        } else {
            $syncStmt = $db->prepare(
                'SELECT is_available, is_available AS profile_schedule_value
                 FROM doctor_schedule_settings WHERE doctor_user_id = ?'
            );
        }
        $syncStmt->execute([$doctorId]);
        $inside = $syncStmt->fetch(PDO::FETCH_ASSOC);
        if (
            !$inside
            || (int)$inside['is_available'] !== (int)$settings['is_available']
            || (int)$inside['profile_schedule_value'] !== (int)$settings['is_available']
        ) {
            throw new RuntimeException("Operational availability was not synchronized inside {$database}.");
        }
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    $after = $service->getSchedule($doctorId);
    if ($before !== $after) {
        throw new RuntimeException("Rollback did not restore schedule DTO exactly in {$database}.");
    }
    if ($mode === DoctorScheduleRepository::SCHEMA_V1) {
        $legacyAvailability = $db->prepare('SELECT available FROM doctors WHERE user_id = ?');
        $legacyAvailability->execute([$doctorId]);
        if ((int)$legacyAvailability->fetchColumn() !== $originalAvailability) {
            throw new RuntimeException("Rollback did not restore the legacy availability flag in {$database}.");
        }
    } else {
        // The v2 weekly table has a composite key and no auto-increment sequence,
        // so reset can safely exercise all seven upserts inside a rollback test.
        $db->beginTransaction();
        try {
            $service->resetToDefault($doctorId);
            $reset = $service->getSchedule($doctorId);
            if (
                !$reset
                || (int)$reset['settings']['appointment_duration'] !== 30
                || (int)$reset['settings']['max_appointments_per_day'] !== 25
                || (int)$reset['settings']['is_available'] !== 1
                || $reset['settings']['break_start'] !== null
                || $reset['settings']['break_end'] !== null
                || count($reset['weekly']) !== 7
            ) {
                throw new RuntimeException("v2 reset-to-default did not return the canonical default schedule.");
            }
            foreach ($reset['weekly'] as $day) {
                $expectedWorking = (int)$day['day_of_week'] <= 5 ? 1 : 0;
                if (
                    (int)$day['is_working'] !== $expectedWorking
                    || (string)$day['start_time'] !== '09:00'
                    || (string)$day['end_time'] !== '17:00'
                ) {
                    throw new RuntimeException("v2 reset-to-default produced an unexpected weekly row.");
                }
            }
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
        }
        if ($before !== $service->getSchedule($doctorId)) {
            throw new RuntimeException("v2 reset rollback did not restore the exact original schedule.");
        }
        echo "RESET_ROLLBACK_OK|{$database}|v2|seven-days\n";
    }

    echo "ROLLBACK_OK|{$database}|{$mode}|settings"
        . ($mode === DoctorScheduleRepository::SCHEMA_V2 ? '+weekly' : '+availability-sync')
        . "\n";
}

try {
    connectAndRollbackTest(HB_V1_FIXTURE, DoctorScheduleRepository::SCHEMA_V1, $host, $port, $user, $password);
    connectAndRollbackTest(HB_V2_FIXTURE, DoctorScheduleRepository::SCHEMA_V2, $host, $port, $user, $password);
    echo "RESULT=PASS (all write transactions rolled back; no fixture changes committed)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "ROLLBACK TEST FAILED/BLOCKED: " . get_class($e) . "\n");
    fwrite(STDERR, "Any open transaction was rolled back before exit.\n");
    exit(2);
}
