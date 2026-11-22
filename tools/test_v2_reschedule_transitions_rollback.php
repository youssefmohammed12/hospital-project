<?php
declare(strict_types=1);

/**
 * Synthetic reschedule-state compatibility test.
 *
 * Updates one matching appointment/request in the two exact disposable clones,
 * compares reserved slot ranges through every meaningful state, then rolls both
 * transactions back. No source/target mutation is committed.
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

require_once __DIR__ . '/../includes/AppointmentTime.php';
require_once __DIR__ . '/../services/Repositories/AppointmentReservationRepository.php';

function connectRescheduleFixture(string $database, string $host, string $port, string $user, string $password): PDO
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

function canonicalReservationRanges(array $ranges): array
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

function compareReservationScenario(
    string $name,
    int $doctorId,
    array $dates,
    int $appointmentId,
    AppointmentReservationRepository $v1Repo,
    AppointmentReservationRepository $v2Repo
): void {
    foreach (array_values(array_unique($dates)) as $date) {
        $left = canonicalReservationRanges($v1Repo->getBookedRanges($doctorId, $date));
        $right = canonicalReservationRanges($v2Repo->getBookedRanges($doctorId, $date));
        if ($left !== $right) {
            throw new RuntimeException(
                "{$name} reservation mismatch for {$date}, appointment {$appointmentId}."
            );
        }
    }
    echo "STATE_OK|{$name}\n";
}

$v1 = null;
$v2 = null;
$transactionStartedV1 = false;
$transactionStartedV2 = false;

try {
    echo "=== HealthBridge synthetic reschedule transition parity ===\n";
    $v1 = connectRescheduleFixture(HB_V1_FIXTURE, $host, $port, $user, $password);
    $v2 = connectRescheduleFixture(HB_V2_FIXTURE, $host, $port, $user, $password);

    $source = $v1->query(
        "SELECT id, doctor_id, date, time, appointment_time_range,
                requested_reschedule_date, requested_reschedule_time,
                pending_reschedule_date, pending_reschedule_time,
                status, reschedule_status
         FROM appointments
         WHERE status='Reschedule Requested' AND reschedule_status='pending'
         ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$source) {
        throw new RuntimeException('No pending synthetic reschedule request exists in the v1 fixture.');
    }

    $appointmentId = (int)$source['id'];
    $doctorId = (int)$source['doctor_id'];
    $originalDate = (string)$source['date'];
    $originalTime = AppointmentTime::normalize((string)$source['time']);
    $requestedDate = (string)$source['requested_reschedule_date'];
    $requestedTime = AppointmentTime::normalize((string)$source['requested_reschedule_time']);

    if (!$requestedDate || !$requestedTime) {
        throw new RuntimeException('The selected source request lacks its original requested time.');
    }

    $requestQuery = $v2->prepare(
        "SELECT id, appointment_id, request_status, requested_starts_at,
                proposed_starts_at
         FROM appointment_reschedule_requests
         WHERE appointment_id = ? AND request_status = 'requested'
         LIMIT 1"
    );
    $requestQuery->execute([$appointmentId]);
    $targetRequest = $requestQuery->fetch(PDO::FETCH_ASSOC);
    if (!$targetRequest) {
        throw new RuntimeException('The matching v2 request row does not exist in requested state.');
    }

    $targetAppointmentQuery = $v2->prepare(
        'SELECT starts_at, duration_minutes FROM appointments WHERE id = ?'
    );
    $targetAppointmentQuery->execute([$appointmentId]);
    $targetAppointment = $targetAppointmentQuery->fetch(PDO::FETCH_ASSOC);
    if (!$targetAppointment) {
        throw new RuntimeException('The matching v2 appointment row does not exist.');
    }

    $sourceDuration = AppointmentTime::duration($v1, $doctorId);
    $proposedDate = (new DateTimeImmutable($requestedDate))->modify('+2 days')->format('Y-m-d');
    if ($proposedDate === $originalDate || $proposedDate === $requestedDate) {
        throw new RuntimeException('Could not construct a distinct proposed date.');
    }
    $proposedTime = '16:15';
    $proposedAt = $proposedDate . ' ' . $proposedTime . ':00';
    $requestedAt = $requestedDate . ' ' . $requestedTime . ':00';
    $originalAt = $originalDate . ' ' . $originalTime . ':00';
    $testDates = [$originalDate, $requestedDate, $proposedDate];

    $v1Repo = new AppointmentReservationRepository($v1, AppointmentReservationRepository::SCHEMA_V1);
    $v2Repo = new AppointmentReservationRepository($v2, AppointmentReservationRepository::SCHEMA_V2);

    $v1->beginTransaction();
    $transactionStartedV1 = true;
    $v2->beginTransaction();
    $transactionStartedV2 = true;

    // Doctor offers a different time: current, patient-requested and proposed
    // slots must all be reserved at once.
    $updateSource = $v1->prepare(
        "UPDATE appointments
         SET pending_reschedule_date = ?, pending_reschedule_time = ?,
             reschedule_status = 'suggested'
         WHERE id = ? AND status = 'Reschedule Requested' AND reschedule_status = 'pending'"
    );
    $updateSource->execute([$proposedDate, $proposedTime, $appointmentId]);
    if ($updateSource->rowCount() !== 1) {
        throw new RuntimeException('Could not create the temporary v1 suggested state.');
    }

    $updateTarget = $v2->prepare(
        "UPDATE appointment_reschedule_requests
         SET request_status = 'suggested', proposed_starts_at = ?
         WHERE id = ? AND request_status = 'requested'"
    );
    $updateTarget->execute([$proposedAt, (int)$targetRequest['id']]);
    if ($updateTarget->rowCount() !== 1) {
        throw new RuntimeException('Could not create the temporary v2 suggested state.');
    }
    compareReservationScenario('suggested', $doctorId, $testDates, $appointmentId, $v1Repo, $v2Repo);

    // Patient accepts suggestion / doctor approves requested target:
    // appointment itself moves to requested date; request becomes terminal.
    $requestedRange = AppointmentTime::range($requestedTime, $sourceDuration);
    $moveSource = $v1->prepare(
        "UPDATE appointments
         SET date = ?, time = ?, appointment_time_range = ?,
             status = 'Confirmed', reschedule_status = 'approved'
         WHERE id = ?"
    );
    $moveSource->execute([$requestedDate, $requestedTime, $requestedRange, $appointmentId]);

    $moveTarget = $v2->prepare(
        "UPDATE appointments SET starts_at = ? WHERE id = ?"
    );
    $moveTarget->execute([$requestedAt, $appointmentId]);
    $closeTarget = $v2->prepare(
        "UPDATE appointment_reschedule_requests
         SET request_status = 'approved'
         WHERE id = ?"
    );
    $closeTarget->execute([(int)$targetRequest['id']]);
    compareReservationScenario('approved', $doctorId, $testDates, $appointmentId, $v1Repo, $v2Repo);

    // Rejection leaves the original appointment time in place and removes all
    // additional request/suggestion reservations.
    $restoreSource = $v1->prepare(
        "UPDATE appointments
         SET date = ?, time = ?, appointment_time_range = ?,
             status = 'Confirmed', reschedule_status = 'rejected'
         WHERE id = ?"
    );
    $restoreSource->execute([
        $originalDate,
        $originalTime,
        AppointmentTime::range($originalTime, $sourceDuration),
        $appointmentId,
    ]);
    $restoreTarget = $v2->prepare(
        'UPDATE appointments SET starts_at = ? WHERE id = ?'
    );
    $restoreTarget->execute([$originalAt, $appointmentId]);
    $rejectTarget = $v2->prepare(
        "UPDATE appointment_reschedule_requests
         SET request_status = 'rejected'
         WHERE id = ?"
    );
    $rejectTarget->execute([(int)$targetRequest['id']]);
    compareReservationScenario('rejected', $doctorId, $testDates, $appointmentId, $v1Repo, $v2Repo);

    // Patient cancellation restores the original booking and clears all
    // legacy reservation metadata; v2 keeps an auditable terminal request.
    $cancelSource = $v1->prepare(
        "UPDATE appointments
         SET date = ?, time = ?, appointment_time_range = ?,
             status = 'Confirmed', reschedule_status = 'none',
             pending_reschedule_date = NULL, pending_reschedule_time = NULL
         WHERE id = ?"
    );
    $cancelSource->execute([
        $originalDate,
        $originalTime,
        AppointmentTime::range($originalTime, $sourceDuration),
        $appointmentId,
    ]);
    $cancelTarget = $v2->prepare(
        "UPDATE appointment_reschedule_requests
         SET request_status = 'cancelled'
         WHERE id = ?"
    );
    $cancelTarget->execute([(int)$targetRequest['id']]);
    compareReservationScenario('cancelled', $doctorId, $testDates, $appointmentId, $v1Repo, $v2Repo);

    $v1->rollBack();
    $transactionStartedV1 = false;
    $v2->rollBack();
    $transactionStartedV2 = false;

    $sourceVerify = $v1->prepare(
        "SELECT status, reschedule_status, pending_reschedule_date, pending_reschedule_time
         FROM appointments WHERE id = ?"
    );
    $sourceVerify->execute([$appointmentId]);
    $restoredSource = $sourceVerify->fetch(PDO::FETCH_ASSOC);

    $targetVerify = $v2->prepare(
        "SELECT request_status, proposed_starts_at
         FROM appointment_reschedule_requests WHERE id = ?"
    );
    $targetVerify->execute([(int)$targetRequest['id']]);
    $restoredTarget = $targetVerify->fetch(PDO::FETCH_ASSOC);

    if (
        !$restoredSource
        || $restoredSource['status'] !== 'Reschedule Requested'
        || $restoredSource['reschedule_status'] !== 'pending'
        || !$restoredTarget
        || $restoredTarget['request_status'] !== 'requested'
        || $restoredTarget['proposed_starts_at'] !== null
    ) {
        throw new RuntimeException('A fixture did not return to its exact original pending-request state.');
    }

    echo "RESULT=PASS (suggested/approved/rejected/cancelled parity; both fixtures rolled back)\n";
    exit(0);
} catch (Throwable $e) {
    if ($v1 instanceof PDO && $transactionStartedV1 && $v1->inTransaction()) {
        $v1->rollBack();
    }
    if ($v2 instanceof PDO && $transactionStartedV2 && $v2->inTransaction()) {
        $v2->rollBack();
    }
    fwrite(STDERR, "TRANSITION TEST FAILED/BLOCKED: " . get_class($e) . "\n");
    fwrite(STDERR, "Any open fixture transaction was rolled back.\n");
    exit(2);
}
