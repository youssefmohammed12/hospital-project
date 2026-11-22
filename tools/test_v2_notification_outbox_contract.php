<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(10); }
const OB_V1='healthbridge_hb_auth_email_verify_20261114';
const OB_V2='healthbridge_v2_prototype_20261118';
const OB_TRIGGER='hb_contract_notification_outbox_fail';

$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');$password=$password===false?'':(string)$password;
if(!in_array($host,['127.0.0.1','localhost','::1'],true)){fwrite(STDERR,"Refusing non-loopback DB host.\n");exit(11);}
require_once __DIR__.'/../services/NotificationService.php';

function obConnect(string $schema):PDO{
    global $host,$port,$user,$password;
    if(!in_array($schema,[OB_V1,OB_V2],true)){throw new RuntimeException('Database not allowlisted.');}
    $db=new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema){throw new RuntimeException('Database identity mismatch.');}
    return $db;
}
function obCounter(PDO $db,string $table):int{
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]);return (int)$q->fetchColumn();
}
function obRestoreCounter(PDO $db,string $table,int $value):void{
    if(!preg_match('/^[a-z_]+$/',$table)||$value<1){throw new RuntimeException('Invalid auto increment metadata.');}
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function obInsert(PDO $db,int $userId,string $title,string $message):int{
    $stmt=$db->prepare(
        "INSERT INTO notification_outbox(user_id,type,title,message,status,available_at,created_at)
         VALUES(?,? ,?,?,'pending',NOW(),NOW())"
    );
    $stmt->execute([$userId,NotificationService::TYPE_APPOINTMENT_CANCELLED,$title,$message]);
    return (int)$db->lastInsertId();
}
function obStatus(PDO $db,int $id):array{
    $stmt=$db->prepare('SELECT status,attempt_count,last_error_code FROM notification_outbox WHERE id=?');
    $stmt->execute([$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
    if(!$row){throw new RuntimeException("Outbox test event {$id} disappeared.");}
    return $row;
}
function obProcess(NotificationService $service,int $id):array{
    return $service->processOutbox(1,$id);
}
function obRun(string $database,string $schema):void{
    $db=obConnect($database);
    $retention=(int)$db->query(
        "SELECT COUNT(*) FROM notification_outbox
         WHERE status IN ('delivered','skipped') AND processed_at<DATE_SUB(NOW(),INTERVAL 30 DAY)"
    )->fetchColumn();
    if($retention!==0){
        throw new RuntimeException("Refusing worker test in {$database}: existing old delivered/skipped rows would be affected by retention cleanup.");
    }
    $triggerCount=$db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');
    $triggerCount->execute([OB_TRIGGER]);
    if((int)$triggerCount->fetchColumn()!==0){throw new RuntimeException('The reserved contract trigger name already exists; refusing to replace it.');}

    $recipient=(int)$db->query("SELECT id FROM users WHERE role='patient' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    if($recipient<=0){throw new RuntimeException("No active patient recipient exists in {$database}.");}
    $missing=4294967294;
    $exists=$db->prepare('SELECT id FROM users WHERE id=? LIMIT 1');$exists->execute([$missing]);
    if($exists->fetchColumn()){throw new RuntimeException('The reserved missing-recipient ID exists; refusing to use it.');}

    $pref=$db->prepare('SELECT notif_appointment FROM user_preferences WHERE user_id=? LIMIT 1');
    $pref->execute([$recipient]);$oldPreference=$pref->fetchColumn();
    $preferenceRowExisted=$oldPreference!==false;
    $outboxCounter=obCounter($db,'notification_outbox');
    $notificationCounter=obCounter($db,'notifications');
    $marker='HB_OUTBOX_CONTRACT_' . strtoupper(bin2hex(random_bytes(5)));
    $titlePrefix=$marker.'_TITLE';
    $messagePrefix=$marker.'_MESSAGE';
    $ids=[];
    $triggerCreated=false;
    $service=new NotificationService($db);

    try{
        $db->prepare(
            'INSERT INTO user_preferences(user_id,notif_appointment) VALUES(?,1)
             ON DUPLICATE KEY UPDATE notif_appointment=1'
        )->execute([$recipient]);

        $deliveredMessage=$messagePrefix.'_DELIVER';
        $ids['deliver']=obInsert($db,$recipient,$titlePrefix.'_DELIVER',$deliveredMessage);
        $stats=obProcess($service,$ids['deliver']);
        $state=obStatus($db,$ids['deliver']);
        if((int)$stats['delivered']!==1||$state['status']!=='delivered'){
            throw new RuntimeException('Pending outbox event did not deliver successfully.');
        }
        $notice=$db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND title=? AND message=?');
        $notice->execute([$recipient,$titlePrefix.'_DELIVER',$deliveredMessage]);
        if((int)$notice->fetchColumn()!==1){throw new RuntimeException('Delivered outbox event did not create exactly one notification.');}
        echo "OUTBOX_DELIVERY_OK|{$schema}\n";

        $ids['missing']=obInsert($db,$missing,$titlePrefix.'_MISSING',$messagePrefix.'_MISSING');
        $stats=obProcess($service,$ids['missing']);
        $state=obStatus($db,$ids['missing']);
        if((int)$stats['skipped']!==1||$state['status']!=='skipped'||$state['last_error_code']!=='recipient_missing'){
            throw new RuntimeException('Missing-recipient outbox event was not safely skipped.');
        }
        echo "OUTBOX_MISSING_RECIPIENT_SKIP_OK|{$schema}\n";

        $db->prepare('UPDATE user_preferences SET notif_appointment=0 WHERE user_id=?')->execute([$recipient]);
        $ids['preference']=obInsert($db,$recipient,$titlePrefix.'_PREF',$messagePrefix.'_PREF');
        $stats=obProcess($service,$ids['preference']);
        $state=obStatus($db,$ids['preference']);
        if((int)$stats['skipped']!==1||$state['status']!=='skipped'||$state['last_error_code']!=='preference_disabled'){
            throw new RuntimeException('Disabled-preference outbox event was not safely skipped.');
        }
        $db->prepare('UPDATE user_preferences SET notif_appointment=1 WHERE user_id=?')->execute([$recipient]);
        echo "OUTBOX_DISABLED_PREFERENCE_SKIP_OK|{$schema}\n";

        $failureMessage=$messagePrefix.'_FORCED_FAILURE';
        $quotedFailure=$db->quote($failureMessage);
        $triggerSql="CREATE TRIGGER " . OB_TRIGGER . " BEFORE INSERT ON notifications
            FOR EACH ROW BEGIN
              IF NEW.message = {$quotedFailure} THEN
                SIGNAL SQLSTATE '45000' SET MYSQL_ERRNO=1644, MESSAGE_TEXT='contract_forced_delivery_failure';
              END IF;
            END";
        $db->exec($triggerSql);
        $triggerCreated=true;

        $ids['failure']=obInsert($db,$recipient,$titlePrefix.'_FAIL',$failureMessage);
        for($attempt=1;$attempt<=8;$attempt++){
            $db->prepare('UPDATE notification_outbox SET available_at=NOW() WHERE id=?')->execute([$ids['failure']]);
            $stats=obProcess($service,$ids['failure']);
            $state=obStatus($db,$ids['failure']);
            if((int)$state['attempt_count']!==$attempt){
                throw new RuntimeException("Retry attempt counter mismatch at attempt {$attempt}.");
            }
            if($attempt<8 && $state['status']!=='pending'){
                throw new RuntimeException("Retry event did not remain pending after attempt {$attempt}.");
            }
            if($attempt===8 && ($state['status']!=='failed'||(int)$stats['failed']!==1)){
                throw new RuntimeException('Retry event did not reach terminal failed state after eight attempts.');
            }
        }
        if(empty($state['last_error_code'])){throw new RuntimeException('Failed outbox event did not retain a safe error classification.');}
        echo "OUTBOX_RETRY_BACKOFF_AND_TERMINAL_FAILURE_OK|{$schema}|attempts=8\n";
    }finally{
        if($triggerCreated){$db->exec('DROP TRIGGER IF EXISTS '.OB_TRIGGER);}
        if($ids){
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $db->prepare("DELETE FROM notification_outbox WHERE id IN ({$placeholders})")->execute(array_values($ids));
        }
        $db->prepare('DELETE FROM notifications WHERE message LIKE ?')->execute([$messagePrefix.'%']);
        if($preferenceRowExisted){
            $db->prepare('UPDATE user_preferences SET notif_appointment=? WHERE user_id=?')
                ->execute([(int)$oldPreference,$recipient]);
        }else{
            $db->prepare('DELETE FROM user_preferences WHERE user_id=?')->execute([$recipient]);
        }
        obRestoreCounter($db,'notification_outbox',$outboxCounter);
        obRestoreCounter($db,'notifications',$notificationCounter);
    }
    if(obCounter($db,'notification_outbox')!==$outboxCounter||obCounter($db,'notifications')!==$notificationCounter){
        throw new RuntimeException('Notification/outbox AUTO_INCREMENT values were not restored.');
    }
    $triggerCount->execute([OB_TRIGGER]);
    if((int)$triggerCount->fetchColumn()!==0){throw new RuntimeException('Temporary outbox test trigger was not removed.');}
    echo "OUTBOX_FIXTURE_CLEANUP_OK|{$database}\n";
}
try{
    echo "=== Notification outbox v1/v2 fixture contract ===\n";
    obRun(OB_V1,'v1');
    obRun(OB_V2,'v2');
    echo "RESULT=PASS (synthetic outbox events removed; preferences/counters restored)\n";
    exit(0);
}catch(Throwable $e){
    fwrite(STDERR,"NOTIFICATION OUTBOX CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
