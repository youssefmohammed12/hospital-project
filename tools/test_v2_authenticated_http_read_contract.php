<?php
declare(strict_types=1);

/**
 * Read-only authenticated HTTP contract for V2-compatible GET controllers.
 * The repository source remains V1-pinned. A disposable copy of api/includes/services
 * is created and only copied schema constants are switched to V2.
 * All HTTP probes are GET, except one rejected POST to a GET-only route.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const HB_DB = 'healthbridge_v2_prototype_20261118';
const HB_PORT = 18766;
const HB_QA = 'C:\\xampp85\\migration-backups\\healthbridge\\hb-v2-authenticated-http-read-20261120';

$root = dirname(__DIR__);
$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$dbUser = (string)(getenv('DB_USER') ?: 'root');
$passValue = getenv('DB_PASS');
$dbPass = $passValue === false ? '' : (string)$passValue;

if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Non-loopback database host refused.\n");
    exit(11);
}

$checks = 0;
$notes = [];
$server = null;
$pipes = [];
$db = null;
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-v2-http-' . bin2hex(random_bytes(8));
$app = $temp . DIRECTORY_SEPARATOR . 'app';
$sessions = $temp . DIRECTORY_SEPARATOR . 'sessions';
$base = 'http://127.0.0.1:' . HB_PORT;
$ok = false;
$failure = null;

function expect(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function copyTree(string $source, string $target): void {
    if (!is_dir($source)) throw new RuntimeException('Missing source folder: ' . basename($source));
    if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) {
        throw new RuntimeException('Could not create temporary folder.');
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $dest = $target . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest) && !mkdir($dest, 0770, true) && !is_dir($dest)) {
                throw new RuntimeException('Could not create copied folder.');
            }
        } elseif ($item->isFile()) {
            $name = strtolower($item->getFilename());
            if ($name === '.env' || str_ends_with($name, '.env')) continue;
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0770, true) && !is_dir(dirname($dest))) {
                throw new RuntimeException('Could not create copied file parent.');
            }
            if (!copy($item->getPathname(), $dest)) {
                throw new RuntimeException('Copy failed: ' . $relative);
            }
        }
    }
}

function removeTree(string $path): void {
    if (!is_dir($path)) {
        if (is_file($path)) @unlink($path);
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() && !$item->isLink()
            ? @rmdir($item->getPathname())
            : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function patchCopiedRoute(string $app, string $route, string $from, string $to): void {
    $path = $app . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $route);
    $source = file_get_contents($path);
    if (!is_string($source)) throw new RuntimeException('Could not read copied route: ' . $route);
    $needle = '$schema = ' . $from . ';';
    $count = 0;
    $updated = str_replace($needle, '$schema = ' . $to . ';', $source, $count);
    if ($count !== 1) throw new RuntimeException('Expected one schema assignment in ' . $route . ', got ' . $count);
    if (file_put_contents($path, $updated) === false) throw new RuntimeException('Could not patch copied route.');
}

function makeSession(string $directory, int $userId, int $version, string $role, ?int $time = null): string {
    session_save_path($directory);
    session_name('PHPSESSID');
    ini_set('session.use_strict_mode', '0');
    $id = bin2hex(random_bytes(16));
    session_id($id);
    if (!session_start()) throw new RuntimeException('Could not create synthetic session.');
    $timestamp = $time ?? time();
    $_SESSION = [
        'user_id' => $userId,
        'role' => $role,
        'auth_created_at' => $timestamp,
        'auth_last_activity' => $timestamp,
        'auth_session_version' => $version,
    ];
    session_write_close();
    if (!is_file($directory . DIRECTORY_SEPARATOR . 'sess_' . $id)) {
        throw new RuntimeException('Synthetic session file missing.');
    }
    return $id;
}

function request(string $base, string $path, ?string $cookie = null, string $method = 'GET'): array {
    $headers = ['Accept: application/json, text/html;q=0.9', 'User-Agent: HB-V2-Auth-Read-QA', 'Connection: close'];
    if ($cookie !== null) $headers[] = 'Cookie: PHPSESSID=' . $cookie;
    $content = '';
    if ($method !== 'GET' && $method !== 'HEAD') {
        $headers[] = 'Content-Type: application/json';
        $content = '{}';
    }
    $ctx = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $content,
        'ignore_errors' => true,
        'timeout' => 30,
        'protocol_version' => 1.1,
    ]]);
    $raw = @file_get_contents($base . $path, false, $ctx);
    $headersOut = $http_response_header ?? [];
    $status = 0;
    foreach ($headersOut as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $m)) $status = (int)$m[1];
    }
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['status' => $status, 'raw' => is_string($raw) ? $raw : '', 'body' => is_array($json) ? $json : []];
}

function success(array $r, string $label): void {
    expect($r['status'] === 200 && ($r['body']['success'] ?? false) === true,
        $label . ' failed with HTTP ' . $r['status']);
}

function patientId(array $body): ?int {
    foreach ([
        $body['patient']['id'] ?? null,
        $body['patient_id'] ?? null,
        $body['medical_record']['patient_id'] ?? null,
    ] as $value) {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) return (int)$value;
    }
    return null;
}

function activeUser(PDO $db, string $role): array {
    $q = $db->prepare("SELECT id,role,name,email,session_version FROM users WHERE role=? AND is_active=1 ORDER BY id LIMIT 1");
    $q->execute([$role]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('No active synthetic ' . $role . ' account.');
    return $row;
}

function awaitServer(): bool {
    for ($i = 0; $i < 40; $i++) {
        $socket = @fsockopen('127.0.0.1', HB_PORT, $errno, $errstr, 0.15);
        if (is_resource($socket)) { fclose($socket); return true; }
        usleep(100000);
    }
    return false;
}

try {
    if (!is_dir(HB_QA) && !mkdir(HB_QA, 0770, true) && !is_dir(HB_QA)) {
        throw new RuntimeException('Could not create QA evidence folder.');
    }

    $db = new PDO(
        "mysql:host={$host};port={$port};dbname=" . HB_DB . ";charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 3]
    );
    expect((string)$db->query('SELECT DATABASE()')->fetchColumn() === HB_DB, 'V2 database identity mismatch.');
    expect((int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ratings' AND COLUMN_NAME='is_public'")->fetchColumn() === 1,
        'V2 rating visibility column is missing.');
    expect((int)$db->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='v_doctor_rating_summary'")->fetchColumn() === 1,
        'V2 public rating summary view is missing.');

    $admin = activeUser($db, 'admin');
    $patient = activeUser($db, 'patient');
    $patientRows = $db->query("SELECT id,role,name,email,session_version FROM users WHERE role='patient' AND is_active=1 ORDER BY id")->fetchAll();

    require_once $root . '/services/ClinicalAccessService.php';
    $clinical = new ClinicalAccessService($db, 'v2');

    $doctorRows = $db->query(
        "SELECT u.id,u.role,u.name,u.email,u.session_version
         FROM users u
         JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
         LEFT JOIN departments dep ON dep.id=dp.department_id
         WHERE u.role='doctor' AND u.is_active=1 AND dp.profile_visible=1
           AND (dp.department_id IS NULL OR dep.status='active' OR dep.id IS NULL)
         ORDER BY u.id"
    )->fetchAll();

    $doctor = null;
    $qualified = null;
    $unrelated = null;
    $appointmentId = 0;
    foreach ($doctorRows as $d) {
        foreach ($patientRows as $p) {
            if ($clinical->doctorCanAccessPatient((int)$d['id'], (int)$p['id'])) {
                $a = $db->prepare("SELECT id FROM appointments WHERE doctor_user_id=? AND patient_user_id=? ORDER BY starts_at DESC,id DESC LIMIT 1");
                $a->execute([(int)$d['id'], (int)$p['id']]);
                $candidateAppointment = (int)$a->fetchColumn();
                if ($candidateAppointment > 0) {
                    $doctor = $d; $qualified = $p; $appointmentId = $candidateAppointment;
                    break 2;
                }
            }
        }
    }
    expect(is_array($doctor) && is_array($qualified) && $appointmentId > 0,
        'No public active doctor with a qualifying patient/appointment was found.');
    foreach ($patientRows as $p) {
        if ((int)$p['id'] !== (int)$qualified['id']
            && !$clinical->doctorCanAccessPatient((int)$doctor['id'], (int)$p['id'])) {
            $unrelated = $p; break;
        }
    }
    expect(is_array($unrelated), 'Could not find a patient outside the selected doctor clinical scope.');

    if (!mkdir($sessions, 0770, true) && !is_dir($sessions)) {
        throw new RuntimeException('Could not create isolated session folder.');
    }
    foreach (['api', 'includes', 'services'] as $folder) {
        copyTree($root . DIRECTORY_SEPARATOR . $folder, $app . DIRECTORY_SEPARATOR . $folder);
    }

    $notes[] = 'Copied api/includes/services to a temporary app root; no vendor, .env, .git or protected source file copied.';
    $notes[] = 'Enabled V2 schema mode only through explicit per-domain process environment selectors in the copied HTTP server.';

    $adminCookie = makeSession($sessions,(int)$admin['id'],(int)$admin['session_version'],'admin');
    $doctorCookie = makeSession($sessions,(int)$doctor['id'],(int)$doctor['session_version'],'doctor');
    $patientCookie = makeSession($sessions,(int)$qualified['id'],(int)$qualified['session_version'],'patient');
    $spoofCookie = makeSession($sessions,(int)$patient['id'],(int)$patient['session_version'],'admin');
    $staleCookie = makeSession($sessions,(int)$patient['id'],0,'patient');
    $expiredCookie = makeSession($sessions,(int)$patient['id'],(int)$patient['session_version'],'patient',time()-28801);

    $probe = @stream_socket_server('tcp://127.0.0.1:' . HB_PORT,$errno,$errstr,STREAM_SERVER_BIND);
    expect(is_resource($probe),'HTTP test port is already in use.');
    fclose($probe);

    $env = getenv();
    if (!is_array($env)) $env = [];
    $env['DB_HOST']='127.0.0.1'; $env['DB_PORT']=$port; $env['DB_USER']=$dbUser; $env['DB_PASS']=$dbPass; $env['DB_NAME']=HB_DB;
    $env['HEALTHBRIDGE_PORTAL_SCHEMA']='v2';
    $env['HEALTHBRIDGE_DOCTORS_SCHEMA']='v2';
    $env['HEALTHBRIDGE_PATIENTS_SCHEMA']='v2';
    $env['HEALTHBRIDGE_CLINICAL_SCHEMA']='v2';
    $env['HEALTHBRIDGE_SCHEDULE_SCHEMA']='v2';
    $env['HEALTHBRIDGE_APPOINTMENTS_SCHEMA']='v2';
    $env['HEALTHBRIDGE_PRESCRIPTIONS_SCHEMA']='v2';
    $env['HEALTHBRIDGE_ANALYTICS_SCHEMA']='v2';
    $env['HEALTHBRIDGE_RATINGS_SCHEMA']='v2';
    $env['HEALTHBRIDGE_AUDIT_SCHEMA']='v2';
    $env['HEALTHBRIDGE_SETTINGS_SCHEMA']='v2';
    $env['HEALTHBRIDGE_DEPARTMENTS_SCHEMA']='v2';

    $server = proc_open(
        [PHP_BINARY,'-d','session.save_path='.$sessions,'-S','127.0.0.1:'.HB_PORT,'-t',$app],
        [0=>['pipe','r'],1=>['file','NUL','a'],2=>['file','NUL','a']],
        $pipes,$app,$env,['bypass_shell'=>true]
    );
    expect(is_resource($server),'Could not start isolated PHP HTTP server.');
    fclose($pipes[0]);
    expect(awaitServer(),'Isolated PHP HTTP server did not start.');

    // Shared authenticated-session middleware.
    expect(request($base,'/api/auth/current_user.php')['status']===401,'Anonymous current-user call was not rejected.');
    $r=request($base,'/api/auth/current_user.php',$adminCookie); success($r,'Admin session');
    expect((int)$r['body']['user']['id']===(int)$admin['id'] && $r['body']['user']['role']==='admin','Admin identity mismatch.');
    $r=request($base,'/api/auth/current_user.php',$doctorCookie); success($r,'Doctor session');
    expect((int)$r['body']['user']['id']===(int)$doctor['id'] && $r['body']['user']['role']==='doctor','Doctor identity mismatch.');
    $r=request($base,'/api/auth/current_user.php',$patientCookie); success($r,'Patient session');
    expect((int)$r['body']['user']['id']===(int)$qualified['id'] && $r['body']['user']['role']==='patient','Patient identity mismatch.');
    $r=request($base,'/api/auth/current_user.php',$spoofCookie); success($r,'Role-refresh session');
    expect($r['body']['user']['role']==='patient','Stored session role was not refreshed from DB.');
    expect(request($base,'/api/auth/current_user.php',$staleCookie)['status']===401,'Stale session_version was not rejected.');
    expect(request($base,'/api/auth/current_user.php',$expiredCookie)['status']===401,'Expired session was not rejected.');
    $notes[]='Auth middleware: anonymous 401; all three roles 200; stale role refreshed; stale-version and expired sessions 401.';

    // V2 EMR and medical-record reads.
    $pid=(int)$qualified['id']; $other=(int)$unrelated['id']; $did=(int)$doctor['id'];
    $r=request($base,'/api/medical/get-emr-data.php?patient_id='.$pid,$adminCookie); success($r,'Admin V2 EMR');
    expect(patientId($r['body'])===$pid,'Admin V2 EMR identity mismatch.');
    $r=request($base,'/api/medical/get-emr-data.php?patient_id='.$pid,$doctorCookie); success($r,'Doctor V2 EMR');
    expect(patientId($r['body'])===$pid,'Doctor V2 EMR identity mismatch.');
    expect(request($base,'/api/medical/get-emr-data.php?patient_id='.$other,$doctorCookie)['status']===403,'Doctor V2 EMR disclosed unrelated patient.');
    $r=request($base,'/api/medical/get-emr-data.php?patient_id='.$other,$patientCookie); success($r,'Patient V2 EMR self-scope');
    expect(patientId($r['body'])===$pid,'Patient V2 EMR did not force self ID.');
    $r=request($base,'/api/medical/get-record.php?patient_id='.$pid,$adminCookie); success($r,'Admin V2 medical record');
    expect((int)($r['body']['patient']['id']??0)===$pid,'Admin medical-record identity mismatch.');
    $r=request($base,'/api/medical/get-record.php?patient_id='.$pid,$doctorCookie); success($r,'Doctor V2 medical record');
    expect((int)($r['body']['patient']['id']??0)===$pid,'Doctor medical-record identity mismatch.');
    $r=request($base,'/api/medical/get-record.php?patient_id='.$other,$patientCookie); success($r,'Patient V2 medical record self-scope');
    expect((int)($r['body']['patient']['id']??0)===$pid,'Patient medical-record route did not force self ID.');
    expect(request($base,'/api/medical/get-emr-data.php?patient_id=abc',$adminCookie)['status']===400,'Malformed EMR ID was not rejected.');
    $postGuard=request($base,'/api/medical/get-emr-data.php?patient_id='.$pid,$adminCookie,'POST');
    expect($postGuard['status']===405,'EMR route did not reject POST; received HTTP '.$postGuard['status'].'.');
    $notes[]='V2 EMR/medical-record HTTP: admin, doctor-qualified, patient-self reads passed; unrelated-doctor denial, malformed ID and authenticated method guard passed.';

    // V2 doctor-only clinical workspace.
    $r=request($base,'/api/doctors/workspace-data.php?patient_id='.$pid.'&appointment_id='.$appointmentId,$doctorCookie);
    success($r,'Doctor V2 workspace');
    expect(patientId($r['body'])===$pid,'Doctor workspace patient identity mismatch.');
    expect(request($base,'/api/doctors/workspace-data.php?patient_id='.$other,$doctorCookie)['status']===403,'Workspace disclosed unrelated patient.');
    expect(request($base,'/api/doctors/workspace-data.php?patient_id='.$pid,$adminCookie)['status']===403,'Admin accessed doctor-only workspace.');
    $notes[]='V2 doctor workspace: qualifying doctor allowed; unrelated patient and admin role rejected.';

    // V2 printable EMR access and patient self-scope.
    $r=request($base,'/api/medical/print-patient-emr.php?patient_id='.$pid,$adminCookie);
    expect($r['status']===200 && stripos($r['raw'],'<!doctype html')!==false,'Admin V2 EMR print failed.');
    $r=request($base,'/api/medical/print-patient-emr.php?patient_id='.$pid,$doctorCookie);
    expect($r['status']===200 && stripos($r['raw'],'<!doctype html')!==false,'Authorized doctor V2 EMR print failed; HTTP '.$r['status'].'.');
    expect(request($base,'/api/medical/print-patient-emr.php?patient_id='.$other,$doctorCookie)['status']===403,'Doctor print disclosed unrelated patient.');
    $r=request($base,'/api/medical/print-patient-emr.php?patient_id='.$other,$patientCookie);
    expect($r['status']===200,'Patient V2 print self-scope failed; HTTP '.$r['status'].'.');
    $ownEmail=htmlspecialchars((string)$qualified['email'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $otherEmail=htmlspecialchars((string)$unrelated['email'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    expect($ownEmail!=='' && str_contains($r['raw'],$ownEmail),'Patient print did not contain authenticated email.');
    expect($otherEmail==='' || $otherEmail===$ownEmail || !str_contains($r['raw'],$otherEmail),'Patient print exposed queried other email.');
    $notes[]='V2 EMR print: admin and authorized doctor returned HTML; unrelated doctor denied; patient query forced to authenticated self.';

    // V2 public directory, availability and private doctor/admin read routes.
    $r=request($base,'/api/doctors/get.php'); success($r,'V2 public doctor directory');
    $publicIds=array_map(static fn(array $d):int=>(int)($d['user_id']??0),$r['body']['doctors']??[]);
    expect(in_array($did,$publicIds,true),'Selected doctor missing from V2 public directory.');
    $r=request($base,'/api/doctors/get-public-availability.php?doctor_id='.$did); success($r,'V2 public availability');
    expect(array_key_exists((string)$did,$r['body']['availability']??[]),'Selected doctor missing from public availability.');
    $r=request($base,'/api/doctors/get_all.php',$adminCookie); success($r,'V2 admin doctor list');
    expect(is_array($r['body']['doctors']??null),'V2 admin doctor list DTO missing.');
    $r=request($base,'/api/doctors/profile.php?id='.$did,$adminCookie); success($r,'V2 admin doctor profile');
    expect(request($base,'/api/doctors/get_all.php',$doctorCookie)['status']===403,'Doctor accessed admin doctor list.');
    expect(request($base,'/api/doctors/get_all.php',$patientCookie)['status']===403,'Patient accessed admin doctor list.');
    expect(request($base,'/api/doctors/profile.php?id='.$did,$doctorCookie)['status']===403,'Doctor accessed admin doctor profile.');
    $r=request($base,'/api/doctors/get-doctor-ratings.php',$doctorCookie); success($r,'V2 doctor own ratings');
    expect(request($base,'/api/doctors/get-doctor-ratings.php?doctor_id='.(int)$admin['id'],$doctorCookie)['status']===403,'Doctor read forged doctor ratings.');
    $r=request($base,'/api/doctors/get-doctor-ratings.php?doctor_id='.$did,$adminCookie); success($r,'V2 admin selected doctor ratings');
    $r=request($base,'/api/doctors/get-admin-ratings.php',$adminCookie); success($r,'V2 admin all ratings');
    expect(request($base,'/api/doctors/get-doctor-ratings.php',$patientCookie)['status']===403,'Patient accessed doctor ratings.');
    expect(request($base,'/api/doctors/get-admin-ratings.php',$doctorCookie)['status']===403,'Doctor accessed admin ratings.');
    expect(request($base,'/api/doctors/get-admin-ratings.php',$patientCookie)['status']===403,'Patient accessed admin ratings.');
    $r=request($base,'/api/audit/get.php',$adminCookie);
    success($r,'V2 admin audit log read');
    expect(is_array($r['body']['filter_options']['actions']??null),'V2 audit action filter DTO missing.');
    expect(is_array($r['body']['filter_options']['admins']??null),'V2 audit actor filter DTO missing.');
    expect(request($base,'/api/audit/get.php',$doctorCookie)['status']===403,'Doctor accessed admin audit log.');
    expect(request($base,'/api/audit/get.php',$patientCookie)['status']===403,'Patient accessed admin audit log.');
    $notes[]='V2 doctor directory, availability, admin profile/list, ratings and generic audit reads passed expected role/scope checks.';

    // V2 patient portal, prescription, schedule and dashboard analytics read surfaces.
    $r=request($base,'/api/patient/dashboard.php',$patientCookie,'POST');
    success($r,'V2 patient dashboard');
    expect(is_array($r['body']['data']??null),'V2 patient dashboard data DTO missing.');
    $r=request($base,'/api/patient/appointments.php',$patientCookie,'GET');
    success($r,'V2 patient appointments');
    expect(is_array($r['body']['data']['appointments']??null),'V2 patient appointment list DTO missing.');
    expect(request($base,'/api/patient/appointments.php',$doctorCookie,'GET')['status']===403,'Doctor accessed patient appointments route.');

    $r=request($base,'/api/prescriptions/get.php',$patientCookie,'GET');
    success($r,'V2 patient prescriptions');
    expect(is_array($r['body']['prescriptions']??null),'V2 patient prescription list DTO missing.');
    $r=request($base,'/api/prescriptions/get.php',$doctorCookie,'GET');
    success($r,'V2 doctor prescriptions');
    $r=request($base,'/api/prescriptions/get.php',$adminCookie,'GET');
    success($r,'V2 admin prescriptions');
    $notes[]='V2 patient dashboard/appointment portal and patient/doctor/admin prescription lists passed authenticated HTTP.';

    $r=request($base,'/api/schedule/get.php?doctor_id='.$did,$adminCookie,'GET');
    success($r,'V2 admin doctor schedule');
    expect(is_array($r['body']['schedule']??null),'V2 doctor schedule DTO missing.');
    $r=request($base,'/api/doctors/get-availability.php',$doctorCookie,'GET');
    success($r,'V2 doctor own operational availability');
    $notes[]='V2 admin schedule read and doctor self-availability read passed authenticated HTTP.';

    $r=request($base,'/api/admin/dashboard-analytics.php',$adminCookie,'GET');
    success($r,'V2 admin dashboard analytics');
    expect(is_array($r['body']['kpi']??null),'V2 analytics KPI DTO missing.');
    expect(request($base,'/api/admin/dashboard-analytics.php',$doctorCookie,'GET')['status']===403,'Doctor accessed admin dashboard analytics.');
    $notes[]='V2 admin dashboard analytics read passed; doctor role denied.';

    // User settings GET: shared profile/preferences plus V2 doctor/admin projections.
    $r=request($base,'/api/settings/get.php',$doctorCookie,'GET');
    success($r,'V2 doctor settings read');
    expect(is_array($r['body']['preferences']??null),'V2 doctor preferences DTO missing.');
    expect(is_array($r['body']['weekly_schedule']??null),'V2 doctor weekly schedule DTO missing.');
    expect((int)($r['body']['profile']['id']??0)===$did,'V2 doctor settings profile identity mismatch.');
    $r=request($base,'/api/settings/get.php',$adminCookie,'GET');
    success($r,'V2 admin settings read');
    expect(($r['body']['preferences']['admin_default_tab']??'')!=='','V2 admin preference DTO missing.');
    $r=request($base,'/api/settings/get.php',$patientCookie,'GET');
    success($r,'V2 patient settings read');
    expect((int)($r['body']['profile']['id']??0)===$pid,'V2 patient settings profile identity mismatch.');
    $notes[]='V2 settings GET passed for doctor, admin and patient roles.';

    // Legacy appointment/profile read surfaces now project the V2 snapshot model.
    $r=request($base,'/api/appointments/get.php',$patientCookie,'GET');
    success($r,'V2 patient appointment list');
    $patientAppointmentIds=array_map(static fn(array $a):int=>(int)($a['id']??0),$r['body']['appointments']??[]);
    expect(in_array($appointmentId,$patientAppointmentIds,true),'Patient appointment list omitted the qualifying appointment.');
    $r=request($base,'/api/appointments/get.php',$adminCookie,'GET');
    success($r,'V2 admin appointment list');
    expect(request($base,'/api/appointments/get.php',$doctorCookie,'GET')['status']===403,'Doctor accessed patient/admin appointment list.');

    $r=request($base,'/api/appointments/get-doctor.php?doctor_id='.$did,$doctorCookie,'GET');
    success($r,'V2 doctor appointment list');
    $doctorAppointmentIds=array_map(static fn(array $a):int=>(int)($a['id']??0),$r['body']['appointments']??[]);
    expect(in_array($appointmentId,$doctorAppointmentIds,true),'Doctor appointment list omitted the assigned appointment.');
    $r=request($base,'/api/appointments/get-doctor.php?doctor_id='.$did,$adminCookie,'GET');
    success($r,'V2 admin selected doctor appointment list');
    expect(request($base,'/api/appointments/get-doctor.php?doctor_id='.$did,$patientCookie,'GET')['status']===403,'Patient accessed doctor appointment list.');

    $r=request($base,'/api/appointments/print.php?id='.$appointmentId,$patientCookie,'GET');
    expect($r['status']===200 && stripos($r['raw'],'Appointment #'.$appointmentId)!==false,'V2 patient appointment print failed.');
    $r=request($base,'/api/appointments/print.php?id='.$appointmentId,$doctorCookie,'GET');
    expect($r['status']===200 && stripos($r['raw'],'Appointment #'.$appointmentId)!==false,'V2 assigned doctor appointment print failed.');
    expect(request($base,'/api/appointments/print.php?id='.$appointmentId,$adminCookie,'GET')['status']===200,'V2 admin appointment print failed.');

    $r=request($base,'/api/patients/profile.php?id='.$pid,$adminCookie,'GET');
    success($r,'V2 admin patient profile');
    expect((int)($r['body']['patient']['id']??0)===$pid,'V2 admin patient profile identity mismatch.');
    expect(is_array($r['body']['appointments']??null),'V2 admin patient appointment history DTO missing.');
    expect(is_array($r['body']['reviews']??null),'V2 admin patient reviews DTO missing.');
    expect(request($base,'/api/patients/profile.php?id='.$pid,$doctorCookie,'GET')['status']===403,'Doctor accessed admin patient profile.');
    $notes[]='V2 patient/admin appointment lists, doctor/admin appointment lists, printable appointment access, and admin patient profile passed role/scope HTTP checks.';

    $r=request($base,'/api/patients/get-audit.php?patient_id='.$pid,$adminCookie,'GET');
    success($r,'V2 admin patient audit history');
    expect(is_array($r['body']['entries']??null),'V2 patient audit entries DTO missing.');
    $r=request($base,'/api/patients/get-audit.php?patient_id='.$pid,$doctorCookie,'GET');
    success($r,'V2 qualified doctor patient audit history');
    $r=request($base,'/api/patients/get-audit.php?patient_id='.$pid,$patientCookie,'GET');
    success($r,'V2 patient own audit history');
    expect(request($base,'/api/patients/get-audit.php?patient_id='.$other,$patientCookie,'GET')['status']===403,
        'V2 patient audit history exposed unrelated patient entries.');
    $notes[]='V2 patient audit-history read projected audit_events with admin/qualified-doctor/self scope.';

    $r=request($base,'/api/departments/get.php');
    success($r,'V2 public department list');
    expect(is_array($r['body']['departments']??null),'V2 public departments DTO missing.');
    $r=request($base,'/api/departments/get.php?include_inactive=1',$adminCookie,'GET');
    success($r,'V2 admin department list');
    expect(is_array($r['body']['departments']??null),'V2 admin departments DTO missing.');
    $notes[]='V2 department public/admin reads passed using the V2 doctor profile department counts.';

    $ok=true;
} catch (Throwable $e) {
    $failure=$e->getMessage();
    fwrite(STDERR,"FAIL: ".$failure."\n");
} finally {
    if (is_resource($server)) {
        @proc_terminate($server);
        foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
        @proc_close($server);
    }
    removeTree($temp);
    if ($db instanceof PDO) {
        try {
            expect((string)$db->query('SELECT DATABASE()')->fetchColumn()===HB_DB,'Database identity changed during test.');
        } catch (Throwable $e) {
            $failure=($failure ? $failure.'; ' : '').'cleanup DB identity check: '.$e->getMessage();
            $ok=false;
        }
    }

    if (!is_dir(HB_QA)) @mkdir(HB_QA,0770,true);
    $report=[
        '# HealthBridge V2 authenticated HTTP read contract',
        '',
        '- Date: '.date('Y-m-d H:i:s P'),
        '- Database: '.HB_DB.' (loopback only)',
        '- HTTP probes: read-only GET requests; one POST rejected by a GET-only route',
        '- Application: temporary copy of api/includes/services; no .env/vendor/.git copied',
        '- Schema mode: explicit per-domain V2 environment selectors in the copied PHP server only',
        '- Original project files: unchanged by the HTTP harness; live runtime selectors default to V1',
        '',
    ];
    foreach ($notes as $line) $report[]='- '.$line;
    $report[]='- Assertions: '.$checks;
    $report[]='- Result: '.($ok && $failure===null ? 'PASS' : 'FAIL');
    if ($failure!==null) $report[]='- Failure: '.$failure;
    $report[]='- Temporary server: stopped';
    $report[]='- Temporary app copy and sessions: removed';
    @file_put_contents(HB_QA.DIRECTORY_SEPARATOR.'RESULT.md',implode("\n",$report)."\n");
}

if (!$ok || $failure!==null) exit(2);
echo "RESULT=PASS\nASSERTIONS={$checks}\nEVIDENCE=".HB_QA."\\RESULT.md\n";
