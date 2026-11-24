<?php
declare(strict_types=1);

/**
 * Two-connection booking serialization test on the v2 disposable clone.
 *
 * Connection A holds the doctor's row lock and inserts a booking without
 * committing. Connection B must time out while attempting the same doctor lock.
 * After A commits, B retries the same slot and must be rejected by validation.
 * The test then deletes its committed synthetic rows and restores AUTO_INCREMENT
 * high-water values. It never accesses the current application database.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }

const CONCURRENCY_DB = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;

if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB host.\n");
    exit(11);
}
if (!preg_match('/^[0-9]{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
    fwrite(STDERR, "Invalid DB port.\n");
    exit(12);
}

require_once __DIR__ . '/../services/Repositories/AppointmentBookingRepository.php';
require_once __DIR__ . '/../services/ScheduleService.php';

function connectConcurrencyDb(string $host, string $port, string $user, string $password): PDO
{
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname=" . CONCURRENCY_DB . ";charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== CONCURRENCY_DB) {
        throw new RuntimeException('Connected schema does not match the exact concurrency fixture.');
    }
    return $db;
}

function concurrencyCounterSnapshot(PDO $db): array
{
    $tables = ['appointments','appointment_events','audit_events','notification_outbox'];
    $stmt = $db->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $values = [];
    foreach ($tables as $table) {
        $stmt->execute([$table]);
        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null) {
            $values[$table] = (int)$value;
        }
    }
    return $values;
}

function restoreConcurrencyCounters(PDO $db, array $values): void
{
    foreach ($values as $table => $value) {
        if (!preg_match('/^[a-z_]+$/', $table) || (int)$value < 1) {
            throw new RuntimeException('Invalid AUTO_INCREMENT restore metadata.');
        }
        $db->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int)$value);
    }
}

function findConcurrencySlot(PDO $db): array
{
    $doctors = $db->query(
        "SELECT u.id AS doctor_user_id, dp.department_id
         FROM users u
         JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
         JOIN doctor_schedule_settings ss ON ss.doctor_user_id=u.id
         JOIN departments d ON d.id=dp.department_id
         WHERE u.role='doctor' AND u.is_active=1
           AND ss.is_available=1 AND d.status='active'
         ORDER BY u.id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $schedule = new ScheduleService($db, 'v2');
    foreach ($doctors as $doctor) {
        $doctorId = (int)$doctor['doctor_user_id'];
        for ($offset = 1; $offset <= 30; $offset++) {
            $date = (new DateTimeImmutable('today'))->modify("+{$offset} days")->format('Y-m-d');
            $slots = $schedule->getAvailableSlots($doctorId, $date);
            if ($slots) {
                return [
                    'doctor_user_id' => $doctorId,
                    'department_id' => (int)$doctor['department_id'],
                    'date' => $date,
                    'time' => (string)$slots[0]['time'],
                ];
            }
        }
    }
    throw new RuntimeException('No future bookable slot was found in the v2 disposable fixture.');
}

$dbA = null;
$dbB = null;
$outerTransactionOpen = false;
$bookingCommitted = false;
$appointmentId = 0;
$counters = [];
$testInput = null;

try {
    echo "=== HealthBridge v2 two-connection booking serialization ===\n";
    $dbA = connectConcurrencyDb($host, $port, $user, $password);
    $dbB = connectConcurrencyDb($host, $port, $user, $password);
    if ($dbA === $dbB) {
        throw new RuntimeException('Test requires two independent PDO connections.');
    }

    $counters = concurrencyCounterSnapshot($dbA);
    $slot = findConcurrencySlot($dbA);
    $patientId = (int)$dbA->query(
        "SELECT id FROM users WHERE role='patient' AND is_active=1 ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($patientId <= 0) {
        throw new RuntimeException('No active patient exists in the v2 fixture.');
    }

    $testInput = [
        'doctor_user_id' => $slot['doctor_user_id'],
        'patient_user_id' => $patientId,
        'department_id' => $slot['department_id'],
        'date' => $slot['date'],
        'time' => $slot['time'],
        'notes' => 'Two-connection concurrency contract; synthetic and rolled back.',
        'actor_user_id' => $patientId,
        'actor_role' => 'patient',
    ];

    $repoA = new AppointmentBookingRepository($dbA, 'v2');
    $repoB = new AppointmentBookingRepository($dbB, 'v2');

    // A acquires doctor -> profile -> department locks, validates, and inserts
    // a pending booking while keeping the outer transaction open.
    $dbA->beginTransaction();
    $outerTransactionOpen = true;
    $appointmentId = $repoA->book($testInput);
    if (!$dbA->inTransaction()) {
        throw new RuntimeException('Booking repository committed a caller-owned transaction.');
    }
    echo "CONNECTION_A_BOOKING_LOCK_HELD|appointment={$appointmentId}\n";

    // B must not pass the same doctor's serialization lock while A is uncommitted.
    $dbB->exec('SET SESSION innodb_lock_wait_timeout = 1');
    $lockBlocked = false;
    try {
        $repoB->book($testInput);
    } catch (PDOException $e) {
        $driverCode = (int)($e->errorInfo[1] ?? 0);
        $lockBlocked = $driverCode === 1205;
        if (!$lockBlocked) {
            throw new RuntimeException(
                'Second connection failed for a reason other than the expected lock wait timeout: '
                . $e->getCode()
            );
        }
    }
    if (!$lockBlocked || $dbB->inTransaction()) {
        throw new RuntimeException('Second connection did not block and roll back on the doctor row lock.');
    }
    echo "CONNECTION_B_BLOCKED_ON_DOCTOR_LOCK|expected_lock_timeout\n";

    // Release A. B now obtains the same doctor lock but must see the committed
    // appointment during the under-lock ScheduleService revalidation.
    $dbA->commit();
    $outerTransactionOpen = false;
    $bookingCommitted = true;

    $slotRejected = false;
    try {
        $repoB->book($testInput);
    } catch (DomainException $e) {
        $slotRejected = str_contains(mb_strtolower($e->getMessage()), 'overlap')
            || str_contains(mb_strtolower($e->getMessage()), 'fully booked');
    }
    if (!$slotRejected || $dbB->inTransaction()) {
        throw new RuntimeException('Second connection was not rejected after the first booking committed.');
    }
    echo "CONNECTION_B_REVALIDATION_REJECTED_DUPLICATE_SLOT\n";

    // Remove the only committed synthetic booking and its transactional side effects.
    $dbA->beginTransaction();
    $dbA->prepare("DELETE FROM notification_outbox WHERE ref_type='appointment' AND ref_id=?")
        ->execute([$appointmentId]);
    $dbA->prepare("DELETE FROM audit_events WHERE entity_type='appointment' AND entity_id=?")
        ->execute([(string)$appointmentId]);
    $dbA->prepare('DELETE FROM appointment_events WHERE appointment_id=?')->execute([$appointmentId]);
    $dbA->prepare('DELETE FROM appointments WHERE id=?')->execute([$appointmentId]);
    $dbA->commit();
    $bookingCommitted = false;

    restoreConcurrencyCounters($dbA, $counters);
    if (concurrencyCounterSnapshot($dbA) !== $counters) {
        throw new RuntimeException('Concurrency test changed one or more AUTO_INCREMENT counters.');
    }

    $exists = $dbA->prepare('SELECT COUNT(*) FROM appointments WHERE id=?');
    $exists->execute([$appointmentId]);
    if ((int)$exists->fetchColumn() !== 0) {
        throw new RuntimeException('Synthetic concurrency appointment survived cleanup.');
    }

    echo "SYNTHETIC_BOOKING_CLEANED_AND_COUNTERS_RESTORED\n";
    echo "RESULT=PASS (two independent connections; duplicate slot could not be committed twice)\n";
    exit(0);
} catch (Throwable $e) {
    if ($dbA instanceof PDO && $outerTransactionOpen && $dbA->inTransaction()) {
        $dbA->rollBack();
        $outerTransactionOpen = false;
    }
    if ($dbB instanceof PDO && $dbB->inTransaction()) {
        $dbB->rollBack();
    }

    if ($dbA instanceof PDO && $bookingCommitted && $appointmentId > 0) {
        try {
            $dbA->beginTransaction();
            $dbA->prepare("DELETE FROM notification_outbox WHERE ref_type='appointment' AND ref_id=?")
                ->execute([$appointmentId]);
            $dbA->prepare("DELETE FROM audit_events WHERE entity_type='appointment' AND entity_id=?")
                ->execute([(string)$appointmentId]);
            $dbA->prepare('DELETE FROM appointment_events WHERE appointment_id=?')->execute([$appointmentId]);
            $dbA->prepare('DELETE FROM appointments WHERE id=?')->execute([$appointmentId]);
            $dbA->commit();
        } catch (Throwable $cleanupError) {
            if ($dbA->inTransaction()) { $dbA->rollBack(); }
            fwrite(STDERR, "CLEANUP ALSO FAILED: " . get_class($cleanupError) . "\n");
        }
    }
    if ($dbA instanceof PDO && $counters) {
        try { restoreConcurrencyCounters($dbA, $counters); }
        catch (Throwable $counterError) { fwrite(STDERR, "COUNTER RESTORE FAILED: " . get_class($counterError) . "\n"); }
    }

    fwrite(STDERR, "BOOKING CONCURRENCY TEST FAILED/BLOCKED: " . get_class($e) . "\n");
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(2);
}
