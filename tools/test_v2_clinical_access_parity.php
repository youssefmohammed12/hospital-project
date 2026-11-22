<?php
declare(strict_types=1);

/**
 * Read-only doctor-to-patient clinical access parity across v1/v2.
 * Checks the complete doctor/patient cross product and one transaction row-lock path.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(10); }
const ACCESS_V1='healthbridge_hb_auth_email_verify_20261114';
const ACCESS_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$pass=getenv('DB_PASS');
$pass=$pass===false?'':(string)$pass;
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) { fwrite(STDERR,"Loopback required.\n"); exit(11); }
require_once __DIR__.'/../services/ClinicalAccessService.php';

function connectAccessFixture(string $name,string $host,string $port,string $user,string $pass): PDO {
    if (!in_array($name,[ACCESS_V1,ACCESS_V2],true)) throw new RuntimeException('Fixture not allowlisted.');
    $db=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$name) throw new RuntimeException('Schema mismatch.');
    return $db;
}

try {
    $v1=connectAccessFixture(ACCESS_V1,$host,$port,$user,$pass);
    $v2=connectAccessFixture(ACCESS_V2,$host,$port,$user,$pass);
    $doctors=array_map('intval',$v1->query("SELECT id FROM users WHERE role='doctor' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    $patients=array_map('intval',$v1->query("SELECT id FROM users WHERE role='patient' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    $access1=new ClinicalAccessService($v1,'v1');
    $access2=new ClinicalAccessService($v2,'v2');
    $mismatches=[];
    $allowed=0;
    $denied=0;
    foreach ($doctors as $doctorId) {
        foreach ($patients as $patientId) {
            $left=$access1->doctorCanAccessPatient($doctorId,$patientId);
            $right=$access2->doctorCanAccessPatient($doctorId,$patientId);
            if ($left!==$right) $mismatches[]="doctor={$doctorId},patient={$patientId},v1=".(int)$left.",v2=".(int)$right;
            if ($left) $allowed++; else $denied++;
        }
    }
    if ($mismatches) {
        foreach(array_slice($mismatches,0,30) as $m) echo "MISMATCH|{$m}\n";
        throw new RuntimeException(count($mismatches).' clinical-access decisions differed.');
    }

    $relationship=$v1->query(
        "SELECT a.doctor_id,a.user_id
         FROM appointments a
         WHERE a.status='Confirmed'
         ORDER BY a.id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$relationship) throw new RuntimeException('No confirmed appointment available for lock-path test.');

    foreach ([[$v1,$access1],[$v2,$access2]] as [$db,$service]) {
        $db->beginTransaction();
        try {
            if (!$service->lockDoctorAndPatient((int)$relationship['doctor_id'],(int)$relationship['user_id'])) {
                throw new RuntimeException('Account-lock path could not lock the doctor/patient relationship.');
            }
            if (!$service->doctorCanAccessPatient((int)$relationship['doctor_id'],(int)$relationship['user_id'],true)) {
                throw new RuntimeException('forUpdate clinical access query denied a confirmed appointment.');
            }
        } finally {
            if ($db->inTransaction()) $db->rollBack();
        }
    }

    echo "DOCTORS=".count($doctors)."\n";
    echo "PATIENTS=".count($patients)."\n";
    echo "PAIR_DECISIONS=".($allowed+$denied)."\n";
    echo "ALLOWED={$allowed}\n";
    echo "DENIED={$denied}\n";
    echo "LOCK_PATHS=2\n";
    echo "RESULT=PASS (read-only cross product; lock transactions rolled back)\n";
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,"CLINICAL ACCESS CONTRACT FAILED: ".get_class($e)."\n");
    fwrite(STDERR,$e->getMessage()."\n");
    exit(2);
}
