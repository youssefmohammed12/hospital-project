<?php
declare(strict_types=1);

/**
 * Read-only medical timeline event parity across v1/v2 synthetic fixtures.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR,"CLI only.\n"); exit(10); }
const MED_TIMELINE_V1='healthbridge_hb_auth_email_verify_20261114';
const MED_TIMELINE_V2='healthbridge_v2_prototype_20261118';
$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$pass=getenv('DB_PASS');
$pass=$pass===false?'':(string)$pass;
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) { fwrite(STDERR,"Loopback required.\n"); exit(11); }

require_once __DIR__.'/../services/PatientPortalService.php';

function connectMedicalTimeline(string $name,string $host,string $port,string $user,string $pass): PDO {
    if (!in_array($name,[MED_TIMELINE_V1,MED_TIMELINE_V2],true)) throw new RuntimeException('Fixture not allowlisted.');
    $db=new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4",$user,$pass,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
    ]);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$name) throw new RuntimeException('Connected schema mismatch.');
    return $db;
}

function canonicalTimelineEvents(array $events): array {
    $result=[];
    foreach ($events as $event) {
        $metadata=$event['metadata']??null;
        if (is_array($metadata)) {
            ksort($metadata);
        }
        $result[]=[
            'date'=>(string)$event['date'],
            'type'=>(string)$event['type'],
            'title'=>(string)$event['title'],
            'description'=>(string)$event['description'],
            'metadata'=>$metadata,
        ];
    }
    return $result;
}

try {
    $v1=connectMedicalTimeline(MED_TIMELINE_V1,$host,$port,$user,$pass);
    $v2=connectMedicalTimeline(MED_TIMELINE_V2,$host,$port,$user,$pass);
    $patientIds=array_map('intval',$v1->query(
        'SELECT id FROM users WHERE role=\'patient\' ORDER BY id'
    )->fetchAll(PDO::FETCH_COLUMN));
    $portal1=new PatientPortalService($v1,'v1');
    $portal2=new PatientPortalService($v2,'v2');
    $mismatches=[];
    $eventCount=0;
    $v1Counts=[];
    $v2Counts=[];

    foreach ($patientIds as $patientId) {
        $left=canonicalTimelineEvents($portal1->getMedicalTimeline($patientId));
        $right=canonicalTimelineEvents($portal2->getMedicalTimeline($patientId));
        $eventCount+=count($left);
        $countByType=static function(array $events): array {
            $counts=[];
            foreach($events as $event) $counts[$event['type']]=($counts[$event['type']]??0)+1;
            ksort($counts);
            return $counts;
        };
        $lc=$countByType($left);
        $rc=$countByType($right);
        foreach ($lc as $type=>$count) $v1Counts[$type]=($v1Counts[$type]??0)+$count;
        foreach ($rc as $type=>$count) $v2Counts[$type]=($v2Counts[$type]??0)+$count;

        if ($left!==$right) {
            $detail = [];
            $limit = max(count($left), count($right));
            for ($i=0; $i<$limit; $i++) {
                if (($left[$i] ?? null) !== ($right[$i] ?? null)) {
                    $detail = [
                        'index'=>$i,
                        'v1'=>$left[$i] ?? null,
                        'v2'=>$right[$i] ?? null,
                    ];
                    break;
                }
            }
            $mismatches[]=[
                'patient_id'=>$patientId,
                'v1_count'=>count($left),
                'v2_count'=>count($right),
                'v1_types'=>$lc,
                'v2_types'=>$rc,
                'first_difference'=>$detail,
            ];
        }
    }

    ksort($v1Counts);
    ksort($v2Counts);
    if ($mismatches) {
        foreach (array_slice($mismatches,0,12) as $row) {
            echo 'MISMATCH|'.json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
        }
        throw new RuntimeException(count($mismatches).' patient medical timeline DTOs differ.');
    }
    if ($v1Counts!==$v2Counts) throw new RuntimeException('Aggregate medical timeline event type counts differ.');

    echo "PATIENTS=".count($patientIds)."\n";
    echo "V1_EVENTS={$eventCount}\n";
    echo "V1_EVENT_TYPES=".json_encode($v1Counts,JSON_UNESCAPED_SLASHES)."\n";
    echo "V2_EVENT_TYPES=".json_encode($v2Counts,JSON_UNESCAPED_SLASHES)."\n";
    echo "RESULT=PASS (read-only medical timeline DTO parity)\n";
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,"MEDICAL TIMELINE CONTRACT FAILED: ".get_class($e)."\n");
    fwrite(STDERR,$e->getMessage()."\n");
    exit(2);
}
