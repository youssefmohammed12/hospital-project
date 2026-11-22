<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(10);
require_once __DIR__ . '/../../includes/db.php';
const SOURCE_SCHEMA = 'healthbridge_hb_auth_email_verify_20261114';
const TARGET_SCHEMA = 'healthbridge_v2_prototype_20261118';
try {
    $db = getDB();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== SOURCE_SCHEMA) {
        throw new RuntimeException('Wrong disposable source schema.');
    }
    $db->exec('USE ' . TARGET_SCHEMA);
    $version = '2.0.1-prototype';
    $q = $db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $q->execute([$version]);
    if ((int)$q->fetchColumn() !== 0) throw new RuntimeException('Migration version already exists; refusing replay.');
    $path = __DIR__ . '/002_appointment_legacy_snapshots.sql';
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql)==='') throw new RuntimeException('Migration file missing.');
    $sql = preg_replace('/^\\xEF\\xBB\\xBF/', '', $sql) ?? $sql;
    $sql = preg_replace('/^\\s*--.*$/m', '', $sql) ?? $sql;
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
    foreach ($statements as $i=>$statement) {
        if (!preg_match('/^ALTER TABLE\\s+appointments\\b/i', $statement)) {
            throw new RuntimeException('Unexpected SQL statement at position '.($i+1));
        }
        $db->exec($statement);
    }
    $hash = hash_file('sha256', $path);
    if (!is_string($hash)) throw new RuntimeException('Could not hash migration.');
    $q = $db->prepare(
        'INSERT INTO schema_migrations (version,checksum_sha256,description,engine_minimum)
         VALUES (?,?,?,?)'
    );
    $q->execute([$version,$hash,'Preserve source appointment time strings for audit/round-trip','MariaDB 10.11+']);
    echo "V2_INCREMENTAL_MIGRATION_PASS\nversion={$version}\nstatements=".count($statements)."\nsha256={$hash}\n";
} catch (Throwable $error) {
    fwrite(STDERR,'V2_INCREMENTAL_MIGRATION_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
