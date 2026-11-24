<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const SOURCE_SCHEMA = 'healthbridge_hb_auth_email_verify_20261114';
const TARGET_SCHEMA = 'healthbridge_v2_prototype_20261118';
const MIGRATION_VERSION = '2.0.6-rating-visibility';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$passwordValue = getenv('DB_PASS');
$password = $passwordValue === false ? '' : (string)$passwordValue;

if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB host.\\n");
    exit(11);
}

try {
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname=" . SOURCE_SCHEMA . ";charset=utf8mb4",
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 3,
        ]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== SOURCE_SCHEMA) {
        throw new RuntimeException('Refusing to run: source connection is not the approved disposable clone.');
    }
    $db->exec('USE ' . TARGET_SCHEMA);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== TARGET_SCHEMA) {
        throw new RuntimeException('Could not select the exact V2 prototype target.');
    }

    $version = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $version->execute([MIGRATION_VERSION]);
    if ((int)$version->fetchColumn() !== 0) {
        throw new RuntimeException('Rating visibility migration is already recorded; refusing replay.');
    }

    $columnCheck = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $columnCheck->execute(['ratings', 'is_public']);
    $hasPublicColumn = (int)$columnCheck->fetchColumn() === 1;

    $sourceVisibility = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $sourceVisibility->execute([SOURCE_SCHEMA, 'ratings', 'is_hidden']);
    $sourceHasHiddenColumn = (int)$sourceVisibility->fetchColumn() === 1;

    $sourceCount = (int)$db->query('SELECT COUNT(*) FROM ' . SOURCE_SCHEMA . '.ratings')->fetchColumn();
    $targetCount = (int)$db->query('SELECT COUNT(*) FROM ratings')->fetchColumn();
    // The migration may run before ETL on a fresh empty target or after ETL
    // on a populated prototype that needs its legacy visibility flags backfilled.
    if ($targetCount !== 0 && $sourceCount !== $targetCount) {
        throw new RuntimeException("Rating source/target row count mismatch: {$sourceCount}/{$targetCount}.");
    }
    if ($targetCount > 0) {
        $unmatched = (int)$db->query(
            'SELECT COUNT(*) FROM ' . SOURCE_SCHEMA . '.ratings s
             LEFT JOIN ratings t ON t.id=s.id WHERE t.id IS NULL'
        )->fetchColumn();
        if ($unmatched !== 0) {
            throw new RuntimeException("Cannot preserve visibility: {$unmatched} source ratings are not mapped by identity.");
        }
    }

    $path = __DIR__ . '/006_rating_visibility.sql';
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('Rating visibility migration file is missing or empty.');
    }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
    $alterStatement = null;
    $viewStatement = null;
    foreach ($statements as $index => $statement) {
        if (preg_match('/^ALTER TABLE\s+ratings\b/i', $statement)) {
            $alterStatement = $statement;
        } elseif (preg_match('/^CREATE OR REPLACE VIEW\s+v_doctor_rating_summary\b/i', $statement)) {
            $viewStatement = $statement;
        } else {
            throw new RuntimeException('Unexpected SQL statement at position ' . ($index + 1) . '.');
        }
    }
    if (!is_string($alterStatement) || !is_string($viewStatement)) {
        throw new RuntimeException('Rating visibility migration is missing a required DDL statement.');
    }

    if (!$hasPublicColumn) {
        $db->exec($alterStatement);
    } else {
        // Resume safely after a partial DDL run: do not try to add the column twice.
        $indexCheck = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?'
        );
        $indexCheck->execute(['ratings', 'idx_ratings_doctor_public_created']);
        $hasPublicIndex = (int)$indexCheck->fetchColumn() > 0;

        $constraintCheck = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?'
        );
        $constraintCheck->execute(['ratings', 'chk_ratings_is_public']);
        $hasPublicCheck = (int)$constraintCheck->fetchColumn() > 0;

        $missingParts = [];
        if (!$hasPublicCheck) {
            $missingParts[] = 'ADD CONSTRAINT chk_ratings_is_public CHECK (is_public IN (0,1))';
        }
        if (!$hasPublicIndex) {
            $missingParts[] = 'ADD KEY idx_ratings_doctor_public_created (doctor_user_id,is_public,created_at)';
        }
        if ($missingParts) {
            $db->exec('ALTER TABLE ratings ' . implode(', ', $missingParts));
        }
    }
    $db->exec($viewStatement);

    $mismatches = 0;
    if ($targetCount > 0 && $sourceHasHiddenColumn) {
        $db->exec(
            'UPDATE ratings t
             JOIN ' . SOURCE_SCHEMA . '.ratings s ON s.id=t.id
             SET t.is_public=CASE WHEN COALESCE(s.is_hidden,0)=1 THEN 0 ELSE 1 END'
        );
        $mismatches = (int)$db->query(
            'SELECT COUNT(*) FROM ratings t
             JOIN ' . SOURCE_SCHEMA . '.ratings s ON s.id=t.id
             WHERE t.is_public <> CASE WHEN COALESCE(s.is_hidden,0)=1 THEN 0 ELSE 1 END'
        )->fetchColumn();
        if ($mismatches !== 0) {
            throw new RuntimeException("V1 hidden-flag migration parity failed: {$mismatches} mismatches.");
        }
    }

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
        'Preserve per-rating public visibility and filter public doctor rating aggregates',
        'MariaDB 10.11+',
    ]);

    echo "V2_RATING_VISIBILITY_MIGRATION_PASS\n";
    echo "source_rows={$sourceCount}\n";
    echo "target_rows={$targetCount}\n";
    echo "visibility_mismatches={$mismatches}\n";
    echo "sha256={$hash}\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'V2_RATING_VISIBILITY_MIGRATION_FAILED: ' . $error->getMessage() . "\n");
    exit(1);
}
