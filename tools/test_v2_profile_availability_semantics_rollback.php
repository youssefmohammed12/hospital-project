<?php
declare(strict_types=1);

/**
 * Transaction-only test for split doctor profile acceptance and schedule availability.
 * The five source doctors with schedule-off/profile-accept-on are tested separately
 * from a synthetic patient-acceptance toggle. Both fixture transactions roll back.
 */
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const HB_PROFILE_V1 = 'healthbridge_hb_auth_email_verify_20261114';
const HB_PROFILE_V2 = 'healthbridge_v2_prototype_20261118';

$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) { fwrite(STDERR, "Loopback host required.\n"); exit(11); }
if (!preg_match('/^[0-9]{1,5}$/', $port) || (int)$port < 1 || (int)$port > 65535) { fwrite(STDERR, "Invalid port.\n"); exit(12); }

require_once __DIR__ . '/../services/Repositories/DoctorProfileRepository.php';

function connectProfileFixture(string $database, string $host, string $port, string $user, string $password): PDO
{
    if (!in_array($database, [HB_PROFILE_V1, HB_PROFILE_V2], true)) {
        throw new RuntimeException('Database is not on exact fixture allowlist.');
    }
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('Connected schema name did not match allowlist.');
    }
    return $db;
}

$v1 = null;
$v2 = null;
try {
    $v1 = connectProfileFixture(HB_PROFILE_V1, $host, $port, $user, $password);
    $v2 = connectProfileFixture(HB_PROFILE_V2, $host, $port, $user, $password);

    $sourceDoctorId = (int)$v1->query(
        "SELECT d.user_id
         FROM doctors d
         JOIN doctor_schedule_settings s ON s.doctor_id=d.user_id
         LEFT JOIN user_preferences p ON p.user_id=d.user_id
         WHERE d.available=1 AND s.is_available=1
           AND COALESCE(p.accept_new_patients,1)=1
         ORDER BY d.user_id LIMIT 1"
    )->fetchColumn();
    if ($sourceDoctorId <= 0) { throw new RuntimeException('No eligible acceptance-on/schedule-on doctor found.'); }

    $v1Repo = new DoctorProfileRepository($v1, 'v1');
    $v2Repo = new DoctorProfileRepository($v2, 'v2');
    $beforeV1 = $v1Repo->findByUserId($sourceDoctorId);
    $beforeV2 = $v2Repo->findByUserId($sourceDoctorId);
    if (!$beforeV1 || !$beforeV2) { throw new RuntimeException('Selected doctor profile missing from one fixture.'); }

    $v1->beginTransaction();
    $v2->beginTransaction();
    try {
        // Simulate settings API's "Accepting New Patients" off transition.
        // Legacy writes both the preference and the old directory availability
        // field; v2 writes the explicit profile setting only.
        $v1->prepare('UPDATE user_preferences SET accept_new_patients=0 WHERE user_id=?')->execute([$sourceDoctorId]);
        $v1->prepare('UPDATE doctors SET available=0 WHERE user_id=?')->execute([$sourceDoctorId]);
        $v2->prepare('UPDATE doctor_profiles SET accepts_new_patients=0 WHERE doctor_user_id=?')->execute([$sourceDoctorId]);

        $offV1 = $v1Repo->findByUserId($sourceDoctorId);
        $offV2 = $v2Repo->findByUserId($sourceDoctorId);
        foreach ([$offV1, $offV2] as $profile) {
            if (
                !$profile
                || (int)$profile['accepts_new_patients'] !== 0
                || (int)$profile['available'] !== 1
                || (int)$profile['is_available'] !== 1
            ) {
                throw new RuntimeException('The separate acceptance preference must not overwrite the legacy schedule-availability DTO.');
            }
        }
        echo "PROFILE_ACCEPTANCE_OFF_OK|doctor={$sourceDoctorId}|schedule_dto_unchanged\n";
    } finally {
        if ($v1->inTransaction()) { $v1->rollBack(); }
        if ($v2->inTransaction()) { $v2->rollBack(); }
    }

    if ($beforeV1 !== $v1Repo->findByUserId($sourceDoctorId)
        || $beforeV2 !== $v2Repo->findByUserId($sourceDoctorId)) {
        throw new RuntimeException('Profile availability fixture data was not restored exactly after rollback.');
    }

    // Existing fixture proves the complementary state: 5 doctors have schedule
    // availability off while their profile preference remains on.
    $sourceOffCount = (int)$v1->query(
        "SELECT COUNT(*)
         FROM doctors d
         JOIN doctor_schedule_settings s ON s.doctor_id=d.user_id
         LEFT JOIN user_preferences p ON p.user_id=d.user_id
         WHERE COALESCE(p.accept_new_patients,1)=1 AND s.is_available=0 AND d.available=0"
    )->fetchColumn();
    $targetOffCount = (int)$v2->query(
        "SELECT COUNT(*)
         FROM doctor_profiles dp
         JOIN doctor_schedule_settings s ON s.doctor_user_id=dp.doctor_user_id
         WHERE dp.accepts_new_patients=1 AND s.is_available=0"
    )->fetchColumn();
    if ($sourceOffCount !== $targetOffCount || $sourceOffCount !== 5) {
        throw new RuntimeException('The schedule-off/profile-accept-on source/target state was not preserved.');
    }
    echo "SCHEDULE_AVAILABILITY_OFF_OK|doctors={$sourceOffCount}|profile_acceptance_remains_on\n";
    echo "RESULT=PASS (both profile transactions rolled back; read-only state count verified)\n";
    exit(0);
} catch (Throwable $e) {
    if ($v1 instanceof PDO && $v1->inTransaction()) { $v1->rollBack(); }
    if ($v2 instanceof PDO && $v2->inTransaction()) { $v2->rollBack(); }
    fwrite(STDERR, "PROFILE AVAILABILITY TEST FAILED/BLOCKED: " . get_class($e) . "\n");
    exit(2);
}
