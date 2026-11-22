<?php
declare(strict_types=1);

/**
 * Read-only patient appointment timeline DTO parity between legacy v1 and v2.
 * Uses only the exact loopback synthetic fixtures; no .env or writes.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const TIMELINE_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const TIMELINE_V2 = 'healthbridge_v2_prototype_20261118';

$host=strtolower(trim((string)(getenv('DB_HOST')?:'127.0.0.1'),'[]'));
$port=(string)(getenv('DB_PORT')?:'3306');
$user=(string)(getenv('DB_USER')?:'root');
$password=getenv('DB_PASS');
$password=$password===false?'':(string)$password;
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) { fwrite(STDERR,"Loopback DB host required.\n"); exit(11); }

require_once __DIR__ . '/../services/PatientPortalService.php';

function connectTimelineFixture(string $database,string $host,string $port,string $user,string $password): PDO
{
    if (!in_array($database,[TIMELINE_V1,TIMELINE_V2],true)) throw new RuntimeException('Fixture not allowlisted.');
    $db=new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$database) throw new RuntimeException('Connected schema mismatch.');
    return $db;
}

function normalizeTimelineTime(mixed $value): string
{
    return AppointmentTime::normalize((string)($value??''));
}
function normalizeTimelineRange(mixed $value): string
{
    return trim(str_replace('–','-',(string)($value??'')));
}
function timelineCanonical(array $row): array
{
    return [
        'id'=>(int)$row['id'],
        'doctor'=>(string)($row['doctor']??''),
        'doctor_id'=>(int)$row['doctor_id'],
        'doctor_specialty'=>(string)($row['doctor_specialty']??''),
        'doctor_available'=>(bool)$row['doctor_available'],
        'department'=>(string)($row['department']??''),
        'department_id'=>(int)$row['department_id'],
        'date'=>(string)$row['date'],
        'time'=>normalizeTimelineTime($row['time']??''),
        'time_range'=>normalizeTimelineRange($row['time_range']??''),
        'notes'=>$row['notes']??null,
        'status'=>(string)$row['status'],
        'workflow_status'=>$row['workflow_status']??null,
        'completed_at'=>$row['completed_at']??null,
        'has_prescription'=>(bool)$row['has_prescription'],
        'prescription_id'=>(int)($row['prescription_id']??0),
        'prescription_status'=>$row['prescription_status']??null,
        'has_rating'=>(bool)$row['has_rating'],
        'rating_stars'=>$row['rating_stars']===null?null:(int)$row['rating_stars'],
        'can_rate'=>(bool)$row['can_rate'],
        'reschedule_status'=>(string)($row['reschedule_status']??'none'),
        'pending_reschedule_date'=>$row['pending_reschedule_date']??null,
        'pending_reschedule_time'=>normalizeTimelineTime($row['pending_reschedule_time']??''),
        'reschedule_reason'=>$row['reschedule_reason']??null,
    ];
}

try {
    $v1=connectTimelineFixture(TIMELINE_V1,$host,$port,$user,$password);
    $v2=connectTimelineFixture(TIMELINE_V2,$host,$port,$user,$password);
    $patientIds=array_map('intval',$v1->query(
        "SELECT DISTINCT user_id FROM appointments ORDER BY user_id"
    )->fetchAll(PDO::FETCH_COLUMN));

    $portal1=new PatientPortalService($v1,'v1');
    $portal2=new PatientPortalService($v2,'v2');
    $mismatches=[];
    $ratingWarnings=[];
    $counts1=['upcoming'=>0,'completed'=>0,'cancelled'=>0,'missed'=>0,'rescheduled'=>0,'total'=>0];
    $counts2=$counts1;
    $appointmentsCompared=0;

    foreach ($patientIds as $patientId) {
        $left=$portal1->getAppointmentTimeline($patientId);
        $right=$portal2->getAppointmentTimeline($patientId);
        foreach (array_keys($counts1) as $key) {
            $counts1[$key]+=(int)($left['counts'][$key]??0);
            $counts2[$key]+=(int)($right['counts'][$key]??0);
        }

        $leftRows=[];
        $rightRows=[];
        foreach (['upcoming','completed','cancelled','missed','rescheduled'] as $bucket) {
            foreach ($left[$bucket] as $row) $leftRows[(int)$row['id']]=timelineCanonical($row);
            foreach ($right[$bucket] as $row) $rightRows[(int)$row['id']]=timelineCanonical($row);
        }
        ksort($leftRows); ksort($rightRows);
        if (array_keys($leftRows)!==array_keys($rightRows)) {
            $mismatches[]="patient {$patientId}:appointment IDs/buckets";
            continue;
        }

        foreach ($leftRows as $appointmentId=>$a) {
            $b=$rightRows[$appointmentId];
            foreach ($a as $field=>$value) {
                if ($field==='doctor_rating') continue;
                if (($field==='reschedule_status') && $value==='pending' && $b[$field]==='requested') continue;
                if ($value!==$b[$field]) $mismatches[]="patient {$patientId} appt {$appointmentId}:{$field}";
            }
            $appointmentsCompared++;
        }
    }

    $ratingDoctorIds=array_map('intval',$v1->query(
        'SELECT DISTINCT doctor_id FROM appointments ORDER BY doctor_id'
    )->fetchAll(PDO::FETCH_COLUMN));
    foreach ($ratingDoctorIds as $doctorId) {
        $ratingFacts=$v1->prepare('SELECT COUNT(*) FROM ratings WHERE doctor_id=?');
        $ratingFacts->execute([$doctorId]);
        if ((int)$ratingFacts->fetchColumn()===0) {
            $leftStmt=$v1->prepare('SELECT rating FROM doctors WHERE user_id=?');
            $leftStmt->execute([$doctorId]);
            $cached=$leftStmt->fetchColumn();
            $rightStmt=$v2->prepare('SELECT average_rating FROM v_doctor_rating_summary WHERE doctor_user_id=?');
            $rightStmt->execute([$doctorId]);
            $derived=$rightStmt->fetchColumn();
            if ($cached!==false && $derived!==false && (float)$cached!==(float)$derived) {
                $ratingWarnings[]="doctor {$doctorId}: stale v1 cached rating is not represented by v2 fact view";
            }
        }
    }

    if ($counts1!==$counts2) {
        $mismatches[]='aggregate timeline category counts differ: '.json_encode(['v1'=>$counts1,'v2'=>$counts2]);
    }
    if ($mismatches) {
        foreach (array_slice(array_unique($mismatches),0,40) as $mismatch) echo "MISMATCH|{$mismatch}\n";
        throw new RuntimeException(count($mismatches).' timeline DTO mismatches.');
    }

    echo "PATIENTS=".count($patientIds)."\n";
    echo "APPOINTMENTS_COMPARED={$appointmentsCompared}\n";
    echo "V1_COUNTS=".json_encode($counts1)."\n";
    echo "V2_COUNTS=".json_encode($counts2)."\n";
    echo "RATING_WARNINGS=".count($ratingWarnings)."\n";
    foreach ($ratingWarnings as $warning) echo "WARNING|{$warning}\n";
    echo "RESULT=PASS (read-only patient timeline DTO parity)\n";
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,"PATIENT TIMELINE CONTRACT FAILED: ".get_class($e)."\n");
    fwrite(STDERR,$e->getMessage()."\n");
    exit(2);
}
