<?php
declare(strict_types=1);

/**
 * Authenticated local HTTP contract for rating visibility.
 * Uses only the approved disposable V1 fixture and always restores its schema,
 * admin password/session version, rating visibility, and synthetic audit rows.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}

const RV_HTTP_SCHEMA = 'healthbridge_hb_auth_email_verify_20261114';
const RV_HTTP_ROOT = 'C:\\xampp85\\htdocs';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$dbUser = (string)(getenv('DB_USER') ?: 'root');
$passValue = getenv('DB_PASS');
$dbPass = $passValue === false ? '' : (string)$passValue;

if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
    fwrite(STDERR, "Non-loopback database host refused.\n");
    exit(11);
}

function hExpect(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

function hRequest(
    string $url,
    string $method = 'GET',
    ?array $payload = null,
    ?string $cookie = null,
    ?string $origin = null,
    string $agent = 'HealthBridgeRatingVisibilityHTTPTest'
): array {
    $headers = ['Accept: application/json', 'Connection: close', 'User-Agent: ' . $agent];
    if ($origin !== null) $headers[] = 'Origin: ' . $origin;
    if ($cookie !== null) $headers[] = 'Cookie: ' . $cookie;
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Sec-Fetch-Site: same-origin';
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 8,
            'protocol_version' => 1.1,
        ],
    ]);
    $raw = file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})\b/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
        'headers' => $responseHeaders,
    ];
}

/** Match by user_id, not doctor-profile primary key. */
function hDoctorSummary(array $response, int $doctorUserId): array
{
    hExpect($response['status'] === 200 && ($response['body']['success'] ?? false) === true,
        'Public doctor directory failed: HTTP ' . $response['status']);
    $doctors = $response['body']['doctors'] ?? [];
    hExpect(is_array($doctors), 'Doctor directory DTO is missing.');
    foreach ($doctors as $doctor) {
        if ((int)($doctor['user_id'] ?? 0) === $doctorUserId) {
            return [
                'rating_count' => (int)($doctor['rating_count'] ?? 0),
                'rating' => (float)($doctor['rating'] ?? 0),
            ];
        }
    }
    throw new RuntimeException("Doctor user {$doctorUserId} is missing from public directory.");
}

function hFindRating(array $rows, int $id): ?array
{
    foreach ($rows as $row) {
        if (is_array($row) && (int)($row['id'] ?? 0) === $id) return $row;
    }
    return null;
}

$db = new PDO(
    "mysql:host={$host};port={$port};dbname=" . RV_HTTP_SCHEMA . ";charset=utf8mb4",
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 3,
    ]
);
hExpect((string)$db->query('SELECT DATABASE()')->fetchColumn() === RV_HTTP_SCHEMA,
    'V1 fixture identity mismatch.');

$admin = null;
$rating = null;
$server = null;
$pipes = [];
$addedColumn = false;
$passwordChanged = false;
$testPassed = false;
$cleanupErrors = [];
$agent = 'HealthBridgeRatingVisibilityHTTPTest/' . bin2hex(random_bytes(8));
$base = 'http://127.0.0.1:18765';

try {
    $columnExists = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ratings' AND COLUMN_NAME='is_hidden'"
    )->fetchColumn();
    hExpect($columnExists === 0, 'V1 fixture already contains is_hidden; refusing to mutate it.');

    $admin = $db->query(
        "SELECT id,email,password,session_version
         FROM users WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    hExpect(is_array($admin), 'Active fixture admin not found.');

    $rating = $db->query(
        'SELECT id,user_id AS patient_user_id,doctor_id AS doctor_user_id
         FROM ratings ORDER BY id LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    hExpect(is_array($rating), 'Fixture rating not found.');

    $auditAutoIncrement = (int)$db->query(
        "SELECT AUTO_INCREMENT FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='admin_audit'"
    )->fetchColumn();

    $db->exec('ALTER TABLE ratings ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER created_at');
    $addedColumn = true;

    $temporaryPassword = bin2hex(random_bytes(24));
    $temporaryHash = password_hash($temporaryPassword, PASSWORD_DEFAULT);
    hExpect(is_string($temporaryHash), 'Could not hash temporary admin password.');
    $setPassword = $db->prepare('UPDATE users SET password=? WHERE id=? AND role=\'admin\'');
    $setPassword->execute([$temporaryHash, (int)$admin['id']]);
    hExpect($setPassword->rowCount() === 1, 'Could not set temporary admin password.');
    $passwordChanged = true;

    $probe = @stream_socket_server('tcp://127.0.0.1:18765', $errno, $error, STREAM_SERVER_BIND);
    hExpect(is_resource($probe), 'Local HTTP test port 18765 is already in use.');
    fclose($probe);

    $serverEnvironment = getenv();
    if (!is_array($serverEnvironment)) $serverEnvironment = [];
    $serverEnvironment['DB_HOST'] = '127.0.0.1';
    $serverEnvironment['DB_PORT'] = $port;
    $serverEnvironment['DB_USER'] = $dbUser;
    $serverEnvironment['DB_PASS'] = $dbPass;
    $serverEnvironment['DB_NAME'] = RV_HTTP_SCHEMA;

    $server = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:18765', '-t', RV_HTTP_ROOT],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        RV_HTTP_ROOT,
        $serverEnvironment,
        ['bypass_shell' => true]
    );
    hExpect(is_resource($server), 'Could not start loopback PHP server.');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $ready = false;
    for ($i = 0; $i < 40; $i++) {
        $socket = @fsockopen('127.0.0.1', 18765, $socketError, $socketMessage, 0.15);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    hExpect($ready, 'Loopback PHP server did not become ready.');

    $origin = $base;
    $login = hRequest(
        $base . '/optimized/api/auth/login.php',
        'POST',
        ['email' => (string)$admin['email'], 'password' => $temporaryPassword],
        null,
        $origin,
        $agent
    );
    hExpect($login['status'] === 200 && ($login['body']['success'] ?? false) === true,
        'Admin login failed: HTTP ' . $login['status'] . ' ' . json_encode($login['body']));
    hExpect((int)($login['body']['user']['id'] ?? 0) === (int)$admin['id']
        && ($login['body']['user']['role'] ?? '') === 'admin', 'Login identity mismatch.');

    $cookie = null;
    foreach ($login['headers'] as $header) {
        if (preg_match('/^Set-Cookie:\s*(PHPSESSID=[^;]+)/i', $header, $match)) {
            $cookie = $match[1];
            break;
        }
    }
    hExpect(is_string($cookie), 'Login did not issue PHP session cookie.');

    $current = hRequest($base . '/optimized/api/auth/current_user.php', 'GET', null, $cookie, $origin, $agent);
    hExpect($current['status'] === 200
        && (int)($current['body']['user']['id'] ?? 0) === (int)$admin['id'],
        'Authenticated current-user session request failed.');

    $unauthenticated = hRequest(
        $base . '/optimized/api/doctors/toggle-rating-visibility.php',
        'POST',
        ['id' => (int)$rating['id'], 'is_hidden' => 1],
        null,
        $origin,
        $agent
    );
    hExpect($unauthenticated['status'] === 401, 'Unauthenticated toggle was not rejected.');

    $crossOrigin = hRequest(
        $base . '/optimized/api/doctors/toggle-rating-visibility.php',
        'POST',
        ['id' => (int)$rating['id'], 'is_hidden' => 1],
        $cookie,
        'http://attacker.invalid',
        $agent
    );
    hExpect($crossOrigin['status'] === 403, 'Cross-origin toggle was not rejected.');

    $wrongMethod = hRequest(
        $base . '/optimized/api/doctors/toggle-rating-visibility.php',
        'GET',
        null,
        $cookie,
        $origin,
        $agent
    );
    hExpect($wrongMethod['status'] === 405, 'Invalid HTTP method was not rejected.');

    $invalidValue = hRequest(
        $base . '/optimized/api/doctors/toggle-rating-visibility.php',
        'POST',
        ['id' => (int)$rating['id'], 'is_hidden' => 2],
        $cookie,
        $origin,
        $agent
    );
    hExpect($invalidValue['status'] === 400, 'Invalid visibility value was not rejected.');

    $missingRating = hRequest(
        $base . '/optimized/api/doctors/toggle-rating-visibility.php',
        'POST',
        ['id' => 4000000000, 'is_hidden' => 1],
        $cookie,
        $origin,
        $agent
    );
    hExpect($missingRating['status'] === 404, 'Missing rating did not return 404.');

    $doctorUserId = (int)$rating['doctor_user_id'];
    $ratingId = (int)$rating['id'];
    $beforeDirectory = hDoctorSummary(
        hRequest($base . '/optimized/api/doctors/get.php', 'GET', null, null, $origin, $agent),
        $doctorUserId
    );
    $beforeSql = $db->prepare('SELECT COUNT(*) FROM ratings WHERE doctor_id=? AND is_hidden=0');
    $beforeSql->execute([$doctorUserId]);
    hExpect($beforeDirectory['rating_count'] === (int)$beforeSql->fetchColumn(),
        'Public directory baseline does not match the fixture ratings.');

    $hide = hRequest(
        $base . '/optimized/api/doctors/toggle-rating-visibility.php',
        'POST',
        ['id' => $ratingId, 'is_hidden' => 1],
        $cookie,
        $origin,
        $agent
    );
    hExpect($hide['status'] === 200 && ($hide['body']['success'] ?? false) === true
        && (int)($hide['body']['is_hidden'] ?? -1) === 1 && ($hide['body']['changed'] ?? false) === true,
        'Authenticated admin hide failed: ' . json_encode($hide['body']));

    $doctorReviews = hRequest(
        $base . '/optimized/api/doctors/get-doctor-ratings.php?doctor_id=' . $doctorUserId,
        'GET',
        null,
        $cookie,
        $origin,
        $agent
    );
    hExpect($doctorReviews['status'] === 200 && ($doctorReviews['body']['success'] ?? false) === true,
        'Doctor-private review read failed.');
    $privateRow = hFindRating($doctorReviews['body']['ratings'] ?? [], $ratingId);
    hExpect($privateRow !== null && (int)($privateRow['is_hidden'] ?? -1) === 1,
        'Doctor-private list did not retain the hidden review.');

    $adminReviews = hRequest(
        $base . '/optimized/api/doctors/get-admin-ratings.php',
        'GET',
        null,
        $cookie,
        $origin,
        $agent
    );
    hExpect($adminReviews['status'] === 200 && ($adminReviews['body']['success'] ?? false) === true,
        'Admin rating list read failed.');
    $adminRow = hFindRating($adminReviews['body']['ratings'] ?? [], $ratingId);
    hExpect($adminRow !== null && (int)($adminRow['is_hidden'] ?? -1) === 1,
        'Admin list did not retain the hidden review.');

    $hiddenDirectory = hDoctorSummary(
        hRequest($base . '/optimized/api/doctors/get.php', 'GET', null, null, $origin, $agent),
        $doctorUserId
    );
    $directHidden = $db->prepare('SELECT COUNT(*) FROM ratings WHERE doctor_id=? AND is_hidden=0');
    $directHidden->execute([$doctorUserId]);
    $directHiddenCount = (int)$directHidden->fetchColumn();
    echo 'PUBLIC_DIRECTORY_COUNTS|before=' . $beforeDirectory['rating_count']
        . '|after=' . $hiddenDirectory['rating_count']
        . '|direct_after=' . $directHiddenCount . "\n";
    hExpect($hiddenDirectory['rating_count'] === max(0, $beforeDirectory['rating_count'] - 1),
        'Hidden review still contributes to public directory count.');
    hExpect($hiddenDirectory['rating_count'] === $directHiddenCount,
        'Public directory count does not match direct public-only SQL.');

    $repeatHide = hRequest(
        $base . '/optimized/api/doctors/toggle-rating-visibility.php',
        'POST',
        ['id' => $ratingId, 'is_hidden' => 1],
        $cookie,
        $origin,
        $agent
    );
    hExpect($repeatHide['status'] === 200 && ($repeatHide['body']['changed'] ?? true) === false,
        'Repeated hide was not idempotent.');

    $restore = hRequest(
        $base . '/optimized/api/doctors/toggle-rating-visibility.php',
        'POST',
        ['id' => $ratingId, 'is_hidden' => 0],
        $cookie,
        $origin,
        $agent
    );
    hExpect($restore['status'] === 200 && ($restore['body']['success'] ?? false) === true
        && (int)($restore['body']['is_hidden'] ?? -1) === 0 && ($restore['body']['changed'] ?? false) === true,
        'Admin restore failed.');

    $afterDirectory = hDoctorSummary(
        hRequest($base . '/optimized/api/doctors/get.php', 'GET', null, null, $origin, $agent),
        $doctorUserId
    );
    hExpect($afterDirectory['rating_count'] === $beforeDirectory['rating_count']
        && $afterDirectory['rating'] === $beforeDirectory['rating'],
        'Restore did not reinstate public directory aggregate.');

    $audit = $db->prepare(
        "SELECT COUNT(*) FROM admin_audit
         WHERE actor_id=? AND entity_type='rating' AND entity_id=? AND user_agent=?
           AND action IN ('hide','restore')"
    );
    $audit->execute([(int)$admin['id'], (string)$ratingId, $agent]);
    hExpect((int)$audit->fetchColumn() === 2, 'Expected exactly two HTTP audit rows.');

    echo "HTTP_ADMIN_LOGIN_AND_SESSION_OK\n";
    echo "HTTP_UNAUTHENTICATED_CROSS_ORIGIN_METHOD_AND_INPUT_GUARDS_OK\n";
    echo "HTTP_HIDE_PRIVATE_ADMIN_READS_PUBLIC_AGGREGATE_IDEMPOTENCY_RESTORE_OK\n";
    echo "HTTP_AUDIT_CORRELATION_OK\n";
    $testPassed = true;
} finally {
    if (is_resource($server)) {
        @proc_terminate($server);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) @fclose($pipe);
        }
        @proc_close($server);
    }

    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() === RV_HTTP_SCHEMA) {
        // Restore credentials first so a failed later cleanup never leaves them changed.
        if (is_array($admin) && $passwordChanged) {
            try {
                $restoreAdmin = $db->prepare(
                    'UPDATE users SET password=?,session_version=? WHERE id=? AND role=\'admin\''
                );
                $restoreAdmin->execute([
                    (string)$admin['password'],
                    (int)$admin['session_version'],
                    (int)$admin['id'],
                ]);
            } catch (Throwable $error) {
                $cleanupErrors[] = 'admin restore: ' . $error->getMessage();
            }
        }

        if ($addedColumn) {
            try {
                if (is_array($rating)) {
                    $restoreRating = $db->prepare('UPDATE ratings SET is_hidden=0 WHERE id=?');
                    $restoreRating->execute([(int)$rating['id']]);
                }
                $deleteAudit = $db->prepare('DELETE FROM admin_audit WHERE user_agent=?');
                $deleteAudit->execute([$agent]);
                $db->exec('ALTER TABLE ratings DROP COLUMN is_hidden');
                if (isset($auditAutoIncrement) && $auditAutoIncrement > 0) {
                    $maxId = (int)$db->query('SELECT COALESCE(MAX(id),0) FROM admin_audit')->fetchColumn();
                    if ($maxId < $auditAutoIncrement) {
                        $db->exec('ALTER TABLE admin_audit AUTO_INCREMENT=' . (int)$auditAutoIncrement);
                    }
                }
            } catch (Throwable $error) {
                $cleanupErrors[] = 'fixture schema/data restore: ' . $error->getMessage();
            }
        }
    } else {
        $cleanupErrors[] = 'Database identity changed before cleanup.';
    }
}

if ($cleanupErrors !== []) {
    foreach ($cleanupErrors as $error) fwrite(STDERR, "CLEANUP_FAILED: {$error}\n");
    exit(3);
}
hExpect((int)$db->query(
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ratings' AND COLUMN_NAME='is_hidden'"
)->fetchColumn() === 0, 'Temporary V1 visibility column remains.');
echo "HTTP_TEST_FIXTURE_CLEANUP_OK|schema_and_credentials_restored\n";

if (!$testPassed) exit(2);
echo "RESULT=PASS (authenticated V1 HTTP contract; disposable fixture restored)\n";
