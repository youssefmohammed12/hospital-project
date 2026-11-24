<?php
declare(strict_types=1);

/**
 * Full v1/v2 booking + reschedule workflow contract.
 *
 * Exact loopback disposable fixtures only. All test DML is inside one outer
 * transaction per fixture; the test rolls back and restores touched AUTO_INCREMENT
 * counters after verifying the complete lifecycle.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }

const BOOKING_V1_FIXTURE = 'healthbridge_hb_auth_email_verify_20261114';
const BOOKING_V2_FIXTURE = 'healthbridge_v2_prototype_20261118';

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
require_once __DIR__ . '/../services/Repositories/RescheduleTransactionRepository.php';
require_once __DIR__ . '/../services/ScheduleService.php';

function connectBookingFixture(string $database, string $host, string $port, string $user, string $password): PDO
{
    if (!in_array($database, [BOOKING_V1_FIXTURE, BOOKING_V2_FIXTURE], true)) {
        throw new RuntimeException('Database is not on exact booking fixture allowlist.');
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
        throw new RuntimeException('Connected schema did not match exact fixture.');
    }
    return $db;
}

function bookingAutoIncrementSnapshot(PDO $db, array $tables): array
{
    $query = $db->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $result = [];
    foreach ($tables as $table) {
        $query->execute([$table]);
        $value = $query->fetchColumn();
        if ($value !== false && $value !== null) {
            $result[$table] = (int)$value;
        }
    }
    return $result;
}

function restoreBookingAutoIncrement(PDO $db, array $snapshot): void
{
    foreach ($snapshot as $table => $value) {
        if (!preg_match('/^[a-z_]+$/', $table) || (int)$value < 1) {
            throw new RuntimeException('Invalid AUTO_INCREMENT restore metadata.');
        }
        $db->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int)$value);
    }
}

function chooseDoctorAndSlots(PDO $db, string $schema): array
{
    if ($schema === 'v1') {
        $sql = "SELECT u.id AS doctor_user_id, d.department_id
                FROM users u
                JOIN doctors d ON d.user_id=u.id
                JOIN doctor_schedule_settings ss ON ss.doctor_id=u.id
                LEFT JOIN departments dep ON dep.id=d.department_id
                WHERE u.role='doctor' AND u.is_active=1
                  AND ss.is_available=1
                  AND d.department_id IS NOT NULL
                  AND dep.status='active'
                ORDER BY u.id";
    } else {
        $sql = "SELECT u.id AS doctor_user_id, dp.department_id
                FROM users u
                JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
                JOIN doctor_schedule_settings ss ON ss.doctor_user_id=u.id
                JOIN departments dep ON dep.id=dp.department_id
                WHERE u.role='doctor' AND u.is_active=1
                  AND ss.is_available=1 AND dep.status='active'
                ORDER BY u.id";
    }

    $schedule = new ScheduleService($db, $schema);
    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $doctor) {
        $doctorId = (int)$doctor['doctor_user_id'];
        $candidateSlots = [];
        $startDate = new DateTimeImmutable('today');
        for ($offset = 1; $offset <= 45 && count($candidateSlots) < 8; $offset++) {
            $date = $startDate->modify("+{$offset} days")->format('Y-m-d');
            foreach ($schedule->getAvailableSlots($doctorId, $date) as $slot) {
                $candidateSlots[] = ['date' => $date, 'time' => (string)$slot['time']];
                if (count($candidateSlots) >= 8) {
                    break;
                }
            }
        }
        if (count($candidateSlots) >= 7) {
            return [
                'doctor_user_id' => $doctorId,
                'department_id' => (int)$doctor['department_id'],
                'slots' => $candidateSlots,
            ];
        }
    }

    throw new RuntimeException("No active and available doctor with seven future free slots in {$schema} fixture.");
}

function firstActivePatient(PDO $db): int
{
    $id = (int)$db->query(
        "SELECT id FROM users WHERE role='patient' AND is_active=1 ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('No active patient fixture exists.');
    }
    return $id;
}

function expectStatus(array $result, string $expected, string $label): void
{
    if (($result['request_status'] ?? null) !== $expected) {
        throw new RuntimeException("{$label} returned unexpected request state.");
    }
}

function assertSlotUnavailable(ScheduleService $schedule, int $doctorId, array $slot, string $label): void
{
    $error = $schedule->validateBookingSlot($doctorId, $slot['date'], $slot['time']);
    if ($error === null) {
        throw new RuntimeException("{$label}: an occupied slot was still reported as available.");
    }
}

function assertSlotAvailable(ScheduleService $schedule, int $doctorId, array $slot, string $label): void
{
    $error = $schedule->validateBookingSlot($doctorId, $slot['date'], $slot['time']);
    if ($error !== null) {
        throw new RuntimeException("{$label}: expected slot to be available, got: {$error}");
    }
}

function assertRescheduleHistoryState(
    RescheduleTransactionRepository $repository,
    int $appointmentId,
    string $schema,
    string $expected,
    string $label
): void {
    $history = $repository->getRescheduleHistory($appointmentId);
    $actual = (string)($history['reschedule_status'] ?? '');
    if ($schema === 'v1' && $actual === 'pending') {
        $actual = 'requested';
    }
    if ($actual !== $expected) {
        throw new RuntimeException("{$label}: expected history state {$expected}, got {$actual}.");
    }
}

function runBookingRescheduleContract(string $database, string $schema, string $host, string $port, string $user, string $password): void
{
    $db = connectBookingFixture($database, $host, $port, $user, $password);
    $autoTables = $schema === 'v1'
        ? ['appointments', 'admin_audit', 'notification_outbox']
        : ['appointments', 'appointment_reschedule_requests', 'appointment_events', 'audit_events', 'notification_outbox'];

    $autoBefore = bookingAutoIncrementSnapshot($db, $autoTables);
    $candidate = chooseDoctorAndSlots($db, $schema);
    $doctorId = $candidate['doctor_user_id'];
    $departmentId = $candidate['department_id'];
    $slots = $candidate['slots'];
    $patientId = firstActivePatient($db);
    $actorId = $patientId;

    $bookingRepo = new AppointmentBookingRepository($db, $schema);
    $rescheduleRepo = new RescheduleTransactionRepository($db, $schema);
    $schedule = new ScheduleService($db, $schema);
    $appointmentId = 0;

    $db->beginTransaction();
    try {
        // 1. Book a real available future slot, then mark the synthetic booking
        // confirmed to represent the doctor's normal approval flow.
        $appointmentId = $bookingRepo->book([
            'doctor_user_id' => $doctorId,
            'patient_user_id' => $patientId,
            'department_id' => $departmentId,
            'date' => $slots[0]['date'],
            'time' => $slots[0]['time'],
            'notes' => 'Synthetic compatibility test; will be rolled back.',
            'actor_user_id' => $actorId,
            'actor_role' => 'patient',
        ]);

        if ($schema === 'v1') {
            $db->prepare("UPDATE appointments SET status='Confirmed' WHERE id=? AND status='Pending'")
                ->execute([$appointmentId]);
        } else {
            $db->prepare("UPDATE appointments SET booking_status='confirmed' WHERE id=? AND booking_status='pending'")
                ->execute([$appointmentId]);
        }
        assertSlotUnavailable($schedule, $doctorId, $slots[0], 'Initial booking');
        echo "BOOKING_OK|{$schema}|appointment={$appointmentId}\n";

        // 2. Patient requests a new time. The patient-requested target must be reserved.
        $request = $rescheduleRepo->requestReschedule(
            $appointmentId, $patientId, 'patient',
            $slots[1]['date'], $slots[1]['time'], 'Synthetic patient reschedule reason.'
        );
        expectStatus($request, 'requested', 'Request reschedule');
        assertRescheduleHistoryState($rescheduleRepo, $appointmentId, $schema, 'requested', 'Requested history state');
        assertSlotUnavailable($schedule, $doctorId, $slots[1], 'Patient requested slot');
        echo "RESCHEDULE_REQUEST_OK|{$schema}\n";

        // 3. Doctor suggests a third time. Both requested and proposed targets must be blocked.
        $suggest = $rescheduleRepo->suggestReschedule(
            $appointmentId, $doctorId, 'doctor',
            $slots[2]['date'], $slots[2]['time'], 'Synthetic doctor suggestion.'
        );
        expectStatus($suggest, 'suggested', 'Suggest reschedule');
        assertRescheduleHistoryState($rescheduleRepo, $appointmentId, $schema, 'suggested', 'Suggested history state');
        assertSlotUnavailable($schedule, $doctorId, $slots[1], 'Original requested slot while suggested');
        assertSlotUnavailable($schedule, $doctorId, $slots[2], 'Doctor proposed slot');
        echo "RESCHEDULE_SUGGEST_OK|{$schema}\n";

        // 4. Patient declines suggestion. Proposed slot is released; requested slot stays held.
        $decline = $rescheduleRepo->declineSuggestion($appointmentId, $patientId, 'patient');
        expectStatus($decline, 'requested', 'Decline suggestion');
        assertRescheduleHistoryState($rescheduleRepo, $appointmentId, $schema, 'requested', 'Declined-suggestion history state');
        assertSlotUnavailable($schedule, $doctorId, $slots[1], 'Requested slot after declining suggestion');
        assertSlotAvailable($schedule, $doctorId, $slots[2], 'Released proposed slot after decline');
        echo "RESCHEDULE_DECLINE_SUGGESTION_OK|{$schema}\n";

        // 5. Doctor approves the patient's original requested slot; booking moves there.
        $approve = $rescheduleRepo->approveRequest($appointmentId, $doctorId, 'doctor', 'Synthetic approval.');
        expectStatus($approve, 'approved', 'Approve request');
        assertRescheduleHistoryState($rescheduleRepo, $appointmentId, $schema, 'approved', 'Approved history state');
        assertSlotAvailable($schedule, $doctorId, $slots[0], 'Original booking slot after move');
        assertSlotUnavailable($schedule, $doctorId, $slots[1], 'Approved appointment slot');
        echo "RESCHEDULE_APPROVE_OK|{$schema}\n";

        // 6. A second request can be created after a terminal request. Suggest then reject;
        // rejection must keep the approved appointment's current time.
        $secondRequest = $rescheduleRepo->requestReschedule(
            $appointmentId, $patientId, 'patient',
            $slots[3]['date'], $slots[3]['time'], 'Second synthetic request.'
        );
        expectStatus($secondRequest, 'requested', 'Second reschedule request');
        $secondSuggest = $rescheduleRepo->suggestReschedule(
            $appointmentId, $doctorId, 'doctor',
            $slots[4]['date'], $slots[4]['time'], 'Second synthetic suggestion.'
        );
        expectStatus($secondSuggest, 'suggested', 'Second suggestion');
        $reject = $rescheduleRepo->rejectRequest($appointmentId, $doctorId, 'doctor', 'Synthetic rejection.');
        expectStatus($reject, 'rejected', 'Reject request');
        assertRescheduleHistoryState($rescheduleRepo, $appointmentId, $schema, 'rejected', 'Rejected history state');
        assertSlotUnavailable($schedule, $doctorId, $slots[1], 'Original approved appointment after rejection');
        assertSlotAvailable($schedule, $doctorId, $slots[3], 'Released patient target after rejection');
        assertSlotAvailable($schedule, $doctorId, $slots[4], 'Released doctor proposal after rejection');
        echo "RESCHEDULE_REJECT_OK|{$schema}\n";

        // 7. A third request can be cancelled by the patient; original booking remains.
        $thirdRequest = $rescheduleRepo->requestReschedule(
            $appointmentId, $patientId, 'patient',
            $slots[5]['date'], $slots[5]['time'], 'Third synthetic request.'
        );
        expectStatus($thirdRequest, 'requested', 'Third reschedule request');
        $cancel = $rescheduleRepo->cancelRequest($appointmentId, $patientId, 'patient');
        expectStatus($cancel, 'cancelled', 'Cancel reschedule');
        assertRescheduleHistoryState($rescheduleRepo, $appointmentId, $schema, 'none', 'Cancelled history state');
        assertSlotUnavailable($schedule, $doctorId, $slots[1], 'Original approved appointment after cancellation');
        assertSlotAvailable($schedule, $doctorId, $slots[5], 'Released patient target after cancellation');
        echo "RESCHEDULE_CANCEL_OK|{$schema}\n";

        // 8. Verify the booking remains confirmed and at the approved time.
        if ($schema === 'v1') {
            $verify = $db->prepare('SELECT status, date, time FROM appointments WHERE id=?');
            $verify->execute([$appointmentId]);
            $appointment = $verify->fetch(PDO::FETCH_ASSOC);
            if (
                !$appointment || $appointment['status'] !== 'Confirmed'
                || $appointment['date'] !== $slots[1]['date']
                || AppointmentTime::normalize((string)$appointment['time']) !== $slots[1]['time']
            ) {
                throw new RuntimeException('v1 final appointment state did not preserve the approved date/time.');
            }
            $audit = $db->prepare("SELECT COUNT(*) FROM admin_audit WHERE entity_type='appointment' AND entity_id=?");
            $audit->execute([$appointmentId]);
            if ((int)$audit->fetchColumn() < 7) {
                throw new RuntimeException('v1 booking/reschedule audit trail is incomplete.');
            }
        } else {
            $verify = $db->prepare('SELECT booking_status, starts_at FROM appointments WHERE id=?');
            $verify->execute([$appointmentId]);
            $appointment = $verify->fetch(PDO::FETCH_ASSOC);
            if (
                !$appointment || $appointment['booking_status'] !== 'confirmed'
                || substr((string)$appointment['starts_at'], 0, 10) !== $slots[1]['date']
                || substr((string)$appointment['starts_at'], 11, 5) !== $slots[1]['time']
            ) {
                throw new RuntimeException('v2 final appointment state did not preserve the approved date/time.');
            }
            $history = $db->prepare('SELECT request_status FROM appointment_reschedule_requests WHERE appointment_id=? ORDER BY id');
            $history->execute([$appointmentId]);
            $states = $history->fetchAll(PDO::FETCH_COLUMN);
            if ($states !== ['approved', 'rejected', 'cancelled']) {
                throw new RuntimeException('v2 request child history did not preserve all three terminal transitions.');
            }
            $event = $db->prepare('SELECT COUNT(*) FROM appointment_events WHERE appointment_id=?');
            $event->execute([$appointmentId]);
            if ((int)$event->fetchColumn() < 7) {
                throw new RuntimeException('v2 appointment event history is incomplete.');
            }
            $audit = $db->prepare("SELECT COUNT(*) FROM audit_events WHERE entity_type='appointment' AND entity_id=?");
            $audit->execute([(string)$appointmentId]);
            if ((int)$audit->fetchColumn() < 7) {
                throw new RuntimeException('v2 booking/reschedule audit trail is incomplete.');
            }
        }

        // Defensive guard: a stale embedded v1 request flag or an active v2
        // request row must not be actionable once its parent appointment is cancelled.
        $staleRequest = $rescheduleRepo->requestReschedule(
            $appointmentId, $patientId, 'patient',
            $slots[6]['date'], $slots[6]['time'], 'Stale-state guard request.'
        );
        expectStatus($staleRequest, 'requested', 'Stale-state guard request');
        if ($schema === 'v1') {
            $db->prepare("UPDATE appointments SET status='Cancelled' WHERE id=?")->execute([$appointmentId]);
        } else {
            $db->prepare("UPDATE appointments SET booking_status='cancelled' WHERE id=?")->execute([$appointmentId]);
        }

        $staleStateBlocked = false;
        try {
            $rescheduleRepo->suggestReschedule(
                $appointmentId, $doctorId, 'doctor',
                $slots[7]['date'], $slots[7]['time'], 'Must not persist after parent cancellation.'
            );
        } catch (DomainException $expected) {
            $staleStateBlocked = str_contains(mb_strtolower($expected->getMessage()), 'pending');
        }
        if (!$staleStateBlocked) {
            throw new RuntimeException('A reschedule transition was allowed after its parent appointment was cancelled.');
        }
        echo "CANCELLED_PARENT_GUARD_OK|{$schema}\n";

        if (!$db->inTransaction()) {
            throw new RuntimeException('A repository committed the caller-owned outer transaction.');
        }
        echo "BOOKING_RESCHEDULE_LIFECYCLE_OK|{$schema}|appointment={$appointmentId}\n";
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    // Verify synthetic rows disappeared and all counters are returned to their
    // pre-test values. The target fixtures are left byte-for-byte equivalent for rows.
    if ($appointmentId > 0) {
        $exists = $db->prepare('SELECT COUNT(*) FROM appointments WHERE id=?');
        $exists->execute([$appointmentId]);
        if ((int)$exists->fetchColumn() !== 0) {
            throw new RuntimeException("Synthetic appointment {$appointmentId} survived rollback.");
        }
    }
    restoreBookingAutoIncrement($db, $autoBefore);
    if (bookingAutoIncrementSnapshot($db, $autoTables) !== $autoBefore) {
        throw new RuntimeException("AUTO_INCREMENT counters did not return to their original values in {$database}.");
    }

    echo "BOOKING_RESCHEDULE_ROLLBACK_OK|{$database}\n";
}

try {
    echo "=== HealthBridge booking/reschedule transaction compatibility ===\n";
    runBookingRescheduleContract(BOOKING_V1_FIXTURE, 'v1', $host, $port, $user, $password);
    runBookingRescheduleContract(BOOKING_V2_FIXTURE, 'v2', $host, $port, $user, $password);
    echo "RESULT=PASS (both fixture transactions rolled back and auto-increment values restored)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "BOOKING/RESCHEDULE CONTRACT FAILED/BLOCKED: " . get_class($e) . "\n");
    fwrite(STDERR, "Any active outer transaction is rolled back by the test.\n");
    exit(2);
}
