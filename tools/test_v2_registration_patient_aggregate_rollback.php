<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(10);}
const REG_V1='healthbridge_hb_auth_email_verify_20261114';
const REG_V2='healthbridge_v2_prototype_20261118';

$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}

// A process-local synthetic test keyring allows construction of the service
// without reading or exposing any application encryption configuration.
$testKey=base64_encode(random_bytes(32));
putenv('HB_REGISTRATION_QUEUE_KEYS_JSON='.json_encode(['contract-test'=>$testKey]));
putenv('HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION=contract-test');

require_once __DIR__.'/../services/RegistrationIntakeService.php';

function regConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[REG_V1,REG_V2],true)){throw new RuntimeException('Database not allowlisted.');}
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema){throw new RuntimeException('Database identity mismatch.');}
    return $db;
}
function regCounter(PDO $db,string $table):int{
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);return (int)$q->fetchColumn();
}
function regRestoreCounter(PDO $db,string $table,int $value):void{
    if(!preg_match('/^[a-z_]+$/',$table)||$value<1){throw new RuntimeException('Invalid auto increment metadata.');}
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function regRun(string $database,string $schema):void{
    $db=regConnect($database);
    $tables=$schema==='v1'
        ? ['users','user_preferences','medical_records','admin_audit','notification_outbox']
        : ['users','user_preferences','patient_profiles','patient_medical_profiles','audit_events','notification_outbox'];
    // V2 profile/preferences use natural primary keys and have no AUTO_INCREMENT.
    // Snapshot only tables that actually expose an AUTO_INCREMENT value.
    $counters=[];
    foreach($tables as $table){
        $value=regCounter($db,$table);
        if($value>0){$counters[$table]=$value;}
    }

    $gov=$db->query('SELECT id,name FROM egypt_governorates ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $city=null;
    if($gov){
        $cityStmt=$db->prepare('SELECT id,name FROM egypt_cities WHERE governorate_id=? ORDER BY id LIMIT 1');
        $cityStmt->execute([(int)$gov['id']]);$city=$cityStmt->fetch(PDO::FETCH_ASSOC)?:null;
    }
    $suffix=strtoupper(bin2hex(random_bytes(5)));
    $email='registration-contract-'.$schema.'-'.$suffix.'@example.invalid';
    $nationalId='9'.str_pad((string)random_int(0,9999999999999),13,'0',STR_PAD_LEFT);
    $phone='0107'.str_pad((string)random_int(0,99999999),8,'0',STR_PAD_LEFT);
    $hash=password_hash('registration-contract-password-only',PASSWORD_DEFAULT);
    $profile=[
        'first_name'=>'Registration',
        'last_name'=>'Contract '.$schema,
        'email'=>$email,
        'password_hash'=>$hash,
        'phone'=>$phone,
        'national_id'=>$nationalId,
        'date_of_birth'=>'1990-03-14',
        'gender'=>'Male',
        'governorate'=>(string)($gov['name']??'Fixture Governorate'),
        'city'=>(string)($city['name']??'Fixture City'),
        'address'=>'Contract test address, synthetic fixture only',
        'blood_type'=>'A+',
        'emergency_contact_name'=>'Synthetic Emergency Contact',
        'emergency_contact_phone'=>'01070000001',
        'emergency_contact_rel'=>'Sibling',
        'allergies'=>'Synthetic test allergy',
        'chronic_diseases'=>'Synthetic test condition',
        'current_medications'=>'Synthetic test medication',
        'insurance_provider'=>'Synthetic test insurer',
        'insurance_number'=>'CONTRACT-'.$suffix,
    ];

    $service=new RegistrationIntakeService($db,$schema);
    $method=new ReflectionMethod(RegistrationIntakeService::class,'createPatientWithinTransaction');
    $method->setAccessible(true);
    $newUserId=0;
    $emailVerifiedAt=date('Y-m-d H:i:s');

    $db->beginTransaction();
    try{
        [$newUserId,$outboxIds]=$method->invoke($service,$profile,$emailVerifiedAt);
        if(!$db->inTransaction()){throw new RuntimeException('Registration helper committed caller-owned transaction.');}

        $passwordColumn=$schema==='v1'?'password':'password_hash';
        $userStmt=$db->prepare(
            "SELECT name,email,{$passwordColumn} AS password_hash,role,is_active,patient_number,
                    email_verification_required,email_verified_at
             FROM users WHERE id=?"
        );
        $userStmt->execute([$newUserId]);$newUser=$userStmt->fetch(PDO::FETCH_ASSOC);
        if(!$newUser||$newUser['email']!==$email||$newUser['role']!=='patient'||(int)$newUser['is_active']!==1
            ||!password_verify('registration-contract-password-only',(string)$newUser['password_hash'])
            ||(int)$newUser['email_verification_required']!==1
            ||$newUser['email_verified_at']!==$emailVerifiedAt
            ||!str_starts_with((string)$newUser['patient_number'],'HB-')){
            throw new RuntimeException("{$schema} registration user credentials/verification fields were not correctly persisted.");
        }

        if($schema==='v1'){
            $profileStmt=$db->prepare(
                'SELECT date_of_birth,gender,blood_type,governorate,city,address,
                        emergency_contact_name,emergency_contact_phone,emergency_contact_rel,
                        allergies,chronic_diseases,current_medications,insurance_provider,insurance_number
                 FROM medical_records WHERE patient_id=?'
            );
            $profileStmt->execute([$newUserId]);$stored=$profileStmt->fetch(PDO::FETCH_ASSOC);
            if(!$stored||$stored['date_of_birth']!=='1990-03-14'||$stored['gender']!=='Male'
                ||$stored['blood_type']!=='A+'||$stored['governorate']!==$profile['governorate']
                ||$stored['city']!==$profile['city']||$stored['address']!==$profile['address']
                ||$stored['emergency_contact_name']!==$profile['emergency_contact_name']
                ||$stored['allergies']!==$profile['allergies']
                ||$stored['insurance_number']!==$profile['insurance_number']){
                throw new RuntimeException('V1 medical_records aggregate mapping failed.');
            }
            $audit=$db->prepare("SELECT COUNT(*) FROM admin_audit WHERE actor_id=? AND entity_type='patient' AND entity_id=? AND action='create'");
        }else{
            $profileStmt=$db->prepare(
                'SELECT date_of_birth,gender,governorate_id,city_id,governorate_text,city_text,address,
                        insurance_provider,insurance_number,emergency_name,emergency_relation,emergency_phone
                 FROM patient_profiles WHERE patient_user_id=?'
            );
            $profileStmt->execute([$newUserId]);$stored=$profileStmt->fetch(PDO::FETCH_ASSOC);
            if(!$stored||$stored['date_of_birth']!=='1990-03-14'||$stored['gender']!=='Male'
                ||$stored['governorate_text']!==$profile['governorate']||$stored['city_text']!==$profile['city']
                ||$stored['address']!==$profile['address']||$stored['emergency_name']!==$profile['emergency_contact_name']
                ||$stored['emergency_relation']!==$profile['emergency_contact_rel']
                ||$stored['emergency_phone']!==$profile['emergency_contact_phone']
                ||$stored['insurance_number']!==$profile['insurance_number']){
                throw new RuntimeException('V2 patient_profiles aggregate mapping failed.');
            }
            $medicalStmt=$db->prepare('SELECT blood_type,allergies,chronic_diseases,current_medications FROM patient_medical_profiles WHERE patient_user_id=?');
            $medicalStmt->execute([$newUserId]);$medical=$medicalStmt->fetch(PDO::FETCH_ASSOC);
            if(!$medical||$medical['blood_type']!=='A+'||$medical['allergies']!==$profile['allergies']
                ||$medical['chronic_diseases']!==$profile['chronic_diseases']
                ||$medical['current_medications']!==$profile['current_medications']){
                throw new RuntimeException('V2 patient_medical_profiles aggregate mapping failed.');
            }
            $audit=$db->prepare("SELECT COUNT(*) FROM audit_events WHERE actor_user_id=? AND entity_type='patient' AND entity_id=? AND action_code='create'");
        }
        $audit->execute([$newUserId,(string)$newUserId]);
        if((int)$audit->fetchColumn()!==1){throw new RuntimeException("{$schema} registration audit record is missing.");}

        $prefs=$db->prepare('SELECT COUNT(*) FROM user_preferences WHERE user_id=?');
        $prefs->execute([$newUserId]);
        if((int)$prefs->fetchColumn()!==1){throw new RuntimeException("{$schema} registration preference row is missing.");}

        if($outboxIds!==[]){
            $ids=implode(',',array_map('intval',$outboxIds));
            $outbox=(int)$db->query(
                "SELECT COUNT(*) FROM notification_outbox WHERE id IN ({$ids}) AND ref_id={$newUserId} AND status='pending'"
            )->fetchColumn();
            if($outbox!==count($outboxIds)){throw new RuntimeException("{$schema} admin notification outbox capture mismatch.");}
        }
        echo "REGISTRATION_PATIENT_AGGREGATE_OK|{$schema}|user={$newUserId}|outbox_events=".count($outboxIds)."\n";
    }finally{
        if($db->inTransaction()){$db->rollBack();}
    }

    regRestoreCounter($db,'users',$counters['users']);
    foreach($counters as $table=>$value){regRestoreCounter($db,$table,$value);}
    $exists=$db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');$exists->execute([$email]);
    if($exists->fetchColumn()){throw new RuntimeException("Synthetic registration user was not rolled back in {$database}.");}
    foreach($counters as $table=>$value){
        if(regCounter($db,$table)!==$value){throw new RuntimeException("{$table} AUTO_INCREMENT was not restored in {$database}.");}
    }
    echo "REGISTRATION_AGGREGATE_ROLLBACK_AND_COUNTER_RESTORE_OK|{$database}\n";
}
try{
    echo "=== Registration patient aggregate v1/v2 contract ===\n";
    regRun(REG_V1,'v1');
    regRun(REG_V2,'v2');
    echo "RESULT=PASS (synthetic users/profiles/audit/outbox rolled back; counters restored)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"REGISTRATION AGGREGATE CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}finally{
    putenv('HB_REGISTRATION_QUEUE_KEYS_JSON');
    putenv('HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION');
}
