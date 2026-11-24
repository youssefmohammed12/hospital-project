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
    $version='2.0.3-prototype';
    $q=$db->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version=?');
    $q->execute([$version]);
    if((int)$q->fetchColumn()!==0) throw new RuntimeException('Migration already recorded.');
    $mismatches=(int)$db->query(
        'SELECT
          (SELECT COUNT(*) FROM clinical_encounters e JOIN appointments a ON a.id=e.appointment_id
           WHERE e.patient_user_id<>a.patient_user_id OR e.doctor_user_id<>a.doctor_user_id)
        + (SELECT COUNT(*) FROM prescriptions p JOIN appointments a ON a.id=p.appointment_id
           WHERE p.patient_user_id<>a.patient_user_id OR p.doctor_user_id<>a.doctor_user_id)
        + (SELECT COUNT(*) FROM ratings r JOIN appointments a ON a.id=r.appointment_id
           WHERE r.patient_user_id<>a.patient_user_id OR r.doctor_user_id<>a.doctor_user_id)'
    )->fetchColumn();
    if((int)$mismatches!==0) throw new RuntimeException('Cannot add composite owner constraints; mismatched rows='.$mismatches);
    $path=__DIR__.'/004_appointment_owner_constraints.sql';
    $sql=file_get_contents($path);
    if(!is_string($sql)||trim($sql)==='') throw new RuntimeException('Migration file missing.');
    $sql=preg_replace('/^\\s*--.*$/m','',$sql)??$sql;
    $parts=array_values(array_filter(array_map('trim',explode(';',$sql))));
    foreach($parts as $i=>$part) {
        if(!preg_match('/^(ALTER TABLE (appointments|clinical_encounters|prescriptions|ratings))\\b/i',$part))
            throw new RuntimeException('Unexpected statement at position '.($i+1));
        $db->exec($part);
    }
    $hash=hash_file('sha256',$path);
    $q=$db->prepare('INSERT INTO schema_migrations(version,checksum_sha256,description,engine_minimum) VALUES(?,?,?,?)');
    $q->execute([$version,$hash,'Enforce appointment patient/doctor identity across clinical facts','MariaDB 10.11+']);
    $fk=(int)$db->query('SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()')->fetchColumn();
    if($fk!==42) throw new RuntimeException('Expected 42 foreign keys, found '.$fk);
    echo "V2_OWNER_CONSTRAINTS_PASS\nstatements=".count($parts)."\nforeign_keys={$fk}\nsha256={$hash}\n";
} catch(Throwable $e) {
    fwrite(STDERR,'V2_OWNER_CONSTRAINTS_FAILED: '.$e->getMessage()."\n");
    exit(1);
}
