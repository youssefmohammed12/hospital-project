<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(10);}
const PR_V1='healthbridge_hb_auth_email_verify_20261114';
const PR_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}
require_once __DIR__.'/../services/Repositories/PasswordResetRepository.php';

function prConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[PR_V1,PR_V2],true)){throw new RuntimeException('Database not allowlisted.');}
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema){throw new RuntimeException('Database identity mismatch.');}
    return $db;
}
function prCounter(PDO $db,string $table):int{
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);return (int)$q->fetchColumn();
}
function prRestoreCounter(PDO $db,string $table,int $value):void{
    if(!preg_match('/^[a-z_]+$/',$table)||$value<1){throw new RuntimeException('Invalid auto increment metadata.');}
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function prRun(string $database,string $schema):void{
    $db=prConnect($database);
    $table=$schema==='v1'?'password_resets':'password_reset_tokens';
    $passwordColumn=$schema==='v1'?'password':'password_hash';
    $expired=(int)$db->query(
        "SELECT COUNT(*) FROM {$table}
         WHERE created_at<DATE_SUB(NOW(),INTERVAL 30 DAY)"
    )->fetchColumn();
    if($expired!==0){
        throw new RuntimeException("Refusing reset contract in {$database}: retention cleanup would remove existing old rows.");
    }

    $candidate=$db->query(
        "SELECT u.id,u.email,u.{$passwordColumn} AS password_hash,u.session_version
         FROM users u
         WHERE u.role='patient' AND u.is_active=1
           AND NOT EXISTS(SELECT 1 FROM {$table} r WHERE r.email=u.email)
         ORDER BY u.id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if(!$candidate){throw new RuntimeException("No active patient without prior reset history in {$database}.");}
    $patientId=(int)$candidate['id'];
    $email=(string)$candidate['email'];
    $counter=prCounter($db,$table);
    $repo=new PasswordResetRepository($db,$schema);
    $cleanupStarted=false;

    try{
        $first=$repo->createRequest($email,null);
        if($first['rate_limited']||!is_string($first['token'])||$first['token']===''){
            throw new RuntimeException('First active-account reset request did not issue a token.');
        }
        $repo->invalidateUndeliveredToken((int)$first['request_id']);
        if($repo->isTokenValid(hash('sha256',$first['token']))){
            throw new RuntimeException('Undelivered token remained usable after invalidation.');
        }
        echo "PASSWORD_RESET_UNDELIVERED_TOKEN_INVALIDATION_OK|{$schema}\n";

        $second=$repo->createRequest($email,null);
        if($second['rate_limited']||!is_string($second['token'])){throw new RuntimeException('Second reset request did not issue a token.');}
        $third=$repo->createRequest($email,null);
        if($third['rate_limited']||!is_string($third['token'])){throw new RuntimeException('Third reset request did not issue a token.');}
        if($repo->isTokenValid(hash('sha256',$second['token']))){
            throw new RuntimeException('A previous reset token was still valid after a newer request.');
        }
        if(!$repo->isTokenValid(hash('sha256',$third['token']))){
            throw new RuntimeException('Newest reset token was not valid.');
        }
        echo "PASSWORD_RESET_TOKEN_ROTATION_OK|{$schema}\n";

        $limited=$repo->createRequest($email,null);
        if(!$limited['rate_limited']||$limited['token']!==null){
            throw new RuntimeException('Per-email reset request limit did not stop the fourth request.');
        }
        echo "PASSWORD_RESET_RATE_LIMIT_OK|{$schema}\n";

        $shadowHash=hash('sha256',random_bytes(32));
        if($schema==='v1'){
            $shadow=$db->prepare(
                "INSERT INTO password_resets(email,token,token_hash,request_ip,expires_at,used,created_at)
                 VALUES (?,NULL,?,NULL,DATE_ADD(NOW(),INTERVAL 60 MINUTE),0,NOW())"
            );
        }else{
            $shadow=$db->prepare(
                "INSERT INTO password_reset_tokens(email,token_hash,request_ip,expires_at,used,created_at)
                 VALUES (?,?,NULL,DATE_ADD(NOW(),INTERVAL 60 MINUTE),0,NOW())"
            );
        }
        $shadow->execute([$email,$shadowHash]);

        $newPassword='password-reset-contract-' . $schema . '-2026';
        $newHash=password_hash($newPassword,PASSWORD_DEFAULT);
        $repo->resetPassword(hash('sha256',$third['token']),$newHash);

        $verify=$db->prepare("SELECT {$passwordColumn} AS password_hash,session_version FROM users WHERE id=?");
        $verify->execute([$patientId]);
        $updated=$verify->fetch(PDO::FETCH_ASSOC);
        if(!$updated||!password_verify($newPassword,(string)$updated['password_hash'])
            ||(int)$updated['session_version']!==(int)$candidate['session_version']+1){
            throw new RuntimeException('Password reset did not persist the hash and exactly one session-version increment.');
        }
        $active=$db->prepare("SELECT COUNT(*) FROM {$table} WHERE email=? AND used=0");
        $active->execute([$email]);
        if((int)$active->fetchColumn()!==0){
            throw new RuntimeException('Password reset did not consume every outstanding token for the account email.');
        }
        if($repo->isTokenValid(hash('sha256',$third['token']))){
            throw new RuntimeException('Consumed password reset token remained valid.');
        }
        echo "PASSWORD_RESET_CREDENTIAL_SESSION_AND_TOKEN_CONSUMPTION_OK|{$schema}\n";
        $cleanupStarted=true;
    }finally{
        // Restore the exact user credential/session values and remove only the
        // reset rows for the chosen patient (the preflight requires zero before).
        $db->prepare(
            "UPDATE users SET {$passwordColumn}=?,session_version=? WHERE id=?"
        )->execute([(string)$candidate['password_hash'],(int)$candidate['session_version'],$patientId]);
        $db->prepare("DELETE FROM {$table} WHERE email=?")->execute([$email]);
        prRestoreCounter($db,$table,$counter);
    }

    $verify=$db->prepare("SELECT {$passwordColumn} AS password_hash,session_version FROM users WHERE id=?");
    $verify->execute([$patientId]);$restored=$verify->fetch(PDO::FETCH_ASSOC);
    if(!$restored||(string)$restored['password_hash']!==(string)$candidate['password_hash']
        ||(int)$restored['session_version']!==(int)$candidate['session_version']){
        throw new RuntimeException("Credential/session values were not restored in {$database}.");
    }
    $left=$db->prepare("SELECT COUNT(*) FROM {$table} WHERE email=?");$left->execute([$email]);
    if((int)$left->fetchColumn()!==0||prCounter($db,$table)!==$counter){
        throw new RuntimeException("Reset fixture rows or AUTO_INCREMENT were not restored in {$database}.");
    }
    echo "PASSWORD_RESET_FIXTURE_CLEANUP_OK|{$database}\n";
}
try{
    echo "=== Password reset v1/v2 contract ===\n";
    prRun(PR_V1,'v1');
    prRun(PR_V2,'v2');
    echo "RESULT=PASS (credentials, tokens and counters restored)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"PASSWORD RESET CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
