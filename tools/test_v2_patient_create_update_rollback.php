<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const PC_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const PC_V2 = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;
if (!in_array($host, ['127.0.0.1','localhost','::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB host.\n"); exit(11);
}
require_once __DIR__ . '/../services/Repositories/PatientManagementRepository.php';

function pcConnect(string $schema): PDO {
    global $host,$port,$user,$password;
    if (!in_array($schema,[PC_V1,PC_V2],true)) { throw new RuntimeException('Database not allowlisted.'); }
    $db = new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $schema) {
        throw new RuntimeException('Database identity mismatch.');
    }
    return $db;
}
function pcCounter(PDO $db,string $table): int {
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);$v=$q->fetchColumn();
    return ($v===false||$v===null)?0:(int)$v;
}
function pcRestore(PDO $db,string $table,int $value): void {
    if ($value<=0) { return; }
    if (!preg_match('/^[a-z_]+$/',$table)) { throw new RuntimeException('Invalid counter table.'); }
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function pcRun(string $database,string $schema): void {
    $db=pcConnect($database);
    $adminId=(int)$db->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($adminId<=0) { throw new RuntimeException("No active administrator in {$database}."); }

    $tables=$schema==='v1'
        ? ['users','medical_records','admin_audit','notification_outbox','notifications']
        : ['users','patient_profiles','patient_medical_profiles','audit_events','notification_outbox','notifications'];
    $counters=[];
    foreach($tables as $table){$v=pcCounter($db,$table);if($v>0){$counters[$table]=$v;}}

    $suffix=strtoupper(bin2hex(random_bytes(5)));
    $email="patient-contract-{$schema}-{$suffix}@example.invalid";
    $number='HB-C'.$suffix;
    $password='Patient-contract-password-2026';
    $hash=password_hash($password,PASSWORD_DEFAULT);
    $updatedHash=password_hash($password.'-updated',PASSWORD_DEFAULT);
    $repo=new PatientManagementRepository($db,$schema);
    $patientId=0;

    $db->beginTransaction();
    try {
        $patientId=$repo->createPatient(
            'Contract Patient '.$suffix,$email,$hash,$number,null,$adminId
        );
        if (!$db->inTransaction()) { throw new RuntimeException('createPatient committed caller-owned transaction.'); }

        $column=$schema==='v1'?'password':'password_hash';
        $q=$db->prepare("SELECT id,name,email,{$column} AS password_hash,role,is_active,session_version,patient_number FROM users WHERE id=?");
        $q->execute([$patientId]);$created=$q->fetch(PDO::FETCH_ASSOC);
        if (!$created||$created['email']!==$email||$created['role']!=='patient'
            ||(int)$created['is_active']!==1||$created['patient_number']!==$number
            ||!password_verify($password,(string)$created['password_hash'])){
            throw new RuntimeException("{$schema} patient account creation failed.");
        }
        if ($schema==='v1') {
            $q=$db->prepare('SELECT patient_id FROM medical_records WHERE patient_id=?');
            $q->execute([$patientId]);
        } else {
            $q=$db->prepare('SELECT patient_user_id FROM patient_profiles WHERE patient_user_id=?');
            $q->execute([$patientId]);
            if ($q->fetchColumn()===false) { throw new RuntimeException('V2 patient demographic profile missing.'); }
            $q=$db->prepare('SELECT patient_user_id FROM patient_medical_profiles WHERE patient_user_id=?');
            $q->execute([$patientId]);
        }
        if ($q->fetchColumn()===false) { throw new RuntimeException("{$schema} patient medical aggregate row missing."); }

        $updated=$repo->updatePatient($patientId,[
            'name'=>'Updated Patient '.$suffix,
            'email'=>'updated-'.$email,
            'phone'=>null,
            'gender'=>'Female',
            'date_of_birth'=>'1994-06-12',
            'is_active'=>0,
            'password_hash'=>$updatedHash,
        ],$adminId,'admin');
        if (!$updated['changed']||!$updated['status_changed']||!$updated['password_changed']) {
            throw new RuntimeException("{$schema} patient update did not report identity/status/password mutations.");
        }
        if (!$db->inTransaction()) { throw new RuntimeException('updatePatient committed caller-owned transaction.'); }

        $q=$db->prepare("SELECT name,email,{$column} AS password_hash,is_active,session_version FROM users WHERE id=?");
        $q->execute([$patientId]);$after=$q->fetch(PDO::FETCH_ASSOC);
        if (!$after||$after['name']!=='Updated Patient '.$suffix
            ||$after['email']!=='updated-'.$email||(int)$after['is_active']!==0
            ||!password_verify($password.'-updated',(string)$after['password_hash'])
            ||(int)$after['session_version']!==(int)$created['session_version']+1){
            throw new RuntimeException("{$schema} patient identity/status/password/session update failed.");
        }

        if ($schema==='v1') {
            $q=$db->prepare('SELECT gender,date_of_birth FROM medical_records WHERE patient_id=?');
            $q->execute([$patientId]);$demographics=$q->fetch(PDO::FETCH_ASSOC);
            $audit=$db->prepare("SELECT COUNT(*) FROM admin_audit WHERE entity_type='patient' AND entity_id=?");
        } else {
            $q=$db->prepare('SELECT gender,date_of_birth FROM patient_profiles WHERE patient_user_id=?');
            $q->execute([$patientId]);$demographics=$q->fetch(PDO::FETCH_ASSOC);
            $audit=$db->prepare("SELECT COUNT(*) FROM audit_events WHERE entity_type='patient' AND entity_id=?");
        }
        if (!$demographics||$demographics['gender']!=='Female'||$demographics['date_of_birth']!=='1994-06-12') {
            throw new RuntimeException("{$schema} patient demographics mapping failed.");
        }
        $audit->execute([(string)$patientId]);
        if ((int)$audit->fetchColumn()<2) { throw new RuntimeException("{$schema} patient create/update audit records missing."); }

        $outbox=$db->prepare('SELECT COUNT(*) FROM notification_outbox WHERE ref_type=\'user\' AND ref_id=? AND status=\'pending\'');
        $outbox->execute([$patientId]);
        if ((int)$outbox->fetchColumn()<2) { throw new RuntimeException("{$schema} patient account notifications/outbox entries missing."); }

        echo "PATIENT_CREATE_UPDATE_SCHEMA_MAPPING_OK|{$schema}|patient={$patientId}\n";
    } finally {
        if ($db->inTransaction()) { $db->rollBack(); }
    }

    foreach($counters as $table=>$value){pcRestore($db,$table,$value);}
    $exists=$db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
    $exists->execute([$email]);
    if ($exists->fetchColumn()) { throw new RuntimeException("Synthetic patient was not rolled back in {$database}."); }
    foreach($counters as $table=>$value){
        if(pcCounter($db,$table)!==$value){throw new RuntimeException("AUTO_INCREMENT for {$table} not restored in {$database}.");}
    }
    echo "PATIENT_CREATE_UPDATE_ROLLBACK_AND_COUNTER_RESTORE_OK|{$database}\n";
}
try {
    echo "=== Patient create/update V1/V2 contract ===\n";
    pcRun(PC_V1,'v1');
    pcRun(PC_V2,'v2');
    echo "RESULT=PASS (synthetic accounts/profiles/audit/outbox rolled back; counters restored)\n";
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,"PATIENT CREATE/UPDATE CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
