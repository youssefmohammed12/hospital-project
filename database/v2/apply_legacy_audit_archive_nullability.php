<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}
require_once __DIR__ . '/../../includes/db.php';

$sourceSchema = (string)(getenv('HEALTHBRIDGE_ETL_SOURCE_SCHEMA') ?: '');
$targetSchema = (string)(getenv('HEALTHBRIDGE_ETL_TARGET_SCHEMA') ?: '');
if (!preg_match('/\Ahealthbridge_hb_etl_source_[a-z0-9_]+\z/D', $sourceSchema)
    || !preg_match('/\Ahealthbridge_v2_etl_target_[a-z0-9_]+\z/D', $targetSchema)
    || $sourceSchema === $targetSchema
    || !defined('DB_NAME')
    || DB_NAME !== $sourceSchema) {
    fwrite(STDERR, "Refusing: use exact disposable ETL source and target schema names.\n");
    exit(11);
}
const MIGRATION_VERSION = '2.0.7-legacy-audit-archive-nullability';

try {
    $etlHost = strtolower(trim((string)DB_HOST, '[]'));
    if (!in_array($etlHost, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Refusing non-loopback ETL database host.');
    }
    $db = getDB();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $sourceSchema) {
        throw new RuntimeException('Connection is not the exact disposable source clone.');
    }
    $db->exec('USE ' . $targetSchema);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $targetSchema) {
        throw new RuntimeException('Could not select the exact disposable V2 target clone.');
    }

    $q = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $q->execute([MIGRATION_VERSION]);
    if ((int)$q->fetchColumn() !== 0) {
        throw new RuntimeException('Migration is already recorded; refusing replay.');
    }

    $column = $db->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_schedule_audit_log'
           AND COLUMN_NAME='description'"
    )->fetchColumn();
    if ($column === false) {
        throw new RuntimeException('Target legacy_schedule_audit_log.description is missing.');
    }
    if ($column !== 'YES') {
        $sql = file_get_contents(__DIR__ . '/007_legacy_audit_archive_nullability.sql');
        if (!is_string($sql) || trim($sql) === '') {
            throw new RuntimeException('Migration SQL is missing or empty.');
        }
        $statement = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statement = trim($statement);
        if (!preg_match('/^ALTER TABLE\s+legacy_schedule_audit_log\b/i', $statement)) {
            throw new RuntimeException('Migration SQL has an unexpected statement.');
        }
        $db->exec($statement);
    }

    $verify = $db->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_schedule_audit_log'
           AND COLUMN_NAME='description'"
    )->fetchColumn();
    if ($verify !== 'YES') {
        throw new RuntimeException('Nullable description migration verification failed.');
    }

    $path = __DIR__ . '/007_legacy_audit_archive_nullability.sql';
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) {
        throw new RuntimeException('Could not hash migration file.');
    }
    $insert = $db->prepare(
        'INSERT INTO schema_migrations(version,checksum_sha256,description,engine_minimum)
         VALUES (?,?,?,?)'
    );
    $insert->execute([
        MIGRATION_VERSION,
        $hash,
        'Preserve nullable description values in the historical schedule audit archive',
        'MariaDB 10.11+',
    ]);

    echo "V2_LEGACY_AUDIT_ARCHIVE_NULLABILITY_PASS\n";
    echo "source_schema={$sourceSchema}\n";
    echo "target_schema={$targetSchema}\n";
    echo "description_nullable=YES\n";
    echo "sha256={$hash}\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'V2_LEGACY_AUDIT_ARCHIVE_NULLABILITY_FAILED: ' . $error->getMessage() . "\n");
    exit(1);
}
