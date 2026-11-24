<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const DS_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const DS_V2 = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;
if (!in_array($host, ['127.0.0.1','localhost','::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback DB host.\n"); exit(11);
}
require_once __DIR__ . '/../services/Repositories/DoctorSettingsRepository.php';

function dsConnect(string $schema): PDO {
    global $host,$port,$user,$password;
    if (!in_array($schema,[DS_V1,DS_V2],true)) { throw new RuntimeException('Database not allowlisted.'); }
    $db = new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4",$user,$password,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3
    ]);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn()!==$schema) { throw new RuntimeException('Schema identity mismatch.'); }
    return $db;
}
function dsCounter(PDO $db,string $table): int {
    $q=$db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$table]); return (int)$q->fetchColumn();
}
function dsRestoreCounter(PDO $db,string $table,int $value): void {
    if (!preg_match('/^[a-z_]+$/',$table) || $value<1) { throw new RuntimeException('Invalid auto increment metadata.'); }
    $db->exec("ALTER TABLE {$table} AUTO_INCREMENT={$value}");
}
function dsAuditCount(PDO $db,string $schema,int $doctorId): int {
    $sql=$schema==='v1'
        ? "SELECT COUNT(*) FROM admin_audit WHERE entity_type='doctor' AND entity_id=? AND action='update'"
        : "SELECT COUNT(*) FROM audit_events WHERE entity_type='doctor' AND entity_id=? AND action_code='update'";
    $q=$db->prepare($sql);$q->execute([(string)$doctorId]);return (int)$q->fetchColumn();
}
function dsRun(string $database,string $schema): void {
    $db=dsConnect($database);
    $auditTable=$schema==='v1'?'admin_audit':'audit_events';
    $counter=dsCounter($db,$auditTable);
    if ($schema==='v1') {
        $row=$db->query("SELECT u.id,d.available FROM users u JOIN doctors d ON d.user_id=u.id
            WHERE u.role='doctor' AND u.is_active=1 ORDER BY u.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $profileTable='doctors';$profileIdCol='user_id';
        $prefsQ=$db->prepare('SELECT accept_new_patients,consultation_duration,working_hours_start,working_hours_end,profile_visible FROM user_preferences WHERE user_id=?');
        $scheduleQ=$db->prepare('SELECT appointment_duration,is_available FROM doctor_schedule_settings WHERE doctor_id=?');
    } else {
        $row=$db->query("SELECT u.id,dp.accepts_new_patients,dp.profile_visible
            FROM users u JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
            WHERE u.role='doctor' AND u.is_active=1 ORDER BY u.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $profileTable='doctor_profiles';$profileIdCol='doctor_user_id';
        $prefsQ=null;
        $scheduleQ=$db->prepare('SELECT appointment_duration_min,is_available FROM doctor_schedule_settings WHERE doctor_user_id=?');
    }
    if (!$row) { throw new RuntimeException("No active doctor candidate in {$database}."); }
    $doctorId=(int)$row['id'];
    $prefs=null;
    if ($prefsQ) { $prefsQ->execute([$doctorId]); $prefs=$prefsQ->fetch(PDO::FETCH_ASSOC); }
    $scheduleQ->execute([$doctorId]); $schedule=$scheduleQ->fetch(PDO::FETCH_ASSOC) ?: [];
    $originalAvailability=$schema==='v1'?(int)$row['available']:(int)($row['accepts_new_patients']??1);
    $originalOperationalAvailability=(int)($schedule['is_available']??1);
    $repo=new DoctorSettingsRepository($db,$schema);

    $db->beginTransaction();
    try {
        $acceptOld=$schema==='v1'?(int)($prefs['accept_new_patients']??$row['available']):(int)$row['accepts_new_patients'];
        $visibleOld=$schema==='v1'?(int)($prefs['profile_visible']??1):(int)$row['profile_visible'];
        $changes=[
            'accept_new_patients'=>$acceptOld===1?0:1,
            'consultation_duration'=>45,
            'profile_visible'=>$visibleOld===1?0:1
        ];
        $originalWeekly = null;
        $changedWorkingDay = null;
        if ($schema==='v2') {
            $scheduleService = new ScheduleService($db, 'v2');
            $originalSchedule = $scheduleService->getSchedule($doctorId);
            if (!$originalSchedule) { throw new RuntimeException('V2 doctor schedule was not readable.'); }
            $originalWeekly = $originalSchedule['weekly'];
            $weekly = $originalWeekly;
            foreach ($weekly as &$day) {
                if ((int)$day['is_working'] === 1) {
                    $hours = $scheduleService->getHospitalHours();
                    $day['start_time'] = $hours['open'];
                    $day['end_time'] = $hours['close'];
                    $changedWorkingDay = (int)$day['day_of_week'];
                    break;
                }
            }
            unset($day);
            if ($changedWorkingDay === null) { throw new RuntimeException('V2 doctor has no working day to update.'); }
            $changes['weekly_schedule'] = $weekly;
        }
        if ($schema==='v1') {
            $changes['working_hours_start']='08:30';
            $changes['working_hours_end']='16:30';
        }
        $result=$repo->updateDoctorSettings($doctorId,$changes,$doctorId);
        if (!$result['changed']) { throw new RuntimeException('Doctor settings change was not reported.'); }

        if ($schema==='v1') {
            $verify=$db->prepare('SELECT available FROM doctors WHERE user_id=?');
            $verify->execute([$doctorId]);
            if ((int)$verify->fetchColumn()!==$changes['accept_new_patients']) { throw new RuntimeException('V1 accepts-new setting did not synchronize legacy doctor availability.'); }
            $verify=$db->prepare('SELECT consultation_duration,working_hours_start,working_hours_end,profile_visible FROM user_preferences WHERE user_id=?');
            $verify->execute([$doctorId]);$got=$verify->fetch(PDO::FETCH_ASSOC);
            if ((int)$got['consultation_duration']!==45 || $got['working_hours_start']!=='08:30:00'
                && $got['working_hours_start']!=='08:30' || $got['working_hours_end']!=='16:30:00'
                && $got['working_hours_end']!=='16:30' || (int)$got['profile_visible']!==$changes['profile_visible']) {
                throw new RuntimeException('V1 preference settings were not persisted correctly.');
            }
            $verify=$db->prepare('SELECT appointment_duration,is_available FROM doctor_schedule_settings WHERE doctor_id=?');
            $verify->execute([$doctorId]);$scheduleNew=$verify->fetch(PDO::FETCH_ASSOC);
            if (!$scheduleNew || (int)$scheduleNew['appointment_duration']!==45
                || (int)$scheduleNew['is_available']!==$originalOperationalAvailability) {
                throw new RuntimeException('V1 schedule duration or operational availability mapping failed.');
            }
            $readDto = $repo->getDoctorSettings($doctorId);
            if ((int)$readDto['preferences']['accept_new_patients']!==$changes['accept_new_patients']
                || (int)$readDto['preferences']['operational_available']!==$originalOperationalAvailability) {
                throw new RuntimeException('V1 settings read DTO conflated acceptance and operational availability.');
            }
        } else {
            $verify=$db->prepare('SELECT accepts_new_patients,profile_visible FROM doctor_profiles WHERE doctor_user_id=?');
            $verify->execute([$doctorId]);$got=$verify->fetch(PDO::FETCH_ASSOC);
            if ((int)$got['accepts_new_patients']!==$changes['accept_new_patients']
                || (int)$got['profile_visible']!==$changes['profile_visible']) {
                throw new RuntimeException('V2 profile-level doctor settings were not updated.');
            }
            $verify=$db->prepare('SELECT appointment_duration_min,is_available FROM doctor_schedule_settings WHERE doctor_user_id=?');
            $verify->execute([$doctorId]);$scheduleNew=$verify->fetch(PDO::FETCH_ASSOC);
            if (!$scheduleNew || (int)$scheduleNew['appointment_duration_min']!==45
                || (int)$scheduleNew['is_available']!==$originalOperationalAvailability) {
                throw new RuntimeException('V2 schedule duration update conflated operational availability.');
            }
            $weeklyVerify = new ScheduleService($db, 'v2');
            $storedSchedule = $weeklyVerify->getSchedule($doctorId);
            $storedDay = null;
            foreach (($storedSchedule['weekly'] ?? []) as $day) {
                if ((int)$day['day_of_week'] === $changedWorkingDay) { $storedDay = $day; break; }
            }
            if (!$storedDay || $storedDay['start_time'] !== $changes['weekly_schedule'][$changedWorkingDay - 1]['start_time']
                || $storedDay['end_time'] !== $changes['weekly_schedule'][$changedWorkingDay - 1]['end_time']) {
                // The weekly payload is sorted Monday-Sunday; the changed day index is day-1.
                throw new RuntimeException('V2 per-weekday schedule update was not persisted.');
            }
            $readDto = $repo->getDoctorSettings($doctorId);
            if (count($readDto['weekly_schedule'] ?? []) !== 7
                || (int)$readDto['preferences']['consultation_duration'] !== 45
                || (int)$readDto['preferences']['operational_available'] !== $originalOperationalAvailability
                || (int)$readDto['preferences']['accept_new_patients'] !== $changes['accept_new_patients']) {
                throw new RuntimeException('V2 settings read DTO did not expose the normalized weekly schedule.');
            }
            $allOff = $changes['weekly_schedule'];
            foreach ($allOff as &$day) { $day['is_working'] = 0; }
            unset($day);
            $invalidRejected = false;
            try {
                $repo->updateDoctorSettings($doctorId, ['weekly_schedule' => $allOff], $doctorId);
            } catch (DomainException $e) {
                $invalidRejected = str_contains($e->getMessage(), 'At least one working day');
            }
            if (!$invalidRejected) {
                throw new RuntimeException('V2 rejected neither an all-days-off schedule nor its invalid state.');
            }
            $afterInvalid = $weeklyVerify->getSchedule($doctorId);
            $afterInvalidDay = null;
            foreach (($afterInvalid['weekly'] ?? []) as $day) {
                if ((int)$day['day_of_week'] === $changedWorkingDay) { $afterInvalidDay = $day; break; }
            }
            if (!$afterInvalidDay || $afterInvalidDay['start_time'] !== $storedDay['start_time']) {
                throw new RuntimeException('Rejected invalid weekly schedule changed the persisted schedule.');
            }
            $blocked=false;
            try { $repo->updateDoctorSettings($doctorId,['working_hours_start'=>'08:30'],$doctorId); }
            catch (DomainException $e) { $blocked=str_contains($e->getMessage(),'separate schedule for each weekday'); }
            if (!$blocked) { throw new RuntimeException('V2 legacy global working-hours write was not safely rejected.'); }
        }

        $beforeToggle = $repo->getDoctorSettings($doctorId);
        $wasOperational = (int)$beforeToggle['preferences']['operational_available'];
        $wasAccepting = (int)$beforeToggle['preferences']['accept_new_patients'];
        $toggleResult = $repo->toggleOperationalAvailability($doctorId, $doctorId);
        $afterToggle = $repo->getDoctorSettings($doctorId);
        // Legacy V1 toggles doctors.available then synchronizes schedule availability.
        // V2 toggles only the schedule flag; accepts_new_patients stays independent.
        $expectedToggledAvailability = $schema === 'v1'
            ? ($wasAccepting === 1 ? 0 : 1)
            : ($wasOperational === 1 ? 0 : 1);
        if ((int)$toggleResult['available'] !== $expectedToggledAvailability
            || (int)$afterToggle['preferences']['operational_available'] !== (int)$toggleResult['available']) {
            throw new RuntimeException("{$schema} operational availability did not toggle according to its schema contract.");
        }
        if ($schema === 'v2') {
            if ((int)$afterToggle['preferences']['accept_new_patients'] !== $wasAccepting) {
                throw new RuntimeException('V2 operational availability toggle changed profile-level patient acceptance.');
            }
        } else {
            if ((int)$afterToggle['preferences']['accept_new_patients'] !== (int)$toggleResult['available']) {
                throw new RuntimeException('V1 availability toggle no longer synchronizes the legacy doctor profile.');
            }
        }

        if (dsAuditCount($db,$schema,$doctorId)<1) { throw new RuntimeException('Doctor settings audit event missing.'); }
        if (!$db->inTransaction()) { throw new RuntimeException('Repository committed caller-owned transaction.'); }
        echo "DOCTOR_SETTINGS_SCHEMA_MAPPING_OK|{$schema}|doctor={$doctorId}\n";
    } finally {
        if ($db->inTransaction()) { $db->rollBack(); }
    }
    dsRestoreCounter($db,$auditTable,$counter);
    if (dsCounter($db,$auditTable)!==$counter) { throw new RuntimeException('Audit AUTO_INCREMENT was not restored.'); }
    if ($schema==='v1') {
        $verify=$db->prepare('SELECT available FROM doctors WHERE user_id=?');
        $verify->execute([$doctorId]);
        if ((int)$verify->fetchColumn()!==(int)$row['available']) { throw new RuntimeException('V1 availability did not roll back.'); }
    } else {
        $verify=$db->prepare('SELECT accepts_new_patients,profile_visible FROM doctor_profiles WHERE doctor_user_id=?');
        $verify->execute([$doctorId]);$restored=$verify->fetch(PDO::FETCH_ASSOC);
        if ((int)$restored['accepts_new_patients']!==(int)$row['accepts_new_patients']
            || (int)$restored['profile_visible']!==(int)$row['profile_visible']) { throw new RuntimeException('V2 doctor profile settings did not roll back.'); }
    }
    echo "DOCTOR_SETTINGS_ROLLBACK_AND_COUNTER_RESTORE_OK|{$database}\n";
}
try {
    echo "=== Doctor settings v1/v2 contract ===\n";
    dsRun(DS_V1,'v1');
    dsRun(DS_V2,'v2');
    echo "RESULT=PASS (both fixtures rolled back; audit AUTO_INCREMENT restored)\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR,"DOCTOR SETTINGS CONTRACT FAILED: ".get_class($e)."\n".$e->getMessage()."\n");
    exit(2);
}
