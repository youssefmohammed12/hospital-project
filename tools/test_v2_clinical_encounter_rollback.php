<?php
declare(strict_types=1);

/**
 * Clinical encounter v1/v2 contract.
 *
 * Read-only parity covers all migrated workflow/note records.
 * Write coverage creates synthetic appointments, workflows, notes and drafts
 * in an outer transaction on each disposable fixture and rolls it back.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }

const CLINICAL_V1_FIXTURE = 'healthbridge_hb_auth_email_verify_20261114';
const CLINICAL_V2_FIXTURE = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;

if (!in_array($host, ['127.0.0.1','localhost','::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB host.\n"); exit(11);
}
if (!preg_match('/^[0-9]{1,5}$/', $port) || (int)$port<1 || (int)$port>65535) {
    fwrite(STDERR, "Invalid DB port.\n"); exit(12);
}

require_once __DIR__ . '/../services/Repositories/ClinicalEncounterRepository.php';
require_once __DIR__ . '/../services/Repositories/AppointmentBookingRepository.php';
require_once __DIR__ . '/../services/VisitWorkflowService.php';
require_once __DIR__ . '/../services/VisitDraftService.php';
require_once __DIR__ . '/../services/ScheduleService.php';

function connectClinicalFixture(string $database,string $host,string $port,string $user,string $password): PDO
{
    if (!in_array($database,[CLINICAL_V1_FIXTURE,CLINICAL_V2_FIXTURE],true)) {
        throw new RuntimeException('Database is not on exact clinical fixture allowlist.');
    }
    $db=new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,$password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$database) {
        throw new RuntimeException('Connected schema did not match exact clinical fixture.');
    }
    return $db;
}

function clinicalAutoIncrementSnapshot(PDO $db,array $tables): array
{
    $stmt=$db->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $result=[];
    foreach ($tables as $table) {
        $stmt->execute([$table]);
        $value=$stmt->fetchColumn();
        if ($value!==false && $value!==null) $result[$table]=(int)$value;
    }
    return $result;
}

function restoreClinicalAutoIncrement(PDO $db,array $snapshot): void
{
    foreach ($snapshot as $table=>$value) {
        if (!preg_match('/^[a-z_]+$/',$table) || (int)$value<1) {
            throw new RuntimeException('Invalid clinical AUTO_INCREMENT restore metadata.');
        }
        $db->exec("ALTER TABLE `{$table}` AUTO_INCREMENT = ".(int)$value);
    }
}

function compareClinicalValue(mixed $left,mixed $right): bool
{
    if ($left===null || $right===null) return $left===$right;
    return (string)$left===(string)$right;
}

function findClinicalBookingSlots(PDO $db,string $schema): array
{
    if ($schema==='v1') {
        $doctors=$db->query(
            "SELECT u.id AS doctor_user_id,d.department_id
             FROM users u JOIN doctors d ON d.user_id=u.id
             JOIN doctor_schedule_settings ss ON ss.doctor_id=u.id
             JOIN departments dep ON dep.id=d.department_id
             WHERE u.role='doctor' AND u.is_active=1 AND ss.is_available=1
               AND d.department_id IS NOT NULL AND dep.status='active'
             ORDER BY u.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $doctors=$db->query(
            "SELECT u.id AS doctor_user_id,dp.department_id
             FROM users u JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
             JOIN doctor_schedule_settings ss ON ss.doctor_user_id=u.id
             JOIN departments dep ON dep.id=dp.department_id
             WHERE u.role='doctor' AND u.is_active=1 AND ss.is_available=1
               AND dep.status='active'
             ORDER BY u.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    $schedule=new ScheduleService($db,$schema);
    foreach ($doctors as $doctor) {
        $doctorId=(int)$doctor['doctor_user_id'];
        $slots=[];
        for ($offset=1;$offset<=45 && count($slots)<3;$offset++) {
            $date=(new DateTimeImmutable('today'))->modify("+{$offset} days")->format('Y-m-d');
            foreach ($schedule->getAvailableSlots($doctorId,$date) as $slot) {
                $slots[]=['date'=>$date,'time'=>(string)$slot['time']];
                if (count($slots)>=3) break;
            }
        }
        if (count($slots)>=3) {
            return [
                'doctor_user_id'=>$doctorId,
                'department_id'=>(int)$doctor['department_id'],
                'slots'=>$slots,
            ];
        }
    }
    throw new RuntimeException("No doctor with three future free slots in {$schema}.");
}

function clinicalPatientId(PDO $db): int
{
    $id=(int)$db->query(
        "SELECT id FROM users WHERE role='patient' AND is_active=1 ORDER BY id LIMIT 1"
    )->fetchColumn();
    if ($id<=0) throw new RuntimeException('No active patient fixture row.');
    return $id;
}

function canonicalWorkflow(?array $row): ?array
{
    if (!$row) return null;
    return [
        'appointment_id'=>(int)$row['appointment_id'],
        'status'=>(string)$row['status'],
        'started_at'=>$row['started_at'] ?? null,
        'completed_at'=>$row['completed_at'] ?? null,
    ];
}

function canonicalNote(?array $row): ?array
{
    if (!$row) return null;
    return [
        'appointment_id'=>(int)$row['appointment_id'],
        'patient_id'=>(int)$row['patient_id'],
        'doctor_id'=>(int)$row['doctor_id'],
        'diagnosis'=>$row['diagnosis'],
        'symptoms'=>$row['symptoms'],
        'treatment'=>$row['treatment'],
        'doctor_notes'=>$row['doctor_notes'],
        'follow_up_instructions'=>$row['follow_up_instructions'],
    ];
}

function assertClinical(bool $condition,string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function verifyExistingClinicalParity(PDO $v1,PDO $v2): array
{
    $repo1=new ClinicalEncounterRepository($v1,'v1');
    $repo2=new ClinicalEncounterRepository($v2,'v2');

    $workflowIds=array_map('intval',$v1->query(
        'SELECT appointment_id FROM visit_workflow ORDER BY appointment_id'
    )->fetchAll(PDO::FETCH_COLUMN));

    $workflowMismatches=[];
    foreach ($workflowIds as $id) {
        $left=$repo1->getWorkflow($id);
        $right=$repo2->getWorkflow($id);
        if (canonicalWorkflow($left)!==canonicalWorkflow($right)) {
            $workflowMismatches[]=$id;
        }
    }

    $noteRows=$v1->query('SELECT appointment_id FROM visit_notes ORDER BY appointment_id')
        ->fetchAll(PDO::FETCH_COLUMN);
    $noteIds=array_map('intval',$noteRows);
    $noteMismatches=[];
    foreach ($noteIds as $id) {
        $left=$repo1->getNote($id);
        $right=$repo2->getNote($id);
        if (canonicalNote($left)!==canonicalNote($right)) {
            $noteMismatches[]=$id;
        }
    }

    $patientIds=array_map('intval',$v1->query(
        'SELECT DISTINCT patient_id FROM visit_notes ORDER BY patient_id'
    )->fetchAll(PDO::FETCH_COLUMN));
    $patientNoteMismatches=[];
    foreach ($patientIds as $patientId) {
        $left=$repo1->getNotesForPatient($patientId);
        $right=$repo2->getNotesForPatient($patientId);
        $leftMap=[];
        $rightMap=[];
        foreach ($left as $row) $leftMap[(int)$row['appointment_id']]=canonicalNote($row);
        foreach ($right as $row) $rightMap[(int)$row['appointment_id']]=canonicalNote($row);
        ksort($leftMap); ksort($rightMap);
        if ($leftMap!==$rightMap) $patientNoteMismatches[]=$patientId;
    }

    $ids=$v1->query('SELECT appointment_id FROM visit_workflow ORDER BY appointment_id')
        ->fetchAll(PDO::FETCH_COLUMN);
    $workflowMap1=$repo1->getWorkflowsForAppointments($ids);
    $workflowMap2=$repo2->getWorkflowsForAppointments($ids);
    $bulkMismatches=[];
    foreach ($ids as $id) {
        $id=(int)$id;
        if (canonicalWorkflow($workflowMap1[$id]??null)!==canonicalWorkflow($workflowMap2[$id]??null)) {
            $bulkMismatches[]=$id;
        }
    }

    assertClinical($workflowMismatches===[],'Existing workflow records differ between v1/v2 for appointment IDs: '.implode(',',$workflowMismatches));
    assertClinical($noteMismatches===[],'Existing note records differ between v1/v2 for appointment IDs: '.implode(',',$noteMismatches));
    assertClinical($patientNoteMismatches===[],'Patient note-list DTOs differ for patient IDs: '.implode(',',$patientNoteMismatches));
    assertClinical($bulkMismatches===[],'Bulk workflow map DTOs differ: '.implode(',',$bulkMismatches));

    return [
        'workflow_count'=>count($workflowIds),
        'note_count'=>count($noteIds),
        'patient_count'=>count($patientIds),
        'checks'=>4,
    ];
}

function runClinicalWriteContract(string $database,string $schema,string $host,string $port,string $user,string $password): void
{
    $db=connectClinicalFixture($database,$host,$port,$user,$password);
    $tables=$schema==='v1'
        ? ['appointments','visit_workflow','visit_notes','visit_drafts','admin_audit','notification_outbox']
        : ['appointments','clinical_encounters','encounter_drafts','appointment_events','audit_events','notification_outbox'];
    $autoBefore=clinicalAutoIncrementSnapshot($db,$tables);

    $candidate=findClinicalBookingSlots($db,$schema);
    $patientId=clinicalPatientId($db);
    $doctorId=$candidate['doctor_user_id'];
    $departmentId=$candidate['department_id'];
    $slots=$candidate['slots'];

    $booking=new AppointmentBookingRepository($db,$schema);
    $workflow=new VisitWorkflowService($db,$schema);
    $drafts=new VisitDraftService($db,$schema);
    $encounters=new ClinicalEncounterRepository($db,$schema);

    $lockWithoutTransactionDenied=false;
    try {
        $encounters->lockAppointmentContext(1);
    } catch (RuntimeException $expected) {
        $lockWithoutTransactionDenied=true;
    }
    assertClinical($lockWithoutTransactionDenied,'Appointment locking was allowed without a caller-owned transaction.');

    $appointmentWorkflowId=0;
    $appointmentNoteOnlyId=0;

    $db->beginTransaction();
    try {
        $appointmentWorkflowId=$booking->book([
            'doctor_user_id'=>$doctorId,
            'patient_user_id'=>$patientId,
            'department_id'=>$departmentId,
            'date'=>$slots[0]['date'],
            'time'=>$slots[0]['time'],
            'notes'=>'Synthetic clinical workflow contract appointment.',
            'actor_user_id'=>$patientId,
            'actor_role'=>'patient',
        ]);
        $appointmentNoteOnlyId=$booking->book([
            'doctor_user_id'=>$doctorId,
            'patient_user_id'=>$patientId,
            'department_id'=>$departmentId,
            'date'=>$slots[1]['date'],
            'time'=>$slots[1]['time'],
            'notes'=>'Synthetic clinical note-only contract appointment.',
            'actor_user_id'=>$patientId,
            'actor_role'=>'patient',
        ]);

        if ($schema==='v1') {
            $db->prepare("UPDATE appointments SET status='Confirmed' WHERE id IN (?,?)")
                ->execute([$appointmentWorkflowId,$appointmentNoteOnlyId]);
        } else {
            $db->prepare("UPDATE appointments SET booking_status='confirmed' WHERE id IN (?,?)")
                ->execute([$appointmentWorkflowId,$appointmentNoteOnlyId]);
        }

        $context=$encounters->getAppointmentContext($appointmentWorkflowId);
        assertClinical(
            ($context['id']??null)===$appointmentWorkflowId
            && ($context['doctor_user_id']??null)===$doctorId
            && ($context['patient_user_id']??null)===$patientId
            && ($context['status']??null)==='confirmed'
            && ($context['appointment_date']??null)===$slots[0]['date']
            && ($context['appointment_time']??null)===$slots[0]['time'],
            'Schema-neutral appointment context did not match the booked appointment.'
        );
        $lockedContext=$encounters->lockAppointmentContext($appointmentWorkflowId);
        assertClinical(
            $lockedContext===$context,
            'Locked appointment context differed from the pre-lock context.'
        );

        assertClinical($workflow->get($appointmentWorkflowId)===null,'New booking should not have an implicit workflow.');
        assertClinical($workflow->create($appointmentWorkflowId),'Workflow create failed.');
        $waiting=$workflow->get($appointmentWorkflowId);
        assertClinical(($waiting['status']??null)==='Waiting','New workflow did not begin in Waiting.');
        $again=$workflow->getOrCreateWorkflow($appointmentWorkflowId);
        assertClinical(($again['status']??null)==='Waiting','getOrCreateWorkflow was not idempotent.');

        $initialFields=[
            'diagnosis'=>'Synthetic diagnosis A',
            'symptoms'=>'Synthetic symptoms A',
            'treatment'=>'Synthetic treatment A',
            'doctor_notes'=>'Synthetic clinician notes A',
            'follow_up_instructions'=>'Synthetic follow up A',
        ];
        assertClinical($encounters->saveNote($appointmentWorkflowId,$patientId,$doctorId,$initialFields),'Initial clinical note save failed.');
        $note=$encounters->getNote($appointmentWorkflowId);
        assertClinical(($note['diagnosis']??null)===$initialFields['diagnosis'],'Clinical note read-back failed.');

        $updatedFields=[
            'diagnosis'=>'Synthetic diagnosis B',
            'symptoms'=>'Synthetic symptoms B',
            'treatment'=>'Synthetic treatment B',
            'doctor_notes'=>'Synthetic clinician notes B',
            'follow_up_instructions'=>'Synthetic follow up B',
        ];
        assertClinical($workflow->saveNote($appointmentWorkflowId,$patientId,$doctorId,$updatedFields),'Clinical note update failed.');
        $note=$encounters->getNote($appointmentWorkflowId);
        assertClinical(($note['diagnosis']??null)===$updatedFields['diagnosis'],'Clinical note update did not persist.');
        assertClinical(count($encounters->getNotesForPatient($patientId))>=1,'Patient note history did not include the synthetic note.');

        $draftFields=[
            'diagnosis'=>'Draft diagnosis A',
            'symptoms'=>'Draft symptoms A',
            'treatment'=>'Draft treatment A',
            'doctor_notes'=>'Draft notes A',
            'follow_up_instructions'=>'Draft follow up A',
        ];
        assertClinical($drafts->save($appointmentWorkflowId,$doctorId,$draftFields),'Draft save failed.');
        $draft=$drafts->get($appointmentWorkflowId);
        assertClinical(($draft['diagnosis']??null)===$draftFields['diagnosis'],'Draft read-back failed.');
        $draftFields['diagnosis']='Draft diagnosis B';
        assertClinical($drafts->save($appointmentWorkflowId,$doctorId,$draftFields),'Draft update failed.');
        assertClinical(($drafts->get($appointmentWorkflowId)['diagnosis']??null)==='Draft diagnosis B','Draft update did not persist.');

        $workflow->transition($appointmentWorkflowId,VisitWorkflowService::STATUS_IN_PROGRESS);
        $inProgress=$workflow->get($appointmentWorkflowId);
        assertClinical(($inProgress['status']??null)==='In Progress' && !empty($inProgress['started_at']),'In Progress transition did not set started_at.');

        $workflow->transition($appointmentWorkflowId,VisitWorkflowService::STATUS_READY_TO_COMPLETE);
        assertClinical(($workflow->get($appointmentWorkflowId)['status']??null)==='Ready to Complete','Ready to Complete transition failed.');

        $workflow->transition($appointmentWorkflowId,VisitWorkflowService::STATUS_IN_PROGRESS);
        $workflow->transition($appointmentWorkflowId,VisitWorkflowService::STATUS_COMPLETED);
        $completed=$workflow->get($appointmentWorkflowId);
        assertClinical(
            ($completed['status']??null)==='Completed' && !empty($completed['completed_at']),
            'Completed transition did not set completed_at.'
        );

        $invalidTransitionBlocked=false;
        try {
            $workflow->transition($appointmentWorkflowId,VisitWorkflowService::STATUS_IN_PROGRESS);
        } catch (DomainException $expected) {
            $invalidTransitionBlocked=true;
        }
        assertClinical($invalidTransitionBlocked,'Completed workflow incorrectly allowed a later transition.');

        assertClinical($drafts->delete($appointmentWorkflowId),'Draft delete failed.');
        assertClinical($drafts->get($appointmentWorkflowId)===null,'Draft still exists after delete.');

        // A visit note can exist without a v1 workflow row. V2 represents that
        // as workflow_status=uninitialized; the workflow API must still return null.
        $noteOnlyFields=[
            'diagnosis'=>'Note-only diagnosis',
            'symptoms'=>null,
            'treatment'=>null,
            'doctor_notes'=>'Note saved without workflow creation',
            'follow_up_instructions'=>null,
        ];
        assertClinical($encounters->saveNote($appointmentNoteOnlyId,$patientId,$doctorId,$noteOnlyFields),'Note-only save failed.');
        assertClinical($workflow->get($appointmentNoteOnlyId)===null,'Note-only save incorrectly exposed a workflow.');
        assertClinical(($encounters->getNote($appointmentNoteOnlyId)['diagnosis']??null)==='Note-only diagnosis','Note-only clinical data was not readable.');
        $upgraded=$workflow->getOrCreateWorkflow($appointmentNoteOnlyId);
        assertClinical(($upgraded['status']??null)==='Waiting','Explicit workflow creation did not upgrade uninitialized encounter to Waiting.');

        if (!$db->inTransaction()) {
            throw new RuntimeException('A clinical repository committed the caller-owned transaction.');
        }

        echo "CLINICAL_WORKFLOW_CREATE_TRANSITION_OK|{$schema}|appointment={$appointmentWorkflowId}\n";
        echo "CLINICAL_NOTE_CREATE_UPDATE_OK|{$schema}\n";
        echo "CLINICAL_DRAFT_CREATE_UPDATE_DELETE_OK|{$schema}\n";
        echo "CLINICAL_NOTE_ONLY_UNINITIALIZED_UPGRADE_OK|{$schema}|appointment={$appointmentNoteOnlyId}\n";
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }

    foreach ([$appointmentWorkflowId,$appointmentNoteOnlyId] as $appointmentId) {
        if ($appointmentId<=0) continue;
        $stmt=$db->prepare('SELECT COUNT(*) FROM appointments WHERE id=?');
        $stmt->execute([$appointmentId]);
        assertClinical((int)$stmt->fetchColumn()===0,"Synthetic clinical appointment {$appointmentId} survived rollback.");
    }
    restoreClinicalAutoIncrement($db,$autoBefore);
    assertClinical(clinicalAutoIncrementSnapshot($db,$tables)===$autoBefore,'Clinical test AUTO_INCREMENT counters were not restored.');
    echo "CLINICAL_FIXTURE_ROLLBACK_OK|{$database}\n";
}

try {
    echo "=== HealthBridge clinical encounter compatibility ===\n";
    $v1=connectClinicalFixture(CLINICAL_V1_FIXTURE,$host,$port,$user,$password);
    $v2=connectClinicalFixture(CLINICAL_V2_FIXTURE,$host,$port,$user,$password);
    $parity=verifyExistingClinicalParity($v1,$v2);
    echo "EXISTING_WORKFLOWS={$parity['workflow_count']}\n";
    echo "EXISTING_NOTES={$parity['note_count']}\n";
    echo "PATIENT_NOTE_LISTS={$parity['patient_count']}\n";
    echo "READ_ONLY_PARITY_CHECKS={$parity['checks']}\n";
    runClinicalWriteContract(CLINICAL_V1_FIXTURE,'v1',$host,$port,$user,$password);
    runClinicalWriteContract(CLINICAL_V2_FIXTURE,'v2',$host,$port,$user,$password);
    echo "RESULT=PASS (all synthetic writes rolled back and counters restored)\n";
    exit(0);
} catch(Throwable $e) {
    fwrite(STDERR,'CLINICAL ENCOUNTER CONTRACT FAILED/BLOCKED: '.get_class($e)."\n");
    fwrite(STDERR,$e->getMessage()."\n");
    fwrite(STDERR,$e->getTraceAsString()."\n");
    exit(2);
}
