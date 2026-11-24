<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const RV_V1_DB = 'healthbridge_hb_auth_email_verify_20261114';
const RV_V2_DB = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$passwordValue = getenv('DB_PASS');
$password = $passwordValue === false ? '' : (string)$passwordValue;

if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB host.\n");
    exit(11);
}

require_once __DIR__ . '/../services/Repositories/RatingRepository.php';

function rvConnect(string $database): PDO
{
    global $host, $port, $user, $password;
    if (!in_array($database, [RV_V1_DB, RV_V2_DB], true)) {
        throw new RuntimeException('Database is not allowlisted.');
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
        throw new RuntimeException('Database identity mismatch.');
    }
    return $db;
}

function rvExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rvCounter(PDO $db, string $table): int
{
    if (!preg_match('/^(admin_audit|audit_events)$/', $table)) {
        throw new InvalidArgumentException('Unapproved counter table.');
    }
    $stmt = $db->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $stmt->execute([$table]);
    $value = $stmt->fetchColumn();
    return $value === false || $value === null ? 0 : (int)$value;
}

function rvRestoreCounter(PDO $db, string $table, int $value): void
{
    if ($value <= 0 || !preg_match('/^(admin_audit|audit_events)$/', $table)) {
        return;
    }
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}

function rvCandidate(PDO $db, string $schema): array
{
    $sql = $schema === RatingRepository::SCHEMA_V1
        ? 'SELECT id,appointment_id,user_id AS patient_user_id,doctor_id AS doctor_user_id
           FROM ratings WHERE is_hidden=0 ORDER BY id LIMIT 1'
        : 'SELECT id,appointment_id,patient_user_id,doctor_user_id
           FROM ratings WHERE is_public=1 ORDER BY id LIMIT 1';
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException("No currently public rating exists in {$schema} fixture.");
    }
    return [
        'id' => (int)$row['id'],
        'appointment_id' => (int)$row['appointment_id'],
        'patient_user_id' => (int)$row['patient_user_id'],
        'doctor_user_id' => (int)$row['doctor_user_id'],
    ];
}

function rvSummary(PDO $db, string $schema, int $doctorId): array
{
    if ($schema === RatingRepository::SCHEMA_V1) {
        $stmt = $db->prepare(
            'SELECT ROUND(AVG(stars),1) AS average_rating,COUNT(*) AS rating_count
             FROM ratings WHERE doctor_id=? AND is_hidden=0'
        );
    } else {
        $stmt = $db->prepare(
            'SELECT average_rating,rating_count
             FROM v_doctor_rating_summary WHERE doctor_user_id=?'
        );
    }
    $stmt->execute([$doctorId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: ['average_rating' => 0, 'rating_count' => 0];
}

function rvAuditCount(PDO $db, string $schema, int $adminId, int $ratingId): int
{
    if ($schema === RatingRepository::SCHEMA_V1) {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM admin_audit
             WHERE actor_id=? AND entity_type='rating' AND entity_id=?
               AND action IN ('hide','restore')"
        );
    } else {
        $stmt = $db->prepare(
            "SELECT COUNT(*) FROM audit_events
             WHERE actor_user_id=? AND entity_type='rating' AND entity_id=?
               AND action_code IN ('hide','restore')"
        );
    }
    $stmt->execute([$adminId, (string)$ratingId]);
    return (int)$stmt->fetchColumn();
}

function rvFindById(array $rows, int $ratingId): ?array
{
    foreach ($rows as $row) {
        if ((int)($row['id'] ?? 0) === $ratingId) {
            return $row;
        }
    }
    return null;
}

function rvLegacyReadOnly(PDO $db): void
{
    $row = $db->query(
        'SELECT id,appointment_id,user_id AS patient_user_id,doctor_id AS doctor_user_id
         FROM ratings ORDER BY id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new RuntimeException('V1 fixture has no rating rows for read compatibility checks.');
    }

    $repo = new RatingRepository($db, RatingRepository::SCHEMA_V1);
    $doctorRows = $repo->getRatingsForDoctor((int)$row['doctor_user_id']);
    $adminRows = $repo->getAllRatings();
    $patient = $repo->getAppointmentRating((int)$row['appointment_id'], (int)$row['patient_user_id']);
    $doctorRow = rvFindById($doctorRows, (int)$row['id']);
    $adminRow = rvFindById($adminRows, (int)$row['id']);

    rvExpect($doctorRow !== null && (int)$doctorRow['is_hidden'] === 0 && (int)$doctorRow['is_public'] === 1,
        'V1 older-shape doctor rating DTO must default to public.');
    rvExpect($adminRow !== null && (int)$adminRow['is_hidden'] === 0 && (int)$adminRow['is_public'] === 1,
        'V1 older-shape admin rating DTO must default to public.');
    rvExpect($patient !== null && (int)$patient['is_hidden'] === 0 && (int)$patient['is_public'] === 1,
        'V1 older-shape patient rating DTO must default to public.');
}

function rvRun(string $database, string $schema): void
{
    $db = rvConnect($database);
    if ($schema === RatingRepository::SCHEMA_V1) {
        $column = $db->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ratings' AND COLUMN_NAME='is_hidden'"
        )->fetchColumn();
        if ((int)$column !== 1) {
            rvLegacyReadOnly($db);
            echo "RATING_VISIBILITY_V1_MUTATION_SKIPPED|fixture_has_no_is_hidden_column;legacy_read_DTOs_OK\n";
            return;
        }
    }
    $candidate = rvCandidate($db, $schema);

    $adminId = (int)$db->query(
        "SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1"
    )->fetchColumn();
    $nonAdminId = (int)$db->query(
        "SELECT id FROM users WHERE role='doctor' AND is_active=1 ORDER BY id LIMIT 1"
    )->fetchColumn();
    rvExpect($adminId > 0, "{$schema}: active admin fixture account missing.");
    rvExpect($nonAdminId > 0, "{$schema}: active non-admin fixture account missing.");

    $repo = new RatingRepository($db, $schema);
    $baselineSummary = rvSummary($db, $schema, $candidate['doctor_user_id']);
    $baselineAudit = rvAuditCount($db, $schema, $adminId, $candidate['id']);
    $auditTable = $schema === RatingRepository::SCHEMA_V1 ? 'admin_audit' : 'audit_events';
    $auditCounter = rvCounter($db, $auditTable);

    $db->beginTransaction();
    try {
        $hidden = $repo->setPublicVisibility($candidate['id'], false, $adminId);
        rvExpect($hidden['changed'] === true, "{$schema}: hide did not report a state change.");
        rvExpect($hidden['is_public'] === false && $hidden['is_hidden'] === true, "{$schema}: hide DTO incorrect.");

        $hiddenSummary = rvSummary($db, $schema, $candidate['doctor_user_id']);
        rvExpect(
            (int)$hiddenSummary['rating_count'] === max(0, (int)$baselineSummary['rating_count'] - 1),
            "{$schema}: hidden rating still contributes to the public count."
        );

        $doctorRows = $repo->getRatingsForDoctor($candidate['doctor_user_id']);
        $adminRows = $repo->getAllRatings();
        $patientRating = $repo->getAppointmentRating($candidate['appointment_id'], $candidate['patient_user_id']);
        $doctorRow = rvFindById($doctorRows, $candidate['id']);
        $adminRow = rvFindById($adminRows, $candidate['id']);

        rvExpect($doctorRow !== null && (int)$doctorRow['is_hidden'] === 1, "{$schema}: private doctor list lost or mislabelled hidden rating.");
        rvExpect($adminRow !== null && (int)$adminRow['is_hidden'] === 1, "{$schema}: admin list lost or mislabelled hidden rating.");
        rvExpect($patientRating !== null && (int)$patientRating['is_hidden'] === 1, "{$schema}: patient's own rating was hidden from their record.");
        rvExpect(
            rvAuditCount($db, $schema, $adminId, $candidate['id']) === $baselineAudit + 1,
            "{$schema}: hide did not write exactly one audit event."
        );

        $sameState = $repo->setPublicVisibility($candidate['id'], false, $adminId);
        rvExpect($sameState['changed'] === false, "{$schema}: repeated hide was not idempotent.");
        rvExpect(
            rvAuditCount($db, $schema, $adminId, $candidate['id']) === $baselineAudit + 1,
            "{$schema}: repeated hide wrote a duplicate audit event."
        );

        $restored = $repo->setPublicVisibility($candidate['id'], true, $adminId);
        rvExpect($restored['changed'] === true, "{$schema}: restore did not report a state change.");
        rvExpect($restored['is_public'] === true && $restored['is_hidden'] === false, "{$schema}: restore DTO incorrect.");
        $restoredSummary = rvSummary($db, $schema, $candidate['doctor_user_id']);
        rvExpect(
            (int)$restoredSummary['rating_count'] === (int)$baselineSummary['rating_count']
                && (float)$restoredSummary['average_rating'] === (float)$baselineSummary['average_rating'],
            "{$schema}: restore did not reinstate the original public aggregate."
        );
        rvExpect(
            rvAuditCount($db, $schema, $adminId, $candidate['id']) === $baselineAudit + 2,
            "{$schema}: restore did not write exactly one additional audit event."
        );

        $unauthorized = false;
        try {
            $repo->setPublicVisibility($candidate['id'], false, $nonAdminId);
        } catch (DomainException $error) {
            $unauthorized = str_contains($error->getMessage(), 'administrator');
        }
        rvExpect($unauthorized, "{$schema}: non-admin visibility change was not rejected.");

        $notFound = false;
        try {
            $repo->setPublicVisibility(4_000_000_000, false, $adminId);
        } catch (OutOfBoundsException $error) {
            $notFound = str_contains($error->getMessage(), 'not found');
        }
        rvExpect($notFound, "{$schema}: nonexistent rating was not rejected.");

        rvExpect($db->inTransaction(), "{$schema}: repository committed the caller-owned transaction.");
        echo "RATING_VISIBILITY_HIDE_RESTORE_PRIVATE_READS_AUTH_AUDIT_OK|{$schema}|rating={$candidate['id']}\n";
    } finally {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
    }

    rvRestoreCounter($db, $auditTable, $auditCounter);

    if (rvSummary($db, $schema, $candidate['doctor_user_id']) != $baselineSummary) {
        throw new RuntimeException("{$schema}: rating aggregate did not roll back.");
    }
    $finalAudit = rvAuditCount($db, $schema, $adminId, $candidate['id']);
    if ($finalAudit !== $baselineAudit) {
        throw new RuntimeException("{$schema}: visibility audit events did not roll back.");
    }
    $rating = $schema === RatingRepository::SCHEMA_V1
        ? $db->prepare('SELECT is_hidden FROM ratings WHERE id=?')
        : $db->prepare('SELECT (1-is_public) AS is_hidden FROM ratings WHERE id=?');
    $rating->execute([$candidate['id']]);
    if ((int)$rating->fetchColumn() !== 0) {
        throw new RuntimeException("{$schema}: rating visibility did not roll back.");
    }
    if ($auditCounter > 0 && rvCounter($db, $auditTable) !== $auditCounter) {
        throw new RuntimeException("{$schema}: {$auditTable} AUTO_INCREMENT counter was not restored.");
    }

    echo "RATING_VISIBILITY_ROLLBACK_AND_COUNTER_RESTORE_OK|{$database}\n";
}

try {
    echo "=== Rating visibility V1/V2 rollback contract ===\n";
    rvRun(RV_V1_DB, RatingRepository::SCHEMA_V1);
    rvRun(RV_V2_DB, RatingRepository::SCHEMA_V2);
    echo "RESULT=PASS (visibility, private/admin/patient retention, idempotency, authorization, audit, and rollback verified)\n";
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, "RATING_VISIBILITY_CONTRACT_FAILED: " . get_class($error) . "\n" . $error->getMessage() . "\n");
    exit(2);
}
