<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(10);}
const AP_V1='healthbridge_hb_auth_email_verify_20261114';
const AP_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}
require_once __DIR__.'/../services/Repositories/AppointmentLifecycleRepository.php';

function apConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[AP_V1,AP_V2],true)){throw new RuntimeException('Database not allowlisted.');}
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema){throw new RuntimeException('Database identity mismatch.');}
    return $db;
}
function apCounter(PDO $db,string $table):int{
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);$value=$q->fetchColumn();return $value===false||$value===null?0:(int)$value;
}
function apRestore(PDO $db,string $table,int $value):void{
    if($value<=0)return;
    if(!preg_match('/^[a-z_]+$/',$table))throw new RuntimeException('Invalid counter table.');
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function apCandidate(PDO $db,string $schema):array{
    $sql=$schema==='v1'
        ? "SELECT a.id,a.doctor_id AS doctor_user_id,a.user_id AS patient_user_id
           FROM appointments a
           JOIN users d ON d.id=a.doctor_id AND d.role='doctor' AND d.is_active=1
           JOIN users p ON p.id=a.user_id AND p.role='patient'
           WHERE a.status='Pending' ORDER BY a.id LIMIT 1"
        : "SELECT a.id,a.doctor_user_id,a.patient_user_id
           FROM appointments a
           JOIN users d ON d.id=a.doctor_user_id AND d.role='doctor' AND d.is_active=1
           JOIN users p ON p.id=a.patient_user_id AND p.role='patient'
           WHERE a.booking_status='pending' ORDER BY a.id LIMIT 1";
    $row=$db->query($sql)->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException("No pending appointment candidate in {$schema}.");
    return ['id'=>(int)$row['id'],'doctor'=>(int)$row['doctor_user_id'],'patient'=>(int)$row['patient_user_id']];
}
function apStatus(PDO $db,string $schema,int $appointmentId):string{
    $column=$schema==='v1'?'status':'booking_status';
    $q=$db->prepare("SELECT {$column} FROM appointments WHERE id=?");$q->execute([$appointmentId]);return (string)$q->fetchColumn();
}
function apAudit(PDO $db,string $schema,int $appointmentId,string $action):int{
    $sql=$schema==='v1'
        ? "SELECT COUNT(*) FROM admin_audit WHERE entity_type='appointment' AND entity_id=? AND action=?"
        : "SELECT COUNT(*) FROM audit_events WHERE entity_type='appointment' AND entity_id=? AND action_code=?";
    $q=$db->prepare($sql);$q->execute([(string)$appointmentId,$action]);return (int)$q->fetchColumn();
}
function apCounters(PDO $db,string $schema):array{
    $tables=$schema==='v1'
        ? ['admin_audit','notification_outbox','notifications','visit_workflow']
        : ['audit_events','appointment_events','notification_outbox','notifications','clinical_encounters'];
    $out=[];foreach($tables as $table){$n=apCounter($db,$table);if($n>0)$out[$table]=$n;}return $out;
}
function apRestoreCounters(PDO $db,array $counters):void{
    foreach($counters as $table=>$counter)apRestore($db,$table,$counter);
}
function apRunCase(PDO $db,string $schema,array $candidate,int $actorId,string $actorRole,string $status,string $reason,string $expectedStored,string $action):void{
    $counters=apCounters($db,$schema);
    $repo=new AppointmentLifecycleRepository($db,$schema);
    $db->beginTransaction();
    try{
        $result=$repo->transitionPending($candidate['id'],$actorId,$actorRole,$status,$reason);
        if(!$db->inTransaction())throw new RuntimeException('Lifecycle repository committed caller-owned transaction.');
        if(apStatus($db,$schema,$candidate['id'])!==$expectedStored)throw new RuntimeException("{$schema} {$actorRole} {$status} status not persisted.");
        if($result['status']!==$expectedStored){
            throw new RuntimeException("{$schema} lifecycle result DTO does not match stored status.");
        }
        if(apAudit($db,$schema,$candidate['id'],$action)!==1)throw new RuntimeException("{$schema} appointment audit event missing for {$action}.");
        if($schema==='v1'){
            $q=$db->prepare('SELECT status FROM visit_workflow WHERE appointment_id=?');
            if($expectedStored==='Confirmed'){
                $q->execute([$candidate['id']]);
                if($q->fetchColumn()!=='Waiting')throw new RuntimeException('V1 approval did not create Waiting workflow.');
            }
        }else{
            $q=$db->prepare('SELECT COUNT(*) FROM appointment_events WHERE appointment_id=? AND actor_user_id=? AND event_type=?');
            $eventType=$status==='confirmed'
                ? ($actorRole==='admin'?'admin_approved':'doctor_confirmed')
                : ($actorRole==='admin'?'admin_declined':'doctor_cancelled');
            $q->execute([$candidate['id'],$actorId,$eventType]);
            if((int)$q->fetchColumn()!==1)throw new RuntimeException("V2 appointment lifecycle event {$eventType} missing.");
            if($expectedStored==='confirmed'){
                $q=$db->prepare('SELECT workflow_status FROM clinical_encounters WHERE appointment_id=?');
                $q->execute([$candidate['id']]);
                if($q->fetchColumn()!=='waiting')throw new RuntimeException('V2 approval did not create waiting encounter.');
            }
        }
        $q=$db->prepare('SELECT COUNT(*) FROM notification_outbox WHERE user_id=? AND ref_type=\'appointment\' AND ref_id=? AND status=\'pending\'');
        $q->execute([$candidate['patient'],$candidate['id']]);
        if((int)$q->fetchColumn()<1)throw new RuntimeException("{$schema} patient notification outbox event missing.");
        if($reason!==''&&$status==='cancelled'){
            $sql=$schema==='v1'
                ? "SELECT COUNT(*) FROM admin_audit WHERE entity_id=? AND description LIKE ?"
                : "SELECT COUNT(*) FROM audit_events WHERE entity_id=? AND description LIKE ?";
            $q=$db->prepare($sql);$q->execute([(string)$candidate['id'],'%'.$reason.'%']);
            if((int)$q->fetchColumn()<1)throw new RuntimeException("{$schema} decline reason missing from audit description.");
        }
        echo "APPOINTMENT_{$action}_OK|{$schema}|appointment={$candidate['id']}|actor={$actorRole}\n";
    }finally{
        if($db->inTransaction())$db->rollBack();
    }
    apRestoreCounters($db,$counters);
    if(apStatus($db,$schema,$candidate['id'])!==($schema==='v1'?'Pending':'pending')){
        throw new RuntimeException("{$schema} appointment status did not roll back.");
    }
    foreach($counters as $table=>$counter){if(apCounter($db,$table)!==$counter)throw new RuntimeException("{$schema} {$table} counter not restored.");}
}
function apRun(string $database,string $schema):void{
    $db=apConnect($database);
    $candidate=apCandidate($db,$schema);
    $adminId=(int)$db->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $otherDoctor=(int)$db->query("SELECT id FROM users WHERE role='doctor' AND is_active=1 AND id<>".(int)$candidate['doctor']." ORDER BY id LIMIT 1")->fetchColumn();
    if($adminId<=0)throw new RuntimeException("No active admin in {$database}.");

    apRunCase($db,$schema,$candidate,$adminId,'admin','confirmed','',
        $schema==='v1'?'Confirmed':'confirmed','approve');
    apRunCase($db,$schema,$candidate,$adminId,'admin','cancelled','Fixture decline reason',
        $schema==='v1'?'Cancelled':'cancelled','decline');
    apRunCase($db,$schema,$candidate,$candidate['doctor'],'doctor','confirmed','',
        $schema==='v1'?'Confirmed':'confirmed','confirm');
    apRunCase($db,$schema,$candidate,$candidate['doctor'],'doctor','cancelled','',
        $schema==='v1'?'Cancelled':'cancelled','cancel');

    if($otherDoctor>0){
        $db->beginTransaction();
        try{
            $blocked=false;
            try{
                (new AppointmentLifecycleRepository($db,$schema))->transitionPending(
                    $candidate['id'],$otherDoctor,'doctor','confirmed'
                );
            }catch(DomainException $e){$blocked=str_contains($e->getMessage(),'assigned to you');}
            if(!$blocked||apStatus($db,$schema,$candidate['id'])!==($schema==='v1'?'Pending':'pending')){
                throw new RuntimeException("{$schema} unrelated doctor was able to transition this appointment.");
            }
            echo "APPOINTMENT_ASSIGNED_DOCTOR_AUTHORIZATION_OK|{$schema}\n";
        }finally{if($db->inTransaction())$db->rollBack();}
    }else{
        echo "APPOINTMENT_ASSIGNED_DOCTOR_AUTHORIZATION_NOT_EXERCISED|{$schema}|no_second_doctor\n";
    }

    $confirmedSql=$schema==='v1'
        ? "SELECT id FROM appointments WHERE status='Confirmed' ORDER BY id LIMIT 1"
        : "SELECT id FROM appointments WHERE booking_status='confirmed' ORDER BY id LIMIT 1";
    $confirmedId=(int)$db->query($confirmedSql)->fetchColumn();
    if($confirmedId>0){
        $db->beginTransaction();
        try{
            $blocked=false;
            try{(new AppointmentLifecycleRepository($db,$schema))->transitionPending($confirmedId,$adminId,'admin','confirmed');}
            catch(DomainException $e){$blocked=str_contains($e->getMessage(),'Only pending appointments');}
            if(!$blocked)throw new RuntimeException("{$schema} non-pending appointment transition was not rejected.");
            echo "APPOINTMENT_NON_PENDING_GUARD_OK|{$schema}\n";
        }finally{if($db->inTransaction())$db->rollBack();}
    }
}
try{
    echo "=== Appointment lifecycle V1/V2 contract ===\n";
    apRun(AP_V1,'v1');
    apRun(AP_V2,'v2');
    echo "RESULT=PASS (appointment transitions, workflows, audit/outbox/events rolled back; counters restored)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"APPOINTMENT LIFECYCLE CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
