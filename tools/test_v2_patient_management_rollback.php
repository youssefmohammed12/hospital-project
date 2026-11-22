<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }

const PM_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const PM_V2 = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;
if (!in_array($host, ['127.0.0.1','localhost','::1'], true)) { fwrite(STDERR, "Refusing non-loopback DB host.\n"); exit(11); }

require_once __DIR__ . '/../services/Repositories/PatientManagementRepository.php';

function pmConnect(string $database): PDO {
    global $host, $port, $user, $password;
    if (!in_array($database, [PM_V1, PM_V2], true)) { throw new RuntimeException('Fixture is not allowlisted.'); }
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user, $password,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES=>false, PDO::ATTR_TIMEOUT=>3]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $database) { throw new RuntimeException('Connected database mismatch.'); }
    return $db;
}

function pmCounters(PDO $db, array $tables): array {
    $q = $db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $out = [];
    foreach ($tables as $table) {
        $q->execute([$table]);
        $v = $q->fetchColumn();
        if ($v !== false && $v !== null) { $out[$table] = (int)$v; }
    }
    return $out;
}

function pmRestoreCounters(PDO $db, array $values): void {
    foreach ($values as $table=>$value) {
        if (!preg_match('/^[a-z_]+$/', $table) || $value < 1) { throw new RuntimeException('Invalid counter metadata.'); }
        $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
    }
}

function pmAuditCount(PDO $db, string $schema, string $entity, int $id, string $action): int {
    $sql = $schema === 'v1'
        ? 'SELECT COUNT(*) FROM admin_audit WHERE entity_type=? AND entity_id=? AND action=?'
        : 'SELECT COUNT(*) FROM audit_events WHERE entity_type=? AND entity_id=? AND action_code=?';
    $q = $db->prepare($sql);
    $q->execute([$entity,(string)$id,$action]);
    return (int)$q->fetchColumn();
}

function pmCancelCandidate(PDO $db, string $schema): ?array {
    if ($schema === 'v1') {
        $sql = "SELECT a.id,a.user_id AS patient_user_id,a.status AS booking_status
                FROM appointments a JOIN users p ON p.id=a.user_id AND p.role='patient' AND p.is_active=1
                LEFT JOIN visit_workflow w ON w.appointment_id=a.id
                WHERE a.status IN ('Pending','Confirmed') AND TIMESTAMP(a.date,a.time)>NOW()
                  AND (w.status IS NULL OR w.status='Waiting')
                ORDER BY a.date,a.time,a.id LIMIT 1";
    } else {
        $sql = "SELECT a.id,a.patient_user_id,a.booking_status
                FROM appointments a JOIN users p ON p.id=a.patient_user_id AND p.role='patient' AND p.is_active=1
                LEFT JOIN clinical_encounters ce ON ce.appointment_id=a.id
                WHERE a.booking_status IN ('pending','confirmed') AND a.starts_at>NOW()
                  AND (ce.id IS NULL OR ce.workflow_status IN ('uninitialized','waiting'))
                  AND NOT EXISTS (SELECT 1 FROM appointment_reschedule_requests r
                                  WHERE r.appointment_id=a.id AND r.request_status IN ('requested','suggested'))
                ORDER BY a.starts_at,a.id LIMIT 1";
    }
    $row = $db->query($sql)->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function pmArchivePatient(PDO $db, int $exclude1, int $exclude2): int {
    $q = $db->prepare("SELECT id FROM users WHERE role='patient' AND is_active=1 AND id NOT IN (?,?) ORDER BY id LIMIT 1");
    $q->execute([$exclude1,$exclude2]);
    return (int)$q->fetchColumn();
}

function pmRun(string $database, string $schema): void {
    $db = pmConnect($database);
    $tables = $schema === 'v1'
        ? ['admin_audit','notification_outbox','notifications','medical_records']
        : ['audit_events','notification_outbox','notifications','appointment_events','patient_profiles'];
    $beforeCounters = pmCounters($db,$tables);
    $adminId = (int)$db->query("SELECT id FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    $patientId = (int)$db->query("SELECT id FROM users WHERE role='patient' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
    if ($adminId<=0 || $patientId<=0) { throw new RuntimeException("Missing active actors in {$database}."); }

    $passwordColumn = $schema === 'v1' ? 'password' : 'password_hash';
    $origQ = $db->prepare("SELECT name,email,is_active,session_version,{$passwordColumn} AS pass_hash FROM users WHERE id=?");
    $origQ->execute([$patientId]);
    $origUser = $origQ->fetch(PDO::FETCH_ASSOC);
    $profileTable = $schema === 'v1' ? 'medical_records' : 'patient_profiles';
    $profileKey = $schema === 'v1' ? 'patient_id' : 'patient_user_id';
    $profileQ = $db->prepare("SELECT gender,date_of_birth FROM {$profileTable} WHERE {$profileKey}=? LIMIT 1");
    $profileQ->execute([$patientId]);
    $origProfile = $profileQ->fetch(PDO::FETCH_ASSOC) ?: ['gender'=>null,'date_of_birth'=>null];

    $cancel = pmCancelCandidate($db,$schema);
    $cancelPatientId = (int)($cancel['patient_user_id'] ?? 0);
    $archiveId = pmArchivePatient($db,$patientId,$cancelPatientId);
    if ($archiveId<=0) { throw new RuntimeException('Could not find separate active archive patient.'); }

    $repo = new PatientManagementRepository($db,$schema);
    $db->beginTransaction();
    try {
        $newGender = ($origProfile['gender'] ?? null)==='Male' ? 'Female' : 'Male';
        $newEmail = 'patient-contract-' . $schema . '-' . bin2hex(random_bytes(4)) . '@example.invalid';
        $update = $repo->updatePatient($patientId,[
            'name'=>'Patient Contract '.strtoupper($schema),'email'=>$newEmail,
            'gender'=>$newGender,'date_of_birth'=>'1992-04-17'
        ],$adminId,'admin');
        if (!$update['changed'] || $update['status_changed'] || $update['password_changed']) { throw new RuntimeException('Patient update flags incorrect.'); }
        $identity = $db->prepare('SELECT name,email FROM users WHERE id=?');
        $identity->execute([$patientId]);
        $identityRow = $identity->fetch(PDO::FETCH_ASSOC);
        if ($identityRow['name']!=='Patient Contract '.strtoupper($schema) || $identityRow['email']!==$newEmail) {
            throw new RuntimeException('Patient identity fields were not persisted.');
        }
        $profileQ->execute([$patientId]);
        $newProfile = $profileQ->fetch(PDO::FETCH_ASSOC);
        if (!$newProfile || $newProfile['gender']!==$newGender || $newProfile['date_of_birth']!=='1992-04-17') {
            throw new RuntimeException("Patient demographics did not map to {$profileTable}.");
        }
        if (pmAuditCount($db,$schema,'patient',$patientId,'update')<1) { throw new RuntimeException('Patient update audit missing.'); }

        $newPassword = 'contract-only-password-2026';
        $repo->resetPatientPassword($patientId,password_hash($newPassword,PASSWORD_DEFAULT),$adminId);
        $passQ = $db->prepare("SELECT {$passwordColumn},session_version FROM users WHERE id=?");
        $passQ->execute([$patientId]);
        $passRow = $passQ->fetch(PDO::FETCH_ASSOC);
        if (!password_verify($newPassword,(string)$passRow[$passwordColumn])
            || (int)$passRow['session_version']!==(int)$origUser['session_version']+1) {
            throw new RuntimeException('Password reset/hash/session revocation check failed.');
        }

        $deactivated = $repo->togglePatientActive($patientId, $adminId);
        $reactivated = $repo->togglePatientActive($patientId, $adminId);
        $statusCheck = $db->prepare('SELECT is_active,session_version FROM users WHERE id=?');
        $statusCheck->execute([$patientId]);
        $statusRow = $statusCheck->fetch(PDO::FETCH_ASSOC);
        if ((int)$deactivated['is_active'] !== 0 || (int)$reactivated['is_active'] !== 1
            || !$deactivated['changed'] || !$reactivated['changed']
            || !$statusRow || (int)$statusRow['is_active'] !== 1
            || (int)$statusRow['session_version'] !== (int)$origUser['session_version'] + 3
            || pmAuditCount($db,$schema,'patient',$patientId,'deactivate') < 1
            || pmAuditCount($db,$schema,'patient',$patientId,'activate') < 1) {
            throw new RuntimeException('Patient status toggle/session/audit contract failed.');
        }
        echo "PATIENT_STATUS_TOGGLE_REACTIVATE_OK|{$schema}\n";

        if ($cancel !== null) {
            $cancelId = (int)$cancel['id'];
            $statusColumn = $schema==='v1' ? 'status' : 'booking_status';
            $cancelBeforeQ = $db->prepare("SELECT {$statusColumn} FROM appointments WHERE id=?");
            $cancelBeforeQ->execute([$cancelId]);
            $originalStatus = (string)$cancelBeforeQ->fetchColumn();
            $repo->cancelAppointmentByPatient($cancelId,$cancelPatientId);
            $cancelAfterQ = $db->prepare("SELECT {$statusColumn} FROM appointments WHERE id=?");
            $cancelAfterQ->execute([$cancelId]);
            $expected = $schema==='v1' ? 'Cancelled' : 'cancelled';
            if ((string)$cancelAfterQ->fetchColumn()!==$expected) { throw new RuntimeException('Patient appointment cancellation status failed.'); }
            if (pmAuditCount($db,$schema,'appointment',$cancelId,'cancel')<1) { throw new RuntimeException('Patient cancellation audit missing.'); }
            if ($schema==='v2') {
                $ev = $db->prepare("SELECT COUNT(*) FROM appointment_events WHERE appointment_id=? AND actor_user_id=? AND event_type='patient_cancelled' AND from_status=? AND to_status='cancelled'");
                $ev->execute([$cancelId,$cancelPatientId,$originalStatus]);
                if ((int)$ev->fetchColumn()!==1) { throw new RuntimeException('V2 patient cancellation event missing/incorrect.'); }
            }
            echo "PATIENT_CANCEL_APPOINTMENT_OK|{$schema}|appointment={$cancelId}\n";
        } else {
            echo "PATIENT_CANCEL_APPOINTMENT_NOT_EXERCISED|{$schema}|no_eligible_future_appointment\n";
        }

        $archive = $repo->archivePatient($archiveId,$adminId);
        if (!$archive['changed'] || (int)$archive['is_active']!==0) { throw new RuntimeException('Patient archive result incorrect.'); }
        $activeQ = $db->prepare('SELECT is_active FROM users WHERE id=?');
        $activeQ->execute([$archiveId]);
        if ((int)$activeQ->fetchColumn()!==0 || pmAuditCount($db,$schema,'patient',$archiveId,'archive')<1) {
            throw new RuntimeException('Patient archive account/audit persistence failed.');
        }
        if ($schema==='v2') {
            $activeReq = $db->prepare("SELECT COUNT(*) FROM appointment_reschedule_requests r JOIN appointments a ON a.id=r.appointment_id WHERE a.patient_user_id=? AND a.booking_status='cancelled' AND r.request_status IN ('requested','suggested')");
            $activeReq->execute([$archiveId]);
            if ((int)$activeReq->fetchColumn()!==0) { throw new RuntimeException('Archive left active reschedule child requests.'); }
        }
        echo "PATIENT_UPDATE_PASSWORD_ARCHIVE_OK|{$schema}|patient={$archiveId}\n";
        if (!$db->inTransaction()) { throw new RuntimeException('Repository committed caller-owned transaction.'); }
    } finally {
        if ($db->inTransaction()) { $db->rollBack(); }
    }

    pmRestoreCounters($db,$beforeCounters);
    $verifyQ = $db->prepare("SELECT name,email,is_active,session_version,{$passwordColumn} AS pass_hash FROM users WHERE id=?");
    $verifyQ->execute([$patientId]);
    $restored = $verifyQ->fetch(PDO::FETCH_ASSOC);
    if (!$restored || $restored['name']!==$origUser['name'] || $restored['email']!==$origUser['email']
        || (int)$restored['is_active']!==(int)$origUser['is_active']
        || (int)$restored['session_version']!==(int)$origUser['session_version']
        || $restored['pass_hash']!==$origUser['pass_hash']) { throw new RuntimeException("Patient account rollback failed in {$database}."); }

    $profileQ->execute([$patientId]);
    $restoredProfile = $profileQ->fetch(PDO::FETCH_ASSOC) ?: ['gender'=>null,'date_of_birth'=>null];
    if (($restoredProfile['gender']??null)!==($origProfile['gender']??null)
        || ($restoredProfile['date_of_birth']??null)!==($origProfile['date_of_birth']??null)) { throw new RuntimeException("Patient demographics rollback failed in {$database}."); }

    $activeQ = $db->prepare('SELECT is_active FROM users WHERE id=?');
    $activeQ->execute([$archiveId]);
    if ((int)$activeQ->fetchColumn()!==1) { throw new RuntimeException("Archived patient rollback failed in {$database}."); }

    if ($cancel !== null) {
        $statusColumn = $schema==='v1' ? 'status' : 'booking_status';
        $verifyCancel = $db->prepare("SELECT {$statusColumn} FROM appointments WHERE id=?");
        $verifyCancel->execute([(int)$cancel['id']]);
        if ((string)$verifyCancel->fetchColumn()!==(string)$cancel['booking_status']) {
            throw new RuntimeException("Appointment cancellation rollback failed in {$database}.");
        }
    }
    if (pmCounters($db,$tables)!==$beforeCounters) { throw new RuntimeException("AUTO_INCREMENT restoration failed in {$database}."); }
    echo "PATIENT_MANAGEMENT_ROLLBACK_AND_COUNTER_RESTORE_OK|{$database}\n";
}

try {
    echo "=== Patient management v1/v2 contract ===\n";
    pmRun(PM_V1,'v1');
    pmRun(PM_V2,'v2');
    echo "RESULT=PASS (both fixtures rolled back; AUTO_INCREMENT counters restored)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR,"PATIENT MANAGEMENT CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
