<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(10); }
const AL_V1='healthbridge_hb_auth_email_verify_20261114';
const AL_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}
require_once __DIR__.'/../services/Repositories/AccountLifecycleRepository.php';

function alConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[AL_V1,AL_V2],true)){throw new RuntimeException('Database not allowlisted.');}
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema){throw new RuntimeException('Database identity mismatch.');}
    return $db;
}
function alCounter(PDO $db,string $table):int{
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);return (int)$q->fetchColumn();
}
function alRestoreCounter(PDO $db,string $table,int $value):void{
    if(!preg_match('/^[a-z_]+$/',$table)||$value<1){throw new RuntimeException('Invalid auto increment metadata.');}
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function alAuditCount(PDO $db,string $schema,int $userId):int{
    $sql=$schema==='v1'
        ? "SELECT COUNT(*) FROM admin_audit WHERE entity_type='user' AND entity_id=? AND action='deactivate'"
        : "SELECT COUNT(*) FROM audit_events WHERE entity_type='user' AND entity_id=? AND action_code='deactivate'";
    $q=$db->prepare($sql);$q->execute([(string)$userId]);return (int)$q->fetchColumn();
}
function alCandidate(PDO $db,string $schema,string $role):int{
    if($role==='doctor'&&$schema==='v1'){
        $sql="SELECT u.id FROM users u JOIN doctors d ON d.user_id=u.id
          WHERE u.role='doctor' AND u.is_active=1
            AND NOT EXISTS(SELECT 1 FROM appointments a JOIN visit_workflow w ON w.appointment_id=a.id
              WHERE a.doctor_id=u.id AND a.status='Confirmed' AND w.status IN ('In Progress','Ready to Complete'))
            AND NOT EXISTS(SELECT 1 FROM prescriptions p WHERE p.doctor_id=u.id AND p.status='Active')
            AND NOT EXISTS(SELECT 1 FROM visit_drafts vd WHERE vd.doctor_id=u.id)
          ORDER BY u.id LIMIT 1";
    }elseif($role==='doctor'){
        $sql="SELECT u.id FROM users u JOIN doctor_profiles d ON d.doctor_user_id=u.id
          WHERE u.role='doctor' AND u.is_active=1
            AND NOT EXISTS(SELECT 1 FROM appointments a JOIN clinical_encounters ce ON ce.appointment_id=a.id
              WHERE a.doctor_user_id=u.id AND a.booking_status='confirmed' AND ce.workflow_status IN ('in_progress','ready_to_complete'))
            AND NOT EXISTS(SELECT 1 FROM prescriptions p WHERE p.doctor_user_id=u.id AND p.status='active')
            AND NOT EXISTS(SELECT 1 FROM encounter_drafts ed WHERE ed.doctor_user_id=u.id)
          ORDER BY u.id LIMIT 1";
    }else{
        $sql="SELECT id FROM users WHERE role='{$role}' AND is_active=1 ORDER BY id LIMIT 1";
    }
    return (int)$db->query($sql)->fetchColumn();
}
function alFindBlockedDoctor(PDO $db,string $schema):?int{
    if($schema==='v1'){
        $sql="SELECT u.id FROM users u JOIN doctors d ON d.user_id=u.id
          WHERE u.role='doctor' AND u.is_active=1 AND (
            EXISTS(SELECT 1 FROM appointments a JOIN visit_workflow w ON w.appointment_id=a.id
              WHERE a.doctor_id=u.id AND a.status='Confirmed' AND w.status IN ('In Progress','Ready to Complete'))
            OR EXISTS(SELECT 1 FROM prescriptions p WHERE p.doctor_id=u.id AND p.status='Active')
            OR EXISTS(SELECT 1 FROM visit_drafts vd WHERE vd.doctor_id=u.id))
          ORDER BY u.id LIMIT 1";
    }else{
        $sql="SELECT u.id FROM users u JOIN doctor_profiles d ON d.doctor_user_id=u.id
          WHERE u.role='doctor' AND u.is_active=1 AND (
            EXISTS(SELECT 1 FROM appointments a JOIN clinical_encounters ce ON ce.appointment_id=a.id
              WHERE a.doctor_user_id=u.id AND a.booking_status='confirmed' AND ce.workflow_status IN ('in_progress','ready_to_complete'))
            OR EXISTS(SELECT 1 FROM prescriptions p WHERE p.doctor_user_id=u.id AND p.status='active')
            OR EXISTS(SELECT 1 FROM encounter_drafts ed WHERE ed.doctor_user_id=u.id))
          ORDER BY u.id LIMIT 1";
    }
    $v=$db->query($sql)->fetchColumn();return $v===false?null:(int)$v;
}
function alPreparePreference(PDO $db,int $userId):void{
    $stmt=$db->prepare('INSERT INTO user_preferences (user_id,notif_appointment) VALUES (?,1)
        ON DUPLICATE KEY UPDATE notif_appointment=1');
    $stmt->execute([$userId]);
}
function alRunRole(PDO $db,string $schema,string $role,string $passwordColumn,string $auditTable):void{
    $userId=alCandidate($db,$schema,$role);
    if($userId<=0){throw new RuntimeException("No active {$role} candidate in {$schema}.");}
    $beforeCounter=alCounter($db,$auditTable);
    $snapshot=$db->prepare('SELECT is_active,session_version FROM users WHERE id=?');
    $snapshot->execute([$userId]);$before=$snapshot->fetch(PDO::FETCH_ASSOC);
    $activeAdmins=(int)$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND is_active=1")->fetchColumn();

    $db->beginTransaction();
    try{
        $hash=password_hash('account-lifecycle-contract-pass',PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET {$passwordColumn}=? WHERE id=?")->execute([$hash,$userId]);
        if($role!=='admin'){alPreparePreference($db,$userId);}
        $repo=new AccountLifecycleRepository($db,$schema);
        $blocked=false;
        try{
            $result=$repo->deactivateSelf($userId,$role,'account-lifecycle-contract-pass');
        }catch(DomainException $e){
            if($role==='admin'&&$activeAdmins<=1&&str_contains($e->getMessage(),'last active administrator')){
                $blocked=true;
                $current=$db->prepare('SELECT is_active,session_version FROM users WHERE id=?');
                $current->execute([$userId]);$after=$current->fetch(PDO::FETCH_ASSOC);
                if((int)$after['is_active']!==1||(int)$after['session_version']!==(int)$before['session_version']){
                    throw new RuntimeException('Last-admin self-deactivation changed account state.');
                }
                echo "LAST_ACTIVE_ADMIN_GUARD_OK|{$schema}|admin={$userId}\n";
            }else{
                throw $e;
            }
        }

        if(!$blocked){
            $current=$db->prepare('SELECT is_active,session_version FROM users WHERE id=?');
            $current->execute([$userId]);$after=$current->fetch(PDO::FETCH_ASSOC);
            if(!$after||(int)$after['is_active']!==0||(int)$after['session_version']!==(int)$before['session_version']+1){
                throw new RuntimeException("{$role} self-deactivation did not disable account/revoke sessions.");
            }
            if((int)$result['cancelled_appointments']<0){throw new RuntimeException('Invalid cancellation count.');}
            if(alAuditCount($db,$schema,$userId)!==1){throw new RuntimeException("{$role} self-deactivation audit event missing.");}

            if($role==='doctor'&&(int)$result['cancelled_appointments']>0){
                if($schema==='v1'){
                    $q=$db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_id=? AND status='Cancelled'");
                }else{
                    $q=$db->prepare("SELECT COUNT(*) FROM appointments WHERE doctor_user_id=? AND booking_status='cancelled'");
                }
                $q->execute([$userId]);
                if((int)$q->fetchColumn()<(int)$result['cancelled_appointments']){
                    throw new RuntimeException('Doctor cancellation count was not reflected in appointments.');
                }
            }
            if($schema==='v2'&&$role!=='admin'&&(int)$result['cancelled_appointments']>0){
                $eventType=$role.'_self_deactivated';
                $q=$db->prepare('SELECT COUNT(*) FROM appointment_events WHERE actor_user_id=? AND event_type=? AND to_status=\'cancelled\'');
                $q->execute([$userId,$eventType]);
                if((int)$q->fetchColumn()<(int)$result['cancelled_appointments']){
                    throw new RuntimeException('V2 self-deactivation appointment events missing.');
                }
            }
            echo "SELF_DEACTIVATION_OK|{$schema}|{$role}|user={$userId}|cancelled=".(int)$result['cancelled_appointments']."\n";
        }
        if(!$db->inTransaction()){throw new RuntimeException('Repository committed caller-owned transaction.');}
    }finally{
        if($db->inTransaction()){$db->rollBack();}
    }
    alRestoreCounter($db,$auditTable,$beforeCounter);
    if(alCounter($db,$auditTable)!==$beforeCounter){throw new RuntimeException('Audit AUTO_INCREMENT was not restored.');}
    $snapshot->execute([$userId]);$restored=$snapshot->fetch(PDO::FETCH_ASSOC);
    if((int)$restored['is_active']!==(int)$before['is_active']||(int)$restored['session_version']!==(int)$before['session_version']){
        throw new RuntimeException("{$role} self-deactivation was not rolled back.");
    }
    echo "SELF_DEACTIVATION_ROLLBACK_OK|{$schema}|{$role}\n";
}
function alRun(string $database,string $schema):void{
    $db=alConnect($database);
    $passwordColumn=$schema==='v1'?'password':'password_hash';
    $auditTable=$schema==='v1'?'admin_audit':'audit_events';
    $tables=$schema==='v1'?['admin_audit','notification_outbox']:['audit_events','notification_outbox','appointment_events'];
    $counters=[];foreach($tables as $table){$counters[$table]=alCounter($db,$table);}
    foreach(['patient','doctor','admin'] as $role){alRunRole($db,$schema,$role,$passwordColumn,$auditTable);}
    foreach($counters as $table=>$before){alRestoreCounter($db,$table,$before);if(alCounter($db,$table)!==$before){throw new RuntimeException("Counter restore failed: {$table}");}}
    $blockedDoctor=alFindBlockedDoctor($db,$schema);
    if($blockedDoctor!==null){
        $before=$db->prepare('SELECT is_active,session_version FROM users WHERE id=?');
        $before->execute([$blockedDoctor]);$state=$before->fetch(PDO::FETCH_ASSOC);
        $db->beginTransaction();
        try{
            $hash=password_hash('account-lifecycle-contract-pass',PASSWORD_DEFAULT);
            $db->prepare("UPDATE users SET {$passwordColumn}=? WHERE id=?")->execute([$hash,$blockedDoctor]);
            $repo=new AccountLifecycleRepository($db,$schema);
            $refused=false;
            try{$repo->deactivateSelf($blockedDoctor,'doctor','account-lifecycle-contract-pass');}
            catch(DomainException $e){$refused=str_contains($e->getMessage(),'active visit');}
            $after=$before;$after->execute([$blockedDoctor]);$afterState=$after->fetch(PDO::FETCH_ASSOC);
            if(!$refused||(int)$afterState['is_active']!==(int)$state['is_active']
                ||(int)$afterState['session_version']!==(int)$state['session_version']){
                throw new RuntimeException('Doctor unfinished-clinical-work guard failed.');
            }
            echo "DOCTOR_UNFINISHED_WORK_GUARD_OK|{$schema}|doctor={$blockedDoctor}\n";
        }finally{if($db->inTransaction()){$db->rollBack();}}
        alRestoreCounter($db,$auditTable,$counters[$auditTable]);
    }else{
        echo "DOCTOR_UNFINISHED_WORK_GUARD_NOT_EXERCISED|{$schema}|none_found\n";
    }
    foreach($counters as $table=>$before){alRestoreCounter($db,$table,$before);}
}
try{
    echo "=== Account lifecycle v1/v2 contract ===\n";
    alRun(AL_V1,'v1');
    alRun(AL_V2,'v2');
    echo "RESULT=PASS (all mutations rolled back; counters restored)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"ACCOUNT LIFECYCLE CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
