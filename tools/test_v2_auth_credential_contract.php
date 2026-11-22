<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){fwrite(STDERR,"CLI only.\n");exit(10);}
const AC_V1='healthbridge_hb_auth_email_verify_20261114';
const AC_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}
require_once __DIR__.'/../services/Repositories/AuthCredentialRepository.php';

function acConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[AC_V1,AC_V2],true)){throw new RuntimeException('Database not allowlisted.');}
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema){throw new RuntimeException('Database identity mismatch.');}
    return $db;
}
function acRun(string $database,string $schema):void{
    $db=acConnect($database);
    $user=$db->query("SELECT id,email FROM users WHERE role='patient' AND is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!$user){throw new RuntimeException("No active patient for auth lookup in {$database}.");}
    $repo=new AuthCredentialRepository($db,$schema);
    $found=$repo->findLoginUser((string)$user['email']);
    if(!$found||(int)$found['id']!==(int)$user['id']||$found['email']!==$user['email']
        ||!is_string($found['password_hash'])||$found['password_hash']===''
        ||!array_key_exists('email_verification_required',$found)||!array_key_exists('email_verified_at',$found)
        ||!array_key_exists('session_version',$found)){
        throw new RuntimeException("{$schema} credential lookup returned an incomplete login DTO.");
    }
    $passwordColumn=$schema==='v1'?'password':'password_hash';
    $raw=$db->prepare("SELECT {$passwordColumn} FROM users WHERE id=?");
    $raw->execute([(int)$user['id']]);
    if((string)$raw->fetchColumn()!==(string)$found['password_hash']){
        throw new RuntimeException("{$schema} password column was not mapped to the stable password_hash DTO field.");
    }
    if($repo->findLoginUser('unknown-auth-contract@example.invalid')!==null){
        throw new RuntimeException("{$schema} unknown-email lookup returned a user.");
    }
    echo "AUTH_CREDENTIAL_LOOKUP_OK|{$schema}|user=".(int)$user['id']."\n";
}
try{
    echo "=== Auth credential v1/v2 contract ===\n";
    acRun(AC_V1,'v1');
    acRun(AC_V2,'v2');
    echo "RESULT=PASS (read-only against both fixtures)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"AUTH CREDENTIAL CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
