<?php
declare(strict_types=1);

/**
 * Authenticated V2 reschedule HTTP mutation contract.
 *
 * A complete clone of the approved V2 fixture is created first. A temporary
 * app copy serves all eight reschedule endpoints with V2 explicitly enabled.
 * The source prototype is read-only throughout; the clone, server, app copy
 * and synthetic sessions are removed in finally.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const HB_RESCHEDULE_SOURCE_DB = 'healthbridge_v2_prototype_20261118';
const HB_RESCHEDULE_QA_ROOT = 'C:\\xampp85\\migration-backups\\healthbridge\\hb-v2-application-http-mutation-20261120';

$root = dirname(__DIR__);
$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$dbUser = (string)(getenv('DB_USER') ?: 'root');
$passValue = getenv('DB_PASS');
$dbPass = $passValue === false ? '' : (string)$passValue;

if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Refusing non-loopback database host.\n");
    exit(11);
}
if (!preg_match('/^[0-9]{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) {
    fwrite(STDERR, "Invalid DB port.\n");
    exit(12);
}

$targetDb = 'hb_v2_resched_http_' . gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3));
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hb-v2-resched-http-' . bin2hex(random_bytes(8));
$app = $temp . DIRECTORY_SEPARATOR . 'app';
$sessions = $temp . DIRECTORY_SEPARATOR . 'sessions';
$server = null;
$pipes = [];
$adminCookie = $doctorCookie = $patientCookie = null;
$control = null;
$clone = null;
$cloneCreated = false;
$checks = 0;
$notes = [];
$failure = null;
$ok = false;
$base = '';
$appointmentId = 0;

function expectHttp(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}

function qid(string $identifier): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new RuntimeException('Unsafe SQL identifier in clone metadata.');
    }
    return '`' . $identifier . '`';
}

function connectServerDb(string $host, string $port, string $user, string $password): PDO {
    return new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]
    );
}

function sourceFingerprint(PDO $db): array {
    $tables = ['appointments', 'appointment_reschedule_requests', 'appointment_events', 'audit_events', 'notification_outbox'];
    $result = [];
    $countStmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    $autoStmt = $db->prepare('SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?');
    foreach ($tables as $table) {
        $countStmt->execute([HB_RESCHEDULE_SOURCE_DB, $table]);
        if ((int)$countStmt->fetchColumn() !== 1) continue;
        $db->exec('USE ' . qid(HB_RESCHEDULE_SOURCE_DB));
        $result[$table]['rows'] = (int)$db->query('SELECT COUNT(*) FROM ' . qid($table))->fetchColumn();
        $autoStmt->execute([HB_RESCHEDULE_SOURCE_DB, $table]);
        $value = $autoStmt->fetchColumn();
        $result[$table]['auto_increment'] = $value === false || $value === null ? null : (int)$value;
    }
    return $result;
}

/** Clone tables, data, views and triggers into a unique disposable database. */
function cloneDatabase(PDO $control, string $target): array {
    $source = qid(HB_RESCHEDULE_SOURCE_DB);
    $targetQ = qid($target);
    $charset = $control->prepare('SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');
    $charset->execute([HB_RESCHEDULE_SOURCE_DB]);
    $schemaOptions = $charset->fetch(PDO::FETCH_NUM);
    if (!$schemaOptions) throw new RuntimeException('Approved V2 source database does not exist.');

    $control->exec("CREATE DATABASE {$targetQ} CHARACTER SET {$schemaOptions[0]} COLLATE {$schemaOptions[1]}");
    // Set the cleanup guard immediately after CREATE so any later clone error
    // still drops only this uniquely named database in finally.
    $GLOBALS['cloneCreated'] = true;
    $targetDb = new PDO(
        "mysql:host=" . (getenv('DB_HOST') ?: '127.0.0.1') . ";port=" . (getenv('DB_PORT') ?: '3306') . ";dbname={$target};charset=utf8mb4",
        (string)(getenv('DB_USER') ?: 'root'),
        (($v = getenv('DB_PASS')) === false ? '' : (string)$v),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]
    );

    $objects = $control->query("SHOW FULL TABLES FROM {$source}")->fetchAll(PDO::FETCH_NUM);
    $tables = [];
    $views = [];
    foreach ($objects as $obj) {
        if (($obj[1] ?? '') === 'BASE TABLE') $tables[] = (string)$obj[0];
        if (($obj[1] ?? '') === 'VIEW') $views[] = (string)$obj[0];
    }
    if (!$tables) throw new RuntimeException('V2 source has no base tables.');

    // Parent tables are created before children so FK definitions remain intact.
    $tableSet = array_fill_keys($tables, true);
    $indegree = array_fill_keys($tables, 0);
    $children = [];
    $fk = $control->prepare(
        'SELECT TABLE_NAME, REFERENCED_TABLE_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA=?
           AND REFERENCED_TABLE_NAME IS NOT NULL'
    );
    $fk->execute([HB_RESCHEDULE_SOURCE_DB, HB_RESCHEDULE_SOURCE_DB]);
    foreach ($fk->fetchAll(PDO::FETCH_ASSOC) as $edge) {
        $child = (string)$edge['TABLE_NAME'];
        $parent = (string)$edge['REFERENCED_TABLE_NAME'];
        if ($child === $parent || !isset($tableSet[$child], $tableSet[$parent])) continue;
        $children[$parent][] = $child;
        $indegree[$child]++;
    }
    $queue = [];
    foreach ($indegree as $table => $degree) if ($degree === 0) $queue[] = $table;
    $order = [];
    while ($queue) {
        $parent = array_shift($queue);
        $order[] = $parent;
        foreach ($children[$parent] ?? [] as $child) {
            $indegree[$child]--;
            if ($indegree[$child] === 0) $queue[] = $child;
        }
    }
    if (count($order) !== count($tables)) {
        throw new RuntimeException('Circular table foreign-key dependency detected; refusing an incomplete clone.');
    }

    $targetDb->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($order as $table) {
        $ddlRow = $control->query('SHOW CREATE TABLE ' . $source . '.' . qid($table))->fetch(PDO::FETCH_NUM);
        if (!isset($ddlRow[1]) || !is_string($ddlRow[1])) throw new RuntimeException('Could not read DDL for ' . $table);
        $targetDb->exec($ddlRow[1]);
    }

    $rowCounts = [];
    foreach ($order as $table) {
        $targetDb->exec('INSERT INTO ' . $targetQ . '.' . qid($table) . ' SELECT * FROM ' . $source . '.' . qid($table));
        $rowCounts[$table] = (int)$targetDb->query('SELECT COUNT(*) FROM ' . qid($table))->fetchColumn();
    }
    $targetDb->exec('SET FOREIGN_KEY_CHECKS=1');

    // Clone views after base tables. Iterate until view dependencies resolve.
    $pendingViews = $views;
    while ($pendingViews) {
        $next = [];
        $madeProgress = false;
        foreach ($pendingViews as $view) {
            $row = $control->query('SHOW CREATE VIEW ' . $source . '.' . qid($view))->fetch(PDO::FETCH_NUM);
            if (!isset($row[1]) || !is_string($row[1])) throw new RuntimeException('Could not read view DDL: ' . $view);
            $ddl = preg_replace('/\sDEFINER=`[^\`]+`@`[^\`]+`/i', '', $row[1]) ?? $row[1];
            try {
                $targetDb->exec($ddl);
                $madeProgress = true;
            } catch (PDOException $e) {
                $next[] = $view;
            }
        }
        if (!$next) break;
        if (!$madeProgress) throw new RuntimeException('Could not resolve dependent V2 views: ' . implode(', ', $next));
        $pendingViews = $next;
    }

    $triggerRows = $control->query('SHOW TRIGGERS FROM ' . $source)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($triggerRows as $trigger) {
        $name = (string)$trigger['Trigger'];
        $row = $control->query('SHOW CREATE TRIGGER ' . $source . '.' . qid($name))->fetch(PDO::FETCH_NUM);
        if (!isset($row[2]) || !is_string($row[2])) throw new RuntimeException('Could not read trigger DDL: ' . $name);
        $ddl = preg_replace('/\sDEFINER=`[^\`]+`@`[^\`]+`/i', '', $row[2]) ?? $row[2];
        $targetDb->exec($ddl);
    }

    return ['db' => $targetDb, 'tables' => count($tables), 'views' => count($views),
        'triggers' => count($triggerRows), 'rows' => $rowCounts];
}

function copyTree(string $source, string $target): void {
    if (!is_dir($source)) throw new RuntimeException('Missing source folder: ' . basename($source));
    if (!is_dir($target) && !mkdir($target, 0770, true) && !is_dir($target)) throw new RuntimeException('Could not create app copy folder.');
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $dest = $target . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($dest) && !mkdir($dest, 0770, true) && !is_dir($dest)) throw new RuntimeException('Could not create app subfolder.');
        } elseif ($item->isFile()) {
            $name = strtolower($item->getFilename());
            if ($name === '.env' || str_ends_with($name, '.env')) continue;
            if (!is_dir(dirname($dest)) && !mkdir(dirname($dest), 0770, true) && !is_dir(dirname($dest))) throw new RuntimeException('Could not create app parent folder.');
            if (!copy($item->getPathname(), $dest)) throw new RuntimeException('App copy failed: ' . $relative);
        }
    }
}

function removeTree(string $path): void {
    if (!is_dir($path)) { if (is_file($path)) @unlink($path); return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    @rmdir($path);
}

function activeUser(PDO $db, string $role): array {
    $stmt = $db->prepare("SELECT id,role,name,email,session_version FROM users WHERE role=? AND is_active=1 ORDER BY id LIMIT 1");
    $stmt->execute([$role]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new RuntimeException('No active ' . $role . ' account in V2 clone.');
    return $row;
}

function makeSession(string $directory, int $userId, int $version, string $role): string {
    session_save_path($directory);
    session_name('PHPSESSID');
    ini_set('session.use_strict_mode', '0');
    $id = bin2hex(random_bytes(16));
    session_id($id);
    if (!session_start()) throw new RuntimeException('Could not create synthetic role session.');
    $_SESSION = [
        'user_id' => $userId, 'role' => $role,
        'auth_created_at' => time(), 'auth_last_activity' => time(),
        'auth_session_version' => $version,
    ];
    session_write_close();
    if (!is_file($directory . DIRECTORY_SEPARATOR . 'sess_' . $id)) throw new RuntimeException('Synthetic session file missing.');
    return $id;
}

function httpRequest(string $base, string $path, string $cookie, string $method = 'POST', array $body = []): array {
    $headers = ['Accept: application/json', 'User-Agent: HB-V2-Reschedule-HTTP-QA', 'Connection: close', 'Cookie: PHPSESSID=' . $cookie];
    $content = '';
    if ($method !== 'GET' && $method !== 'HEAD') {
        $headers[] = 'Content-Type: application/json';
        $content = json_encode($body, JSON_THROW_ON_ERROR);
    }
    $context = stream_context_create(['http' => [
        'method' => $method, 'header' => implode("\r\n", $headers) . "\r\n",
        'content' => $content, 'ignore_errors' => true, 'timeout' => 20,
        'protocol_version' => 1.1,
    ]]);
    $raw = @file_get_contents($base . $path, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $line, $m)) $status = (int)$m[1];
    }
    $headersOut = $http_response_header ?? [];
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'status' => $status,
        'raw' => is_string($raw) ? $raw : '',
        'body' => is_array($decoded) ? $decoded : [],
        'headers' => $headersOut,
    ];
}

function apiSuccess(array $response, string $label): void {
    expectHttp($response['status'] === 200 && ($response['body']['success'] ?? false) === true,
        $label . ' failed; HTTP ' . $response['status'] . ' body=' . substr($response['raw'], 0, 300));
}

function awaitServer(int $port): bool {
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.15);
        if (is_resource($socket)) { fclose($socket); return true; }
        usleep(100000);
    }
    return false;
}

try {
    if (!is_dir(HB_RESCHEDULE_QA_ROOT) && !mkdir(HB_RESCHEDULE_QA_ROOT, 0770, true) && !is_dir(HB_RESCHEDULE_QA_ROOT)) {
        throw new RuntimeException('Could not create QA evidence folder.');
    }

    $control = connectServerDb($host, $port, $dbUser, $dbPass);
    $sourceIdentity = (string)$control->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=' . $control->quote(HB_RESCHEDULE_SOURCE_DB))->fetchColumn();
    expectHttp($sourceIdentity === HB_RESCHEDULE_SOURCE_DB, 'Approved source V2 database is missing.');
    $beforeFingerprint = sourceFingerprint($control);

    $cloneInfo = cloneDatabase($control, $targetDb);
    $cloneCreated = true;
    $clone = $cloneInfo['db'];
    expectHttp((string)$clone->query('SELECT DATABASE()')->fetchColumn() === $targetDb, 'Clone DB identity mismatch.');
    expectHttp((int)$clone->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='appointment_reschedule_requests'")->fetchColumn() > 0,
        'Cloned V2 reschedule table is missing.');

    // Existing pending outbox payloads belong to the source snapshot, not this test.
    $clone->exec('DELETE FROM notification_outbox');

    require_once $root . '/services/RescheduleService.php';
    require_once $root . '/services/Repositories/AppointmentBookingRepository.php';
    require_once $root . '/services/Repositories/DoctorSettingsRepository.php';

    $candidateSql = "SELECT u.id AS doctor_user_id, dp.department_id
                     FROM users u
                     JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
                     JOIN doctor_schedule_settings ss ON ss.doctor_user_id=u.id
                     JOIN departments dep ON dep.id=dp.department_id
                     WHERE u.role='doctor' AND u.is_active=1
                       AND ss.is_available=1 AND dep.status='active'
                     ORDER BY u.id";
    $schedule = new ScheduleService($clone, RescheduleService::SCHEMA_V2);
    $candidate = null;
    $slots = [];
    foreach ($clone->query($candidateSql)->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $available = [];
        $start = new DateTimeImmutable('today');
        for ($offset = 1; $offset <= 45 && count($available) < 9; $offset++) {
            $date = $start->modify('+' . $offset . ' days')->format('Y-m-d');
            foreach ($schedule->getAvailableSlots((int)$row['doctor_user_id'], $date) as $slot) {
                $available[] = ['date' => $date, 'time' => (string)$slot['time']];
                if (count($available) >= 9) break;
            }
        }
        if (count($available) >= 9) { $candidate = $row; $slots = $available; break; }
    }
    expectHttp(is_array($candidate), 'No V2 doctor has seven available future slots in the clone.');
    $patient = activeUser($clone, 'patient');
    $doctor = activeUser($clone, 'doctor');
    $admin = activeUser($clone, 'admin');
    $doctorId = (int)$candidate['doctor_user_id'];
    // Use the same active doctor whose schedule generated the test slots.
    $doctorStmt = $clone->prepare("SELECT id,role,name,email,session_version FROM users WHERE id=? AND role='doctor' AND is_active=1");
    $doctorStmt->execute([$doctorId]);
    $doctor = $doctorStmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($doctor)) throw new RuntimeException('Selected schedule doctor account is not active.');

    $booking = new AppointmentBookingRepository($clone, RescheduleService::SCHEMA_V2);
    $appointmentId = $booking->book([
        'doctor_user_id' => $doctorId,
        'patient_user_id' => (int)$patient['id'],
        'department_id' => (int)$candidate['department_id'],
        'date' => $slots[0]['date'],
        'time' => $slots[0]['time'],
        'notes' => 'Synthetic authenticated HTTP reschedule contract; disposable clone only.',
        'actor_user_id' => (int)$patient['id'],
        'actor_role' => 'patient',
    ]);
    $confirm = $clone->prepare("UPDATE appointments SET booking_status='confirmed' WHERE id=? AND booking_status='pending'");
    $confirm->execute([$appointmentId]);
    expectHttp($confirm->rowCount() === 1, 'Synthetic booking could not be confirmed in clone.');

    if (!mkdir($sessions, 0770, true) && !is_dir($sessions)) throw new RuntimeException('Could not create synthetic session directory.');
    foreach (['api', 'includes', 'services'] as $folder) {
        copyTree($root . DIRECTORY_SEPARATOR . $folder, $app . DIRECTORY_SEPARATOR . $folder);
    }
    // RegistrationIntakeService eagerly includes the mail transport, even
    // though this contract must never invoke SMTP. Supply a no-op Composer
    // autoloader only in the temporary app copy; production/vendor is untouched.
    $testVendor = $app . DIRECTORY_SEPARATOR . 'vendor';
    if (!is_dir($testVendor) && !mkdir($testVendor, 0770, true) && !is_dir($testVendor)) {
        throw new RuntimeException('Could not create isolated vendor stub directory.');
    }
    $stubAutoloader = "<?php\n// Test-only no-op autoloader. SMTP paths are not invoked by this HTTP contract.\n";
    if (file_put_contents($testVendor . DIRECTORY_SEPARATOR . 'autoload.php', $stubAutoloader) === false) {
        throw new RuntimeException('Could not write isolated no-op autoloader.');
    }
    $adminCookie = makeSession($sessions, (int)$admin['id'], (int)$admin['session_version'], 'admin');
    $doctorCookie = makeSession($sessions, $doctorId, (int)$doctor['session_version'], 'doctor');
    $patientCookie = makeSession($sessions, (int)$patient['id'], (int)$patient['session_version'], 'patient');

    // Allocate a free loopback port and start the V2-enabled temporary app copy.
    $portProbe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND);
    if (!is_resource($portProbe)) throw new RuntimeException('Could not allocate an HTTP test port.');
    $portName = stream_socket_get_name($portProbe, false);
    fclose($portProbe);
    $httpPort = (int)substr(strrchr((string)$portName, ':'), 1);
    if ($httpPort < 1) throw new RuntimeException('Could not determine HTTP test port.');
    $base = 'http://127.0.0.1:' . $httpPort;

    $env = getenv();
    if (!is_array($env)) $env = [];
    $env['DB_HOST'] = $host; $env['DB_PORT'] = $port; $env['DB_USER'] = $dbUser;
    $env['DB_PASS'] = $dbPass; $env['DB_NAME'] = $targetDb;
    $env['HEALTHBRIDGE_RESCHEDULE_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_APPOINTMENTS_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_SCHEDULE_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_RATINGS_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_AUDIT_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_SETTINGS_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_HOSPITAL_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_DEPARTMENTS_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_DOCTORS_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_PATIENTS_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_CLINICAL_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_PRESCRIPTIONS_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_AUTH_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_PORTAL_SCHEMA'] = 'v2';
    $env['HEALTHBRIDGE_ANALYTICS_SCHEMA'] = 'v2';
    // A synthetic, per-run encryption key lets registration intake be tested
    // without reading or using the host's live key material.
    $env['HB_REGISTRATION_QUEUE_KEYS_JSON'] = json_encode([
        'contract-test' => base64_encode(random_bytes(32)),
    ], JSON_THROW_ON_ERROR);
    $env['HB_REGISTRATION_QUEUE_ACTIVE_KEY_VERSION'] = 'contract-test';

    $server = proc_open(
        [PHP_BINARY, '-d', 'session.save_path=' . $sessions, '-d', 'sys_temp_dir=' . $temp,
         '-S', '127.0.0.1:' . $httpPort, '-t', $app],
        [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'a'],
         2 => ['file', HB_RESCHEDULE_QA_ROOT . DIRECTORY_SEPARATOR . 'v2-auth-route-server.stderr.log', 'a']],
        $pipes, $app, $env, ['bypass_shell' => true]
    );
    expectHttp(is_resource($server), 'Could not start isolated V2 HTTP server.');
    fclose($pipes[0]);
    expectHttp(awaitServer($httpPort), 'Isolated HTTP server did not become ready.');

    // Authentication and method guards across all eight reschedule routes.
    expectHttp(httpRequest($base, '/api/appointments/request-reschedule.php', $doctorCookie, 'POST', ['appointment_id' => $appointmentId])['status'] === 403, 'Doctor was allowed to request reschedule.');
    expectHttp(httpRequest($base, '/api/appointments/suggest-reschedule.php', $patientCookie, 'POST', ['appointment_id' => $appointmentId])['status'] === 403, 'Patient was allowed to suggest a new time.');
    expectHttp(httpRequest($base, '/api/appointments/accept-suggestion.php', $doctorCookie, 'POST', ['appointment_id' => $appointmentId])['status'] === 403, 'Doctor was allowed to accept a suggestion.');
    expectHttp(httpRequest($base, '/api/appointments/decline-suggestion.php', $doctorCookie, 'POST', ['appointment_id' => $appointmentId])['status'] === 403, 'Doctor was allowed to decline a suggestion.');
    expectHttp(httpRequest($base, '/api/appointments/approve-reschedule.php', $patientCookie, 'POST', ['appointment_id' => $appointmentId])['status'] === 403, 'Patient was allowed to approve a reschedule.');
    expectHttp(httpRequest($base, '/api/appointments/reject-reschedule.php', $patientCookie, 'POST', ['appointment_id' => $appointmentId])['status'] === 403, 'Patient was allowed to reject a reschedule.');
    expectHttp(httpRequest($base, '/api/appointments/cancel-reschedule-request.php', $doctorCookie, 'POST', ['appointment_id' => $appointmentId])['status'] === 403, 'Doctor was allowed to cancel a patient request.');
    expectHttp(httpRequest($base, '/api/appointments/get-pending-reschedules.php', $patientCookie, 'GET')['status'] === 403, 'Patient was allowed to read doctor/admin pending requests.');
    expectHttp(httpRequest($base, '/api/appointments/get-pending-reschedules.php', $adminCookie, 'POST')['status'] === 405, 'Pending-list route did not enforce GET.');
    $notes[] = 'Authorization/method: all eight routes reject the wrong role; pending-list rejects POST with 405.';

    // 1) Patient request; doctor and admin pending-list projections.
    $r = httpRequest($base, '/api/appointments/request-reschedule.php', $patientCookie, 'POST', [
        'appointment_id' => $appointmentId, 'new_date' => $slots[1]['date'], 'new_time' => $slots[1]['time'], 'reason' => 'HTTP request test',
    ]);
    apiSuccess($r, 'Patient request-reschedule');
    $pendingDoctor = httpRequest($base, '/api/appointments/get-pending-reschedules.php', $doctorCookie, 'GET');
    apiSuccess($pendingDoctor, 'Doctor pending-reschedules');
    $hasDoctor = false;
    foreach (($pendingDoctor['body']['requests'] ?? []) as $item) if ((int)($item['id'] ?? 0) === $appointmentId) $hasDoctor = true;
    expectHttp($hasDoctor, 'Doctor pending-list omitted the synthetic request.');
    $pendingAdmin = httpRequest($base, '/api/appointments/get-pending-reschedules.php', $adminCookie, 'GET');
    apiSuccess($pendingAdmin, 'Admin pending-reschedules');
    $hasAdmin = false;
    foreach (($pendingAdmin['body']['requests'] ?? []) as $item) if ((int)($item['id'] ?? 0) === $appointmentId) $hasAdmin = true;
    expectHttp($hasAdmin, 'Admin pending-list omitted the synthetic request.');
    $notes[] = 'Patient request and doctor/admin pending-list DTOs passed through authenticated HTTP.';

    // 2) Doctor suggests; 3) patient declines; 4) doctor approves original request.
    apiSuccess(httpRequest($base, '/api/appointments/suggest-reschedule.php', $doctorCookie, 'POST', [
        'appointment_id' => $appointmentId, 'suggested_date' => $slots[2]['date'], 'suggested_time' => $slots[2]['time'], 'notes' => 'HTTP suggestion',
    ]), 'Doctor suggest-reschedule');
    apiSuccess(httpRequest($base, '/api/appointments/decline-suggestion.php', $patientCookie, 'POST', [
        'appointment_id' => $appointmentId,
    ]), 'Patient decline-suggestion');
    apiSuccess(httpRequest($base, '/api/appointments/approve-reschedule.php', $doctorCookie, 'POST', [
        'appointment_id' => $appointmentId, 'notes' => 'HTTP approval',
    ]), 'Doctor approve-reschedule');
    $notes[] = 'Suggest -> patient decline -> doctor approval flow passed through HTTP.';

    // 5) New request -> suggest -> 6) patient accepts suggestion.
    apiSuccess(httpRequest($base, '/api/appointments/request-reschedule.php', $patientCookie, 'POST', [
        'appointment_id' => $appointmentId, 'new_date' => $slots[3]['date'], 'new_time' => $slots[3]['time'], 'reason' => 'HTTP second request',
    ]), 'Second patient request');
    apiSuccess(httpRequest($base, '/api/appointments/suggest-reschedule.php', $doctorCookie, 'POST', [
        'appointment_id' => $appointmentId, 'suggested_date' => $slots[4]['date'], 'suggested_time' => $slots[4]['time'], 'notes' => 'HTTP accepted suggestion',
    ]), 'Second doctor suggestion');
    apiSuccess(httpRequest($base, '/api/appointments/accept-suggestion.php', $patientCookie, 'POST', [
        'appointment_id' => $appointmentId,
    ]), 'Patient accept-suggestion');
    $notes[] = 'Second request -> suggestion -> patient acceptance flow passed through HTTP.';

    // 7) New request -> doctor rejection; 8) new request -> patient cancellation.
    apiSuccess(httpRequest($base, '/api/appointments/request-reschedule.php', $patientCookie, 'POST', [
        'appointment_id' => $appointmentId, 'new_date' => $slots[5]['date'], 'new_time' => $slots[5]['time'], 'reason' => 'HTTP rejection request',
    ]), 'Third patient request');
    apiSuccess(httpRequest($base, '/api/appointments/reject-reschedule.php', $doctorCookie, 'POST', [
        'appointment_id' => $appointmentId, 'notes' => 'HTTP rejection',
    ]), 'Doctor reject-reschedule');
    apiSuccess(httpRequest($base, '/api/appointments/request-reschedule.php', $patientCookie, 'POST', [
        'appointment_id' => $appointmentId, 'new_date' => $slots[6]['date'], 'new_time' => $slots[6]['time'], 'reason' => 'HTTP cancellation request',
    ]), 'Fourth patient request');
    apiSuccess(httpRequest($base, '/api/appointments/cancel-reschedule-request.php', $patientCookie, 'POST', [
        'appointment_id' => $appointmentId,
    ]), 'Patient cancel-reschedule-request');
    $notes[] = 'Doctor rejection and patient cancellation flows passed through HTTP.';

    $repo = new RescheduleTransactionRepository($clone, RescheduleService::SCHEMA_V2);
    $history = $repo->getRescheduleHistory($appointmentId);
    expectHttp(is_array($history) && ($history['reschedule_status'] ?? '') === 'none', 'Final cancelled reschedule history did not return none.');
    $appointmentStmt = $clone->prepare('SELECT starts_at, booking_status FROM appointments WHERE id=?');
    $appointmentStmt->execute([$appointmentId]);
    $final = $appointmentStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($final) && $final['booking_status'] === 'confirmed'
        && (string)$final['starts_at'] === $slots[4]['date'] . ' ' . $slots[4]['time'] . ':00',
        'Final appointment schedule/status did not match accepted suggestion.');
    $audit = $clone->prepare("SELECT COUNT(*) FROM audit_events WHERE entity_type='appointment' AND entity_id=?");
    $audit->execute([(string)$appointmentId]);
    $auditCount = (int)$audit->fetchColumn();
    expectHttp($auditCount >= 10, 'Expected reschedule lifecycle audit events; found ' . $auditCount . '.');
    $outbox = $clone->prepare("SELECT COUNT(*) FROM notification_outbox WHERE ref_type='appointment' AND ref_id=?");
    $outbox->execute([$appointmentId]);
    $outboxCount = (int)$outbox->fetchColumn();
    $notifications = $clone->prepare("SELECT COUNT(*) FROM notifications WHERE ref_type='appointment' AND ref_id=?");
    $notifications->execute([$appointmentId]);
    $notificationCount = (int)$notifications->fetchColumn();
    expectHttp($outboxCount + $notificationCount > 0, 'No reschedule notifications/outbox events were recorded.');
    $notes[] = 'Final cancelled history, confirmed appointment schedule, audit_events (' . $auditCount . '), notification outbox/delivery rows (' . ($outboxCount + $notificationCount) . ') passed.';
    // Additional V2 HTTP mutation coverage, confined to this disposable clone.
    $bookBody = [
        'department_id' => (int)$candidate['department_id'],
        'doctor_id' => $doctorId,
        'patient_id' => (int)$patient['id'],
        'patientName' => (string)$patient['name'],
        'date' => $slots[7]['date'],
        'time' => $slots[7]['time'],
        'notes' => 'Synthetic V2 HTTP booking; disposable clone only.',
    ];
    expectHttp(httpRequest($base, '/api/appointments/book.php', $doctorCookie, 'POST', $bookBody)['status'] === 403,
        'Doctor was allowed to book an appointment.');
    $booked = httpRequest($base, '/api/appointments/book.php', $patientCookie, 'POST', $bookBody);
    apiSuccess($booked, 'Patient HTTP booking');
    $bookedAppointmentId = (int)($booked['body']['appointment_id'] ?? 0);
    expectHttp($bookedAppointmentId > 0, 'HTTP booking did not return a valid appointment ID.');
    apiSuccess(httpRequest($base, '/api/appointments/approve.php', $adminCookie, 'POST', ['id' => $bookedAppointmentId]),
        'Admin HTTP appointment approval');
    $statusStmt = $clone->prepare('SELECT booking_status FROM appointments WHERE id=?');
    $statusStmt->execute([$bookedAppointmentId]);
    expectHttp((string)$statusStmt->fetchColumn() === 'confirmed', 'HTTP-approved appointment was not confirmed.');

    $declineBody = $bookBody;
    $declineBody['date'] = $slots[8]['date'];
    $declineBody['time'] = $slots[8]['time'];
    $declineBody['notes'] = 'Synthetic V2 HTTP decline test; disposable clone only.';
    $declinedBooking = httpRequest($base, '/api/appointments/book.php', $patientCookie, 'POST', $declineBody);
    apiSuccess($declinedBooking, 'Second patient HTTP booking');
    $declinedAppointmentId = (int)($declinedBooking['body']['appointment_id'] ?? 0);
    expectHttp($declinedAppointmentId > 0, 'Second HTTP booking did not return a valid appointment ID.');
    apiSuccess(httpRequest($base, '/api/appointments/decline.php', $adminCookie, 'POST', [
        'id' => $declinedAppointmentId,
        'reason' => 'Synthetic HTTP decline; disposable clone only.',
    ]), 'Admin HTTP appointment decline');
    $statusStmt->execute([$declinedAppointmentId]);
    expectHttp((string)$statusStmt->fetchColumn() === 'cancelled', 'HTTP-declined appointment was not cancelled.');
    $notes[] = 'V2 patient booking plus admin approval and decline passed authenticated HTTP.';

    $scheduleBefore = $schedule->getSchedule($doctorId);
    if (!is_array($scheduleBefore) || empty($scheduleBefore['weekly']) || !is_array($scheduleBefore['settings'] ?? null)) {
        throw new RuntimeException('Could not read schedule for HTTP update.');
    }
    $weeklyPayload = [];
    foreach ($scheduleBefore['weekly'] as $day) {
        $weeklyPayload[] = [
            'day_of_week' => (int)$day['day_of_week'],
            'start_time' => substr((string)($day['start_time'] ?? '09:00'), 0, 5),
            'end_time' => substr((string)($day['end_time'] ?? '17:00'), 0, 5),
            'is_working' => (int)$day['is_working'],
        ];
    }
    $oldCapacity = (int)($scheduleBefore['settings']['max_appointments_per_day'] ?? 25);
    $newCapacity = $oldCapacity < 100 ? $oldCapacity + 1 : $oldCapacity - 1;
    apiSuccess(httpRequest($base, '/api/schedule/update.php', $adminCookie, 'POST', [
        'doctor_id' => $doctorId,
        'weekly' => $weeklyPayload,
        'settings' => ['max_appointments_per_day' => $newCapacity],
    ]), 'V2 HTTP schedule update');
    $scheduleAfter = (new ScheduleService($clone, RescheduleService::SCHEMA_V2))->getSchedule($doctorId);
    expectHttp((int)($scheduleAfter['settings']['max_appointments_per_day'] ?? 0) === $newCapacity,
        'HTTP schedule update did not persist.');
    $scheduleRead = httpRequest($base, '/api/schedule/get.php?doctor_id=' . $doctorId, $adminCookie, 'GET');
    expectHttp($scheduleRead['status'] === 200 && ($scheduleRead['body']['success'] ?? false) === true,
        'V2 schedule read failed after update.');
    $slotRead = httpRequest($base, '/api/appointments/get-available-slots.php', $doctorCookie, 'POST', [
        'doctor_id' => $doctorId,
        'date' => $slots[8]['date'],
    ]);
    apiSuccess($slotRead, 'V2 HTTP available-slot read');
    expectHttp(is_array($slotRead['body']['slots'] ?? null), 'Available-slot DTO is missing.');
    $notes[] = 'V2 schedule update/get and available-slot endpoints passed authenticated HTTP.';

    $ratingId = (int)$clone->query('SELECT id FROM ratings ORDER BY id LIMIT 1')->fetchColumn();
    expectHttp($ratingId > 0, 'No V2 rating fixture exists for visibility HTTP test.');
    apiSuccess(httpRequest($base, '/api/doctors/toggle-rating-visibility.php', $adminCookie, 'POST', [
        'id' => $ratingId, 'is_hidden' => 1,
    ]), 'Admin V2 HTTP rating hide');
    expectHttp((int)$clone->query('SELECT is_public FROM ratings WHERE id=' . $ratingId)->fetchColumn() === 0,
        'Rating hide did not persist.');
    apiSuccess(httpRequest($base, '/api/doctors/toggle-rating-visibility.php', $adminCookie, 'POST', [
        'id' => $ratingId, 'is_hidden' => 0,
    ]), 'Admin V2 HTTP rating restore');
    expectHttp((int)$clone->query('SELECT is_public FROM ratings WHERE id=' . $ratingId)->fetchColumn() === 1,
        'Rating restore did not persist.');
    $notes[] = 'V2 rating visibility hide/restore passed admin HTTP.';

    // V2 settings update paths, isolated to this disposable clone.
    $doctorPracticeBefore = (new DoctorSettingsRepository($clone, DoctorSettingsRepository::SCHEMA_V2))
        ->getDoctorSettings($doctorId);
    $doctorSettingsRows = $doctorPracticeBefore['weekly_schedule'] ?? [];
    if (count($doctorSettingsRows) !== 7) {
        throw new RuntimeException('V2 doctor settings did not return a complete weekly schedule.');
    }
    $weeklySettingsPayload = [];
    foreach ($doctorSettingsRows as $day) {
        $weeklySettingsPayload[] = [
            'day_of_week' => (int)$day['day_of_week'],
            'start_time' => substr((string)$day['start_time'], 0, 5),
            'end_time' => substr((string)$day['end_time'], 0, 5),
            'is_working' => (int)$day['is_working'],
        ];
    }
    $oldVisible = (int)($doctorPracticeBefore['preferences']['profile_visible'] ?? 1);
    $newVisible = $oldVisible === 1 ? 0 : 1;
    $oldDuration = (int)($doctorPracticeBefore['preferences']['consultation_duration'] ?? 30);
    $newDuration = $oldDuration < 120 ? $oldDuration + 5 : $oldDuration - 5;
    apiSuccess(httpRequest($base, '/api/settings/update.php', $doctorCookie, 'POST', [
        'section' => 'doctor',
        'profile_visible' => $newVisible,
        'consultation_duration' => $newDuration,
        'weekly_schedule' => $weeklySettingsPayload,
    ]), 'V2 doctor settings update');
    $doctorPracticeAfter = (new DoctorSettingsRepository($clone, DoctorSettingsRepository::SCHEMA_V2))
        ->getDoctorSettings($doctorId);
    expectHttp((int)$doctorPracticeAfter['preferences']['profile_visible'] === $newVisible,
        'V2 doctor profile visibility setting did not persist.');
    expectHttp((int)$doctorPracticeAfter['preferences']['consultation_duration'] === $newDuration,
        'V2 doctor consultation duration did not persist.');
    expectHttp(count($doctorPracticeAfter['weekly_schedule'] ?? []) === 7,
        'V2 weekly schedule was not retained through the settings update.');

    apiSuccess(httpRequest($base, '/api/settings/update.php', $adminCookie, 'POST', [
        'section' => 'admin',
        'admin_default_tab' => 'doctors',
    ]), 'V2 admin default-tab update');
    $adminDefaultTab = $clone->prepare('SELECT default_tab FROM admin_preferences WHERE admin_user_id=?');
    $adminDefaultTab->execute([(int)$admin['id']]);
    expectHttp((string)$adminDefaultTab->fetchColumn() === 'doctors',
        'V2 admin default-tab setting did not persist.');
    $notes[] = 'V2 settings writes passed authenticated HTTP: doctor weekly settings and admin default tab.';

    // Hospital global settings: V2 time normalization and weekly-schedule conflict projection.
    require_once $root . '/services/HospitalSettingsService.php';
    $hospitalService = new HospitalSettingsService($clone, HospitalSettingsService::SCHEMA_V2);
    $hospitalBefore = $hospitalService->getSettings();
    $hospitalOpen = (string)$hospitalBefore['appointment_open_time'];
    $hospitalClose = (string)$hospitalBefore['appointment_close_time'];
    expectHttp((bool)preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $hospitalOpen),
        'V2 hospital open time was not normalized to HH:MM.');
    expectHttp((bool)preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $hospitalClose),
        'V2 hospital close time was not normalized to HH:MM.');
    $hospitalConflicts = $hospitalService->checkScheduleConflicts($hospitalOpen, $hospitalClose);
    expectHttp(is_array($hospitalConflicts), 'V2 hospital schedule-conflict projection did not return an array.');
    $qaHospitalName = 'HealthBridge V2 HTTP QA ' . bin2hex(random_bytes(4));
    apiSuccess(httpRequest($base, '/api/settings/update-hospital.php', $adminCookie, 'POST', [
        'hospital_name' => $qaHospitalName,
    ]), 'V2 admin hospital settings update');
    $hospitalAfter = (new HospitalSettingsService($clone, HospitalSettingsService::SCHEMA_V2))->getSettings();
    expectHttp((string)($hospitalAfter['hospital_name'] ?? '') === $qaHospitalName,
        'V2 hospital name update did not persist.');
    $notes[] = 'V2 hospital settings read, HH:MM normalization, schedule conflict projection and admin update passed in the disposable clone.';

    // Department CRUD/count projections and doctor assignment in V2.
    require_once $root . '/services/DepartmentService.php';
    $departmentSuffix = bin2hex(random_bytes(4));
    $departmentName = 'V2 HTTP QA Department ' . $departmentSuffix;
    $departmentCreate = httpRequest($base, '/api/departments/create.php', $adminCookie, 'POST', [
        'name' => $departmentName,
        'description' => 'Disposable V2 department HTTP contract.',
        'status' => 'active',
    ]);
    apiSuccess($departmentCreate, 'V2 admin department create');
    $qaDepartmentId = (int)($departmentCreate['body']['department_id'] ?? 0);
    expectHttp($qaDepartmentId > 0, 'Department create did not return its ID.');
    $departmentUpdateName = $departmentName . ' Updated';
    apiSuccess(httpRequest($base, '/api/departments/update.php', $adminCookie, 'POST', [
        'id' => $qaDepartmentId,
        'name' => $departmentUpdateName,
        'description' => 'Updated disposable V2 department.',
        'status' => 'active',
    ]), 'V2 admin department update');
    $departmentService = new DepartmentService($clone, DepartmentService::SCHEMA_V2);
    $departmentAfterUpdate = $departmentService->getDepartmentById($qaDepartmentId);
    expectHttp((string)($departmentAfterUpdate['name'] ?? '') === $departmentUpdateName,
        'V2 department update did not persist.');

    $oldDoctorDepartmentStmt = $clone->prepare('SELECT department_id FROM doctor_profiles WHERE doctor_user_id=?');
    $oldDoctorDepartmentStmt->execute([$doctorId]);
    $oldDoctorDepartmentValue = $oldDoctorDepartmentStmt->fetchColumn();
    $oldDoctorDepartment = $oldDoctorDepartmentValue === false || $oldDoctorDepartmentValue === null
        ? null : (int)$oldDoctorDepartmentValue;
    apiSuccess(httpRequest($base, '/api/departments/assign-doctor.php', $adminCookie, 'POST', [
        'doctor_id' => $doctorId,
        'department_id' => $qaDepartmentId,
    ]), 'V2 admin doctor department assignment');
    $assignedDepartment = $clone->prepare('SELECT department_id FROM doctor_profiles WHERE doctor_user_id=?');
    $assignedDepartment->execute([$doctorId]);
    expectHttp((int)$assignedDepartment->fetchColumn() === $qaDepartmentId,
        'V2 doctor department assignment did not persist.');
    $departmentWithDoctor = $departmentService->getDepartmentById($qaDepartmentId);
    expectHttp((int)($departmentWithDoctor['doctor_count'] ?? 0) === 1,
        'V2 department doctor count did not use doctor_profiles.');
    if ($oldDoctorDepartment !== null && $oldDoctorDepartment > 0) {
        apiSuccess(httpRequest($base, '/api/departments/assign-doctor.php', $adminCookie, 'POST', [
            'doctor_id' => $doctorId,
            'department_id' => $oldDoctorDepartment,
        ]), 'V2 restore doctor department assignment');
    } else {
        $clone->prepare('UPDATE doctor_profiles SET department_id=NULL WHERE doctor_user_id=?')->execute([$doctorId]);
    }
    expectHttp((int)$departmentService->getDepartmentById($qaDepartmentId)['doctor_count'] === 0,
        'V2 doctor assignment was not restored before department cleanup.');

    apiSuccess(httpRequest($base, '/api/departments/deactivate.php', $adminCookie, 'POST', [
        'id' => $qaDepartmentId,
    ]), 'V2 admin department deactivate');
    apiSuccess(httpRequest($base, '/api/departments/activate.php', $adminCookie, 'POST', [
        'id' => $qaDepartmentId,
    ]), 'V2 admin department reactivate');
    apiSuccess(httpRequest($base, '/api/departments/delete.php', $adminCookie, 'POST', [
        'id' => $qaDepartmentId,
    ]), 'V2 admin department delete');
    expectHttp($departmentService->getDepartmentById($qaDepartmentId) === null,
        'V2 department delete did not remove the synthetic department.');
    $notes[] = 'V2 department create/update/deactivate/reactivate/delete, doctor assignment, and doctor_profiles count projection passed authenticated admin HTTP.';

    // Account status toggles use the matching V2 schema selectors in this copied server.
    $doctorStatusOff = httpRequest($base, '/api/doctors/toggle-status.php', $adminCookie, 'POST', ['id' => $doctorId]);
    apiSuccess($doctorStatusOff, 'V2 doctor status deactivate');
    expectHttp((int)($doctorStatusOff['body']['is_active'] ?? 1) === 0, 'V2 doctor deactivate did not persist.');
    $doctorStatusOn = httpRequest($base, '/api/doctors/toggle-status.php', $adminCookie, 'POST', ['id' => $doctorId]);
    apiSuccess($doctorStatusOn, 'V2 doctor status reactivate');
    expectHttp((int)($doctorStatusOn['body']['is_active'] ?? 0) === 1, 'V2 doctor reactivate did not persist.');

    $patientStatusOff = httpRequest($base, '/api/patients/toggle-status.php', $adminCookie, 'POST', ['id' => (int)$patient['id']]);
    apiSuccess($patientStatusOff, 'V2 patient status deactivate');
    expectHttp((int)($patientStatusOff['body']['is_active'] ?? 1) === 0, 'V2 patient deactivate did not persist.');
    $patientStatusOn = httpRequest($base, '/api/patients/toggle-status.php', $adminCookie, 'POST', ['id' => (int)$patient['id']]);
    apiSuccess($patientStatusOn, 'V2 patient status reactivate');
    expectHttp((int)($patientStatusOn['body']['is_active'] ?? 0) === 1, 'V2 patient reactivate did not persist.');
    $notes[] = 'V2 doctor and patient status deactivate/reactivate passed authenticated HTTP and were restored.';

    // Status transitions revoke prior sessions; refresh synthetic cookies before the next route checks.
    $doctorVersionRefresh = $clone->prepare('SELECT session_version FROM users WHERE id=?');
    $doctorVersionRefresh->execute([$doctorId]);
    $doctorCookie = makeSession($sessions, $doctorId, (int)$doctorVersionRefresh->fetchColumn(), 'doctor');
    $patientVersionRefresh = $clone->prepare('SELECT session_version FROM users WHERE id=?');
    $patientVersionRefresh->execute([(int)$patient['id']]);
    $patientCookie = makeSession($sessions, (int)$patient['id'], (int)$patientVersionRefresh->fetchColumn(), 'patient');

    // V2 admin-provisioned doctor lifecycle: create, aggregate, update, status.
    $qaSuffix = bin2hex(random_bytes(5));
    $qaDoctorEmail = 'v2-doctor-' . $qaSuffix . '@example.test';
    $qaDoctorName = 'V2 HTTP Doctor ' . $qaSuffix;
    $qaDoctorPassword = 'V2-QaDoctor-2026-' . $qaSuffix . '!';
    $otherDepartmentValue = $clone->query(
        'SELECT id FROM departments WHERE status=\'active\' AND id<>' . (int)$candidate['department_id'] . ' ORDER BY id LIMIT 1'
    )->fetchColumn();
    $qaDoctorDepartment = $otherDepartmentValue === false
        ? (int)$candidate['department_id']
        : (int)$otherDepartmentValue;

    expectHttp(httpRequest($base, '/api/doctors/add.php', $patientCookie, 'POST', [
        'name' => $qaDoctorName,
        'email' => $qaDoctorEmail,
        'password' => $qaDoctorPassword,
        'department_id' => $qaDoctorDepartment,
    ])['status'] === 403, 'Patient was allowed to create a doctor account.');

    $doctorCreate = httpRequest($base, '/api/doctors/add.php', $adminCookie, 'POST', [
        'name' => $qaDoctorName,
        'email' => $qaDoctorEmail,
        'password' => $qaDoctorPassword,
        'department_id' => $qaDoctorDepartment,
    ]);
    apiSuccess($doctorCreate, 'V2 admin doctor create');
    $qaDoctorId = (int)($doctorCreate['body']['doctor_id'] ?? 0);
    expectHttp($qaDoctorId > 0, 'Doctor create did not return a valid doctor ID.');

    $qaDoctorRowStmt = $clone->prepare(
        "SELECT u.role,u.is_active,u.name,u.email,dp.department_id
         FROM users u JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
         WHERE u.id=?"
    );
    $qaDoctorRowStmt->execute([$qaDoctorId]);
    $qaDoctorRow = $qaDoctorRowStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaDoctorRow)
        && $qaDoctorRow['role'] === 'doctor'
        && (int)$qaDoctorRow['is_active'] === 1
        && (string)$qaDoctorRow['email'] === $qaDoctorEmail
        && (int)$qaDoctorRow['department_id'] === $qaDoctorDepartment,
        'V2 doctor create did not persist the user/profile aggregate.');
    $qaScheduleStmt = $clone->prepare(
        'SELECT COUNT(*) FROM doctor_weekly_schedules WHERE doctor_user_id=?'
    );
    $qaScheduleStmt->execute([$qaDoctorId]);
    expectHttp((int)$qaScheduleStmt->fetchColumn() === 7,
        'V2 doctor create did not provision all seven weekly schedule rows.');

    $qaDoctorUpdatedName = $qaDoctorName . ' Updated';
    $qaDoctorUpdatedEmail = 'v2-doctor-updated-' . $qaSuffix . '@example.test';
    apiSuccess(httpRequest($base, '/api/doctors/update.php', $adminCookie, 'POST', [
        'id' => $qaDoctorId,
        'name' => $qaDoctorUpdatedName,
        'email' => $qaDoctorUpdatedEmail,
        'phone' => '',
        'department_id' => (int)$candidate['department_id'],
    ]), 'V2 admin doctor update');
    $qaDoctorRowStmt->execute([$qaDoctorId]);
    $qaDoctorRow = $qaDoctorRowStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaDoctorRow)
        && (string)$qaDoctorRow['name'] === $qaDoctorUpdatedName
        && (string)$qaDoctorRow['email'] === $qaDoctorUpdatedEmail
        && (int)$qaDoctorRow['department_id'] === (int)$candidate['department_id'],
        'V2 doctor update did not persist identity and department fields.');

    $qaDoctorOff = httpRequest($base, '/api/doctors/toggle-status.php', $adminCookie, 'POST', ['id' => $qaDoctorId]);
    apiSuccess($qaDoctorOff, 'V2 new doctor deactivate');
    expectHttp((int)($qaDoctorOff['body']['is_active'] ?? 1) === 0,
        'New V2 doctor did not deactivate.');
    $qaDoctorOn = httpRequest($base, '/api/doctors/toggle-status.php', $adminCookie, 'POST', ['id' => $qaDoctorId]);
    apiSuccess($qaDoctorOn, 'V2 new doctor reactivate');
    expectHttp((int)($qaDoctorOn['body']['is_active'] ?? 0) === 1,
        'New V2 doctor did not reactivate.');
    $notes[] = 'V2 doctor create/update/deactivate/reactivate passed authenticated HTTP; the seven-day schedule aggregate and department projection were verified.';

    // V2 admin-provisioned patient lifecycle and account-owned settings/security routes.
    $qaPatientEmail = 'v2-patient-' . $qaSuffix . '@example.test';
    $qaPatientName = 'V2 HTTP Patient ' . $qaSuffix;
    $qaPatientPassword = 'V2-QaPatient-2026-' . $qaSuffix . '!';
    expectHttp(httpRequest($base, '/api/patients/add.php', $doctorCookie, 'POST', [
        'name' => $qaPatientName,
        'email' => $qaPatientEmail,
        'password' => $qaPatientPassword,
    ])['status'] === 403, 'Doctor was allowed to create a patient account.');

    $patientCreate = httpRequest($base, '/api/patients/add.php', $adminCookie, 'POST', [
        'name' => $qaPatientName,
        'email' => $qaPatientEmail,
        'password' => $qaPatientPassword,
        'phone' => '',
    ]);
    apiSuccess($patientCreate, 'V2 admin patient create');
    $qaPatientId = (int)($patientCreate['body']['patient']['id'] ?? 0);
    $qaPatientNumber = (string)($patientCreate['body']['patient']['patient_number'] ?? '');
    expectHttp($qaPatientId > 0 && $qaPatientNumber !== '',
        'V2 patient create did not return the patient ID and number.');
    $qaPatientAggregate = $clone->prepare(
        'SELECT COUNT(*) FROM patient_profiles WHERE patient_user_id=?'
    );
    $qaPatientAggregate->execute([$qaPatientId]);
    expectHttp((int)$qaPatientAggregate->fetchColumn() === 1,
        'V2 patient create did not provision patient_profiles.');
    $qaMedicalAggregate = $clone->prepare(
        'SELECT COUNT(*) FROM patient_medical_profiles WHERE patient_user_id=?'
    );
    $qaMedicalAggregate->execute([$qaPatientId]);
    expectHttp((int)$qaMedicalAggregate->fetchColumn() === 1,
        'V2 patient create did not provision patient_medical_profiles.');

    $qaPatientUpdatedName = $qaPatientName . ' Updated';
    $qaPatientUpdatedEmail = 'v2-patient-updated-' . $qaSuffix . '@example.test';
    apiSuccess(httpRequest($base, '/api/patients/update.php', $adminCookie, 'POST', [
        'patient_id' => $qaPatientId,
        'name' => $qaPatientUpdatedName,
        'email' => $qaPatientUpdatedEmail,
        'phone' => '',
        'gender' => 'Other',
        'date_of_birth' => '2001-02-03',
    ]), 'V2 admin patient update');
    $qaPatientIdentityStmt = $clone->prepare(
        'SELECT name,email,role,is_active,patient_number,session_version FROM users WHERE id=?'
    );
    $qaPatientIdentityStmt->execute([$qaPatientId]);
    $qaPatientIdentity = $qaPatientIdentityStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaPatientIdentity)
        && (string)$qaPatientIdentity['name'] === $qaPatientUpdatedName
        && (string)$qaPatientIdentity['email'] === $qaPatientUpdatedEmail
        && $qaPatientIdentity['role'] === 'patient'
        && (int)$qaPatientIdentity['is_active'] === 1
        && (string)$qaPatientIdentity['patient_number'] === $qaPatientNumber,
        'V2 patient update changed or lost the wrong identity fields.');
    $qaDemographicsStmt = $clone->prepare(
        'SELECT gender,date_of_birth FROM patient_profiles WHERE patient_user_id=?'
    );
    $qaDemographicsStmt->execute([$qaPatientId]);
    $qaDemographics = $qaDemographicsStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaDemographics)
        && $qaDemographics['gender'] === 'Other'
        && (string)$qaDemographics['date_of_birth'] === '2001-02-03',
        'V2 patient update did not persist profile demographics.');

    $qaPatientVersion = (int)$qaPatientIdentity['session_version'];
    $qaPatientCookie = makeSession($sessions, $qaPatientId, $qaPatientVersion, 'patient');
    apiSuccess(httpRequest($base, '/api/settings/get.php', $qaPatientCookie, 'GET'),
        'V2 patient settings GET');

    // Shared V2 contact_messages table and support-reply outbox path.
    $clone->prepare('UPDATE user_preferences SET notif_messages=1 WHERE user_id=?')
        ->execute([$qaPatientId]);
    expectHttp(httpRequest($base, '/api/settings/get-contact-messages.php', $patientCookie, 'GET')['status'] === 403,
        'Patient was allowed to read all contact messages.');
    expectHttp(httpRequest($base, '/api/settings/reply-contact-message.php', $patientCookie, 'POST', [
        'id' => 1,
        'reply' => 'Unauthorized synthetic reply attempt.',
    ])['status'] === 403, 'Patient was allowed to reply to a contact message.');

    $contactPayload = [
        'name' => $qaPatientUpdatedName,
        'email' => $qaPatientUpdatedEmail,
        'phone' => '',
        'department' => 'General Inquiry',
        'subject' => 'V2 support route acceptance ' . $qaSuffix,
        'message' => 'Synthetic contact message for V2 authenticated support route acceptance.',
    ];
    $contactCreate = httpRequest($base, '/api/settings/contact.php', $qaPatientCookie, 'POST', $contactPayload);
    apiSuccess($contactCreate, 'V2 authenticated contact submission');
    $qaContactId = (int)($contactCreate['body']['id'] ?? 0);
    expectHttp($qaContactId > 0, 'V2 contact submission did not return a valid message ID.');
    $contactRowStmt = $clone->prepare(
        'SELECT user_id,name,email,subject,message,reply,replied_at,is_read FROM contact_messages WHERE id=?'
    );
    $contactRowStmt->execute([$qaContactId]);
    $contactRow = $contactRowStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($contactRow)
        && (int)$contactRow['user_id'] === $qaPatientId
        && (string)$contactRow['email'] === $qaPatientUpdatedEmail
        && (string)$contactRow['subject'] === $contactPayload['subject'],
        'V2 contact submission did not persist the authenticated sender and message fields.');
    $userMessages = httpRequest($base, '/api/settings/get-user-messages.php', $qaPatientCookie, 'GET');
    apiSuccess($userMessages, 'V2 patient-owned contact messages');
    $foundUserMessage = false;
    foreach (($userMessages['body']['messages'] ?? []) as $item) {
        if ((int)($item['id'] ?? 0) === $qaContactId) $foundUserMessage = true;
    }
    expectHttp($foundUserMessage, 'V2 patient-owned message list omitted the new contact message.');

    $adminMessages = httpRequest($base, '/api/settings/get-contact-messages.php', $adminCookie, 'GET');
    apiSuccess($adminMessages, 'V2 admin contact-message list');
    $foundAdminMessage = false;
    foreach (($adminMessages['body']['messages'] ?? []) as $item) {
        if ((int)($item['id'] ?? 0) === $qaContactId) $foundAdminMessage = true;
    }
    expectHttp($foundAdminMessage, 'V2 admin contact-message list omitted the new contact message.');

    $supportReply = 'Synthetic V2 support reply accepted for disposable-clone QA.';
    apiSuccess(httpRequest($base, '/api/settings/reply-contact-message.php', $adminCookie, 'POST', [
        'id' => $qaContactId,
        'reply' => $supportReply,
    ]), 'V2 admin contact-message reply');
    $contactRowStmt->execute([$qaContactId]);
    $contactRow = $contactRowStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($contactRow)
        && (string)$contactRow['reply'] === $supportReply
        && !empty($contactRow['replied_at']),
        'V2 support reply did not persist reply text and timestamp.');
    $supportNotification = $clone->prepare(
        "SELECT COUNT(*) FROM notifications
         WHERE user_id=? AND type='support_reply'
           AND ref_type='contact_message' AND ref_id=?"
    );
    $supportNotification->execute([$qaPatientId, $qaContactId]);
    expectHttp((int)$supportNotification->fetchColumn() === 1,
        'V2 support reply did not produce exactly one in-app notification.');
    $notes[] = 'V2 contact submission, patient-owned/admin list projections, role denial, admin reply and in-app support notification passed authenticated HTTP. No email was sent.';

    $qaNewPassword = 'V2-QaNewPassword-2026-' . $qaSuffix . '!';
    apiSuccess(httpRequest($base, '/api/settings/update.php', $qaPatientCookie, 'POST', [
        'section' => 'password',
        'current_password' => $qaPatientPassword,
        'new_password' => $qaNewPassword,
        'confirm_password' => $qaNewPassword,
    ]), 'V2 patient password change');
    $qaPasswordStmt = $clone->prepare('SELECT password_hash,session_version FROM users WHERE id=?');
    $qaPasswordStmt->execute([$qaPatientId]);
    $qaPasswordRow = $qaPasswordStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaPasswordRow)
        && password_verify($qaNewPassword, (string)$qaPasswordRow['password_hash'])
        && (int)$qaPasswordRow['session_version'] === $qaPatientVersion + 1,
        'V2 password change did not update the password hash and session version.');
    apiSuccess(httpRequest($base, '/api/settings/get.php', $qaPatientCookie, 'GET'),
        'V2 patient settings GET after password change');

    $qaPasswordVersion = (int)$qaPasswordRow['session_version'];
    apiSuccess(httpRequest($base, '/api/settings/update.php', $qaPatientCookie, 'POST', [
        'section' => 'delete_account',
        'confirm_password' => $qaNewPassword,
    ]), 'V2 patient self-deactivation');
    $qaDeactivatedStmt = $clone->prepare('SELECT is_active,session_version FROM users WHERE id=?');
    $qaDeactivatedStmt->execute([$qaPatientId]);
    $qaDeactivated = $qaDeactivatedStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaDeactivated)
        && (int)$qaDeactivated['is_active'] === 0
        && (int)$qaDeactivated['session_version'] === $qaPasswordVersion + 1,
        'V2 patient self-deactivation did not disable the account and increment session_version.');
    expectHttp(httpRequest($base, '/api/settings/get.php', $qaPatientCookie, 'GET')['status'] === 403,
        'Self-deactivated V2 patient was not denied by the inactive-account guard.');
    $qaPatientStatus = httpRequest($base, '/api/patients/toggle-status.php', $adminCookie, 'POST', ['id' => $qaPatientId]);
    apiSuccess($qaPatientStatus, 'V2 admin restore self-deactivated patient');
    expectHttp((int)($qaPatientStatus['body']['is_active'] ?? 0) === 1,
        'Admin could not restore the synthetic self-deactivated patient.');
    $notes[] = 'V2 patient create/update/demographics, settings read, password change/session refresh, self-deactivation/session revocation, and admin restoration passed authenticated HTTP.';

    // Prescription lifecycle via V2 authenticated HTTP, using a fresh appointment.
    // The earlier original-doctor status test intentionally cancelled its waiting visits.
    $qaDoctorVersionStmt = $clone->prepare('SELECT session_version FROM users WHERE id=?');
    $qaDoctorVersionStmt->execute([$qaDoctorId]);
    $qaDoctorCookie = makeSession($sessions, $qaDoctorId, (int)$qaDoctorVersionStmt->fetchColumn(), 'doctor');
    $patientVersionStmt = $clone->prepare('SELECT session_version FROM users WHERE id=?');
    $patientVersionStmt->execute([(int)$patient['id']]);
    $patientCookie = makeSession($sessions, (int)$patient['id'], (int)$patientVersionStmt->fetchColumn(), 'patient');

    $rxSlot = null;
    $slotStart = new DateTimeImmutable('today');
    for ($offset = 1; $offset <= 60 && $rxSlot === null; $offset++) {
        $rxDate = $slotStart->modify('+' . $offset . ' days')->format('Y-m-d');
        $rxAvailable = $schedule->getAvailableSlots($qaDoctorId, $rxDate);
        if ($rxAvailable !== []) {
            $rxSlot = ['date' => $rxDate, 'time' => (string)$rxAvailable[0]['time']];
        }
    }
    expectHttp(is_array($rxSlot), 'No V2 slots were available for the synthetic doctor prescription appointment.');
    $rxAppointmentId = $booking->book([
        'doctor_user_id' => $qaDoctorId,
        'patient_user_id' => (int)$patient['id'],
        'department_id' => (int)$candidate['department_id'],
        'date' => $rxSlot['date'],
        'time' => $rxSlot['time'],
        'notes' => 'Synthetic V2 prescription lifecycle booking; disposable clone only.',
        'actor_user_id' => (int)$patient['id'],
        'actor_role' => 'patient',
    ]);
    $rxConfirm = $clone->prepare(
        "UPDATE appointments SET booking_status='confirmed' WHERE id=? AND booking_status='pending'"
    );
    $rxConfirm->execute([$rxAppointmentId]);
    expectHttp($rxConfirm->rowCount() === 1, 'Fresh V2 prescription appointment could not be confirmed.');
    $rxStatusStmt = $clone->prepare('SELECT booking_status FROM appointments WHERE id=?');
    $rxStatusStmt->execute([$rxAppointmentId]);
    expectHttp((string)$rxStatusStmt->fetchColumn() === 'confirmed',
        'Fresh V2 prescription appointment was not confirmed.');

    $prescriptionBody = [
        'appointment_id' => $rxAppointmentId,
        'notes' => 'Synthetic V2 authenticated HTTP prescription test; disposable clone only.',
        'items' => [[
            'medication_name' => 'V2 HTTP QA Medication',
            'strength' => '250 mg',
            'dosage' => '1 tablet',
            'frequency' => 'Twice daily',
            'duration' => '5 days',
            'instructions' => 'Synthetic test instructions only.',
        ]],
    ];
    expectHttp(httpRequest($base, '/api/prescriptions/create.php', $patientCookie, 'POST', $prescriptionBody)['status'] === 403,
        'Patient was allowed to create a prescription.');
    expectHttp(httpRequest($base, '/api/prescriptions/create.php', $adminCookie, 'POST', $prescriptionBody)['status'] === 403,
        'Admin was allowed to create a prescription.');

    $prescriptionCreate = httpRequest($base, '/api/prescriptions/create.php', $qaDoctorCookie, 'POST', $prescriptionBody);
    apiSuccess($prescriptionCreate, 'V2 doctor prescription create');
    $qaPrescriptionId = (int)($prescriptionCreate['body']['prescription_id'] ?? 0);
    expectHttp($qaPrescriptionId > 0, 'Prescription create did not return a valid ID.');
    $qaPrescriptionStmt = $clone->prepare(
        'SELECT patient_user_id,doctor_user_id,appointment_id,status,notes FROM prescriptions WHERE id=?'
    );
    $qaPrescriptionStmt->execute([$qaPrescriptionId]);
    $qaPrescription = $qaPrescriptionStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaPrescription)
        && (int)$qaPrescription['patient_user_id'] === (int)$patient['id']
        && (int)$qaPrescription['doctor_user_id'] === $qaDoctorId
        && (int)$qaPrescription['appointment_id'] === $rxAppointmentId
        && strtolower((string)$qaPrescription['status']) === 'active',
        'V2 prescription create persisted the wrong patient, doctor, appointment or status.');
    $qaPrescriptionItemsStmt = $clone->prepare(
        'SELECT medication_name FROM prescription_items WHERE prescription_id=? ORDER BY sort_order,id'
    );
    $qaPrescriptionItemsStmt->execute([$qaPrescriptionId]);
    expectHttp((string)$qaPrescriptionItemsStmt->fetchColumn() === 'V2 HTTP QA Medication',
        'V2 prescription create did not persist medication items.');

    expectHttp(httpRequest($base, '/api/prescriptions/create.php', $qaDoctorCookie, 'POST', $prescriptionBody)['status'] === 409,
        'V2 prescription create did not reject a duplicate for the same appointment.');
    $updatedPrescriptionBody = [
        'prescription_id' => $qaPrescriptionId,
        'notes' => 'Updated V2 synthetic HTTP prescription notes.',
        'items' => [[
            'medication_name' => 'V2 HTTP QA Medication Updated',
            'strength' => '500 mg',
            'dosage' => '1 capsule',
            'frequency' => 'Once daily',
            'duration' => '7 days',
            'instructions' => 'Updated synthetic test instructions.',
        ]],
    ];
    apiSuccess(httpRequest($base, '/api/prescriptions/update.php', $qaDoctorCookie, 'POST', $updatedPrescriptionBody),
        'V2 doctor prescription update');
    $qaPrescriptionStmt->execute([$qaPrescriptionId]);
    $qaPrescription = $qaPrescriptionStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaPrescription)
        && (string)$qaPrescription['notes'] === 'Updated V2 synthetic HTTP prescription notes.',
        'V2 prescription update did not persist notes.');
    $qaPrescriptionItemsStmt->execute([$qaPrescriptionId]);
    expectHttp((string)$qaPrescriptionItemsStmt->fetchColumn() === 'V2 HTTP QA Medication Updated',
        'V2 prescription update did not replace medication items.');

    apiSuccess(httpRequest($base, '/api/prescriptions/cancel.php', $qaDoctorCookie, 'POST', [
        'prescription_id' => $qaPrescriptionId,
        'reason' => 'Synthetic V2 HTTP cancellation reason; disposable clone only.',
    ]), 'V2 doctor prescription cancel');
    $qaPrescriptionStmt->execute([$qaPrescriptionId]);
    $qaPrescription = $qaPrescriptionStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaPrescription)
        && strtolower((string)$qaPrescription['status']) === 'cancelled',
        'V2 prescription cancel did not persist the cancelled status.');
    expectHttp(httpRequest($base, '/api/prescriptions/update.php', $qaDoctorCookie, 'POST', $updatedPrescriptionBody)['status'] === 403,
        'V2 prescription update accepted a cancelled prescription.');
    $notes[] = 'V2 prescription create/duplicate-guard/update/cancel and patient/admin role denials passed authenticated HTTP.';

    // Complete a separate prescription and verify the full status/audit path.
    $rxCompleteSlot = null;
    for ($offset = 1; $offset <= 60 && $rxCompleteSlot === null; $offset++) {
        $rxDate = $slotStart->modify('+' . $offset . ' days')->format('Y-m-d');
        $available = $schedule->getAvailableSlots($qaDoctorId, $rxDate);
        foreach ($available as $availableSlot) {
            if ($rxDate !== $rxSlot['date'] || (string)$availableSlot['time'] !== $rxSlot['time']) {
                $rxCompleteSlot = ['date' => $rxDate, 'time' => (string)$availableSlot['time']];
                break;
            }
        }
    }
    expectHttp(is_array($rxCompleteSlot), 'No additional V2 slot was available for prescription completion.');
    $rxCompleteAppointmentId = $booking->book([
        'doctor_user_id' => $qaDoctorId,
        'patient_user_id' => (int)$patient['id'],
        'department_id' => (int)$candidate['department_id'],
        'date' => $rxCompleteSlot['date'],
        'time' => $rxCompleteSlot['time'],
        'notes' => 'Synthetic V2 prescription completion booking; disposable clone only.',
        'actor_user_id' => (int)$patient['id'],
        'actor_role' => 'patient',
    ]);
    $rxConfirm->execute([$rxCompleteAppointmentId]);
    expectHttp($rxConfirm->rowCount() === 1, 'Prescription completion appointment could not be confirmed.');
    $completionPrescriptionBody = $prescriptionBody;
    $completionPrescriptionBody['appointment_id'] = $rxCompleteAppointmentId;
    $completionPrescriptionBody['notes'] = 'Synthetic V2 prescription completion test.';
    $completionCreate = httpRequest($base, '/api/prescriptions/create.php', $qaDoctorCookie, 'POST', $completionPrescriptionBody);
    apiSuccess($completionCreate, 'V2 prescription for completion');
    $qaCompletionPrescriptionId = (int)($completionCreate['body']['prescription_id'] ?? 0);
    expectHttp($qaCompletionPrescriptionId > 0, 'Prescription completion setup did not return an ID.');
    expectHttp(httpRequest($base, '/api/prescriptions/complete.php', $patientCookie, 'POST', [
        'prescription_id' => $qaCompletionPrescriptionId,
    ])['status'] === 403, 'Patient was allowed to complete a prescription.');
    expectHttp(httpRequest($base, '/api/prescriptions/complete.php', $adminCookie, 'POST', [
        'prescription_id' => $qaCompletionPrescriptionId,
    ])['status'] === 403, 'Admin was allowed to complete a prescription.');
    apiSuccess(httpRequest($base, '/api/prescriptions/complete.php', $qaDoctorCookie, 'POST', [
        'prescription_id' => $qaCompletionPrescriptionId,
    ]), 'V2 doctor prescription complete');
    $qaPrescriptionStmt->execute([$qaCompletionPrescriptionId]);
    $qaCompletedPrescription = $qaPrescriptionStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaCompletedPrescription)
        && strtolower((string)$qaCompletedPrescription['status']) === 'completed',
        'V2 prescription completion did not persist Completed status.');
    $notes[] = 'V2 prescription completion and patient/admin role denials passed authenticated HTTP.';

    // Archive the synthetic doctor: retain profile, seven-day schedule and all prescription history.
    apiSuccess(httpRequest($base, '/api/doctors/delete.php', $adminCookie, 'POST', [
        'id' => $qaDoctorId,
    ]), 'V2 admin doctor archive');
    $qaArchivedDoctorStmt = $clone->prepare('SELECT is_active,session_version FROM users WHERE id=?');
    $qaArchivedDoctorStmt->execute([$qaDoctorId]);
    $qaArchivedDoctor = $qaArchivedDoctorStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaArchivedDoctor) && (int)$qaArchivedDoctor['is_active'] === 0,
        'V2 doctor archive did not disable sign-in.');
    $qaDoctorAggregateCount = $clone->prepare('SELECT COUNT(*) FROM doctor_profiles WHERE doctor_user_id=?');
    $qaDoctorAggregateCount->execute([$qaDoctorId]);
    expectHttp((int)$qaDoctorAggregateCount->fetchColumn() === 1,
        'V2 doctor archive deleted the retained doctor profile.');
    $qaDoctorScheduleCount = $clone->prepare('SELECT COUNT(*) FROM doctor_weekly_schedules WHERE doctor_user_id=?');
    $qaDoctorScheduleCount->execute([$qaDoctorId]);
    expectHttp((int)$qaDoctorScheduleCount->fetchColumn() === 7,
        'V2 doctor archive deleted the retained weekly schedule.');
    $qaDoctorPrescriptionCount = $clone->prepare('SELECT COUNT(*) FROM prescriptions WHERE doctor_user_id=?');
    $qaDoctorPrescriptionCount->execute([$qaDoctorId]);
    expectHttp((int)$qaDoctorPrescriptionCount->fetchColumn() === 2,
        'V2 doctor archive did not retain cancelled/completed prescription history.');
    $qaDoctorArchiveAudit = $clone->prepare(
        "SELECT COUNT(*) FROM audit_events WHERE action_code='archive' AND entity_type='doctor' AND entity_id=?"
    );
    $qaDoctorArchiveAudit->execute([(string)$qaDoctorId]);
    expectHttp((int)$qaDoctorArchiveAudit->fetchColumn() === 1,
        'V2 doctor archive audit event was not recorded.');

    // Archive the synthetic patient and confirm profile + medical aggregate retention.
    $qaPatientVersionBeforeArchive = (int)$clone->query(
        'SELECT session_version FROM users WHERE id=' . $qaPatientId
    )->fetchColumn();
    apiSuccess(httpRequest($base, '/api/patients/delete.php', $adminCookie, 'POST', [
        'id' => $qaPatientId,
    ]), 'V2 admin patient archive');
    $qaPatientArchivedStmt = $clone->prepare('SELECT is_active,session_version FROM users WHERE id=?');
    $qaPatientArchivedStmt->execute([$qaPatientId]);
    $qaPatientArchived = $qaPatientArchivedStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($qaPatientArchived)
        && (int)$qaPatientArchived['is_active'] === 0
        && (int)$qaPatientArchived['session_version'] === $qaPatientVersionBeforeArchive + 1,
        'V2 patient archive did not disable sign-in and revoke existing sessions.');
    $qaPatientAggregate->execute([$qaPatientId]);
    expectHttp((int)$qaPatientAggregate->fetchColumn() === 1,
        'V2 patient archive deleted the retained patient profile.');
    $qaMedicalAggregate->execute([$qaPatientId]);
    expectHttp((int)$qaMedicalAggregate->fetchColumn() === 1,
        'V2 patient archive deleted the retained medical profile.');
    $qaPatientArchiveAudit = $clone->prepare(
        "SELECT COUNT(*) FROM audit_events WHERE action_code='archive' AND entity_type='patient' AND entity_id=?"
    );
    $qaPatientArchiveAudit->execute([(string)$qaPatientId]);
    expectHttp((int)$qaPatientArchiveAudit->fetchColumn() === 1,
        'V2 patient archive audit event was not recorded.');
    $notes[] = 'V2 doctor/patient archive routes passed authenticated HTTP and retained profile, schedule, clinical prescription and audit history as designed.';

    // V2 clinical EMR HTTP lifecycle on one fresh, synthetic, confirmed appointment.
    // Rebuild original fixture sessions after earlier status toggles revoked old sessions.
    $doctorVersionForClinical = $clone->prepare('SELECT session_version FROM users WHERE id=?');
    $doctorVersionForClinical->execute([$doctorId]);
    $doctorCookie = makeSession($sessions, $doctorId, (int)$doctorVersionForClinical->fetchColumn(), 'doctor');
    $patientVersionForClinical = $clone->prepare('SELECT session_version FROM users WHERE id=?');
    $patientVersionForClinical->execute([(int)$patient['id']]);
    $patientCookie = makeSession($sessions, (int)$patient['id'], (int)$patientVersionForClinical->fetchColumn(), 'patient');

    $clinicalSlot = null;
    for ($offset = 1; $offset <= 60 && $clinicalSlot === null; $offset++) {
        $clinicalDate = (new DateTimeImmutable('today'))->modify('+' . $offset . ' days')->format('Y-m-d');
        $clinicalSlots = $schedule->getAvailableSlots($doctorId, $clinicalDate);
        if ($clinicalSlots !== []) {
            $clinicalSlot = ['date' => $clinicalDate, 'time' => (string)$clinicalSlots[0]['time']];
        }
    }
    expectHttp(is_array($clinicalSlot), 'No V2 slot available for clinical EMR HTTP acceptance.');
    $clinicalAppointmentId = $booking->book([
        'doctor_user_id' => $doctorId,
        'patient_user_id' => (int)$patient['id'],
        'department_id' => (int)$candidate['department_id'],
        'date' => $clinicalSlot['date'],
        'time' => $clinicalSlot['time'],
        'notes' => 'Synthetic V2 clinical EMR HTTP appointment; disposable clone only.',
        'actor_user_id' => (int)$patient['id'],
        'actor_role' => 'patient',
    ]);
    $clinicalConfirm = $clone->prepare(
        "UPDATE appointments SET booking_status='confirmed' WHERE id=? AND booking_status='pending'"
    );
    $clinicalConfirm->execute([$clinicalAppointmentId]);
    expectHttp($clinicalConfirm->rowCount() === 1, 'Synthetic V2 clinical appointment could not be confirmed.');

    $draftPayload = [
        'appointment_id' => $clinicalAppointmentId,
        'diagnosis' => 'Synthetic HTTP draft diagnosis',
        'symptoms' => 'Synthetic HTTP draft symptoms',
        'treatment' => 'Synthetic HTTP draft plan',
        'doctor_notes' => 'Synthetic HTTP draft notes',
        'follow_up_instructions' => 'Synthetic HTTP draft follow-up',
    ];
    expectHttp(httpRequest($base, '/api/medical/save-visit-draft.php', $patientCookie, 'POST', $draftPayload)['status'] === 403,
        'Patient was allowed to save a doctor visit draft.');
    apiSuccess(httpRequest($base, '/api/medical/save-visit-draft.php', $doctorCookie, 'POST', $draftPayload),
        'V2 doctor save visit draft');
    $draftRead = httpRequest($base, '/api/medical/get-visit-draft.php?appointment_id=' . $clinicalAppointmentId, $doctorCookie, 'GET');
    apiSuccess($draftRead, 'V2 doctor get visit draft');
    expectHttp((string)($draftRead['body']['draft']['diagnosis'] ?? '') === $draftPayload['diagnosis'],
        'V2 visit draft read-back did not match.');
    expectHttp(httpRequest($base, '/api/medical/get-visit-draft.php?appointment_id=' . $clinicalAppointmentId, $patientCookie, 'GET')['status'] === 403,
        'Patient was allowed to read a doctor visit draft.');
    expectHttp(httpRequest($base, '/api/medical/delete-visit-draft.php', $patientCookie, 'POST', [
        'appointment_id' => $clinicalAppointmentId,
    ])['status'] === 403, 'Patient was allowed to delete a doctor visit draft.');

    $visitNotePayload = [
        'appointment_id' => $clinicalAppointmentId,
        'diagnosis' => 'Synthetic HTTP diagnosis',
        'symptoms' => 'Synthetic HTTP symptoms',
        'treatment' => 'Synthetic HTTP treatment',
        'doctor_notes' => 'Synthetic HTTP clinician note',
        'follow_up_instructions' => 'Synthetic HTTP follow-up',
    ];
    expectHttp(httpRequest($base, '/api/medical/add-visit-note.php', $patientCookie, 'POST', $visitNotePayload)['status'] === 403,
        'Patient was allowed to add clinical visit notes.');
    apiSuccess(httpRequest($base, '/api/medical/add-visit-note.php', $doctorCookie, 'POST', $visitNotePayload),
        'V2 doctor add visit note');
    $clinicalRowStmt = $clone->prepare(
        'SELECT patient_user_id,doctor_user_id,workflow_status,diagnosis,symptoms,treatment,doctor_notes,follow_up_instructions
         FROM clinical_encounters WHERE appointment_id=?'
    );
    $clinicalRowStmt->execute([$clinicalAppointmentId]);
    $clinicalRow = $clinicalRowStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($clinicalRow)
        && (int)$clinicalRow['patient_user_id'] === (int)$patient['id']
        && (int)$clinicalRow['doctor_user_id'] === $doctorId
        && (string)$clinicalRow['diagnosis'] === $visitNotePayload['diagnosis']
        && (string)$clinicalRow['treatment'] === $visitNotePayload['treatment'],
        'V2 visit note did not persist its patient/doctor ownership or clinical fields.');

    $adminNoteUpdate = $visitNotePayload;
    $adminNoteUpdate['diagnosis'] = 'Synthetic HTTP admin-corrected diagnosis';
    apiSuccess(httpRequest($base, '/api/medical/add-visit-note.php', $adminCookie, 'POST', $adminNoteUpdate),
        'V2 admin clinical note correction');
    $clinicalRowStmt->execute([$clinicalAppointmentId]);
    $clinicalRow = $clinicalRowStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp((string)$clinicalRow['diagnosis'] === $adminNoteUpdate['diagnosis'],
        'V2 admin clinical note correction did not persist.');

    // Patient and assigned doctor may read their EMR; patient IDs in query strings cannot widen scope.
    $patientRecord = httpRequest($base, '/api/medical/get-record.php?patient_id=' . (int)$admin['id'], $patientCookie, 'GET');
    apiSuccess($patientRecord, 'V2 patient medical record read');
    expectHttp((int)($patientRecord['body']['patient']['id'] ?? 0) === (int)$patient['id'],
        'Patient medical-record endpoint did not force the authenticated patient identity.');
    $doctorRecord = httpRequest($base, '/api/medical/get-record.php?patient_id=' . (int)$patient['id'], $doctorCookie, 'GET');
    apiSuccess($doctorRecord, 'V2 assigned doctor medical record read');
    $doctorEmr = httpRequest($base, '/api/medical/get-emr-data.php?patient_id=' . (int)$patient['id'], $doctorCookie, 'GET');
    apiSuccess($doctorEmr, 'V2 assigned doctor EMR read model');
    expectHttp((int)($doctorEmr['body']['patient']['id'] ?? 0) === (int)$patient['id']
        && is_array($doctorEmr['body']['visit_notes'] ?? null),
        'V2 doctor EMR model omitted patient identity or visit-note collection.');
    $adminEmr = httpRequest($base, '/api/medical/get-emr-data.php?patient_id=' . (int)$patient['id'], $adminCookie, 'GET');
    apiSuccess($adminEmr, 'V2 admin EMR read model');
    expectHttp((int)($adminEmr['body']['patient']['id'] ?? 0) === (int)$patient['id'],
        'V2 admin EMR read model returned the wrong patient.');

    $otherDoctorId = (int)$clone->query(
        "SELECT u.id FROM users u
         WHERE u.role='doctor' AND u.is_active=1 AND u.id<>" . $doctorId . "
           AND NOT EXISTS (
             SELECT 1 FROM appointments a
             WHERE a.doctor_user_id=u.id AND a.patient_user_id=" . (int)$patient['id'] . "
           )
         ORDER BY u.id LIMIT 1"
    )->fetchColumn();
    expectHttp($otherDoctorId > 0, 'No second active doctor fixture was available for EMR ownership denial.');
    $otherDoctorVersion = $clone->prepare('SELECT session_version FROM users WHERE id=?');
    $otherDoctorVersion->execute([$otherDoctorId]);
    $otherDoctorCookie = makeSession($sessions, $otherDoctorId, (int)$otherDoctorVersion->fetchColumn(), 'doctor');
    expectHttp(httpRequest($base, '/api/medical/get-record.php?patient_id=' . (int)$patient['id'], $otherDoctorCookie, 'GET')['status'] === 403,
        'Unassigned doctor was allowed to read the patient medical record.');
    expectHttp(httpRequest($base, '/api/medical/get-emr-data.php?patient_id=' . (int)$patient['id'], $otherDoctorCookie, 'GET')['status'] === 403,
        'Unassigned doctor was allowed to read the patient EMR.');

    expectHttp(httpRequest($base, '/api/medical/transition-workflow.php', $patientCookie, 'POST', [
        'appointment_id' => $clinicalAppointmentId,
        'status' => 'In Progress',
    ])['status'] === 403, 'Patient was allowed to change clinical workflow status.');
    expectHttp(httpRequest($base, '/api/medical/transition-workflow.php', $doctorCookie, 'POST', [
        'appointment_id' => $clinicalAppointmentId,
        'status' => 'Completed',
    ])['status'] === 400, 'Workflow endpoint accepted a direct Completed transition.');
    $workflowStart = httpRequest($base, '/api/medical/transition-workflow.php', $doctorCookie, 'POST', [
        'appointment_id' => $clinicalAppointmentId,
        'status' => 'In Progress',
    ]);
    apiSuccess($workflowStart, 'V2 workflow transition to In Progress');
    expectHttp((string)($workflowStart['body']['workflow']['status'] ?? '') === 'In Progress',
        'V2 workflow did not enter In Progress.');
    $workflowReady = httpRequest($base, '/api/medical/transition-workflow.php', $doctorCookie, 'POST', [
        'appointment_id' => $clinicalAppointmentId,
        'status' => 'Ready to Complete',
    ]);
    apiSuccess($workflowReady, 'V2 workflow transition to Ready to Complete');
    expectHttp((string)($workflowReady['body']['workflow']['status'] ?? '') === 'Ready to Complete',
        'V2 workflow did not enter Ready to Complete.');

    expectHttp(httpRequest($base, '/api/medical/complete-visit.php', $patientCookie, 'POST', $visitNotePayload)['status'] === 403,
        'Patient was allowed to complete a clinical visit.');
    $incompleteVisit = httpRequest($base, '/api/medical/complete-visit.php', $doctorCookie, 'POST', [
        'appointment_id' => $clinicalAppointmentId,
        'diagnosis' => 'Complete guard test only',
        'symptoms' => '',
        'treatment' => '',
    ]);
    expectHttp($incompleteVisit['status'] === 422,
        'V2 visit completion did not enforce required diagnosis, symptoms and treatment.');

    apiSuccess(httpRequest($base, '/api/medical/delete-visit-draft.php', $doctorCookie, 'POST', [
        'appointment_id' => $clinicalAppointmentId,
    ]), 'V2 doctor delete visit draft');
    $draftCount = $clone->prepare('SELECT COUNT(*) FROM encounter_drafts WHERE appointment_id=?');
    $draftCount->execute([$clinicalAppointmentId]);
    expectHttp((int)$draftCount->fetchColumn() === 0, 'V2 visit draft remained after explicit delete.');
    // Recreate the draft so atomic visit completion can prove that it removes it.
    apiSuccess(httpRequest($base, '/api/medical/save-visit-draft.php', $doctorCookie, 'POST', $draftPayload),
        'V2 doctor recreate draft before completion');
    apiSuccess(httpRequest($base, '/api/medical/complete-visit.php', $doctorCookie, 'POST', $visitNotePayload),
        'V2 doctor atomic complete visit');
    $clinicalRowStmt->execute([$clinicalAppointmentId]);
    $clinicalRow = $clinicalRowStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($clinicalRow)
        && (string)$clinicalRow['workflow_status'] === 'completed'
        && (string)$clinicalRow['diagnosis'] === $visitNotePayload['diagnosis'],
        'V2 atomic visit completion did not persist clinical note and completed workflow.');
    $draftCount->execute([$clinicalAppointmentId]);
    expectHttp((int)$draftCount->fetchColumn() === 0,
        'V2 atomic visit completion did not remove the autosave draft.');
    $clinicalAppointmentStatus = $clone->prepare('SELECT booking_status FROM appointments WHERE id=?');
    $clinicalAppointmentStatus->execute([$clinicalAppointmentId]);
    expectHttp((string)$clinicalAppointmentStatus->fetchColumn() === 'confirmed',
        'V2 clinical workflow completion incorrectly changed the parent appointment booking status.');
    expectHttp(httpRequest($base, '/api/medical/get-visit-draft.php?appointment_id=' . $clinicalAppointmentId, $doctorCookie, 'GET')['status'] === 409,
        'Completed V2 visit still exposed an editable draft endpoint.');
    expectHttp(httpRequest($base, '/api/medical/save-visit-draft.php', $doctorCookie, 'POST', $draftPayload)['status'] === 409,
        'Completed V2 visit still accepted a new editable draft.');
    expectHttp(httpRequest($base, '/api/medical/complete-visit.php', $doctorCookie, 'POST', $visitNotePayload)['status'] === 409,
        'V2 visit completion was not guarded against an already-completed visit.');
    $patientRecordAfter = httpRequest($base, '/api/medical/get-record.php?patient_id=' . (int)$patient['id'], $patientCookie, 'GET');
    apiSuccess($patientRecordAfter, 'V2 patient medical record after completion');
    $foundClinicalVisit = false;
    foreach (($patientRecordAfter['body']['visit_history'] ?? []) as $visit) {
        if ((int)($visit['appointment_id'] ?? 0) === $clinicalAppointmentId) $foundClinicalVisit = true;
    }
    expectHttp($foundClinicalVisit, 'V2 completed clinical note was missing from the patient visit history.');

    // V2 medical-profile corrections, admin reason enforcement and doctor timeline deduplication.
    expectHttp(httpRequest($base, '/api/medical/update-record.php', $patientCookie, 'POST', [
        'patient_id' => (int)$patient['id'],
        'allergies' => 'Patient must not edit protected medical profile.',
    ])['status'] === 403, 'Patient was allowed to update the protected medical profile.');
    expectHttp(httpRequest($base, '/api/medical/update-record.php', $otherDoctorCookie, 'POST', [
        'patient_id' => (int)$patient['id'],
        'allergies' => 'Unassigned doctor must not edit this profile.',
    ])['status'] === 403, 'Unassigned doctor was allowed to update the medical profile.');

    $medicalQaSuffix = bin2hex(random_bytes(5));
    $profileBeforeStmt = $clone->prepare(
        'SELECT blood_type,allergies,height_cm FROM patient_medical_profiles WHERE patient_user_id=?'
    );
    $profileBeforeStmt->execute([(int)$patient['id']]);
    $profileBefore = $profileBeforeStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($profileBefore), 'V2 patient medical profile fixture is missing.');
    $newBloodType = (string)$profileBefore['blood_type'] === 'A+' ? 'O-' : 'A+';
    $adminCorrection = [
        'patient_id' => (int)$patient['id'],
        'blood_type' => $newBloodType,
        'allergies' => 'Synthetic V2 admin correction ' . $medicalQaSuffix,
        'height_cm' => 171.5,
        'emergency_contact_name' => 'Synthetic Admin Contact ' . $medicalQaSuffix,
        'reason' => 'Synthetic administrative correction reason for V2 HTTP QA.',
    ];
    expectHttp(httpRequest($base, '/api/medical/update-record.php', $adminCookie, 'POST', [
        'patient_id' => (int)$patient['id'],
        'blood_type' => $newBloodType,
    ])['status'] === 400,
        'V2 sensitive admin medical correction was accepted without a reason.');
    apiSuccess(httpRequest($base, '/api/medical/update-record.php', $adminCookie, 'POST', $adminCorrection),
        'V2 admin medical profile correction');
    $profileAfterStmt = $clone->prepare(
        'SELECT blood_type,allergies,height_cm FROM patient_medical_profiles WHERE patient_user_id=?'
    );
    $profileAfterStmt->execute([(int)$patient['id']]);
    $profileAfter = $profileAfterStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($profileAfter)
        && (string)$profileAfter['blood_type'] === $newBloodType
        && (string)$profileAfter['allergies'] === $adminCorrection['allergies']
        && (float)$profileAfter['height_cm'] === 171.5,
        'V2 admin medical profile correction did not persist into patient_medical_profiles.');
    $profileContactStmt = $clone->prepare(
        'SELECT emergency_name FROM patient_profiles WHERE patient_user_id=?'
    );
    $profileContactStmt->execute([(int)$patient['id']]);
    expectHttp((string)$profileContactStmt->fetchColumn() === $adminCorrection['emergency_contact_name'],
        'V2 admin emergency-contact correction did not persist into patient_profiles.');

    $adminEmrAfterCorrection = httpRequest($base,
        '/api/medical/get-emr-data.php?patient_id=' . (int)$patient['id'], $adminCookie, 'GET');
    apiSuccess($adminEmrAfterCorrection, 'V2 admin EMR after medical-profile correction');
    $adminAuditHasReason = false;
    foreach (($adminEmrAfterCorrection['body']['audit_history'] ?? []) as $auditItem) {
        if (($auditItem['field_name'] ?? '') === 'blood_type'
            && str_contains((string)($auditItem['reason'] ?? ''), 'Synthetic administrative correction reason')) {
            $adminAuditHasReason = true;
        }
    }
    expectHttp($adminAuditHasReason,
        'V2 admin EMR audit projection omitted the sensitive correction field or reason.');

    $doctorOldAllergies = (string)$profileAfter['allergies'];
    $doctorNewAllergies = 'Synthetic V2 doctor history update ' . $medicalQaSuffix;
    apiSuccess(httpRequest($base, '/api/medical/update-record.php', $doctorCookie, 'POST', [
        'patient_id' => (int)$patient['id'],
        'allergies' => $doctorNewAllergies,
    ]), 'V2 assigned-doctor medical profile update');
    $historyCountStmt = $clone->prepare(
        'SELECT COUNT(*) FROM medical_history_events
         WHERE patient_user_id=? AND doctor_user_id=? AND field_name=?
           AND old_value <=> ? AND new_value <=> ?'
    );
    $historyParams = [
        (int)$patient['id'], $doctorId, 'allergies', $doctorOldAllergies, $doctorNewAllergies,
    ];
    $historyCountStmt->execute($historyParams);
    expectHttp((int)$historyCountStmt->fetchColumn() === 1,
        'V2 doctor medical-profile update did not create exactly one medical history event.');
    apiSuccess(httpRequest($base, '/api/medical/add-history-update.php', $doctorCookie, 'POST', [
        'patient_id' => (int)$patient['id'],
        'field' => 'allergies',
        'old_value' => $doctorOldAllergies,
        'new_value' => $doctorNewAllergies,
    ]), 'V2 legacy doctor history follow-up acknowledgement');
    $historyCountStmt->execute($historyParams);
    expectHttp((int)$historyCountStmt->fetchColumn() === 1,
        'V2 history follow-up endpoint duplicated the update-record timeline event.');
    expectHttp(httpRequest($base, '/api/medical/add-history-update.php', $otherDoctorCookie, 'POST', [
        'patient_id' => (int)$patient['id'],
        'field' => 'allergies',
        'old_value' => $doctorOldAllergies,
        'new_value' => 'Unauthorized history value.',
    ])['status'] === 403,
        'Unassigned doctor was allowed to append medical-history events.');
    $patientEmrAfterProfileUpdates = httpRequest($base,
        '/api/medical/get-emr-data.php?patient_id=' . (int)$patient['id'], $patientCookie, 'GET');
    apiSuccess($patientEmrAfterProfileUpdates, 'V2 patient EMR after profile changes');
    expectHttp((string)($patientEmrAfterProfileUpdates['body']['medical_record']['allergies'] ?? '') === $doctorNewAllergies,
        'V2 patient EMR did not expose the latest normalized medical-profile value.');
    $notes[] = 'V2 medical-profile HTTP passed: patient/unassigned-doctor denials, admin sensitive correction reason requirement, V2 split profile persistence, admin audit reason projection, doctor history event creation and duplicate-suppressed legacy history acknowledgement.';

    $notes[] = 'V2 clinical EMR HTTP passed: doctor draft save/read/delete; note create/admin correction; patient, assigned-doctor/admin reads; unassigned-doctor denial; workflow transitions; validation and atomic completion/draft removal; appointment booking status retained.';

    // V2 registration intake and single-use email verification; no worker or SMTP.
    $regSuffix = bin2hex(random_bytes(5));
    $regEmail = 'v2-registration-' . $regSuffix . '@example.test';
    $regPhone = '0107' . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $regEmergencyPhone = '0108' . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $regPassword = 'V2-Reg-Password-2026-' . $regSuffix . '!';
    $regBasePayload = [
        'first_name' => 'V2',
        'last_name' => 'Registration ' . $regSuffix,
        'email' => $regEmail,
        'phone' => $regPhone,
        'password' => $regPassword,
        'confirm_password' => $regPassword,
    ];
    $queueMaxBefore = (int)$clone->query('SELECT COALESCE(MAX(id),0) FROM registration_intake_queue')->fetchColumn();
    apiSuccess(httpRequest($base, '/api/auth/register.php', $adminCookie, 'POST',
        array_merge(['step' => 1], $regBasePayload)), 'V2 registration step-one validation');
    $stepTwoPayload = array_merge($regBasePayload, [
        'step' => 2,
        'national_id' => '29901010100011',
        'date_of_birth' => '1999-01-01',
        'gender' => 'Male',
        'governorate' => 'Cairo',
        'city' => 'Nasr City',
        'address' => 'Synthetic registration test address.',
        'blood_type' => 'O+',
        'emergency_contact_name' => 'Synthetic Emergency Contact',
        'emergency_contact_phone' => $regEmergencyPhone,
        'emergency_contact_rel' => 'Sibling',
        'allergies' => 'Synthetic QA allergy',
        'chronic_diseases' => 'Synthetic QA condition',
        'current_medications' => 'Synthetic QA medication',
        'insurance_provider' => 'Synthetic QA insurer',
        'insurance_number' => 'QA-' . $regSuffix,
    ]);
    $stepTwoResponse = httpRequest($base, '/api/auth/register.php', $adminCookie, 'POST', $stepTwoPayload);
    apiSuccess($stepTwoResponse, 'V2 registration encrypted intake submission');
    expectHttp(!str_contains($stepTwoResponse['raw'], $regEmail)
        && !str_contains($stepTwoResponse['raw'], $regPhone)
        && !str_contains($stepTwoResponse['raw'], $regPassword),
        'V2 registration acknowledgement disclosed submitted identity/credential data.');
    $regQueueStmt = $clone->prepare(
        'SELECT id,request_id,key_version,nonce,auth_tag,ciphertext,email_lookup_hmac,status,email_verified_at
         FROM registration_intake_queue WHERE id>? ORDER BY id DESC LIMIT 1'
    );
    $regQueueStmt->execute([$queueMaxBefore]);
    $regQueue = $regQueueStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($regQueue)
        && $regQueue['status'] === 'pending'
        && $regQueue['key_version'] === 'contract-test'
        && !empty($regQueue['nonce']) && !empty($regQueue['auth_tag'])
        && !empty($regQueue['ciphertext']) && !empty($regQueue['email_lookup_hmac'])
        && $regQueue['email_verified_at'] === null,
        'V2 registration did not persist its encrypted pending intake row.');
    $regUserCheck = $clone->prepare('SELECT COUNT(*) FROM users WHERE email=?');
    $regUserCheck->execute([$regEmail]);
    expectHttp((int)$regUserCheck->fetchColumn() === 0,
        'V2 registration HTTP request created an account before worker processing.');

    $regToken = bin2hex(random_bytes(32));
    $awaitingStmt = $clone->prepare(
        "UPDATE registration_intake_queue SET status='awaiting_verification',
         verification_expires_at=DATE_ADD(NOW(),INTERVAL 10 MINUTE)
         WHERE id=? AND status='pending'"
    );
    $awaitingStmt->execute([(int)$regQueue['id']]);
    expectHttp($awaitingStmt->rowCount() === 1, 'Synthetic registration queue could not enter awaiting-verification.');
    $challengeStmt = $clone->prepare(
        'INSERT INTO registration_email_challenges(queue_id,token_hash,created_at,expires_at)
         VALUES (?, ?, NOW(), DATE_ADD(NOW(),INTERVAL 10 MINUTE))'
    );
    $challengeStmt->execute([(int)$regQueue['id'], hash('sha256', $regToken)]);
    $regChallengeId = (int)$clone->lastInsertId();
    apiSuccess(httpRequest($base, '/api/auth/confirm-registration-email.php', $adminCookie, 'POST', [
        'token' => $regToken,
    ]), 'V2 registration email confirmation');
    $regQueueStmt = $clone->prepare(
        'SELECT status,email_verified_at,email_lookup_hmac,verification_expires_at
         FROM registration_intake_queue WHERE id=?'
    );
    $regQueueStmt->execute([(int)$regQueue['id']]);
    $regQueueState = $regQueueStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($regQueueState)
        && $regQueueState['status'] === 'pending'
        && !empty($regQueueState['email_verified_at'])
        && $regQueueState['email_lookup_hmac'] === null
        && $regQueueState['verification_expires_at'] === null,
        'V2 confirmation did not release the queue and clear lookup/expiry data.');
    $challengeStateStmt = $clone->prepare(
        'SELECT consumed_at,revoked_at FROM registration_email_challenges WHERE id=?'
    );
    $challengeStateStmt->execute([$regChallengeId]);
    $challengeState = $challengeStateStmt->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($challengeState) && !empty($challengeState['consumed_at'])
        && $challengeState['revoked_at'] === null,
        'V2 confirmation did not consume the accepted challenge.');
    expectHttp(httpRequest($base, '/api/auth/confirm-registration-email.php', $adminCookie, 'POST', [
        'token' => $regToken,
    ])['status'] === 400, 'V2 registration token was accepted more than once.');
    $regUserCheck->execute([$regEmail]);
    expectHttp((int)$regUserCheck->fetchColumn() === 0,
        'Email confirmation created a patient without worker processing.');
    $notes[] = 'V2 registration HTTP passed: validation, encrypted intake, no pre-worker account creation, single-use verification and queue release. No worker/email was invoked.';

    // V2 login verification gate and password reset; all credential changes are restored in this disposable clone.
    require_once $root . '/services/Repositories/PasswordResetRepository.php';
    $authCandidate = $clone->query(
        "SELECT u.id,u.email,u.password_hash,u.session_version,u.is_active,
                u.email_verification_required,u.email_verified_at
         FROM users u
         WHERE u.role='patient' AND u.is_active=1
           AND NOT EXISTS (
             SELECT 1 FROM password_reset_tokens r
             WHERE r.email=u.email AND r.created_at>=DATE_SUB(NOW(),INTERVAL 1 HOUR)
           )
         ORDER BY u.id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    expectHttp(is_array($authCandidate), 'No suitable active V2 patient was available for auth HTTP QA.');
    $authUserId = (int)$authCandidate['id'];
    $authEmail = (string)$authCandidate['email'];
    $loginPassword = 'V2-Auth-Login-2026-' . $regSuffix . '!';
    $loginHash = password_hash($loginPassword, PASSWORD_DEFAULT);
    $restoreAuthUser = $clone->prepare(
        'UPDATE users SET password_hash=?,is_active=?,session_version=?,
         email_verification_required=?,email_verified_at=? WHERE id=?'
    );
    try {
        $restoreAuthUser->execute([$loginHash,1,(int)$authCandidate['session_version'],1,null,$authUserId]);
        $unverifiedLogin = httpRequest($base, '/api/auth/login.php', $patientCookie, 'POST', [
            'email' => $authEmail, 'password' => $loginPassword,
        ]);
        expectHttp($unverifiedLogin['status'] === 403
            && ($unverifiedLogin['body']['code'] ?? '') === 'EMAIL_VERIFICATION_REQUIRED',
            'V2 login did not block an unverified account.');
        $clone->prepare('UPDATE users SET email_verified_at=NOW() WHERE id=?')->execute([$authUserId]);
        apiSuccess(httpRequest($base, '/api/auth/login.php', $patientCookie, 'POST', [
            'email' => $authEmail, 'password' => $loginPassword,
        ]), 'V2 verified patient login');

        $resetRepo = new PasswordResetRepository($clone, 'v2');
        $resetRequest = $resetRepo->createRequest($authEmail, null);
        expectHttp(!$resetRequest['rate_limited'] && is_string($resetRequest['token']),
            'V2 test-only reset request did not issue a token.');
        $resetToken = (string)$resetRequest['token'];
        apiSuccess(httpRequest($base, '/api/auth/reset_password.php', $patientCookie, 'POST', [
            'token' => $resetToken, 'password' => '', 'confirm_password' => '', 'verify_only' => true,
        ]), 'V2 password-reset verify-only');
        $newResetPassword = 'V2-Reset-Password-2026-' . $regSuffix . '!';
        apiSuccess(httpRequest($base, '/api/auth/reset_password.php', $patientCookie, 'POST', [
            'token' => $resetToken, 'password' => $newResetPassword,
            'confirm_password' => $newResetPassword, 'verify_only' => false,
        ]), 'V2 password-reset consume/update');
        $updatedAuthUser = $clone->prepare('SELECT password_hash,session_version FROM users WHERE id=?');
        $updatedAuthUser->execute([$authUserId]);
        $updatedAuth = $updatedAuthUser->fetch(PDO::FETCH_ASSOC);
        expectHttp(is_array($updatedAuth)
            && password_verify($newResetPassword, (string)$updatedAuth['password_hash'])
            && (int)$updatedAuth['session_version'] === (int)$authCandidate['session_version'] + 1,
            'V2 reset did not update credentials and revoke prior sessions.');
        expectHttp(httpRequest($base, '/api/auth/reset_password.php', $patientCookie, 'POST', [
            'token' => $resetToken, 'password' => '', 'confirm_password' => '', 'verify_only' => true,
        ])['status'] === 400, 'V2 consumed reset token remained valid.');
        expectHttp(httpRequest($base, '/api/auth/login.php', $patientCookie, 'POST', [
            'email' => $authEmail, 'password' => $loginPassword,
        ])['status'] === 401, 'V2 previous password remained valid after reset.');
        apiSuccess(httpRequest($base, '/api/auth/login.php', $patientCookie, 'POST', [
            'email' => $authEmail, 'password' => $newResetPassword,
        ]), 'V2 login using reset password');
        $notes[] = 'V2 auth HTTP passed: unverified login denial, verified login, reset-token verify/consume, credential/session-version update, prior-password rejection and new-password login. No reset email was sent.';
    } finally {
        $clone->prepare('DELETE FROM password_reset_tokens WHERE email=?')->execute([$authEmail]);
        $restoreAuthUser->execute([
            (string)$authCandidate['password_hash'],(int)$authCandidate['is_active'],
            (int)$authCandidate['session_version'],(int)$authCandidate['email_verification_required'],
            $authCandidate['email_verified_at'],$authUserId,
        ]);
    }

    $ok = true;
} catch (Throwable $e) {
    $failure = $e->getMessage();
    fwrite(STDERR, 'FAIL: ' . $failure . "\n");
} finally {
    if (is_resource($server)) {
        @proc_terminate($server);
        foreach ($pipes as $pipe) if (is_resource($pipe)) @fclose($pipe);
        @proc_close($server);
    }
    removeTree($temp);
    $clone = null;
    if ($cloneCreated && $control instanceof PDO) {
        try {
            $control->exec('DROP DATABASE ' . qid($targetDb));
            $notes[] = 'Disposable V2 clone dropped after HTTP testing.';
        } catch (Throwable $cleanupError) {
            $failure = ($failure ? $failure . '; ' : '') . 'clone cleanup failed: ' . $cleanupError->getMessage();
            $ok = false;
        }
    }
    if ($control instanceof PDO) {
        try {
            $afterFingerprint = sourceFingerprint($control);
            if (($beforeFingerprint ?? null) !== $afterFingerprint) {
                $failure = ($failure ? $failure . '; ' : '') . 'source V2 fingerprint changed during test.';
                $ok = false;
            } else {
                $notes[] = 'Source V2 counts/AUTO_INCREMENT fingerprint unchanged.';
            }
        } catch (Throwable $fingerprintError) {
            $failure = ($failure ? $failure . '; ' : '') . 'source fingerprint check failed: ' . $fingerprintError->getMessage();
            $ok = false;
        }
    }

    if (!is_dir(HB_RESCHEDULE_QA_ROOT)) @mkdir(HB_RESCHEDULE_QA_ROOT, 0770, true);
    $report = [
        '# HealthBridge V2 authenticated application HTTP mutation contract',
        '',
        '- Date: ' . date('Y-m-d H:i:s P'),
        '- Source database: ' . HB_RESCHEDULE_SOURCE_DB . ' (read-only)',
        '- Test database: unique disposable full clone (dropped in cleanup)',
        '- HTTP: temporary app copy; V2 enabled through explicit per-domain selectors for the exercised appointment, reschedule, schedule, rating, audit, settings, hospital, department, doctor and patient routes',
        '- Copied paths: api, includes, services; no .env, vendor, or .git copied',
        '- Test appointment ID: ' . ($appointmentId ?: 'not created'),
        '',
    ];
    foreach ($notes as $line) $report[] = '- ' . $line;
    $report[] = '- Assertions: ' . $checks;
    $report[] = '- Result: ' . ($ok && $failure === null ? 'PASS' : 'FAIL');
    if ($failure !== null) $report[] = '- Failure: ' . $failure;
    $report[] = '- Temporary PHP server stopped; app copy and sessions removed.';
    @file_put_contents(HB_RESCHEDULE_QA_ROOT . DIRECTORY_SEPARATOR . 'RESULT.md', implode("\n", $report) . "\n");
}

if (!$ok || $failure !== null) exit(1);
echo "RESULT=PASS|assertions={$checks}|appointment={$appointmentId}|source_untouched=1|clone_dropped=1\n";
