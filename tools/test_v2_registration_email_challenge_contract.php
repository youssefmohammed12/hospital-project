<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(10);}
const EC_V1='healthbridge_hb_auth_email_verify_20261114';
const EC_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}
$testKey=base64_encode(random_bytes(32));
putenv('HB_REGISTRATION_QUEUE_KEYS_JSON='.json_encode(['contract-test'=>$testKey]));
putenv('HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION=contract-test');
require_once __DIR__.'/../services/RegistrationIntakeService.php';

function ecConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[EC_V1,EC_V2],true)){throw new RuntimeException('Database not allowlisted.');}
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema){throw new RuntimeException('Database identity mismatch.');}
    return $db;
}
function ecCounter(PDO $db,string $table):int{
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);return (int)$q->fetchColumn();
}
function ecRestore(PDO $db,string $table,int $value):void{
    if(!preg_match('/^[a-z_]+$/',$table)||$value<1){throw new RuntimeException('Invalid auto increment metadata.');}
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function ecRun(string $database,string $schema):void{
    $db=ecConnect($database);
    $queueCounter=ecCounter($db,'registration_intake_queue');
    $challengeCounter=ecCounter($db,'registration_email_challenges');
    $requestId=bin2hex(random_bytes(16));
    $tokenA=bin2hex(random_bytes(32));
    $tokenB=bin2hex(random_bytes(32));
    $hashA=hash('sha256',$tokenA);
    $hashB=hash('sha256',$tokenB);
    $queueInsert=$db->prepare(
        "INSERT INTO registration_intake_queue
            (request_id,payload_version,key_version,status,attempt_count,available_at,
             created_at,verification_expires_at)
         VALUES (?,1,'contract-test','awaiting_verification',0,NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 10 MINUTE))"
    );
    $queueInsert->execute([$requestId]);
    $queueId=(int)$db->lastInsertId();
    $challengeInsert=$db->prepare(
        'INSERT INTO registration_email_challenges(queue_id,token_hash,created_at,expires_at)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(),INTERVAL 10 MINUTE))'
    );
    $challengeInsert->execute([$queueId,$hashA]);$challengeA=(int)$db->lastInsertId();
    $challengeInsert->execute([$queueId,$hashB]);$challengeB=(int)$db->lastInsertId();

    try{
        $service=new RegistrationIntakeService($db,$schema);
        if(!$service->confirmRegistrationEmail($tokenA)){
            throw new RuntimeException("{$schema} valid email verification challenge was rejected.");
        }
        $queue=$db->prepare(
            'SELECT status,email_verified_at,email_lookup_hmac,verification_expires_at
             FROM registration_intake_queue WHERE id=?'
        );
        $queue->execute([$queueId]);$queueRow=$queue->fetch(PDO::FETCH_ASSOC);
        if(!$queueRow||$queueRow['status']!=='pending'||empty($queueRow['email_verified_at'])
            ||$queueRow['email_lookup_hmac']!==null||$queueRow['verification_expires_at']!==null){
            throw new RuntimeException("{$schema} queue was not released/marked verified correctly.");
        }
        $challenge=$db->prepare(
            'SELECT id,consumed_at,revoked_at FROM registration_email_challenges WHERE queue_id=? ORDER BY id'
        );
        $challenge->execute([$queueId]);$rows=$challenge->fetchAll(PDO::FETCH_ASSOC);
        if(count($rows)!==2||(int)$rows[0]['id']!==$challengeA||empty($rows[0]['consumed_at'])
            ||!empty($rows[0]['revoked_at'])||(int)$rows[1]['id']!==$challengeB||empty($rows[1]['revoked_at'])){
            throw new RuntimeException("{$schema} confirmation did not consume one challenge and revoke its sibling.");
        }
        if($service->confirmRegistrationEmail($tokenA)){
            throw new RuntimeException("{$schema} consumed registration token was accepted a second time.");
        }
        if($service->confirmRegistrationEmail($tokenB)){
            throw new RuntimeException("{$schema} revoked sibling registration token remained usable.");
        }
        echo "REGISTRATION_EMAIL_CHALLENGE_SINGLE_USE_AND_QUEUE_RELEASE_OK|{$schema}\n";
    }finally{
        $db->prepare('DELETE FROM registration_intake_queue WHERE id=?')->execute([$queueId]);
        ecRestore($db,'registration_intake_queue',$queueCounter);
        ecRestore($db,'registration_email_challenges',$challengeCounter);
    }
    if(ecCounter($db,'registration_intake_queue')!==$queueCounter
        ||ecCounter($db,'registration_email_challenges')!==$challengeCounter){
        throw new RuntimeException("{$schema} registration challenge counters were not restored.");
    }
    echo "REGISTRATION_EMAIL_CHALLENGE_CLEANUP_OK|{$database}\n";
}
try{
    echo "=== Registration email challenge v1/v2 contract ===\n";
    ecRun(EC_V1,'v1');
    ecRun(EC_V2,'v2');
    echo "RESULT=PASS (synthetic challenges and queue rows removed; counters restored)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"REGISTRATION EMAIL CHALLENGE CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}finally{
    putenv('HB_REGISTRATION_QUEUE_KEYS_JSON');
    putenv('HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION');
}
