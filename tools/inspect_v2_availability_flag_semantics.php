<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const ALLOWED = ['healthbridge_hb_auth_email_verify_20261114', 'healthbridge_v2_prototype_20261118'];
$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$password = getenv('DB_PASS');
$password = $password === false ? '' : (string)$password;
if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) { fwrite(STDERR, "Loopback host required.\n"); exit(11); }
foreach (ALLOWED as $database) {
    $db = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $user, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $database) { throw new RuntimeException('Schema mismatch.'); }
    echo "DATABASE={$database}\n";
    if ($database === ALLOWED[0]) {
        $sql = "SELECT d.available AS directory_available,
                       COALESCE(p.accept_new_patients,1) AS accepts_new_patients,
                       COALESCE(s.is_available,d.available) AS schedule_available,
                       COUNT(*) AS doctors
                FROM doctors d
                LEFT JOIN user_preferences p ON p.user_id=d.user_id
                LEFT JOIN doctor_schedule_settings s ON s.doctor_id=d.user_id
                GROUP BY d.available, COALESCE(p.accept_new_patients,1), COALESCE(s.is_available,d.available)
                ORDER BY 1,2,3";
    } else {
        $sql = "SELECT COALESCE(s.is_available, dp.accepts_new_patients) AS directory_available,
                       dp.accepts_new_patients AS accepts_new_patients,
                       s.is_available AS schedule_available,
                       COUNT(*) AS doctors
                FROM doctor_profiles dp
                LEFT JOIN doctor_schedule_settings s ON s.doctor_user_id=dp.doctor_user_id
                GROUP BY dp.accepts_new_patients,s.is_available
                ORDER BY 1,2,3";
    }
    foreach ($db->query($sql)->fetchAll() as $row) {
        echo json_encode($row, JSON_UNESCAPED_SLASHES) . "\n";
    }
}
echo "RESULT=READ_ONLY\n";
