<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(10); }
const DB_ALLOWLIST = ['healthbridge_hb_auth_email_verify_20261114','healthbridge_v2_prototype_20261118'];
$host = strtolower(trim((string)(getenv('DB_HOST') ?: '127.0.0.1'), '[]'));
$port = (string)(getenv('DB_PORT') ?: '3306');
$user = (string)(getenv('DB_USER') ?: 'root');
$pass = getenv('DB_PASS');
$pass = $pass === false ? '' : (string)$pass;
if (!in_array($host,['127.0.0.1','localhost','::1'],true)) { fwrite(STDERR,"Loopback required.\n"); exit(11); }
foreach (DB_ALLOWLIST as $database) {
    $db = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== $database) { throw new RuntimeException('Fixture name mismatch.'); }
    if (str_contains($database,'v2_')) {
        $sql = "SELECT u.id,u.is_active,dp.department_id,
                    (SELECT COUNT(*) FROM appointments a WHERE a.doctor_user_id=u.id AND a.booking_status IN ('pending','confirmed')) AS open_appointments,
                    (SELECT COUNT(*) FROM clinical_encounters ce WHERE ce.doctor_user_id=u.id AND ce.workflow_status IN ('in_progress','ready_to_complete')) AS active_visits,
                    (SELECT COUNT(*) FROM prescriptions p WHERE p.doctor_user_id=u.id AND p.status='active') AS active_prescriptions,
                    (SELECT COUNT(*) FROM encounter_drafts ed WHERE ed.doctor_user_id=u.id) AS drafts
                FROM users u JOIN doctor_profiles dp ON dp.doctor_user_id=u.id
                WHERE u.role='doctor' ORDER BY u.id";
    } else {
        $sql = "SELECT u.id,u.is_active,d.department_id,
                    (SELECT COUNT(*) FROM appointments a WHERE a.doctor_id=u.id AND a.status IN ('Pending','Confirmed','Reschedule Requested')) AS open_appointments,
                    (SELECT COUNT(*) FROM appointments a JOIN visit_workflow w ON w.appointment_id=a.id WHERE a.doctor_id=u.id AND a.status='Confirmed' AND w.status IN ('In Progress','Ready to Complete')) AS active_visits,
                    (SELECT COUNT(*) FROM prescriptions p WHERE p.doctor_id=u.id AND p.status='Active') AS active_prescriptions,
                    (SELECT COUNT(*) FROM visit_drafts vd WHERE vd.doctor_id=u.id) AS drafts
                FROM users u JOIN doctors d ON d.user_id=u.id
                WHERE u.role='doctor' ORDER BY u.id";
    }
    echo "DATABASE={$database}\n";
    foreach ($db->query($sql)->fetchAll() as $row) {
        if ((int)$row['active_visits']===0 && (int)$row['active_prescriptions']===0 && (int)$row['drafts']===0) {
            echo json_encode($row,JSON_UNESCAPED_SLASHES)."\n";
        }
    }
}
