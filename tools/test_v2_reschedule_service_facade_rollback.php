<?php
declare(strict_types=1);

/**
 * V2 RescheduleService facade contract.
 *
 * Uses only the approved loopback V2 disposable fixture. A synthetic booking
 * and all reschedule mutations run inside one caller-owned transaction, which
 * is rolled back. AUTO_INCREMENT counters touched by the test are restored.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const RESCHEDULE_FACADE_V2_FIXTURE = 'healthbridge_v2_prototype_20261118';

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
require_once __DIR__ . '/../services/RescheduleService.php';

function connectRescheduleFacadeFixture(string $host, string $port, string $user, string $password): PDO
{
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname=" . RESCHEDULE_FACADE_V2_FIXTURE . ";charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== RESCHEDULE_FACADE_V2_FIXTURE) {
        throw new RuntimeException('Connected schema did not match the exact V2 fixture.');
    }
    return $db;
}

function rescheduleFacadeAutoIncrementSnapshot(PDO $db): array
{
    $tables = [
        'appointments',
        'appointment_reschedule_requests',
        'appointment_events',
        'audit_events',
        'notification_outbox',
    ];
    $stmt = $db->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $snapshot = [];
    foreach ($tables as $table) {
        $stmt->execute([$table]);
        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null) {
            $snapshot[$table] = (int)$value;
        }
    }
    return $snapshot;
}

function restoreRescheduleFacadeAutoIncrement(PDO $db, array $snapshot): void
{
    foreach ($snapshot as $table => $value) {
        if (!preg_match('/^[a-z_]+$/', $table) || (int)$value < 1) {
            throw new RuntimeException('Invalid AUTO_INCREMENT restore metadata.');
        }
        $db->exec('ALTER TABLE ' . chr(96) . $table . chr(96) . ' AUTO_INCREMENT = ' . (int)$value);
    }
}

function chooseRescheduleFacadeDoctorAndSlots(PDO $db): array
{
    $sql = "SELECT u.id AS doctor_user_id, dp.department_id
            FROM users u
            JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
            JOIN doctor_schedule_settings ss ON ss.doctor_user_id=u.id
            JOIN departments dep ON dep.id=dp.department_id
            WHERE u.role='doctor' AND u.is_active=1
              AND ss.is_available=1 AND dep.status='active'
            ORDER BY u.id";
    $schedule = new ScheduleService($db);
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $doctor) {
        $doctorId = (int)$doctor['doctor_user_id'];
        $slots = [];
        $startDate = new DateTimeImmutable('today');
        for ($offset = 1; $offset <= 45 && count($slots) < 8; $offset++) {
            $date = $startDate->modify("+{$offset} days")->format('Y-m-d');
            foreach ($schedule->getAvailableSlots($doctorId, $date) as $slot) {
                $slots[] = ['date' => $date, 'time' => (string)$slot['time']];
                if (count($slots) >= 8) {
                    break;
                }
            }
        }
        if (count($slots) >= 7) {
            return [
                'doctor_user_id' => $doctorId,
                'department_id' => (int)$doctor['department_id'],
                'slots' => $slots,
            ];
        }
    }
    throw new RuntimeException('No eligible V2 doctor has seven future free slots.');
}

function firstRescheduleFacadePatient(PDO $db): int
{
    $id = (int)$db->query(
        "SELECT id FROM users WHERE role='patient' AND is_active=1 ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('No active patient fixture exists.');
    }
    return $id;
}

function assertFacadeSuccess(array $result, string $expectedMessage, string $label): void
{
    if (($result['success'] ?? false) !== true || ($result['message'] ?? null) !== $expectedMessage) {
        throw new RuntimeException($label . ' returned an unexpected legacy API response.');
    }
}

function assertFacadeHistory(RescheduleService $service, int $appointmentId, string $expectedStatus, string $label): void
{
    $history = $service->getRescheduleHistory($appointmentId);
    $actual = (string)($history['reschedule_status'] ?? '');
    if ($actual !== $expectedStatus) {
        throw new RuntimeException($label . ": expected {$expectedStatus}, got {$actual}.");
    }
}

$db = null;
$autoBefore = [];
$transactionStarted = false;
$failure = null;

try {
    $db = connectRescheduleFacadeFixture($host, $port, $user, $password);
    $autoBefore = rescheduleFacadeAutoIncrementSnapshot($db);

    $candidate = chooseRescheduleFacadeDoctorAndSlots($db);
    $doctorId = (int)$candidate['doctor_user_id'];
    $departmentId = (int)$candidate['department_id'];
    $slots = $candidate['slots'];
    $patientId = firstRescheduleFacadePatient($db);

    // Constructor contract (V1 retired in Batch 2 Stage B): construction
    // always selects the V2 transaction repository; there is no schema arg.
    $defaultService = new RescheduleService($db, $patientId, 'patient');
    $v2Service = new RescheduleService($db, $patientId, 'patient');
    $repositoryProperty = new ReflectionProperty(RescheduleService::class, 'repository');
    $repositoryProperty->setAccessible(true);
    if (!$repositoryProperty->getValue($defaultService) instanceof RescheduleTransactionRepository
        || !$repositoryProperty->getValue($v2Service) instanceof RescheduleTransactionRepository) {
        throw new RuntimeException('Construction must select RescheduleTransactionRepository.');
    }
    echo "SCHEMA_ROUTING_OK|default=repository|explicit_v2=repository\n";

    $patient = $v2Service;
    $doctor = new RescheduleService($db, $doctorId, 'doctor');
    $bookingRepo = new AppointmentBookingRepository($db);

    $db->beginTransaction();
    $transactionStarted = true;

    $appointmentId = $bookingRepo->book([
        'doctor_user_id' => $doctorId,
        'patient_user_id' => $patientId,
        'department_id' => $departmentId,
        'date' => $slots[0]['date'],
        'time' => $slots[0]['time'],
        'notes' => 'Synthetic V2 service-facade contract; rolled back.',
        'actor_user_id' => $patientId,
        'actor_role' => 'patient',
    ]);
    $confirm = $db->prepare("UPDATE appointments SET booking_status='confirmed' WHERE id=? AND booking_status='pending'");
    $confirm->execute([$appointmentId]);
    if ($confirm->rowCount() !== 1) {
        throw new RuntimeException('Synthetic booking could not be confirmed.');
    }
    echo "SYNTHETIC_BOOKING_OK|appointment={$appointmentId}\n";

    // Request -> suggest -> decline -> approve.
    assertFacadeSuccess(
        $patient->requestReschedule($appointmentId, $slots[1]['date'], $slots[1]['time'], 'Facade request reason.'),
        'Reschedule request submitted successfully. Awaiting doctor approval.',
        'requestReschedule'
    );
    assertFacadeHistory($patient, $appointmentId, 'requested', 'Requested state');
    if (count($doctor->getPendingRequestsForDoctor($doctorId)) < 1 || count($doctor->getAllPendingRequests()) < 1) {
        throw new RuntimeException('V2 pending-list facade did not expose the active request.');
    }
    echo "REQUEST_AND_PENDING_READS_OK\n";

    assertFacadeSuccess(
        $doctor->suggestReschedule($appointmentId, $slots[2]['date'], $slots[2]['time'], 'Facade suggestion.'),
        'Alternative time suggested. Awaiting patient response.',
        'suggestReschedule'
    );
    assertFacadeHistory($patient, $appointmentId, 'suggested', 'Suggested state');

    assertFacadeSuccess(
        $patient->declineRescheduleSuggestion($appointmentId),
        'Suggested time declined. The original request is restored for the doctor to review.',
        'declineRescheduleSuggestion'
    );
    assertFacadeHistory($patient, $appointmentId, 'requested', 'Declined suggestion state');

    assertFacadeSuccess(
        $doctor->approveReschedule($appointmentId, 'Facade approval.'),
        'Reschedule request approved. Appointment has been updated.',
        'approveReschedule'
    );
    assertFacadeHistory($patient, $appointmentId, 'approved', 'Approved state');
    echo "DECLINE_AND_APPROVE_OK\n";

    // Request -> suggest -> accept.
    assertFacadeSuccess(
        $patient->requestReschedule($appointmentId, $slots[3]['date'], $slots[3]['time'], 'Second facade request.'),
        'Reschedule request submitted successfully. Awaiting doctor approval.',
        'second requestReschedule'
    );
    assertFacadeSuccess(
        $doctor->suggestReschedule($appointmentId, $slots[4]['date'], $slots[4]['time'], 'Second facade suggestion.'),
        'Alternative time suggested. Awaiting patient response.',
        'second suggestReschedule'
    );
    assertFacadeSuccess(
        $patient->acceptRescheduleSuggestion($appointmentId),
        'Suggested time accepted. Appointment has been updated.',
        'acceptRescheduleSuggestion'
    );
    assertFacadeHistory($patient, $appointmentId, 'approved', 'Accepted suggestion state');
    echo "ACCEPT_SUGGESTION_OK\n";

    // Request -> reject.
    assertFacadeSuccess(
        $patient->requestReschedule($appointmentId, $slots[5]['date'], $slots[5]['time'], 'Third facade request.'),
        'Reschedule request submitted successfully. Awaiting doctor approval.',
        'third requestReschedule'
    );
    assertFacadeSuccess(
        $doctor->rejectReschedule($appointmentId, 'Facade rejection.'),
        'Reschedule request rejected. The appointment remains at its original schedule.',
        'rejectReschedule'
    );
    assertFacadeHistory($patient, $appointmentId, 'rejected', 'Rejected state');
    echo "REJECT_OK\n";

    // Request -> cancel.
    assertFacadeSuccess(
        $patient->requestReschedule($appointmentId, $slots[6]['date'], $slots[6]['time'], 'Fourth facade request.'),
        'Reschedule request submitted successfully. Awaiting doctor approval.',
        'fourth requestReschedule'
    );
    assertFacadeSuccess(
        $patient->cancelRescheduleRequest($appointmentId),
        'Reschedule request cancelled. Appointment remains at original schedule.',
        'cancelRescheduleRequest'
    );
    assertFacadeHistory($patient, $appointmentId, 'none', 'Cancelled state');
    if (count($doctor->getPendingRequestsForDoctor($doctorId)) !== 0) {
        throw new RuntimeException('Cancelled request remained in the pending list.');
    }

    $audit = $db->prepare("SELECT COUNT(*) FROM audit_events WHERE entity_type='appointment' AND entity_id=?");
    $audit->execute([(string)$appointmentId]);
    if ((int)$audit->fetchColumn() < 7) {
        throw new RuntimeException('V2 audit_events did not record the reschedule lifecycle.');
    }

    $appointment = $db->prepare('SELECT starts_at, booking_status FROM appointments WHERE id=?');
    $appointment->execute([$appointmentId]);
    $finalAppointment = $appointment->fetch(PDO::FETCH_ASSOC);
    if (!$finalAppointment || $finalAppointment['booking_status'] !== 'confirmed'
        || (string)$finalAppointment['starts_at'] !== $slots[4]['date'] . ' ' . $slots[4]['time'] . ':00') {
        throw new RuntimeException('Final appointment schedule/status was not preserved through the lifecycle.');
    }

    echo "AUDIT_AND_FINAL_APPOINTMENT_OK\n";
    echo "V2_RESCHEDULE_SERVICE_FACADE_OK|appointment={$appointmentId}\n";
} catch (Throwable $e) {
    $failure = $e;
    fwrite(STDERR, 'FAIL: ' . $e->getMessage() . "\n");
} finally {
    if ($db instanceof PDO) {
        if ($transactionStarted && $db->inTransaction()) {
            $db->rollBack();
        }
        try {
            if ($autoBefore) {
                restoreRescheduleFacadeAutoIncrement($db, $autoBefore);
            }
        } catch (Throwable $restoreError) {
            $failure = $failure ?: $restoreError;
            fwrite(STDERR, 'AUTO_INCREMENT_RESTORE_FAIL: ' . $restoreError->getMessage() . "\n");
        }
    }
}

if ($failure) {
    exit(1);
}
echo "RESULT=PASS (V2 service facade lifecycle rolled back; fixture and AUTO_INCREMENT counters restored)\n";
