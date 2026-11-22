<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(10);}
const RT_V1='healthbridge_hb_auth_email_verify_20261114';
const RT_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}
require_once __DIR__.'/../services/Repositories/RatingRepository.php';

function rtConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[RT_V1,RT_V2],true))throw new RuntimeException('Database not allowlisted.');
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema)throw new RuntimeException('Database identity mismatch.');
    return $db;
}
function rtCounter(PDO $db,string $table):int{
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);$v=$q->fetchColumn();return $v===false||$v===null?0:(int)$v;
}
function rtRestore(PDO $db,string $table,int $value):void{
    if($value<=0)return;
    if(!preg_match('/^[a-z_]+$/',$table))throw new RuntimeException('Invalid counter table.');
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function rtCandidate(PDO $db,string $schema):array{
    $sql=$schema==='v1'
        ? "SELECT a.id,a.user_id AS patient_user_id,a.doctor_id AS doctor_user_id
           FROM appointments a
           JOIN users p ON p.id=a.user_id AND p.role='patient' AND p.is_active=1
           JOIN users d ON d.id=a.doctor_id AND d.role='doctor' AND d.is_active=1
           JOIN visit_workflow w ON w.appointment_id=a.id AND w.status='Completed'
           LEFT JOIN ratings r ON r.appointment_id=a.id
           WHERE a.status='Confirmed' AND r.id IS NULL ORDER BY a.id LIMIT 1"
        : "SELECT a.id,a.patient_user_id,a.doctor_user_id
           FROM appointments a
           JOIN users p ON p.id=a.patient_user_id AND p.role='patient' AND p.is_active=1
           JOIN users d ON d.id=a.doctor_user_id AND d.role='doctor' AND d.is_active=1
           JOIN clinical_encounters ce ON ce.appointment_id=a.id AND ce.workflow_status='completed'
           LEFT JOIN ratings r ON r.appointment_id=a.id
           WHERE a.booking_status='confirmed' AND r.id IS NULL ORDER BY a.id LIMIT 1";
    $row=$db->query($sql)->fetch(PDO::FETCH_ASSOC);
    if(!$row)throw new RuntimeException("No completed, un-rated appointment available in {$schema} fixture.");
    return ['appointment'=>(int)$row['id'],'patient'=>(int)$row['patient_user_id'],'doctor'=>(int)$row['doctor_user_id']];
}
function rtSummary(PDO $db,string $schema,int $doctorId):array{
    if($schema==='v1'){
        $q=$db->prepare('SELECT ROUND(AVG(stars),1) AS average_rating,COUNT(*) AS rating_count FROM ratings WHERE doctor_id=?');
    }else{
        $q=$db->prepare('SELECT average_rating,rating_count FROM v_doctor_rating_summary WHERE doctor_user_id=?');
    }
    $q->execute([$doctorId]);return $q->fetch(PDO::FETCH_ASSOC)?:['average_rating'=>0,'rating_count'=>0];
}
function rtRun(string $database,string $schema):void{
    $db=rtConnect($database);$candidate=rtCandidate($db,$schema);
    $doctorBefore=null;
    if($schema==='v1'){
        $q=$db->prepare('SELECT rating FROM doctors WHERE user_id=?');$q->execute([$candidate['doctor']]);$doctorBefore=$q->fetchColumn();
    }
    $summaryBefore=rtSummary($db,$schema,$candidate['doctor']);
    $tables=['ratings','notifications','notification_outbox'];
    $counters=[];foreach($tables as $table){$v=rtCounter($db,$table);if($v>0)$counters[$table]=$v;}
    $review='Synthetic rating contract '.strtoupper(bin2hex(random_bytes(5)));
    $repo=new RatingRepository($db,$schema);

    $db->beginTransaction();
    try{
        $result=$repo->submitForCompletedAppointment($candidate['appointment'],$candidate['patient'],5,$review);
        if(!$db->inTransaction())throw new RuntimeException('Rating repository committed caller-owned transaction.');
        if((int)$result['appointment_id']!==$candidate['appointment']
            ||(int)$result['doctor_user_id']!==$candidate['doctor']
            ||(int)$result['stars']!==5){
            throw new RuntimeException("{$schema} rating DTO mismatch.");
        }
        $q=$db->prepare('SELECT stars,review FROM ratings WHERE appointment_id=?');$q->execute([$candidate['appointment']]);$rating=$q->fetch(PDO::FETCH_ASSOC);
        if(!$rating||(int)$rating['stars']!==5||$rating['review']!==$review)throw new RuntimeException("{$schema} rating record not created.");
        $summaryAfter=rtSummary($db,$schema,$candidate['doctor']);
        if((int)$summaryAfter['rating_count']!==(int)$summaryBefore['rating_count']+1
            ||(float)$summaryAfter['average_rating']!==(float)$result['average_rating']){
            throw new RuntimeException("{$schema} live rating aggregate incorrect.");
        }
        if($schema==='v1'){
            $q=$db->prepare('SELECT rating FROM doctors WHERE user_id=?');$q->execute([$candidate['doctor']]);
            if((float)$q->fetchColumn()!==(float)$result['average_rating'])throw new RuntimeException('V1 doctor rating cache not synchronized.');
        }
        $duplicateBlocked=false;
        try{$repo->submitForCompletedAppointment($candidate['appointment'],$candidate['patient'],4,'Duplicate rating');}
        catch(DomainException $e){$duplicateBlocked=str_contains($e->getMessage(),'already rated');}
        if(!$duplicateBlocked)throw new RuntimeException("{$schema} duplicate rating was not refused.");
        $q=$db->prepare('SELECT COUNT(*) FROM notification_outbox WHERE ref_type=\'rating\' AND ref_id=? AND status=\'pending\'');
        $q->execute([$candidate['appointment']]);
        if((int)$q->fetchColumn()<1)throw new RuntimeException("{$schema} rating notification was not queued.");
        echo "RATING_SUBMIT_AGGREGATE_DUPLICATE_AND_OUTBOX_OK|{$schema}|appointment={$candidate['appointment']}\n";
    }finally{
        if($db->inTransaction())$db->rollBack();
    }
    foreach($counters as $table=>$counter)rtRestore($db,$table,$counter);
    $q=$db->prepare('SELECT id FROM ratings WHERE appointment_id=?');$q->execute([$candidate['appointment']]);
    if($q->fetchColumn())throw new RuntimeException("Synthetic rating did not roll back in {$database}.");
    if(rtSummary($db,$schema,$candidate['doctor'])!=$summaryBefore)throw new RuntimeException("Rating aggregate did not roll back in {$database}.");
    if($schema==='v1'){
        $q=$db->prepare('SELECT rating FROM doctors WHERE user_id=?');$q->execute([$candidate['doctor']]);
        if((string)$q->fetchColumn()!==(string)$doctorBefore)throw new RuntimeException('V1 doctor rating cache did not roll back.');
    }
    foreach($counters as $table=>$counter){if(rtCounter($db,$table)!==$counter)throw new RuntimeException("{$table} counter not restored.");}
    echo "RATING_ROLLBACK_AND_COUNTER_RESTORE_OK|{$database}\n";
}
try{
    echo "=== Rating V1/V2 contract ===\n";
    rtRun(RT_V1,'v1');
    rtRun(RT_V2,'v2');
    echo "RESULT=PASS (synthetic ratings and notifications rolled back; counters restored)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"RATING CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
