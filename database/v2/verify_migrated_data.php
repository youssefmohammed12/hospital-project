<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}
require_once __DIR__ . '/../../includes/db.php';

const SOURCE_SCHEMA = 'healthbridge_hb_auth_email_verify_20261114';
const TARGET_SCHEMA = 'healthbridge_v2_prototype_20261118';

function qi(string $name): string {
    $tick = chr(96);
    return $tick . str_replace($tick, $tick . $tick, $name) . $tick;
}
function rowCountFor(PDO $db, string $table): int {
    return (int)$db->query('SELECT COUNT(*) FROM ' . qi($table))->fetchColumn();
}
function mismatch(PDO $db, string $sql): int {
    return (int)$db->query($sql)->fetchColumn();
}

try {
    $db = getDB();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== SOURCE_SCHEMA) {
        throw new RuntimeException('Wrong source database; refusing verification.');
    }
    $db->exec('USE ' . TARGET_SCHEMA);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== TARGET_SCHEMA) {
        throw new RuntimeException('Could not select target schema.');
    }

    $expected = [
      'schema_migrations'=>7,'users'=>166,'departments'=>9,'egypt_governorates'=>27,'egypt_cities'=>200,
      'patient_profiles'=>133,'patient_medical_profiles'=>133,'doctor_profiles'=>32,
      'user_preferences'=>166,'admin_preferences'=>1,'doctor_schedule_settings'=>32,
      'doctor_weekly_schedules'=>224,'appointments'=>486,'appointment_reschedule_requests'=>12,
      'appointment_events'=>0,'clinical_encounters'=>451,'encounter_drafts'=>0,'medical_history_events'=>0,
      'prescriptions'=>99,'prescription_items'=>186,'ratings'=>79,'notifications'=>558,
      'notification_outbox'=>13,'contact_messages'=>0,'audit_events'=>0,'legacy_admin_audit'=>13,
      'legacy_medical_record_audit'=>0,'legacy_patient_audit_log'=>0,'legacy_schedule_audit_log'=>0,
      'legacy_doctor_profile_state'=>32,'legacy_user_preferences'=>166,'password_reset_tokens'=>0,
      'registration_intake_queue'=>20,'registration_email_challenges'=>11,'hospital_settings'=>1,
      'migration_row_map'=>3466,'migration_exceptions'=>100
    ];
    foreach ($expected as $table=>$n) {
        $actual=rowCountFor($db,$table);
        if($actual!==$n) throw new RuntimeException("Row count mismatch {$table}: {$actual}, expected {$n}");
        echo "ROWS|{$table}|{$actual}\n";
    }

    $views=(int)$db->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()")->fetchColumn();
    $fkCount=(int)$db->query("SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()")->fetchColumn();
    if($views!==1 || $fkCount!==42) throw new RuntimeException("Unexpected catalog totals: views={$views}, fks={$fkCount}");

    // Dynamically evaluate every declared v2 foreign key for orphaned child keys.
    $fkGroups=$db->query(
        "SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME
         FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
         GROUP BY TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME ORDER BY TABLE_NAME,CONSTRAINT_NAME"
    )->fetchAll(PDO::FETCH_ASSOC);
    $fkFailures=[];
    foreach($fkGroups as $g) {
        $q=$db->prepare(
            "SELECT COLUMN_NAME,REFERENCED_COLUMN_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?
               AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY ORDINAL_POSITION"
        );
        $q->execute([$g['TABLE_NAME'],$g['CONSTRAINT_NAME']]);
        $parts=$q->fetchAll(PDO::FETCH_ASSOC);
        $on=[]; $nn=[];
        foreach($parts as $p) {
            $on[]='c.'.qi($p['COLUMN_NAME']).' = p.'.qi($p['REFERENCED_COLUMN_NAME']);
            $nn[]='c.'.qi($p['COLUMN_NAME']).' IS NOT NULL';
        }
        $probe=qi($parts[0]['REFERENCED_COLUMN_NAME']);
        $sql='SELECT COUNT(*) FROM '.qi($g['TABLE_NAME']).' c LEFT JOIN '.qi($g['REFERENCED_TABLE_NAME'])
            .' p ON '.implode(' AND ',$on).' WHERE '.implode(' AND ',$nn).' AND p.'.$probe.' IS NULL';
        $n=(int)$db->query($sql)->fetchColumn();
        if($n) $fkFailures[]=$g['CONSTRAINT_NAME'].'='.$n;
    }
    if($fkFailures) throw new RuntimeException('FK orphan rows: '.implode(',',$fkFailures));
    echo "FOREIGN_KEYS|{$fkCount}|orphan_rows=0\n";

    $sourceRatingVisibility = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $sourceRatingVisibility->execute([SOURCE_SCHEMA, 'ratings', 'is_hidden']);
    $ratingVisibilityWhere = (int)$sourceRatingVisibility->fetchColumn() === 1
        ? ' WHERE is_hidden=0'
        : '';

    $checks = [
      'users_identity_and_auth' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".users s JOIN users t ON t.id=s.id
         WHERE NOT (t.name <=> s.name) OR NOT (t.first_name <=> s.first_name)
           OR NOT (t.last_name <=> s.last_name) OR NOT (t.email <=> s.email)
           OR NOT (t.password_hash <=> s.password) OR NOT (t.role <=> s.role)
           OR NOT (t.patient_number <=> s.patient_number) OR NOT (t.phone <=> s.phone)
           OR NOT (t.national_id <=> s.national_id) OR t.is_active<>s.is_active
           OR t.session_version<>s.session_version
           OR t.email_verification_required<>s.email_verification_required
           OR NOT (t.email_verified_at <=> s.email_verified_at)
           OR t.created_at<>s.created_at OR NOT (t.updated_at <=> s.updated_at)",
      'users_phone_normalization' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".users s
         JOIN users t ON t.id=s.id
         WHERE NOT (t.phone_normalized <=> s.phone_normalized)",
      'doctor_profile_identity_and_fields' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".doctors s
         JOIN doctor_profiles t ON t.doctor_user_id=s.user_id
         LEFT JOIN " . SOURCE_SCHEMA . ".user_preferences p ON p.user_id=s.user_id
         WHERE t.specialty<>s.specialty OR NOT (t.department_id <=> s.department_id)
           OR t.experience_years<>GREATEST(s.exp,0) OR t.profile_icon<>s.emoji
           OR t.accepts_new_patients<>COALESCE(p.accept_new_patients,1)
           OR t.profile_visible<>COALESCE(p.profile_visible,1)",
      'doctor_legacy_archive_exact' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".doctors s
         JOIN legacy_doctor_profile_state t ON t.source_doctor_id=s.id
         WHERE t.doctor_user_id<>s.user_id OR t.legacy_name<>s.name
           OR t.legacy_specialty<>s.specialty OR NOT (t.legacy_department_id <=> s.department_id)
           OR t.legacy_rating<>s.rating OR t.legacy_experience<>s.exp
           OR t.legacy_available<>s.available OR t.legacy_emoji<>s.emoji",
      'user_preferences_common_fields' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".user_preferences s
         JOIN user_preferences t ON t.user_id=s.user_id
         WHERE t.theme<>s.theme OR t.notif_appointment<>s.notif_appointment
           OR t.notif_ratings<>s.notif_ratings OR t.notif_messages<>s.notif_messages
           OR t.notif_announcements<>s.notif_announcements OR t.notif_email<>s.notif_email",
      'user_preferences_legacy_archive_exact' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".user_preferences s
         JOIN legacy_user_preferences t ON t.source_preference_id=s.id
         WHERE t.user_id<>s.user_id OR t.theme<>s.theme
           OR t.notif_appointment<>s.notif_appointment OR t.notif_ratings<>s.notif_ratings
           OR t.notif_messages<>s.notif_messages OR t.notif_announcements<>s.notif_announcements
           OR t.notif_email<>s.notif_email
           OR NOT (t.accept_new_patients <=> s.accept_new_patients)
           OR NOT (t.consultation_duration <=> s.consultation_duration)
           OR NOT (t.working_hours_start <=> s.working_hours_start)
           OR NOT (t.working_hours_end <=> s.working_hours_end)
           OR NOT (t.profile_visible <=> s.profile_visible)
           OR NOT (t.admin_default_tab <=> s.admin_default_tab)
           OR t.created_at<>s.created_at OR NOT (t.updated_at <=> s.updated_at)",
      'patient_profile_split_demographics' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".medical_records s
         JOIN patient_profiles t ON t.patient_user_id=s.patient_id
         LEFT JOIN " . SOURCE_SCHEMA . ".egypt_governorates g ON g.name=s.governorate
         LEFT JOIN " . SOURCE_SCHEMA . ".egypt_cities c ON c.governorate_id=g.id AND c.name=s.city
         WHERE NOT (t.date_of_birth <=> s.date_of_birth) OR NOT (t.gender <=> s.gender)
           OR NOT (t.governorate_id <=> g.id) OR NOT (t.city_id <=> c.id)
           OR NOT (t.governorate_text <=> s.governorate) OR NOT (t.city_text <=> s.city)
           OR NOT (t.address <=> s.address) OR NOT (t.insurance_provider <=> s.insurance_provider)
           OR NOT (t.insurance_number <=> s.insurance_number)
           OR NOT (t.emergency_name <=> s.emergency_contact_name)
           OR NOT (t.emergency_relation <=> s.emergency_contact_rel)
           OR NOT (t.emergency_phone <=> s.emergency_contact_phone)",
      'patient_medical_profile_split_facts' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".medical_records s
         JOIN patient_medical_profiles t ON t.patient_user_id=s.patient_id
         WHERE NOT (t.blood_type <=> s.blood_type) OR NOT (t.height_cm <=> s.height_cm)
           OR NOT (t.weight_kg <=> s.weight_kg) OR NOT (t.allergies <=> s.allergies)
           OR NOT (t.chronic_diseases <=> s.chronic_diseases)
           OR NOT (t.current_medications <=> s.current_medications)
           OR NOT (t.previous_surgeries <=> s.previous_surgeries)
           OR NOT (t.family_history <=> s.family_history) OR NOT (t.medical_notes <=> s.medical_notes)",
      'appointments_snapshots_and_timestamps' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".appointments s JOIN appointments t ON t.id=s.id
         WHERE t.patient_user_id<>s.user_id OR t.doctor_user_id<>s.doctor_id
           OR NOT (t.department_id <=> s.department_id)
           OR t.patient_name_snapshot<>s.patient_name OR t.doctor_name_snapshot<>s.doctor
           OR t.department_name_snapshot<>s.department
           OR NOT (t.source_time_snapshot <=> s.time)
           OR NOT (t.source_time_range_snapshot <=> s.appointment_time_range)
           OR NOT (t.legacy_status_snapshot <=> s.status) OR NOT (t.notes <=> s.notes)
           OR t.created_at<>s.created_at OR NOT (t.updated_at <=> s.updated_at)
           OR t.starts_at<>CASE WHEN s.time REGEXP '^[0-9]{1,2}:[0-9]{2} ?[AP]M$'
                THEN STR_TO_DATE(CONCAT(s.date,' ',UPPER(REPLACE(TRIM(s.time),' ',''))),'%Y-%m-%d%h:%i%p')
                ELSE STR_TO_DATE(CONCAT(s.date,' ',s.time),'%Y-%m-%d%H:%i') END
           OR t.duration_minutes<>COALESCE(
                (SELECT x.appointment_duration FROM " . SOURCE_SCHEMA . ".doctor_schedule_settings x
                 WHERE x.doctor_id=s.doctor_id),30)",
      'appointment_booking_state_mapping' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".appointments s JOIN appointments t ON t.id=s.id
         WHERE t.booking_status<>CASE
              WHEN s.status='Pending' THEN 'pending'
              WHEN s.status='Confirmed' THEN 'confirmed'
              WHEN s.status='Cancelled' THEN 'cancelled'
              WHEN s.status='Reschedule Requested' AND s.reschedule_status='pending' THEN 'confirmed'
              ELSE 'invalid' END",
      'reschedule_child_records' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".appointments s
         JOIN appointment_reschedule_requests t ON t.appointment_id=s.id
         WHERE s.reschedule_status='none' OR t.requested_by_user_id<>s.reschedule_requested_by
           OR t.original_starts_at<>STR_TO_DATE(CONCAT(s.date,' ',s.time),'%Y-%m-%d %H:%i')
           OR t.requested_starts_at<>STR_TO_DATE(CONCAT(s.requested_reschedule_date,' ',s.requested_reschedule_time),'%Y-%m-%d %H:%i')
           OR NOT (t.reason <=> s.reschedule_reason)
           OR NOT (t.response_notes <=> s.reschedule_response_notes)",
      'clinical_encounter_workflow_and_notes' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".visit_workflow w
         JOIN " . SOURCE_SCHEMA . ".appointments a ON a.id=w.appointment_id
         JOIN clinical_encounters t ON t.appointment_id=a.id
         LEFT JOIN " . SOURCE_SCHEMA . ".visit_notes n ON n.appointment_id=a.id
         WHERE t.patient_user_id<>a.user_id OR t.doctor_user_id<>a.doctor_id
           OR t.workflow_status<>CASE w.status WHEN 'Waiting' THEN 'waiting'
                WHEN 'In Progress' THEN 'in_progress' WHEN 'Ready to Complete' THEN 'ready_to_complete'
                WHEN 'Completed' THEN 'completed' ELSE 'invalid' END
           OR NOT (t.diagnosis <=> n.diagnosis) OR NOT (t.symptoms <=> n.symptoms)
           OR NOT (t.treatment <=> n.treatment) OR NOT (t.doctor_notes <=> n.doctor_notes)
           OR NOT (t.follow_up_instructions <=> n.follow_up_instructions)
           OR NOT (t.started_at <=> w.started_at) OR NOT (t.completed_at <=> w.completed_at)",
      'prescription_headers' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".prescriptions s JOIN prescriptions t ON t.id=s.id
         WHERE t.patient_user_id<>s.patient_id OR t.doctor_user_id<>s.doctor_id
           OR t.appointment_id<>s.appointment_id OR NOT (t.notes <=> s.notes)
           OR t.status<>LOWER(s.status) OR NOT (t.cancellation_reason <=> s.cancellation_reason)
           OR t.created_at<>s.created_at OR NOT (t.updated_at <=> s.updated_at)",
      'prescription_line_items' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".prescription_items s JOIN prescription_items t ON t.id=s.id
         WHERE t.prescription_id<>s.prescription_id OR t.medication_name<>s.medication_name
           OR t.strength<>s.strength OR t.dosage<>s.dosage OR t.frequency<>s.frequency
           OR t.duration<>s.duration OR NOT (t.instructions <=> s.instructions)
           OR t.sort_order<>s.sort_order",
      'rating_facts' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".ratings s JOIN ratings t ON t.id=s.id
         WHERE t.appointment_id<>s.appointment_id OR t.patient_user_id<>s.user_id
           OR t.doctor_user_id<>s.doctor_id OR t.stars<>s.stars
           OR NOT (t.review <=> s.review) OR t.created_at<>s.created_at",
      'notification_facts' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".notifications s JOIN notifications t ON t.id=s.id
         WHERE t.user_id<>s.user_id OR t.type<>s.type OR t.title<>s.title OR t.message<>s.message
           OR NOT (t.ref_type <=> s.ref_type) OR NOT (t.ref_id <=> s.ref_id)
           OR t.is_read<>s.is_read OR t.created_at<>s.created_at",
      'notification_outbox_facts' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".notification_outbox s JOIN notification_outbox t ON t.id=s.id
         WHERE t.user_id<>s.user_id OR t.type<>s.type OR t.title<>s.title OR t.message<>s.message
           OR NOT (t.ref_type <=> s.ref_type) OR NOT (t.ref_id <=> s.ref_id)
           OR t.status<>s.status OR t.attempt_count<>s.attempt_count
           OR t.available_at<>s.available_at OR t.created_at<>s.created_at
           OR NOT (t.processed_at <=> s.processed_at) OR NOT (t.last_error_code <=> s.last_error_code)",
      'registration_queue_ciphertext' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".registration_intake_queue s
         JOIN registration_intake_queue t ON t.id=s.id
         WHERE t.request_id<>s.request_id OR t.payload_version<>s.payload_version
           OR t.key_version<>s.key_version OR NOT (t.nonce <=> s.nonce)
           OR NOT (t.auth_tag <=> s.auth_tag) OR NOT (t.ciphertext <=> s.ciphertext)
           OR t.status<>s.status OR t.attempt_count<>s.attempt_count
           OR t.available_at<>s.available_at OR NOT (t.lease_expires_at <=> s.lease_expires_at)
           OR NOT (t.worker_token <=> s.worker_token) OR t.created_at<>s.created_at
           OR NOT (t.started_at <=> s.started_at) OR NOT (t.processed_at <=> s.processed_at)
           OR NOT (t.last_error_code <=> s.last_error_code)
           OR NOT (t.email_lookup_hmac <=> s.email_lookup_hmac)
           OR NOT (t.verification_expires_at <=> s.verification_expires_at)
           OR NOT (t.email_verified_at <=> s.email_verified_at)",
      'registration_challenge_hashes' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".registration_email_challenges s
         JOIN registration_email_challenges t ON t.id=s.id
         WHERE t.queue_id<>s.queue_id OR t.token_hash<>s.token_hash
           OR t.created_at<>s.created_at OR t.expires_at<>s.expires_at
           OR NOT (t.consumed_at <=> s.consumed_at) OR NOT (t.revoked_at <=> s.revoked_at)
           OR t.send_attempt_count<>s.send_attempt_count
           OR NOT (t.last_send_attempt_at <=> s.last_send_attempt_at)
           OR NOT (t.last_sent_at <=> s.last_sent_at)
           OR NOT (t.last_send_failure_at <=> s.last_send_failure_at)",
      'department_catalog' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".departments s JOIN departments t ON t.id=s.id
         WHERE t.name<>s.name OR NOT (t.description <=> s.description) OR t.status<>s.status
           OR t.created_at<>s.created_at OR NOT (t.updated_at <=> s.updated_at)",
      'egypt_governorates_catalog' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".egypt_governorates s JOIN egypt_governorates t ON t.id=s.id
         WHERE t.name<>s.name OR t.sort_order<>s.sort_order",
      'egypt_cities_catalog' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".egypt_cities s JOIN egypt_cities t ON t.id=s.id
         WHERE t.governorate_id<>s.governorate_id OR t.name<>s.name",
      'doctor_schedule_settings' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".doctor_schedule_settings s
         JOIN doctor_schedule_settings t ON t.doctor_user_id=s.doctor_id
         WHERE t.appointment_duration_min<>s.appointment_duration
           OR t.max_appointments_per_day<>s.max_appointments_per_day
           OR NOT (t.break_start <=> CAST(s.break_start AS TIME))
           OR NOT (t.break_end <=> CAST(s.break_end AS TIME))
           OR t.is_available<>s.is_available OR t.created_at<>s.created_at
           OR NOT (t.updated_at <=> s.updated_at)",
      'doctor_weekly_schedules' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".doctor_schedule_weekly s
         JOIN doctor_weekly_schedules t ON t.doctor_user_id=s.doctor_id AND t.day_of_week=s.day_of_week
         WHERE t.start_time<>CAST(s.start_time AS TIME) OR t.end_time<>CAST(s.end_time AS TIME)
           OR t.is_working<>s.is_working OR t.created_at<>s.created_at
           OR NOT (t.updated_at <=> s.updated_at)",
      'hospital_settings' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".hospital_settings s JOIN hospital_settings t ON t.id=s.id
         WHERE NOT (t.hospital_name <=> s.hospital_name) OR NOT (t.hospital_phone <=> s.hospital_phone)
           OR NOT (t.hospital_email <=> s.hospital_email)
           OR NOT (t.hospital_address <=> s.hospital_address)
           OR NOT (t.hospital_description <=> s.hospital_description)
           OR t.appointment_open_time<>CAST(s.appointment_open_time AS TIME)
           OR t.appointment_close_time<>CAST(s.appointment_close_time AS TIME)
           OR t.default_appointment_duration<>s.default_appointment_duration
           OR NOT (t.updated_at <=> s.updated_at)",
      'admin_audit_legacy_archive' =>
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".admin_audit s JOIN legacy_admin_audit t ON t.id=s.id
         WHERE t.actor_id<>s.actor_id OR t.actor_role<>s.actor_role OR t.action<>s.action
           OR t.entity_type<>s.entity_type OR NOT (t.entity_id <=> s.entity_id)
           OR NOT (t.patient_id <=> s.patient_id) OR NOT (t.doctor_id <=> s.doctor_id)
           OR NOT (t.old_value <=> s.old_value) OR NOT (t.new_value <=> s.new_value)
           OR NOT (t.description <=> s.description) OR NOT (t.ip_address <=> s.ip_address)
           OR NOT (t.user_agent <=> s.user_agent) OR t.created_at<>s.created_at",
      'derived_doctor_rating_summary' =>
        "SELECT COUNT(*) FROM (
           SELECT d.user_id
           FROM " . SOURCE_SCHEMA . ".doctors d
           LEFT JOIN v_doctor_rating_summary v ON v.doctor_user_id=d.user_id
           LEFT JOIN (
             SELECT doctor_id,ROUND(AVG(stars),1) avg_rating,COUNT(*) rating_count
             FROM " . SOURCE_SCHEMA . ".ratings" . $ratingVisibilityWhere . " GROUP BY doctor_id
           ) r ON r.doctor_id=d.user_id
           WHERE NOT (v.average_rating <=> r.avg_rating)
              OR COALESCE(v.rating_count,0)<>COALESCE(r.rating_count,0)
         ) mismatches"
    ];

    foreach($checks as $label=>$sql) {
        $n=mismatch($db,$sql);
        echo "EQUIVALENCE|{$label}|{$n}\n";
        if($n!==0) throw new RuntimeException("Field-level difference in {$label}: {$n}");
    }

    $exceptionRows=$db->query(
        "SELECT exception_code,resolution_status,COUNT(*) AS n
         FROM migration_exceptions GROUP BY exception_code,resolution_status ORDER BY exception_code"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach($exceptionRows as $e) {
        echo "EXCEPTION|".$e['exception_code']."|".$e['resolution_status']."|".$e['n']."\n";
        if($e['resolution_status']!=='accepted_legacy') throw new RuntimeException('Unaccepted migration exception.');
    }
    $blocking=(int)$db->query(
        "SELECT COUNT(*) FROM migration_exceptions WHERE severity='blocking' AND resolution_status='open'"
    )->fetchColumn();
    if($blocking!==0) throw new RuntimeException('Blocking exceptions remain.');

    foreach(['notification_outbox','registration_intake_queue'] as $table) {
        $q=$db->prepare(
            'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $q->execute([$table]);
        if((int)$q->fetchColumn()!==0) throw new RuntimeException('Unexpected FK on no-user-FK queue '.$table);
    }

    // Run CHECK TABLE for every prototype base table.
    $tables=$db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME"
    )->fetchAll(PDO::FETCH_COLUMN);
    $checkFailures=[];
    foreach($tables as $table) {
        $rows=$db->query('CHECK TABLE '.qi((string)$table))->fetchAll(PDO::FETCH_ASSOC);
        foreach($rows as $row) {
            if(($row['Msg_type']??'')==='Error' ||
               (($row['Msg_type']??'')==='status' && ($row['Msg_text']??'')!=='OK')) {
                $checkFailures[]=$table.':'.($row['Msg_text']??'unknown');
            }
        }
    }
    if($checkFailures) throw new RuntimeException('CHECK TABLE failures: '.implode(',',$checkFailures));

    echo "V2_ETL_VERIFICATION_PASS\n";
    echo 'target='.TARGET_SCHEMA."\n";
    echo 'base_tables='.count($tables)."\n";
    echo 'views='.$views."\n";
    echo 'foreign_keys='.$fkCount."\n";
    echo "fk_orphan_rows=0\n";
    echo 'field_level_checks='.count($checks)."\n";
    echo 'check_table=' . count($tables) . '/' . count($tables) . "\n";
    echo 'lineage_rows='.rowCountFor($db,'migration_row_map')."\n";
    echo 'accepted_exceptions='.rowCountFor($db,'migration_exceptions')."\n";
    echo "blocking_exceptions=0\n";
} catch(Throwable $error) {
    fwrite(STDERR,'V2_ETL_VERIFICATION_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
