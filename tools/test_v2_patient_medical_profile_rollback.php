<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const MED_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const MED_V2 = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;
if (!in_array($host, ['127.0.0.1','localhost','::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB host.\n"); exit(11);
}

require_once __DIR__ . '/../services/Repositories/PatientManagementRepository.php';
require_once __DIR__ . '/../services/ClinicalAccessService.php';

function medConnect(string $schema): PDO
{
    global $host,$port,$user,$password;
    if (!in_array($schema,[MED_V1,MED_V2],true)) { throw new RuntimeException('Database not allowlisted.'); }
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",
        $user,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $schema) {
        throw new RuntimeException('Database identity mismatch.');
    }
    return $db;
}

function medCounter(PDO $db,string $table): int
{
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);$v=$q->fetchColumn();
    return ($v===false||$v===null)?0:(int)$v;
}

function medRestoreCounter(PDO $db,string $table,int $value): void
{
    if ($value<=0) { return; }
    if (!preg_match('/^[a-z_]+$/',$table)) { throw new RuntimeException('Invalid auto increment table.'); }
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}

function medSnapshot(PDO $db,string $schema,int $patientId): array
{
    if ($schema==='v1') {
        $q=$db->prepare('SELECT * FROM medical_records WHERE patient_id=? LIMIT 1');
        $q->execute([$patientId]);return $q->fetch(PDO::FETCH_ASSOC)?:[];
    }
    $p=$db->prepare('SELECT * FROM patient_profiles WHERE patient_user_id=? LIMIT 1');
    $p->execute([$patientId]);$profile=$p->fetch(PDO::FETCH_ASSOC)?:[];
    $m=$db->prepare('SELECT * FROM patient_medical_profiles WHERE patient_user_id=? LIMIT 1');
    $m->execute([$patientId]);$medical=$m->fetch(PDO::FETCH_ASSOC)?:[];
    return ['patient_profiles'=>$profile,'patient_medical_profiles'=>$medical];
}

function medAuditCount(PDO $db,string $schema,int $patientId,string $action): int
{
    $sql=$schema==='v1'
        ? 'SELECT COUNT(*) FROM admin_audit WHERE entity_type=? AND entity_id=? AND action=?'
        : 'SELECT COUNT(*) FROM audit_events WHERE entity_type=? AND entity_id=? AND action_code=?';
    $q=$db->prepare($sql);$q->execute(['patient',(string)$patientId,$action]);return (int)$q->fetchColumn();
}

function medFindDoctorPatient(PDO $db,string $schema): array
{
    if ($schema==='v1') {
        $sql="SELECT a.doctor_id AS doctor_user_id,a.user_id AS patient_user_id
              FROM appointments a
              JOIN users d ON d.id=a.doctor_id AND d.role='doctor' AND d.is_active=1
              JOIN users p ON p.id=a.user_id AND p.role='patient'
              ORDER BY a.id";
    } else {
        $sql="SELECT a.doctor_user_id,a.patient_user_id
              FROM appointments a
              JOIN users d ON d.id=a.doctor_user_id AND d.role='doctor' AND d.is_active=1
              JOIN users p ON p.id=a.patient_user_id AND p.role='patient'
              ORDER BY a.id";
    }
    $access=new ClinicalAccessService($db,$schema);
    foreach($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $pair) {
        $doctorId=(int)$pair['doctor_user_id'];$patientId=(int)$pair['patient_user_id'];
        if($access->doctorCanAccessPatient($doctorId,$patientId,false)) {
            return ['doctor_user_id'=>$doctorId,'patient_user_id'=>$patientId];
        }
    }
    throw new RuntimeException("No authorized doctor/patient clinical relationship found in {$schema} fixture.");
}

function medRun(string $database,string $schema): void
{
    $db=medConnect($database);
    $pair=medFindDoctorPatient($db,$schema);
    $doctorId=$pair['doctor_user_id'];$patientId=$pair['patient_user_id'];
    $adminId=(int)$db->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $otherPatient=(int)$db->query("SELECT id FROM users WHERE role='patient' AND is_active=1 AND id<>".(int)$patientId." ORDER BY id LIMIT 1")->fetchColumn();
    if($adminId<=0||$otherPatient<=0){throw new RuntimeException("Missing admin/unauthorized patient in {$database}.");}

    $original=medSnapshot($db,$schema,$patientId);
    $auditTable=$schema==='v1'?'admin_audit':'audit_events';
    $counterTables=$schema==='v1'
        ? ['admin_audit','medical_record_audit','medical_history_updates','notification_outbox','notifications','medical_records']
        : ['audit_events','medical_history_events','notification_outbox','notifications','patient_profiles','patient_medical_profiles'];
    $counters=[];
    foreach($counterTables as $table){$v=medCounter($db,$table);if($v>0){$counters[$table]=$v;}}
    $adminAuditBefore=medAuditCount($db,$schema,$patientId,'update');

    $repo=new PatientManagementRepository($db,$schema);
    $suffix=strtoupper(bin2hex(random_bytes(4)));
    $doctorChanges=[
        'allergies'=>'Clinical history test '.$suffix,
        'current_medications'=>'Clinical medication test '.$suffix,
        'weight_kg'=>72.5,
    ];
    $db->beginTransaction();
    try{
        // An admin cannot make a sensitive correction without a reason.
        $reasonBlocked=false;
        try{
            $repo->updateMedicalProfile($patientId,['blood_type'=>'O+','emergency_contact_name'=>'No Reason Change'],$adminId,'admin','');
        }catch(DomainException $e){
            $reasonBlocked=str_contains($e->getMessage(),'Reason is required');
        }
        if(!$reasonBlocked){throw new RuntimeException('Sensitive administrative correction did not require a reason.');}

        $doctorResult=$repo->updateMedicalProfile($patientId,$doctorChanges,$doctorId,'doctor');
        if(!$doctorResult['changed']||count($doctorResult['changed_fields'])!==3){
            throw new RuntimeException('Doctor medical profile update did not return the expected field changes.');
        }

        if($schema==='v1'){
            $stored=$db->prepare('SELECT allergies,current_medications,weight_kg FROM medical_records WHERE patient_id=?');
            $stored->execute([$patientId]);$row=$stored->fetch(PDO::FETCH_ASSOC);
        }else{
            $stored=$db->prepare('SELECT allergies,current_medications,weight_kg FROM patient_medical_profiles WHERE patient_user_id=?');
            $stored->execute([$patientId]);$row=$stored->fetch(PDO::FETCH_ASSOC);
        }
        if(!$row||$row['allergies']!==$doctorChanges['allergies']
            ||$row['current_medications']!==$doctorChanges['current_medications']
            ||(float)$row['weight_kg']!==72.5){
            throw new RuntimeException("Doctor medical update did not map to the {$schema} clinical profile fields.");
        }

        $oldAllergies = $schema==='v1'
            ? (string)($original['allergies']??'')
            : (string)($original['patient_medical_profiles']['allergies']??'');
        $legacyTimelineResult=$repo->recordMedicalHistoryUpdate(
            $patientId,$doctorId,'allergies',$oldAllergies,$doctorChanges['allergies']
        );

        if($schema==='v1'){
            if(!$legacyTimelineResult){throw new RuntimeException('V1 medical history timeline event was not inserted.');}
            $history=$db->prepare('SELECT COUNT(*) FROM medical_history_updates WHERE patient_id=? AND doctor_id=?');
            $history->execute([$patientId,$doctorId]);
            if((int)$history->fetchColumn()!==1){throw new RuntimeException('V1 medical history timeline row is missing.');}
        }else{
            if($legacyTimelineResult){throw new RuntimeException('V2 compatibility follow-up duplicated a history event already committed with the profile update.');}
            $history=$db->prepare('SELECT COUNT(*) FROM medical_history_events WHERE patient_user_id=? AND doctor_user_id=?');
            $history->execute([$patientId,$doctorId]);
            if((int)$history->fetchColumn()!==3){throw new RuntimeException('V2 doctor medical history did not record each changed field exactly once.');}
        }

        $manualEventWritten=$repo->recordMedicalHistoryUpdate(
            $patientId,$doctorId,'medical_notes','',('Separate history event '.$suffix)
        );
        if(!$manualEventWritten){throw new RuntimeException('A distinct medical history event was not inserted.');}
        if($schema==='v2'){
            $duplicateManualEvent=$repo->recordMedicalHistoryUpdate(
                $patientId,$doctorId,'medical_notes','',('Separate history event '.$suffix)
            );
            if($duplicateManualEvent){throw new RuntimeException('V2 medical history duplicate suppression failed.');}
        }
        $historyCountSql=$schema==='v1'
            ? 'SELECT COUNT(*) FROM medical_history_updates WHERE patient_id=? AND doctor_id=?'
            : 'SELECT COUNT(*) FROM medical_history_events WHERE patient_user_id=? AND doctor_user_id=?';
        $historyCount=$db->prepare($historyCountSql);
        $historyCount->execute([$patientId,$doctorId]);
        $expectedHistoryCount=$schema==='v1'?2:4;
        if((int)$historyCount->fetchColumn()!==$expectedHistoryCount){
            throw new RuntimeException("Medical history timeline count did not match {$expectedHistoryCount} in {$schema}.");
        }

        $unauthorizedRejected=false;
        try{
            $repo->updateMedicalProfile($otherPatient,['allergies'=>'Unauthorized '.$suffix],$doctorId,'doctor');
        }catch(DomainException $e){
            $unauthorizedRejected=str_contains($e->getMessage(),'only update records');
        }
        if(!$unauthorizedRejected){throw new RuntimeException('Doctor was able to update a patient without a clinical relationship.');}

        $adminChanges=['blood_type'=>'O+','emergency_contact_name'=>'Admin Correction '.$suffix,'emergency_contact_phone'=>'01075551234'];
        $adminResult=$repo->updateMedicalProfile(
            $patientId,$adminChanges,$adminId,'admin','Contract reason: correcting synthetic fixture data.'
        );
        if(!$adminResult['changed']||count($adminResult['changed_fields'])!==3){
            throw new RuntimeException('Administrative medical correction did not update all requested fields.');
        }

        if($schema==='v1'){
            $audit=$db->prepare('SELECT COUNT(*) FROM medical_record_audit WHERE patient_id=? AND admin_id=? AND reason=?');
            $audit->execute([$patientId,$adminId,'Contract reason: correcting synthetic fixture data.']);
            if((int)$audit->fetchColumn()!==3){throw new RuntimeException('V1 sensitive medical correction audit rows are missing.');}
        }else{
            $audit=$db->prepare(
                "SELECT COUNT(*) FROM audit_events
                 WHERE actor_user_id=? AND patient_user_id=? AND action_code='medical_record_admin_correction'
                   AND description LIKE ?"
            );
            $audit->execute([$adminId,$patientId,'%Contract reason: correcting synthetic fixture data.%']);
            if((int)$audit->fetchColumn()!==1){throw new RuntimeException('V2 sensitive medical correction audit event is missing.');}
        }

        if(!$db->inTransaction()){throw new RuntimeException('Medical profile repository committed caller-owned transaction.');}
        echo "MEDICAL_PROFILE_DOCTOR_ADMIN_UPDATE_OK|{$schema}|patient={$patientId}|doctor={$doctorId}\n";
    }finally{
        if($db->inTransaction()){$db->rollBack();}
    }

    foreach($counters as $table=>$value){medRestoreCounter($db,$table,$value);}
    $restored=medSnapshot($db,$schema,$patientId);
    if($restored!=$original){throw new RuntimeException("Medical profile rows were not restored in {$database}.");}
    if(medAuditCount($db,$schema,$patientId,'update')!==$adminAuditBefore){
        throw new RuntimeException("Medical profile general audit rows were not rolled back in {$database}.");
    }
    foreach($counters as $table=>$value){
        if(medCounter($db,$table)!==$value){throw new RuntimeException("AUTO_INCREMENT was not restored for {$table} in {$database}.");}
    }
    echo "MEDICAL_PROFILE_ROLLBACK_AND_COUNTER_RESTORE_OK|{$database}\n";
}

try{
    echo "=== Patient medical profile V1/V2 contract ===\n";
    medRun(MED_V1,'v1');
    medRun(MED_V2,'v2');
    echo "RESULT=PASS (medical profile rows/audits/outbox rolled back; counters restored)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"PATIENT MEDICAL PROFILE CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
