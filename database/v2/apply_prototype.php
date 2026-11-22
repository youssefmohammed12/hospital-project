<?php
declare(strict_types=1);

/**
 * Create the isolated HealthBridge v2 prototype from an exact disposable clone.
 * This script never drops a database and refuses to reuse an existing target.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

require_once __DIR__ . '/../../includes/db.php';

const SOURCE_SCHEMA = 'healthbridge_hb_auth_email_verify_20261114';
const TARGET_SCHEMA = 'healthbridge_v2_prototype_20261118';

try {
    $db = getDB();
    $selected = (string)$db->query('SELECT DATABASE()')->fetchColumn();
    if ($selected !== SOURCE_SCHEMA) {
        throw new RuntimeException('Refusing to run: selected schema is not the exact disposable source clone.');
    }

    $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([TARGET_SCHEMA]);
    if ((int)$exists->fetchColumn() !== 0) {
        throw new RuntimeException('Refusing to overwrite an existing prototype target. Inspect it manually first.');
    }

    $db->exec(
        'CREATE DATABASE ' . TARGET_SCHEMA .
        ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $db->exec('USE ' . TARGET_SCHEMA);

    $schemaPath = __DIR__ . '/001_schema.sql';
    $sql = file_get_contents($schemaPath);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Schema source file is missing or empty.');
    }

    $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));

    $applied = 0;
    foreach ($statements as $index => $statement) {
        if (!preg_match('/^(SET\s+NAMES|CREATE\s+TABLE|CREATE\s+VIEW)\b/i', $statement)) {
            throw new RuntimeException('Unexpected SQL statement at position ' . ($index + 1) . '.');
        }
        try {
            $db->exec($statement);
            $applied++;
        } catch (Throwable $error) {
            throw new RuntimeException(
                'DDL failed at statement ' . ($index + 1) . ' (target left intact for inspection): ' .
                $error->getMessage(),
                0,
                $error
            );
        }
    }

    $tableCount = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    )->fetchColumn();
    $viewCount = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"
    )->fetchColumn();
    $fkCount = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()"
    )->fetchColumn();
    $checkCount = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'CHECK'"
    )->fetchColumn();

    if ($tableCount !== 35 || $viewCount !== 1) {
        throw new RuntimeException(
            "Unexpected schema object count: tables={$tableCount}, views={$viewCount}; target retained for inspection."
        );
    }

    $checksum = hash_file('sha256', $schemaPath);
    if (!is_string($checksum)) {
        throw new RuntimeException('Could not calculate schema source checksum.');
    }

    $insert = $db->prepare(
        'INSERT INTO schema_migrations (version, checksum_sha256, description, engine_minimum)
         VALUES (?, ?, ?, ?)'
    );
    $insert->execute([
        '2.0.0-prototype',
        $checksum,
        'Initial isolated HealthBridge v2 schema prototype',
        'MariaDB 10.11+'
    ]);

    echo "SCHEMA_APPLY_PASS\n";
    echo 'source=' . SOURCE_SCHEMA . "\n";
    echo 'target=' . TARGET_SCHEMA . "\n";
    echo 'ddl_statements=' . $applied . "\n";
    echo 'tables=' . $tableCount . "\n";
    echo 'views=' . $viewCount . "\n";
    echo 'foreign_keys=' . $fkCount . "\n";
    echo 'check_constraints=' . $checkCount . "\n";
    echo 'schema_sha256=' . $checksum . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'SCHEMA_APPLY_FAILED: ' . $error->getMessage() . "\n");
    exit(1);
}
