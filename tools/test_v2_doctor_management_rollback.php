<?php
declare(strict_types=1);

/**
 * Doctor-management compatibility contract.
 *
 * Runs only against the two loopback disposable fixtures below. Every account,
 * profile, assignment, status, appointment, audit and outbox mutation occurs in
 * one caller-owned transaction and is rolled back. AUTO_INCREMENT high-water
 * values touched by rollback-only INSERTs are restored to their pre-test values.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }

const DOCTOR_MGMT_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const DOCTOR_MGMT_V2 = 'healthbridge_v2_prototype_20261118';

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

require_once __DIR__ . '/../services/Repositories/DoctorManagementRepository.php';
require_once __DIR__ . '/../services/Repositories/DoctorProfileRepository.php';
require_once __DIR__ . '/../services/ScheduleService.php';

function connectDoctorManagementFixture(string $database, string $host, string $port, string $user, string $password): PDO
{
    if (!in_array($database, [DOCTOR_MGMT_V1, DOCTOR_MGMT_V2], true)) {
        throw new RuntimeException('Database is not on the exact doctor-management fixture allowlist.');
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
        throw new RuntimeException('Connected schema does not match exact disposable fixture.');
    }
    return $db;
}

function autoIncrementSnapshot(PDO $db, array $tables): array
{
    $stmt = $db->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $result = [];
    foreach ($tables as $table) {
        $stmt->execute([$table]);
        $value = $stmt->fetchColumn();
        if ($value !== false && $value !== null) {
            $result[$table] = (int)$value;
        }
    }
    return $result;
}

function restoreAutoIncrement(PDO $db, array $snapshot): void
{
    foreach ($snapshot as $table => $value) {
        if (!preg_match('/^[a-z_]+$/', $table) || $value < 1) {
            throw new RuntimeException('Invalid AUTO_INCREMENT restoration metadata.');
        }
        $db->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int)$value);
    }
}

function sameJsonValue(mixed $left, mixed $right): bool
{
    return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function selectManagementCandidate(PDO $db, string $schema): array
{
    if ($schema === 'v1') {
        $sql = "SELECT u.id, d.department_id,
                       (SELECT COUNT(*) FROM appointments a
                        WHERE a.doctor_id=u.id
                          AND a.status IN ('Pending','Confirmed','Reschedule Requested')
                          AND (
                              (SELECT w.status FROM visit_workflow w WHERE w.appointment_id=a.id LIMIT 1) IS NULL
                              OR (SELECT w.status FROM visit_workflow w WHERE w.appointment_id=a.id LIMIT 1)='Waiting'
                          )
                          AND NOT EXISTS (SELECT 1 FROM visit_drafts vd WHERE vd.appointment_id=a.id)
                          AND NOT EXISTS (SELECT 1 FROM visit_notes vn WHERE vn.appointment_id=a.id)
                          AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.appointment_id=a.id)
                       ) AS cancellable_count
                FROM users u
                JOIN doctors d ON d.user_id=u.id
                WHERE u.role='doctor' AND u.is_active=1
                  AND NOT EXISTS (
                      SELECT 1 FROM appointments a
                      JOIN visit_workflow w ON w.appointment_id=a.id
                      WHERE a.doctor_id=u.id AND a.status='Confirmed'
                        AND w.status IN ('In Progress','Ready to Complete')
                  )
                  AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.doctor_id=u.id AND p.status='Active')
                  AND NOT EXISTS (SELECT 1 FROM visit_drafts vd WHERE vd.doctor_id=u.id)
                ORDER BY u.id";
    } else {
        $sql = "SELECT u.id, dp.department_id,
                       (SELECT COUNT(*) FROM appointments a
                        LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
                        WHERE a.doctor_user_id=u.id
                          AND a.booking_status IN ('pending','confirmed')
                          AND (
                              ce.id IS NULL OR (
                                  ce.workflow_status IN ('uninitialized','waiting')
                                  AND ce.diagnosis IS NULL AND ce.symptoms IS NULL
                                  AND ce.treatment IS NULL AND ce.doctor_notes IS NULL
                                  AND ce.follow_up_instructions IS NULL
                              )
                          )
                          AND NOT EXISTS (SELECT 1 FROM encounter_drafts ed WHERE ed.appointment_id=a.id)
                          AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.appointment_id=a.id)
                       ) AS cancellable_count
                FROM users u
                JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
                WHERE u.role='doctor' AND u.is_active=1
                  AND NOT EXISTS (
                      SELECT 1 FROM appointments a
                      JOIN clinical_encounters ce ON ce.appointment_id=a.id
                      WHERE a.doctor_user_id=u.id AND a.booking_status='confirmed'
                        AND ce.workflow_status IN ('in_progress','ready_to_complete')
                  )
                  AND NOT EXISTS (SELECT 1 FROM prescriptions p WHERE p.doctor_user_id=u.id AND p.status='active')
                  AND NOT EXISTS (SELECT 1 FROM encounter_drafts ed WHERE ed.doctor_user_id=u.id)
                ORDER BY u.id";
    }

    foreach ($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ((int)$row['cancellable_count'] > 0 && (int)$row['department_id'] > 0) {
            return [
                'doctor_user_id' => (int)$row['id'],
                'department_id' => (int)$row['department_id'],
                'cancellable_count' => (int)$row['cancellable_count'],
            ];
        }
    }
    throw new RuntimeException("No active doctor with cancellable untouched appointments was found in {$schema} fixture.");
}

function findActiveDepartmentDifferentFrom(PDO $db, int $currentDepartmentId): int
{
    $stmt = $db->prepare(
        "SELECT id FROM departments
         WHERE status='active' AND id <> ?
         ORDER BY id LIMIT 1"
    );
    $stmt->execute([$currentDepartmentId]);
    $id = (int)$stmt->fetchColumn();
    if ($id <= 0) {
        throw new RuntimeException('No second active department is available for assignment test.');
    }
    return $id;
}

function findUniquePhone(PDO $db): string
{
    for ($i = 1000; $i < 9999; $i++) {
        $phone = '010' . str_pad((string)$i, 8, '0', STR_PAD_LEFT);
        $stmt = $db->prepare('SELECT id FROM users WHERE phone_normalized = ? LIMIT 1');
        $stmt->execute([$phone]);
        if (!$stmt->fetchColumn()) {
            return $phone;
        }
    }
    throw new RuntimeException('Could not find a unique synthetic phone value.');
}

function countCancelledForDoctor(PDO $db, string $schema, int $doctorUserId): int
{
    if ($schema === 'v1') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND status='Cancelled'");
    } else {
        $stmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_user_id=? AND booking_status='cancelled'");
    }
    $stmt->execute([$doctorUserId]);
    return (int)$stmt->fetchColumn();
}

function findDoctorWithUnfinishedClinicalWork(PDO $db, string $schema): ?int
{
    if ($schema === 'v1') {
        $sql = "SELECT u.id
                FROM users u JOIN doctors d ON d.user_id=u.id
                WHERE u.role='doctor' AND u.is_active=1
                  AND (
                      EXISTS (
                          SELECT 1 FROM appointments a
                          JOIN visit_workflow w ON w.appointment_id=a.id
                          WHERE a.doctor_id=u.id AND a.status='Confirmed'
                            AND w.status IN ('In Progress','Ready to Complete')
                      )
                      OR EXISTS (
                          SELECT 1 FROM prescriptions p
                          WHERE p.doctor_id=u.id AND p.status='Active'
                      )
                      OR EXISTS (SELECT 1 FROM visit_drafts vd WHERE vd.doctor_id=u.id)
                  )
                ORDER BY u.id LIMIT 1";
    } else {
        $sql = "SELECT u.id
                FROM users u JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
                WHERE u.role='doctor' AND u.is_active=1
                  AND (
                      EXISTS (
                          SELECT 1 FROM appointments a
                          JOIN clinical_encounters ce ON ce.appointment_id=a.id
                          WHERE a.doctor_user_id=u.id AND a.booking_status='confirmed'
                            AND ce.workflow_status IN ('in_progress','ready_to_complete')
                      )
                      OR EXISTS (
                          SELECT 1 FROM prescriptions p
                          WHERE p.doctor_user_id=u.id AND p.status='active'
                      )
                      OR EXISTS (SELECT 1 FROM encounter_drafts ed WHERE ed.doctor_user_id=u.id)
                  )
                ORDER BY u.id LIMIT 1";
    }

    $value = $db->query($sql)->fetchColumn();
    return $value === false ? null : (int)$value;
}

function countDoctorAudit(PDO $db, string $schema, int $doctorUserId, string $action): int
{
    if ($schema === 'v1') {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM admin_audit
             WHERE entity_type='doctor' AND entity_id=? AND action=?"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM audit_events
             WHERE entity_type='doctor' AND entity_id=? AND action_code=?"
        );
    }
    $stmt->execute([$doctorUserId, $action]);
    return (int)$stmt->fetchColumn();
}

function runDoctorManagementContract(string $database, string $schema, string $host, string $port, string $user, string $password): void
{
    $db = connectDoctorManagementFixture($database, $host, $port, $user, $password);
    $autoTables = $schema === 'v1'
        ? ['users','doctors','doctor_schedule_settings','doctor_schedule_weekly','user_preferences','admin_audit','notification_outbox']
        : ['users','audit_events','notification_outbox','appointment_events'];
    $autoBefore = autoIncrementSnapshot($db, $autoTables);
    $candidate = selectManagementCandidate($db, $schema);
    $doctorUserId = $candidate['doctor_user_id'];
    $originalCancelledCount = countCancelledForDoctor($db, $schema, $doctorUserId);

    $adminId = (int)$db->query(
        "SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($adminId <= 0) {
        throw new RuntimeException("No active administrator fixture found in {$database}.");
    }

    $profileRepo = new DoctorProfileRepository($db, $schema);
    $management = new DoctorManagementRepository($db, $schema);
    $scheduleService = new ScheduleService($db, $schema);
    $createdEmail = 'compat-doctor-' . ($schema === 'v1' ? 'v1' : 'v2') . '@example.invalid';
    $createdDoctorId = 0;
    $cancelledBefore = $candidate['cancellable_count'];

    $db->beginTransaction();
    try {
        $hash = password_hash('synthetic-contract-password-only', PASSWORD_DEFAULT);
        $createdDoctorId = $management->createDoctor(
            'Compatibility Test Doctor',
            $createdEmail,
            $hash,
            $candidate['department_id'],
            $adminId
        );
        if (!$db->inTransaction()) {
            throw new RuntimeException('createDoctor ended a caller-owned transaction.');
        }

        $created = $profileRepo->findByUserId($createdDoctorId);
        if (
            !$created
            || (int)$created['doctor_id'] !== $createdDoctorId
            || (int)$created['department_id'] !== $candidate['department_id']
            || (int)$created['is_active'] !== 1
        ) {
            throw new RuntimeException('New doctor profile did not expose canonical users.id and department details.');
        }
        if (countDoctorAudit($db, $schema, $createdDoctorId, 'create') !== 1) {
            throw new RuntimeException('Doctor creation did not write exactly one audit event.');
        }
        $userStmt = $db->prepare('SELECT password' . ($schema === 'v1' ? '' : '_hash') . ' FROM users WHERE id=?');
        $userStmt->execute([$createdDoctorId]);
        $storedHash = (string)$userStmt->fetchColumn();
        if (!password_verify('synthetic-contract-password-only', $storedHash)) {
            throw new RuntimeException('New doctor password hash was not stored/verified correctly.');
        }

        $createdSchedule = $scheduleService->getSchedule($createdDoctorId);
        if (
            !$createdSchedule
            || count($createdSchedule['weekly']) !== 7
            || (int)$createdSchedule['settings']['is_available'] !== 1
            || (int)$createdSchedule['settings']['appointment_duration'] !== 30
        ) {
            throw new RuntimeException('New doctor did not receive a complete default schedule.');
        }
        $workingDays = array_values(array_filter(
            $createdSchedule['weekly'],
            static fn(array $day): bool => (int)$day['is_working'] === 1
        ));
        if (count($workingDays) !== 5) {
            throw new RuntimeException('New doctor default schedule should include five working days.');
        }

        $updatedEmail = 'compat-doctor-updated-' . ($schema === 'v1' ? 'v1' : 'v2') . '@example.invalid';
        $testPhone = findUniquePhone($db);
        $updateResult = $management->updateDoctor($createdDoctorId, [
            'name' => 'Compatibility Doctor Updated',
            'email' => $updatedEmail,
            'phone' => $testPhone,
        ], $adminId);
        if (!$updateResult['changed'] || count($updateResult['changes']) !== 3) {
            throw new RuntimeException('Doctor account update did not report the three changed identity fields.');
        }
        if (countDoctorAudit($db, $schema, $createdDoctorId, 'update') !== 1) {
            throw new RuntimeException('Doctor account update did not write exactly one audit event.');
        }
        $updated = $profileRepo->findByUserId($createdDoctorId);
        if (
            !$updated
            || $updated['name'] !== 'Compatibility Doctor Updated'
            || $updated['email'] !== $updatedEmail
            || $updated['phone'] !== $testPhone
        ) {
            throw new RuntimeException('Updated doctor profile fields did not match the submitted values.');
        }

        $newDepartmentId = findActiveDepartmentDifferentFrom($db, $candidate['department_id']);
        $assignment = $management->assignDoctorToDepartment($createdDoctorId, $newDepartmentId, $adminId);
        if (!$assignment['changed'] || (int)$assignment['department_id'] !== $newDepartmentId) {
            throw new RuntimeException('Doctor department assignment did not report the new department.');
        }
        if (countDoctorAudit($db, $schema, $createdDoctorId, 'reassign') !== 1) {
            throw new RuntimeException('Doctor department assignment did not write exactly one audit event.');
        }
        $reassigned = $profileRepo->findByUserId($createdDoctorId);
        $departmentStmt = $db->prepare('SELECT name FROM departments WHERE id=?');
        $departmentStmt->execute([$newDepartmentId]);
        $newDepartmentName = (string)$departmentStmt->fetchColumn();
        if (
            !$reassigned
            || (int)$reassigned['department_id'] !== $newDepartmentId
            || $reassigned['specialty'] !== $newDepartmentName
        ) {
            throw new RuntimeException('Department assignment did not synchronize department_id and specialty.');
        }

        $testDeactivate = $management->toggleDoctorActive($createdDoctorId, $adminId);
        $testReactivate = $management->toggleDoctorActive($createdDoctorId, $adminId);
        if (
            (int)$testDeactivate['is_active'] !== 0
            || (int)$testReactivate['is_active'] !== 1
            || !$testDeactivate['changed']
            || !$testReactivate['changed']
        ) {
            throw new RuntimeException('New doctor deactivate/reactivate transitions did not complete.');
        }
        $sessionVersionStmt = $db->prepare('SELECT session_version FROM users WHERE id=?');
        $sessionVersionStmt->execute([$createdDoctorId]);
        if ((int)$sessionVersionStmt->fetchColumn() !== 3) {
            throw new RuntimeException('Doctor deactivation/reactivation did not increment the session version twice.');
        }
        if (
            countDoctorAudit($db, $schema, $createdDoctorId, 'deactivate') !== 1
            || countDoctorAudit($db, $schema, $createdDoctorId, 'activate') !== 1
        ) {
            throw new RuntimeException('Doctor status transitions did not produce matching audit events.');
        }

        $blockedDoctorId = findDoctorWithUnfinishedClinicalWork($db, $schema);
        if ($blockedDoctorId !== null) {
            $blockedStateStmt = $db->prepare('SELECT is_active, session_version FROM users WHERE id=?');
            $blockedStateStmt->execute([$blockedDoctorId]);
            $blockedState = $blockedStateStmt->fetch(PDO::FETCH_ASSOC);
            $refused = false;
            try {
                $management->setDoctorActive($blockedDoctorId, false, $adminId);
            } catch (DomainException $expected) {
                $refused = str_contains($expected->getMessage(), 'active visit');
            }
            $blockedStateStmt->execute([$blockedDoctorId]);
            $blockedAfter = $blockedStateStmt->fetch(PDO::FETCH_ASSOC);
            if (
                !$refused
                || !$blockedAfter
                || (int)$blockedAfter['is_active'] !== (int)$blockedState['is_active']
                || (int)$blockedAfter['session_version'] !== (int)$blockedState['session_version']
            ) {
                throw new RuntimeException('Doctor with unfinished clinical responsibility was not safely blocked from deactivation.');
            }
            echo "UNFINISHED_CLINICAL_WORK_GUARD_OK|{$schema}|doctor={$blockedDoctorId}\n";
        } else {
            echo "UNFINISHED_CLINICAL_WORK_GUARD_NOT_EXERCISED|{$schema}|fixture_has_no_blocking_doctor\n";
        }

        $existingDeactivateAuditBefore = countDoctorAudit($db, $schema, $doctorUserId, 'deactivate');
        $existingArchiveAuditBefore = countDoctorAudit($db, $schema, $doctorUserId, 'archive');
        $beforeArchive = countCancelledForDoctor($db, $schema, $doctorUserId);
        if ($beforeArchive !== $originalCancelledCount) {
            throw new RuntimeException('Cancelled appointment count changed before archive test.');
        }
        $archived = $management->archiveDoctor($doctorUserId, $adminId);
        if (
            (int)$archived['is_active'] !== 0
            || (int)$archived['cancelled_appointments'] !== $cancelledBefore
            || trim((string)($archived['doctor_name'] ?? '')) === ''
        ) {
            throw new RuntimeException('Existing doctor archive did not return its identity or cancel exactly the untouched appointment set.');
        }
        $afterArchive = countCancelledForDoctor($db, $schema, $doctorUserId);
        if ($afterArchive - $beforeArchive !== $cancelledBefore) {
            throw new RuntimeException('Doctor archive did not persist the expected cancellation count in the transaction.');
        }
        if (
            countDoctorAudit($db, $schema, $doctorUserId, 'archive') !== $existingArchiveAuditBefore + 1
            || countDoctorAudit($db, $schema, $doctorUserId, 'deactivate') !== $existingDeactivateAuditBefore
        ) {
            throw new RuntimeException('Doctor archive did not preserve the archive audit action without creating a deactivate action.');
        }
        if ($schema === 'v2') {
            $activeRequestStmt = $db->prepare(
                "SELECT COUNT(*) FROM appointment_reschedule_requests r
                 JOIN appointments a ON a.id=r.appointment_id
                 WHERE a.doctor_user_id=? AND a.booking_status='cancelled'
                   AND r.request_status IN ('requested','suggested')"
            );
            $activeRequestStmt->execute([$doctorUserId]);
            if ((int)$activeRequestStmt->fetchColumn() !== 0) {
                throw new RuntimeException('Archive left an active v2 reschedule request on a cancelled appointment.');
            }
            $eventStmt = $db->prepare(
                "SELECT COUNT(*) FROM appointment_events
                 WHERE actor_user_id=? AND event_type='doctor_account_disabled'"
            );
            $eventStmt->execute([$adminId]);
            if ((int)$eventStmt->fetchColumn() < $cancelledBefore) {
                throw new RuntimeException('Archive did not write appointment cancellation events.');
            }
        }
        $reactivated = $management->setDoctorActive($doctorUserId, true, $adminId);
        if ((int)$reactivated['is_active'] !== 1) {
            throw new RuntimeException('Existing doctor reactivation failed.');
        }

        // The repository must never commit the test's caller-owned transaction.
        if (!$db->inTransaction()) {
            throw new RuntimeException('Doctor-management operations committed the caller-owned transaction.');
        }

        echo "DOCTOR_CREATE_UPDATE_ASSIGN_OK|{$schema}|id={$createdDoctorId}\n";
        echo "DOCTOR_DEACTIVATE_REACTIVATE_OK|{$schema}|synthetic_new_account\n";
        echo "DOCTOR_ARCHIVE_CANCEL_REACTIVATE_OK|{$schema}|doctor={$doctorUserId}|cancelled={$cancelledBefore}\n";
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    // Transactional rows must be gone/restored. Restore counters that MariaDB
    // advances even when an INSERT transaction is rolled back.
    restoreAutoIncrement($db, $autoBefore);

    $createdCheck = $db->prepare('SELECT id FROM users WHERE email IN (?, ?) LIMIT 1');
    $createdCheck->execute([
        'compat-doctor-' . ($schema === 'v1' ? 'v1' : 'v2') . '@example.invalid',
        'compat-doctor-updated-' . ($schema === 'v1' ? 'v1' : 'v2') . '@example.invalid',
    ]);
    if ($createdCheck->fetchColumn()) {
        throw new RuntimeException("Created doctor account was not rolled back in {$database}.");
    }

    $activeStmt = $db->prepare('SELECT is_active FROM users WHERE id=? AND role=\'doctor\'');
    $activeStmt->execute([$doctorUserId]);
    if ((int)$activeStmt->fetchColumn() !== 1) {
        throw new RuntimeException("Archive test doctor was not restored to active after rollback in {$database}.");
    }
    if (countCancelledForDoctor($db, $schema, $doctorUserId) !== $originalCancelledCount) {
        throw new RuntimeException("Appointment cancellations were not rolled back in {$database}.");
    }

    $autoAfter = autoIncrementSnapshot($db, $autoTables);
    if ($autoBefore !== $autoAfter) {
        throw new RuntimeException("AUTO_INCREMENT values changed after rollback in {$database}: "
            . json_encode(['before' => $autoBefore, 'after' => $autoAfter]));
    }

    echo "FIXTURE_ROLLBACK_AND_COUNTER_RESTORE_OK|{$database}\n";
}

try {
    echo "=== HealthBridge doctor-management v1/v2 contract ===\n";
    runDoctorManagementContract(DOCTOR_MGMT_V1, 'v1', $host, $port, $user, $password);
    runDoctorManagementContract(DOCTOR_MGMT_V2, 'v2', $host, $port, $user, $password);
    echo "RESULT=PASS (both fixtures rolled back; auto-increment counters restored)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "DOCTOR MANAGEMENT CONTRACT FAILED/BLOCKED: " . get_class($e) . "\n");
    fwrite(STDERR, "The test rolls back any active transaction before exit.\n");
    exit(2);
}
