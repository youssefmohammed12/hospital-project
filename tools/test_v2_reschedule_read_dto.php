<?php
declare(strict_types=1);

/**
 * Read-only pending-reschedule API DTO parity test.
 * Exact loopback fixture allowlist; no environment file is loaded; no writes.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const RESCHED_READ_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const RESCHED_READ_V2 = 'healthbridge_v2_prototype_20261118';
$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) { fwrite(STDERR,"Loopback DB host required.\n"); exit(11); }

require_once __DIR__ . '/../services/Repositories/RescheduleTransactionRepository.php';

function connectRescheduleReadFixture(string $database,string $host,string $port,string $user,string $password): PDO
{
    if (!in_array($database,[RESCHED_READ_V1,RESCHED_READ_V2],true)) {
        throw new RuntimeException('Database not on exact fixture allowlist.');
    }
    $db=new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$database) {
        throw new RuntimeException('Connected schema did not match exact fixture.');
    }
    return $db;
}

function canonicalRescheduleDto(array $row): array
{
    $status = (string)($row['reschedule_status'] ?? '');
    if ($status === 'pending') { $status = 'requested'; }
    return [
        'id' => (int)$row['id'],
        'user_id' => (int)($row['user_id'] ?? $row['patient_id'] ?? 0),
        'doctor_id' => (int)($row['doctor_id'] ?? 0),
        'patient_name' => (string)($row['patient_name'] ?? ''),
        'doctor' => (string)($row['doctor'] ?? $row['doctor_name'] ?? ''),
        'department' => (string)($row['department'] ?? ''),
        'date' => substr((string)($row['date'] ?? ''),0,10),
        'time' => AppointmentTime::normalize((string)($row['time'] ?? '')),
        'status' => (string)($row['status'] ?? ''),
        'reschedule_status' => $status,
        'pending_reschedule_date' => (string)($row['pending_reschedule_date'] ?? ''),
        'pending_reschedule_time' => AppointmentTime::normalize((string)($row['pending_reschedule_time'] ?? '')),
        'requested_reschedule_date' => (string)($row['requested_reschedule_date'] ?? ''),
        'requested_reschedule_time' => AppointmentTime::normalize((string)($row['requested_reschedule_time'] ?? '')),
        'reschedule_reason' => (string)($row['reschedule_reason'] ?? ''),
        'reschedule_requested_by' => (int)($row['reschedule_requested_by'] ?? 0),
    ];
}

function canonicalHistoryDto(array $row): array
{
    $status = (string)($row['reschedule_status'] ?? '');
    if ($status === 'pending') { $status = 'requested'; }
    return [
        'id' => (int)$row['id'],
        'pending_reschedule_date' => (string)($row['pending_reschedule_date'] ?? ''),
        'pending_reschedule_time' => AppointmentTime::normalize((string)($row['pending_reschedule_time'] ?? '')),
        'requested_reschedule_date' => (string)($row['requested_reschedule_date'] ?? ''),
        'requested_reschedule_time' => AppointmentTime::normalize((string)($row['requested_reschedule_time'] ?? '')),
        'reschedule_reason' => (string)($row['reschedule_reason'] ?? ''),
        'reschedule_status' => $status,
        'reschedule_requested_by' => (int)($row['reschedule_requested_by'] ?? 0),
    ];
}

try {
    $v1=connectRescheduleReadFixture(RESCHED_READ_V1,$host,$port,$user,$password);
    $v2=connectRescheduleReadFixture(RESCHED_READ_V2,$host,$port,$user,$password);
    $repoV1=new RescheduleTransactionRepository($v1,'v1');
    $repoV2=new RescheduleTransactionRepository($v2,'v2');

    $doctorIds=array_map('intval',$v1->query(
        "SELECT DISTINCT doctor_id FROM appointments
         WHERE status='Reschedule Requested' AND reschedule_status IN ('pending','suggested')
         ORDER BY doctor_id"
    )->fetchAll(PDO::FETCH_COLUMN));

    $checks=0;
    $assert=static function(bool $condition,string $label) use (&$checks): void {
        $checks++;
        if (!$condition) { throw new RuntimeException("Assertion failed: {$label}"); }
    };

    $doctorMismatches=[];
    $historyMismatches=[];
    $totalV1=0;
    $totalV2=0;
    foreach ($doctorIds as $doctorId) {
        $left=$repoV1->getPendingRequestsForDoctor($doctorId);
        $right=$repoV2->getPendingRequestsForDoctor($doctorId);
        $leftMap=[];
        $rightMap=[];
        foreach ($left as $row) { $leftMap[(int)$row['id']]=canonicalRescheduleDto($row); }
        foreach ($right as $row) { $rightMap[(int)$row['id']]=canonicalRescheduleDto($row); }
        ksort($leftMap);
        ksort($rightMap);
        if ($leftMap!==$rightMap) { $doctorMismatches[]=$doctorId; }
        $totalV1+=count($left);
        $totalV2+=count($right);

        foreach (array_keys($leftMap) as $appointmentId) {
            $historyV1=$repoV1->getRescheduleHistory($appointmentId);
            $historyV2=$repoV2->getRescheduleHistory($appointmentId);
            if (!$historyV1 || !$historyV2
                || canonicalHistoryDto($historyV1)!==canonicalHistoryDto($historyV2)) {
                $historyMismatches[]=$appointmentId;
            }
        }
    }

    $allV1=$repoV1->getAllPendingRequests();
    $allV2=$repoV2->getAllPendingRequests();
    $allV1Map=[];
    $allV2Map=[];
    foreach ($allV1 as $row) { $allV1Map[(int)$row['id']]=canonicalRescheduleDto($row); }
    foreach ($allV2 as $row) { $allV2Map[(int)$row['id']]=canonicalRescheduleDto($row); }
    ksort($allV1Map);
    ksort($allV2Map);

    $assert($doctorMismatches===[],'Per-doctor pending request DTO parity');
    $assert($historyMismatches===[],'Per-appointment reschedule history DTO parity');
    $assert($allV1Map===$allV2Map,'Admin all-pending request DTO parity');
    $assert($totalV1===12 && $totalV2===12,'Fixture contains twelve matching active requests');

    $neverRequestedId = (int)$v2->query(
        "SELECT a.id FROM appointments a
         WHERE NOT EXISTS (
             SELECT 1 FROM appointment_reschedule_requests r WHERE r.appointment_id=a.id
         )
         ORDER BY a.id LIMIT 1"
    )->fetchColumn();
    $noHistoryV1 = $repoV1->getRescheduleHistory($neverRequestedId);
    $noHistoryV2 = $repoV2->getRescheduleHistory($neverRequestedId);
    $assert(
        $neverRequestedId > 0 && $noHistoryV1 && $noHistoryV2
        && canonicalHistoryDto($noHistoryV1) === canonicalHistoryDto($noHistoryV2),
        'Appointments with no prior requests retain the legacy none/default history DTO'
    );

    echo "DOCTORS_WITH_REQUESTS=".count($doctorIds)."\n";
    echo "REQUESTS_V1={$totalV1}\n";
    echo "REQUESTS_V2={$totalV2}\n";
    echo "CHECKS={$checks}\n";
    echo "RESULT=PASS (read-only; no fixture writes)\n";
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,"RESCHEDULE READ DTO TEST FAILED: ".get_class($e)."\n");
    fwrite(STDERR,$e->getMessage()."\n");
    exit(2);
}
