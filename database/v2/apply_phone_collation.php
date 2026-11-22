<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(10);
require_once __DIR__ . '/../../includes/db.php';
const SOURCE_SCHEMA='healthbridge_hb_auth_email_verify_20261114';
const TARGET_SCHEMA='healthbridge_v2_prototype_20261118';
try {
    $db=getDB();
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==SOURCE_SCHEMA) {
        throw new RuntimeException('Wrong source schema.');
    }
    $db->exec('USE '.TARGET_SCHEMA);
    $version='2.0.5-prototype';
    $q=$db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $q->execute([$version]);
    if((int)$q->fetchColumn()!==0) throw new RuntimeException('Migration already recorded.');
    $before=$db->query(
        "SELECT COUNT(*) FROM users u
         WHERE NOT (u.phone_normalized <=> (
           SELECT NULLIF(
             REPLACE(
               REPLACE(
                 REPLACE(
                   REPLACE(
                     REPLACE(
                       REPLACE(
                         REPLACE(
                           REPLACE(
                             REPLACE(
                               REPLACE(
                                 REPLACE(
                                   CONVERT(TRIM(u2.phone) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                                   _utf8mb4' ' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                                 ),
                                 CONVERT(CHAR(9) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                               ),
                               CONVERT(CHAR(10) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                             ),
                             CONVERT(CHAR(11) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                           ),
                           CONVERT(CHAR(12) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                         ),
                         CONVERT(CHAR(13) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                       ),
                       _utf8mb4'(' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                     ),
                     _utf8mb4')' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                   ),
                   _utf8mb4'.' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
                 ),
                 _utf8mb4'-' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci
               ),
               _utf8mb4'+20' COLLATE utf8mb4_unicode_ci,_utf8mb4'0' COLLATE utf8mb4_unicode_ci
             ),
             _utf8mb4'' COLLATE utf8mb4_unicode_ci
           ) FROM users u2 WHERE u2.id=u.id
         ))"
    )->fetchColumn();
    if((int)$before!==0) throw new RuntimeException('Existing generated values differ from deterministic normalization; found '.$before);
    $path=__DIR__.'/005_phone_normalization_collation.sql';
    $sql=file_get_contents($path);
    if(!is_string($sql)||trim($sql)==='') throw new RuntimeException('Migration file missing.');
    $sql=preg_replace('/^\\s*--.*$/m','',$sql)??$sql;
    $parts=array_values(array_filter(array_map('trim',explode(';',$sql))));
    foreach($parts as $i=>$part) {
        if(!preg_match('/^ALTER TABLE users\\s+MODIFY COLUMN phone_normalized\\b/i',$part)) {
            throw new RuntimeException('Unexpected statement at position '.($i+1));
        }
        $db->exec($part);
    }
    $after=(int)$db->query(
        "SELECT COUNT(*) FROM users u
         WHERE NOT (u.phone_normalized <=> (
           SELECT NULLIF(
             REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
               CONVERT(TRIM(u2.phone) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
               _utf8mb4' ' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               CONVERT(CHAR(9) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               CONVERT(CHAR(10) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               CONVERT(CHAR(11) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               CONVERT(CHAR(12) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               CONVERT(CHAR(13) USING utf8mb4) COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               _utf8mb4'(' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               _utf8mb4')' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               _utf8mb4'.' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               _utf8mb4'-' COLLATE utf8mb4_unicode_ci,_utf8mb4'' COLLATE utf8mb4_unicode_ci),
               _utf8mb4'+20' COLLATE utf8mb4_unicode_ci,_utf8mb4'0' COLLATE utf8mb4_unicode_ci),
               _utf8mb4'' COLLATE utf8mb4_unicode_ci
           ) FROM users u2 WHERE u2.id=u.id
         ))"
    )->fetchColumn();
    if($after!==0) throw new RuntimeException('Generated phone value mismatch after migration: '.$after);
    $hash=hash_file('sha256',$path);
    $q=$db->prepare('INSERT INTO schema_migrations(version,checksum_sha256,description,engine_minimum) VALUES(?,?,?,?)');
    $q->execute([$version,$hash,'Use deterministic utf8mb4 collation for generated normalized phone','MariaDB 10.11+']);
    echo "V2_PHONE_COLLATION_PASS\\nstatements=".count($parts)."\\npreflight_mismatches={$before}\\npost_mismatches={$after}\\nsha256={$hash}\\n";
} catch(Throwable $e) {
    fwrite(STDERR,'V2_PHONE_COLLATION_FAILED: '.$e->getMessage()."\\n");
    exit(1);
}
