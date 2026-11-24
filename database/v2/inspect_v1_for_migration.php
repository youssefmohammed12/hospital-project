<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(10);
require_once __DIR__ . '/../../includes/db.php';
const SOURCE_SCHEMA = 'healthbridge_hb_auth_email_verify_20261114';
try {
    $db = getDB();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== SOURCE_SCHEMA) {
        throw new RuntimeException('Wrong disposable source schema.');
    }
    echo "SOURCE=" . SOURCE_SCHEMA . "\n";
    echo "SERVER=" . $db->query('SELECT VERSION()')->fetchColumn() . "\n";
    $tables = [
        'users','departments','doctors','appointments','visit_workflow','visit_drafts',
        'visit_notes','medical_records','medical_history_updates','prescriptions',
        'prescription_items','ratings','notifications','notification_outbox',
        'contact_messages','admin_audit','medical_record_audit','patient_audit_log',
        'schedule_audit_log','password_resets','doctor_schedule_settings',
        'doctor_schedule_weekly','user_preferences','hospital_settings',
        'egypt_governorates','egypt_cities','registration_intake_queue',
        'registration_email_challenges'
    ];
    foreach ($tables as $table) {
        $count=(int)$db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        echo "ROWS|{$table}|{$count}\n";
    }
    $queries = [
      'user_roles' => "SELECT role,COUNT(*) n FROM users GROUP BY role ORDER BY role",
      'appointments_status' => "SELECT status,COUNT(*) n FROM appointments GROUP BY status ORDER BY status",
      'reschedule_status' => "SELECT reschedule_status,status,COUNT(*) n FROM appointments GROUP BY reschedule_status,status ORDER BY reschedule_status,status",
      'appointment_time_shapes' => "SELECT CASE WHEN time REGEXP '^[0-2][0-9]:[0-5][0-9]$' THEN '24H_HH_MM' WHEN time REGEXP '^[0-9]{1,2}:[0-9]{2} ?[AP]M$' THEN '12H_AM_PM' ELSE 'OTHER' END shape,COUNT(*) n FROM appointments GROUP BY shape",
      'reschedule_missing_original' => "SELECT COUNT(*) n FROM appointments WHERE reschedule_status<>'none' AND (requested_reschedule_date IS NULL OR requested_reschedule_time IS NULL)",
      'reschedule_missing_pending' => "SELECT COUNT(*) n FROM appointments WHERE reschedule_status<>'none' AND (pending_reschedule_date IS NULL OR pending_reschedule_time IS NULL)",
      'patient_missing_appointment' => "SELECT COUNT(*) n FROM appointments WHERE user_id IS NULL",
      'appointments_missing_doctor_profile' => "SELECT COUNT(*) n FROM appointments a WHERE NOT EXISTS (SELECT 1 FROM doctors d WHERE d.user_id=a.doctor_id)",
      'doctors_missing_user_or_wrong_role' => "SELECT COUNT(*) n FROM doctors d LEFT JOIN users u ON u.id=d.user_id WHERE u.id IS NULL OR u.role<>'doctor'",
      'doctors_duplicate_link' => "SELECT COUNT(*) n FROM (SELECT user_id FROM doctors GROUP BY user_id HAVING COUNT(*)>1) x",
      'doctor_name_mismatch' => "SELECT COUNT(*) n FROM doctors d JOIN users u ON u.id=d.user_id WHERE d.name<>u.name",
      'doctor_rating_cache_mismatch' => "SELECT COUNT(*) n FROM (SELECT d.user_id FROM doctors d JOIN ratings r ON r.doctor_id=d.user_id GROUP BY d.user_id,d.rating HAVING ABS(d.rating-ROUND(AVG(r.stars),1))>0.05) x",
      'doctor_availability_mismatch' => "SELECT COUNT(*) n FROM doctors d JOIN doctor_schedule_settings s ON s.doctor_id=d.user_id WHERE d.available<>s.is_available",
      'weekly_schedule_duplicate_day' => "SELECT COUNT(*) n FROM (SELECT doctor_id,day_of_week FROM doctor_schedule_weekly GROUP BY doctor_id,day_of_week HAVING COUNT(*)>1) x",
      'weekly_schedule_invalid_time' => "SELECT COUNT(*) n FROM doctor_schedule_weekly WHERE start_time>=end_time",
      'workflow_without_appointment' => "SELECT COUNT(*) n FROM visit_workflow w LEFT JOIN appointments a ON a.id=w.appointment_id WHERE a.id IS NULL",
      'notes_without_appointment' => "SELECT COUNT(*) n FROM visit_notes n LEFT JOIN appointments a ON a.id=n.appointment_id WHERE a.id IS NULL",
      'workflow_note_owner_mismatch' => "SELECT COUNT(*) n FROM visit_workflow w JOIN visit_notes n ON n.appointment_id=w.appointment_id JOIN appointments a ON a.id=w.appointment_id WHERE n.patient_id<>a.user_id OR n.doctor_id<>a.doctor_id",
      'appointments_without_workflow' => "SELECT COUNT(*) n FROM appointments a LEFT JOIN visit_workflow w ON w.appointment_id=a.id WHERE w.id IS NULL",
      'workflow_without_note' => "SELECT COUNT(*) n FROM visit_workflow w LEFT JOIN visit_notes n ON n.appointment_id=w.appointment_id WHERE n.id IS NULL",
      'note_without_workflow' => "SELECT COUNT(*) n FROM visit_notes n LEFT JOIN visit_workflow w ON w.appointment_id=n.appointment_id WHERE w.id IS NULL",
      'prescription_owner_mismatch' => "SELECT COUNT(*) n FROM prescriptions p JOIN appointments a ON a.id=p.appointment_id WHERE p.patient_id<>a.user_id OR p.doctor_id<>a.doctor_id",
      'rating_owner_mismatch' => "SELECT COUNT(*) n FROM ratings r JOIN appointments a ON a.id=r.appointment_id WHERE r.user_id<>a.user_id OR r.doctor_id<>a.doctor_id",
      'medical_record_wrong_role' => "SELECT COUNT(*) n FROM medical_records m JOIN users u ON u.id=m.patient_id WHERE u.role<>'patient'",
      'medical_records_duplicate_patient' => "SELECT COUNT(*) n FROM (SELECT patient_id FROM medical_records GROUP BY patient_id HAVING COUNT(*)>1) x",
      'settings_rows' => "SELECT COUNT(*) n FROM hospital_settings",
      'city_duplicate_governorate_name' => "SELECT COUNT(*) n FROM (SELECT governorate_id,name FROM egypt_cities GROUP BY governorate_id,name HAVING COUNT(*)>1) x",
      'reset_plaintext_tokens' => "SELECT COUNT(*) n FROM password_resets WHERE token IS NOT NULL AND token<>''",
      'reset_missing_hash' => "SELECT COUNT(*) n FROM password_resets WHERE token_hash IS NULL OR token_hash=''",
      'queue_statuses' => "SELECT status,COUNT(*) n FROM registration_intake_queue GROUP BY status ORDER BY status",
      'challenge_without_queue' => "SELECT COUNT(*) n FROM registration_email_challenges c LEFT JOIN registration_intake_queue q ON q.id=c.queue_id WHERE q.id IS NULL",
      'patient_profile_unmatched_location' => "SELECT COUNT(*) n FROM medical_records m WHERE (m.governorate IS NOT NULL AND NOT EXISTS (SELECT 1 FROM egypt_governorates g WHERE g.name=m.governorate)) OR (m.city IS NOT NULL AND NOT EXISTS (SELECT 1 FROM egypt_cities c JOIN egypt_governorates g ON g.id=c.governorate_id WHERE c.name=m.city AND (m.governorate IS NULL OR g.name=m.governorate)))"
    ];
    foreach ($queries as $label=>$sql) {
        $rows=$db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $parts=[];
            foreach ($row as $key=>$value) $parts[]=$key.'='.$value;
            echo "CHECK|{$label}|".implode('|',$parts)."\n";
        }
    }
    echo "INSPECTION_PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'INSPECTION_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
