<?php
declare(strict_types=1);

/**
 * Prescription v1/v2 compatibility contract.
 *
 * Read parity covers migrated headers, medication lines, list/filter DTOs and
 * patient-portal prescription cards. Mutation scenarios run only against the
 * exact disposable local fixtures, suppress prompt outbox dispatch, disable
 * the test recipient's appointment notifications, and explicitly clean all
 * synthetic rows plus AUTO_INCREMENT high-water values.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const RX_V1_FIXTURE = 'healthbridge_hb_auth_email_verify_20261114';
const RX_V2_FIXTURE = 'healthbridge_v2_prototype_20261118';

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

require_once __DIR__ . '/../services/PrescriptionService.php';
require_once __DIR__ . '/../services/PatientPortalService.php';

final class PrescriptionTestService extends PrescriptionService
{
    protected function dispatchNotificationOutbox(): void
    {
        // The CLI test must not drain unrelated fixture outbox events.
    }
}

function rxConnect(string $database, string $host, string $port, string $user, string $password): PDO
{
    if (!in_array($database, [RX_V1_FIXTURE, RX_V2_FIXTURE], true)) {
        throw new RuntimeException('Database is not on the exact prescription fixture allowlist.');
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
        throw new RuntimeException('Connected schema did not match the exact prescription fixture.');
    }
    return $db;
}

function rxAutoIncrement(PDO $db, array $tables): array
{
    $stmt = $db->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
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

function rxRestoreAutoIncrement(PDO $db, array $snapshot): void
{
    foreach ($snapshot as $table => $value) {
        if (!preg_match('/^[a-z_]+$/', $table) || $value < 1) {
            throw new RuntimeException('Invalid AUTO_INCREMENT restoration metadata.');
        }
        $db->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = " . (int)$value);
    }
}

function rxAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rxCompareHeaders(array $left, array $right): void
{
    $keys = [
        'id', 'patient_id', 'doctor_id', 'appointment_id', 'notes',
        'status', 'cancellation_reason', 'created_at', 'updated_at',
    ];
    foreach ($keys as $key) {
        if (($left[$key] ?? null) !== ($right[$key] ?? null)
            && (string)($left[$key] ?? '') !== (string)($right[$key] ?? '')) {
            throw new RuntimeException("Prescription header mismatch at {$key} for ID " . ($left['id'] ?? '?'));
        }
    }
}

function rxCompareItems(array $left, array $right, int $prescriptionId): void
{
    $keys = [
        'id', 'prescription_id', 'medication_name', 'strength', 'dosage',
        'frequency', 'duration', 'instructions', 'sort_order',
    ];
    if (count($left) !== count($right)) {
        throw new RuntimeException("Medication item count mismatch for prescription {$prescriptionId}.");
    }
    foreach ($left as $index => $item) {
        foreach ($keys as $key) {
            $a = $item[$key] ?? null;
            $b = $right[$index][$key] ?? null;
            if ((string)($a ?? '') !== (string)($b ?? '')) {
                throw new RuntimeException("Medication item mismatch for prescription {$prescriptionId}, {$key}.");
            }
        }
    }
}

function rxFindCandidateAppointments(PDO $v1, PDO $v2): array
{
    $v1Rows = $v1->query(
        "SELECT a.id, a.user_id AS patient_id, a.doctor_id
         FROM appointments a
         JOIN users pu ON pu.id = a.user_id AND pu.role = 'patient'
         JOIN users du ON du.id = a.doctor_id AND du.role = 'doctor' AND du.is_active = 1
         LEFT JOIN prescriptions p ON p.appointment_id = a.id
         WHERE a.status = 'Confirmed' AND p.id IS NULL
         ORDER BY a.id"
    )->fetchAll();

    $v2Rows = $v2->query(
        "SELECT a.id, a.patient_user_id AS patient_id, a.doctor_user_id AS doctor_id
         FROM appointments a
         JOIN users pu ON pu.id = a.patient_user_id AND pu.role = 'patient'
         JOIN users du ON du.id = a.doctor_user_id AND du.role = 'doctor' AND du.is_active = 1
         JOIN doctor_profiles dp ON dp.doctor_user_id = a.doctor_user_id
         LEFT JOIN prescriptions p ON p.appointment_id = a.id
         LEFT JOIN appointment_reschedule_requests rr
           ON rr.appointment_id = a.id AND rr.request_status IN ('requested','suggested')
         WHERE a.booking_status = 'confirmed' AND p.id IS NULL AND rr.id IS NULL
         ORDER BY a.id"
    )->fetchAll();

    $v2ById = [];
    foreach ($v2Rows as $row) {
        $v2ById[(int)$row['id']] = $row;
    }
    $matches = [];
    foreach ($v1Rows as $row) {
        $id = (int)$row['id'];
        if (isset($v2ById[$id])
            && (int)$row['patient_id'] === (int)$v2ById[$id]['patient_id']
            && (int)$row['doctor_id'] === (int)$v2ById[$id]['doctor_id']) {
            $matches[] = [
                'appointment_id' => $id,
                'patient_id' => (int)$row['patient_id'],
                'doctor_id' => (int)$row['doctor_id'],
            ];
        }
    }
    if (count($matches) < 3) {
        throw new RuntimeException('Fewer than three matching confirmed, unprescribed, non-reschedule appointments are available.');
    }
    return array_slice($matches, 0, 3);
}

function rxSetNotificationsDisabled(PDO $db, int $patientId): ?int
{
    $stmt = $db->prepare('SELECT notif_appointment FROM user_preferences WHERE user_id = ?');
    $stmt->execute([$patientId]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        $db->prepare('INSERT INTO user_preferences (user_id, notif_appointment) VALUES (?, 0)')
            ->execute([$patientId]);
        return null;
    }
    $db->prepare('UPDATE user_preferences SET notif_appointment = 0 WHERE user_id = ?')
        ->execute([$patientId]);
    return (int)$value;
}

function rxRestoreNotifications(PDO $db, int $patientId, ?int $oldValue): void
{
    if ($oldValue === null) {
        $db->prepare('DELETE FROM user_preferences WHERE user_id = ?')->execute([$patientId]);
    } else {
        $db->prepare('UPDATE user_preferences SET notif_appointment = ? WHERE user_id = ?')
            ->execute([$oldValue, $patientId]);
    }
}

function rxRunMutationSuite(PDO $db, string $schema, array $appointments): void
{
    $tables = $schema === 'v1'
        ? ['prescriptions', 'prescription_items', 'admin_audit']
        : ['prescriptions', 'prescription_items', 'audit_events'];
    $auto = rxAutoIncrement($db, $tables);
    $patientIds = array_values(array_unique(array_map(
        static fn(array $appointment): int => (int)$appointment['patient_id'],
        $appointments
    )));
    $oldPreferences = [];
    foreach ($patientIds as $patientId) {
        $oldPreferences[$patientId] = rxSetNotificationsDisabled($db, $patientId);
    }
    $createdIds = [];

    try {
        $service = new PrescriptionTestService($db, $schema);
        $items = [[
            'medication_name' => 'Compatibility Test Medication',
            'strength' => '250 mg',
            'dosage' => '1 tablet',
            'frequency' => 'Twice daily',
            'duration' => '5 days',
            'instructions' => 'Take after meals',
        ]];

        $updateId = $service->create(
            (int)$appointments[0]['patient_id'],
            (int)$appointments[0]['doctor_id'],
            (int)$appointments[0]['appointment_id'],
            $items,
            'synthetic prescription test'
        );
        $createdIds[] = $updateId;
        rxAssert($updateId > 0, "{$schema}: create did not return an ID.");
        rxAssert($service->update($updateId, [[
            'medication_name' => 'Updated Test Medication',
            'strength' => '500 mg',
            'dosage' => '1 capsule',
            'frequency' => 'Once daily',
            'duration' => '7 days',
            'instructions' => null,
        ]], 'updated synthetic notes', (int)$appointments[0]['doctor_id']), "{$schema}: update failed.");

        $updated = $service->get($updateId);
        rxAssert($updated !== null, "{$schema}: updated prescription could not be read.");
        rxAssert($updated['status'] === 'Active', "{$schema}: update changed the status.");
        rxAssert($updated['notes'] === 'updated synthetic notes', "{$schema}: update notes mismatch.");
        rxAssert(($updated['items'][0]['medication_name'] ?? null) === 'Updated Test Medication', "{$schema}: replacement item mismatch.");

        rxAssert($service->complete($updateId, (int)$appointments[0]['doctor_id']), "{$schema}: complete failed.");
        $completed = $service->get($updateId);
        rxAssert(($completed['status'] ?? null) === 'Completed', "{$schema}: completed status did not normalize.");

        $cancelId = $service->create(
            (int)$appointments[1]['patient_id'],
            (int)$appointments[1]['doctor_id'],
            (int)$appointments[1]['appointment_id'],
            $items,
            'synthetic cancellation test'
        );
        $createdIds[] = $cancelId;
        rxAssert($service->cancel($cancelId, (int)$appointments[1]['doctor_id'], 'Synthetic rollback-test reason'), "{$schema}: cancel failed.");
        $cancelled = $service->get($cancelId);
        rxAssert(($cancelled['status'] ?? null) === 'Cancelled', "{$schema}: cancelled status did not normalize.");
        rxAssert(($cancelled['cancellation_reason'] ?? null) === 'Synthetic rollback-test reason', "{$schema}: cancellation reason mismatch.");

        $transactionalId = $service->create(
            (int)$appointments[2]['patient_id'],
            (int)$appointments[2]['doctor_id'],
            (int)$appointments[2]['appointment_id'],
            $items,
            'synthetic outer-transaction test'
        );
        $createdIds[] = $transactionalId;
        $db->beginTransaction();
        try {
            $changed = $service->completeForAppointmentInTransaction(
                (int)$appointments[2]['appointment_id'],
                (int)$appointments[2]['doctor_id']
            );
            rxAssert($changed === 1, "{$schema}: caller-owned appointment completion did not update exactly one prescription.");
            $db->rollBack();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
        $afterRollback = $service->get($transactionalId);
        rxAssert(($afterRollback['status'] ?? null) === 'Active', "{$schema}: caller-owned completion did not roll back.");

        $wrongDoctor = $service->update($updateId, $items, null, 2147483000);
        rxAssert($wrongDoctor === false, "{$schema}: prescription update accepted a non-owner doctor.");
        echo "MUTATIONS|{$schema}|create-update-complete-cancel-outer-rollback|PASS\n";
    } finally {
        if ($createdIds !== []) {
            $placeholders = implode(',', array_fill(0, count($createdIds), '?'));
            $db->prepare("DELETE FROM prescription_items WHERE prescription_id IN ({$placeholders})")->execute($createdIds);
            if ($schema === 'v1') {
                $db->prepare(
                    "DELETE FROM admin_audit WHERE entity_type = 'prescription' AND entity_id IN ({$placeholders})"
                )->execute($createdIds);
            } else {
                $db->prepare(
                    "DELETE FROM audit_events WHERE entity_type = 'prescription' AND entity_id IN ({$placeholders})"
                )->execute(array_map('strval', $createdIds));
            }
            $db->prepare("DELETE FROM prescriptions WHERE id IN ({$placeholders})")->execute($createdIds);
        }
        foreach ($oldPreferences as $patientId => $oldPreference) {
            rxRestoreNotifications($db, (int)$patientId, $oldPreference);
        }
        rxRestoreAutoIncrement($db, $auto);
    }

    $leftovers = $db->query(
        "SELECT COUNT(*) FROM prescriptions p
         WHERE p.notes LIKE 'synthetic %' AND p.id >= " . (int)($auto['prescriptions'] ?? 1)
    )->fetchColumn();
    rxAssert((int)$leftovers === 0, "{$schema}: synthetic prescription rows remain after cleanup.");
}

try {
    $v1 = rxConnect(RX_V1_FIXTURE, $host, $port, $user, $password);
    $v2 = rxConnect(RX_V2_FIXTURE, $host, $port, $user, $password);

    $v1Service = new PrescriptionService($v1, 'v1');
    $v2Service = new PrescriptionService($v2, 'v2');
    $v1Rows = $v1Service->search([]);
    $v2Rows = $v2Service->search([]);
    rxAssert(count($v1Rows) === 99 && count($v2Rows) === 99, 'Expected 99 migrated prescription headers in each fixture.');
    foreach ($v1Rows as $index => $left) {
        $right = $v2Rows[$index] ?? null;
        rxAssert($right !== null, 'Missing v2 prescription list row.');
        rxCompareHeaders($left, $right);
        rxCompareItems($left['items'] ?? [], $right['items'] ?? [], (int)$left['id']);
    }

    $patientIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['patient_id'], $v1Rows)));
    $doctorIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['doctor_id'], $v1Rows)));
    foreach (array_slice($patientIds, 0, 12) as $patientId) {
        $left = $v1Service->getByPatient($patientId);
        $right = $v2Service->getByPatient($patientId);
        rxAssert(array_column($left, 'id') === array_column($right, 'id'), 'Patient prescription list IDs differ.');
    }
    foreach (array_slice($doctorIds, 0, 12) as $doctorId) {
        $left = $v1Service->getByDoctor($doctorId);
        $right = $v2Service->getByDoctor($doctorId);
        rxAssert(array_column($left, 'id') === array_column($right, 'id'), 'Doctor prescription list IDs differ.');
    }
    rxAssert(array_column($v1Service->getAll(), 'id') === array_column($v2Service->getAll(), 'id'), 'Admin prescription list IDs differ.');

    foreach (['Active', 'Completed', 'Cancelled'] as $status) {
        $left = $v1Service->search(['status' => $status]);
        $right = $v2Service->search(['status' => $status]);
        rxAssert(array_column($left, 'id') === array_column($right, 'id'), "Status-filter IDs differ for {$status}.");
    }

    $v1Portal = (new PatientPortalService($v1, 'v1'));
    $v2Portal = (new PatientPortalService($v2, 'v2'));
    foreach (array_slice($patientIds, 0, 12) as $patientId) {
        $left = $v1Portal->getPrescriptions($patientId);
        $right = $v2Portal->getPrescriptions($patientId);
        rxAssert(array_column($left['prescriptions'], 'id') === array_column($right['prescriptions'], 'id'), 'Patient portal prescription card IDs differ.');
        foreach ($left['prescriptions'] as $index => $prescription) {
            rxAssert($prescription['status'] === $right['prescriptions'][$index]['status'], 'Patient portal prescription status differs.');
            rxAssert($prescription['items_count'] === $right['prescriptions'][$index]['items_count'], 'Patient portal medication item count differs.');
        }
    }

    echo "READ_PARITY|99_headers|186_items|patient_doctor_admin_lists|3_status_filters|patient_portal_cards|PASS\n";

    $appointments = rxFindCandidateAppointments($v1, $v2);
    rxRunMutationSuite($v1, 'v1', $appointments);
    rxRunMutationSuite($v2, 'v2', $appointments);

    echo "PRESCRIPTION_COMPATIBILITY_SUITE=PASS\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "PRESCRIPTION_COMPATIBILITY_SUITE=FAIL\n" . $error->getMessage() . "\n");
    exit(1);
}
