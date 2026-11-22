<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(10);
require_once __DIR__ . '/../../includes/db.php';
const SOURCE_SCHEMA='healthbridge_hb_auth_email_verify_20261114';
const TARGET_SCHEMA='healthbridge_v2_prototype_20261118';
try {
    $db=getDB();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!==SOURCE_SCHEMA) throw new RuntimeException('Wrong source schema.');
    $db->exec('USE '.TARGET_SCHEMA);
    $version='2.0.2-prototype';
    $q=$db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $q->execute([$version]);
    if ((int)$q->fetchColumn()!==0) throw new RuntimeException('Migration already applied.');
    $path=__DIR__.'/003_legacy_value_archives.sql';
    $sql=file_get_contents($path);
    if (!is_string($sql)||trim($sql)==='') throw new RuntimeException('Migration file missing.');
    $sql=preg_replace('/^\\s*--.*$/m','',$sql)??$sql;
    $parts=array_values(array_filter(array_map('trim',explode(';',$sql))));
    foreach($parts as $part) {
        if(!preg_match('/^CREATE TABLE\\s+(legacy_doctor_profile_state|legacy_user_preferences)\\b/i',$part))
            throw new RuntimeException('Unexpected statement in archive migration.');
        $db->exec($part);
    }
    $hash=hash_file('sha256',$path);
    $q=$db->prepare('INSERT INTO schema_migrations(version,checksum_sha256,description,engine_minimum) VALUES(?,?,?,?)');
    $q->execute([$version,$hash,'Preserve legacy doctor cache and mixed preference fields','MariaDB 10.11+']);
    echo "V2_ARCHIVE_TABLES_PASS\ntables=".count($parts)."\nsha256={$hash}\n";
} catch(Throwable $e) {
    fwrite(STDERR,'V2_ARCHIVE_TABLES_FAILED: '.$e->getMessage()."\n");
    exit(1);
}
