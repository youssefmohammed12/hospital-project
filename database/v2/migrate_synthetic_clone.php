<?php
declare(strict_types=1);

/**
 * Data-preserving ETL rehearsal from one named synthetic v1 clone to the
 * separately named v2 prototype. Source is SELECT-only; all writes target v2.
 * One transaction covers all inserts, lineage, exceptions and ETL ledger entry.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/AppointmentTime.php';

$sourceSchema = (string)(getenv('HEALTHBRIDGE_ETL_SOURCE_SCHEMA') ?: '');
$targetSchema = (string)(getenv('HEALTHBRIDGE_ETL_TARGET_SCHEMA') ?: '');
if (!preg_match('/\Ahealthbridge_hb_etl_source_[a-z0-9_]+\z/D', $sourceSchema)
    || !preg_match('/\Ahealthbridge_v2_etl_target_[a-z0-9_]+\z/D', $targetSchema)
    || $sourceSchema === $targetSchema) {
    throw new RuntimeException('ETL requires uniquely named disposable source/target schemas under the approved ETL prefixes.');
}
if (!defined('DB_NAME') || DB_NAME !== $sourceSchema) {
    throw new RuntimeException('DB_NAME must point to the exact disposable ETL source clone.');
}
define('SOURCE_SCHEMA', $sourceSchema);
define('TARGET_SCHEMA', $targetSchema);
const ETL_VERSION = '2.1.2-phase8-audit-etl';

function sourceTableColumns(PDO $db, string $table): array
{
    if (!in_array($table, ['patient_audit_log', 'schedule_audit_log'], true)) {
        throw new InvalidArgumentException('Unapproved schema-inspection table.');
    }
    $q = $db->prepare(
        'SELECT COLUMN_NAME, DATA_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=?'
    );
    $q->execute([SOURCE_SCHEMA, $table]);
    $columns = [];
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $columns[strtolower((string)$row['COLUMN_NAME'])] = strtolower((string)$row['DATA_TYPE']);
    }
    if (!$columns) {
        throw new RuntimeException('Required source audit table is missing: ' . $table);
    }
    return $columns;
}

function sourceColumnExpr(array $columns, string $column, string $alias = 's'): string
{
    return isset($columns[$column]) ? $alias . '.' . $column : 'NULL';
}

function requireSourceColumns(array $columns, array $required, string $table): void
{
    $missing = array_values(array_filter($required, static fn(string $column): bool => !isset($columns[$column])));
    if ($missing) {
        throw new RuntimeException(
            'Unsupported ' . $table . ' audit schema; missing required columns: ' . implode(', ', $missing)
        );
    }
}

function sourceAuditTimestampType(array $columns, string $table): string
{
    $type = $columns['created_at'] ?? '';
    if (!in_array($type, ['timestamp', 'datetime'], true)) {
        throw new RuntimeException('Unsupported ' . $table . '.created_at type: ' . ($type ?: 'missing'));
    }
    return $type;
}

function copyRows(PDO $db, string $label, string $sql): int
{
    $count = $db->exec($sql);
    if ($count === false) throw new RuntimeException('Copy failed: ' . $label);
    echo "COPY|{$label}|{$count}\n";
    return $count;
}

function addMap(PDO $db, string $sourceTable, string $targetTable, string $selectSql): int
{
    $sql = "INSERT INTO migration_row_map
        (source_table,source_id,target_table,target_id,mapping_version)
        SELECT ?,CAST(m.source_id AS CHAR),?,CAST(m.target_id AS CHAR),?
        FROM ({$selectSql}) AS m";
    $q = $db->prepare($sql);
    $q->execute([$sourceTable,$targetTable,ETL_VERSION]);
    $count = $q->rowCount();
    echo "MAP|{$sourceTable}|{$targetTable}|{$count}\n";
    return $count;
}

function sourceCount(PDO $db, string $table): int
{
    $allowed = [
        'users','departments','doctors','appointments','visit_workflow','visit_drafts',
        'visit_notes','medical_records','medical_history_updates','prescriptions',
        'prescription_items','ratings','notifications','notification_outbox',
        'contact_messages','admin_audit','medical_record_audit','patient_audit_log',
        'schedule_audit_log','password_resets','doctor_schedule_settings',
        'doctor_schedule_weekly','user_preferences','hospital_settings',
        'egypt_governorates','egypt_cities','registration_intake_queue',
        'registration_email_challenges'
    ];
    if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Unapproved source table.');
    return (int)$db->query('SELECT COUNT(*) FROM ' . SOURCE_SCHEMA . '.' . $table)->fetchColumn();
}

try {
    $etlHost = strtolower(trim((string)DB_HOST, '[]'));
    if (!in_array($etlHost, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('Refusing non-loopback ETL database host.');
    }
    $db = getDB();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== SOURCE_SCHEMA) {
        throw new RuntimeException('Refusing to run: source connection is not the exact disposable clone.');
    }
    $db->exec("SET SESSION time_zone = '+00:00'");
    if ((string)$db->query('SELECT @@session.time_zone')->fetchColumn() !== '+00:00') {
        throw new RuntimeException('Could not establish UTC session semantics for legacy TIMESTAMP migration.');
    }
    $db->exec('USE ' . TARGET_SCHEMA);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== TARGET_SCHEMA) {
        throw new RuntimeException('Could not select the exact v2 target.');
    }

    $nonempty = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'
           AND TABLE_NAME NOT IN ('schema_migrations')
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($nonempty as $table) {
        if ((int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
            throw new RuntimeException('Target table is not empty: ' . $table);
        }
    }
    $migrations = (int)$db->query(
        "SELECT COUNT(*) FROM schema_migrations
         WHERE version IN ('2.0.0-prototype','2.0.1-prototype','2.0.2-prototype','2.0.5-prototype','2.0.6-rating-visibility','2.0.7-legacy-audit-archive-nullability')"
    )->fetchColumn();
    if ($migrations !== 6) throw new RuntimeException('Expected v2 schema versions 2.0.0-2.0.2, 2.0.5, 2.0.6 and 2.0.7 before ETL.');
    $visibilityColumn = $db->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ratings' AND COLUMN_NAME='is_public'"
    )->fetchColumn();
    if ((int)$visibilityColumn !== 1) throw new RuntimeException('Rating visibility migration must be applied before ETL.');
    $sourceRatingVisibility = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $sourceRatingVisibility->execute([SOURCE_SCHEMA, 'ratings', 'is_hidden']);
    $ratingVisibilityExpression = (int)$sourceRatingVisibility->fetchColumn() === 1
        ? 'CASE WHEN COALESCE(is_hidden,0)=1 THEN 0 ELSE 1 END'
        : '1';

    // Explicit source-state gates. The legacy status "Reschedule Requested" is
    // permitted only with the pending reschedule state created by the Confirmed-only service.
    $badStatus = (int)$db->query(
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".appointments
         WHERE status NOT IN ('Pending','Confirmed','Cancelled','Reschedule Requested')"
    )->fetchColumn();
    $badReschedule = (int)$db->query(
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".appointments
         WHERE reschedule_status NOT IN ('none','pending','suggested','approved','rejected')"
    )->fetchColumn();
    $badResetTokens = (int)$db->query(
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".password_resets
         WHERE (token IS NOT NULL AND token<>'') OR token_hash IS NULL OR token_hash=''"
    )->fetchColumn();
    $badActiveReschedule = (int)$db->query(
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".appointments
         WHERE reschedule_status<>'none'
           AND (reschedule_requested_by IS NULL OR reschedule_requested_at IS NULL
             OR requested_reschedule_date IS NULL OR requested_reschedule_time IS NULL)"
    )->fetchColumn();
    $badRoles = (int)$db->query(
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".doctors d
         JOIN " . SOURCE_SCHEMA . ".users u ON u.id=d.user_id WHERE u.role<>'doctor'"
    )->fetchColumn();
    $badProfileLink = (int)$db->query(
        "SELECT COUNT(*) FROM " . SOURCE_SCHEMA . ".doctors WHERE user_id IS NULL"
    )->fetchColumn();
    if ($badStatus || $badReschedule || $badResetTokens || $badActiveReschedule || $badRoles || $badProfileLink) {
        throw new RuntimeException(
            "Source mapping blocked: appointment_status={$badStatus}, reschedule_status={$badReschedule}, reset_token_rows={$badResetTokens}, incomplete_reschedules={$badActiveReschedule}, doctor_role_mismatch={$badRoles}, missing_doctor_user={$badProfileLink}"
        );
    }

    $patientAuditColumns = sourceTableColumns($db, 'patient_audit_log');
    $scheduleAuditColumns = sourceTableColumns($db, 'schedule_audit_log');
    requireSourceColumns(
        $patientAuditColumns,
        ['id','actor_id','actor_role','patient_id','action','old_value','new_value','description','ip_address','user_agent','created_at'],
        'patient_audit_log'
    );
    requireSourceColumns(
        $scheduleAuditColumns,
        ['id','actor_id','actor_role','doctor_id','action','old_value','new_value','description','ip_address','user_agent','created_at'],
        'schedule_audit_log'
    );
    $hasPatientFieldName = isset($patientAuditColumns['field_name']);
    $hasPatientEntityType = isset($patientAuditColumns['entity_type']);
    $hasPatientEntityId = isset($patientAuditColumns['entity_id']);
    if ($hasPatientEntityType !== $hasPatientEntityId || (!$hasPatientFieldName && !$hasPatientEntityType)) {
        throw new RuntimeException('Unsupported patient_audit_log variant: expected field_name and/or the entity_type/entity_id pair.');
    }
    $patientCreatedAtType = sourceAuditTimestampType($patientAuditColumns, 'patient_audit_log');
    $scheduleCreatedAtType = sourceAuditTimestampType($scheduleAuditColumns, 'schedule_audit_log');

    $patientFieldExpr = sourceColumnExpr($patientAuditColumns, 'field_name');
    $patientEntityTypeExpr = sourceColumnExpr($patientAuditColumns, 'entity_type');
    $patientEntityIdExpr = isset($patientAuditColumns['entity_id'])
        ? 'CAST(s.entity_id AS CHAR)'
        : 'NULL';
    $patientLegacyPayloadExpr = sourceColumnExpr($patientAuditColumns, 'legacy_payload');

    $schedulePayloadExpr = 'NULL';
    if (isset($scheduleAuditColumns['change_type'])) {
        $payloadPairs = [
            "'source_columns'",
            "JSON_ARRAY('change_type')",
            "'change_type'",
            's.change_type',
        ];
        if (isset($scheduleAuditColumns['legacy_payload'])) {
            $payloadPairs[] = "'source_legacy_payload'";
            $payloadPairs[] = 's.legacy_payload';
        }
        $schedulePayloadExpr = 'JSON_OBJECT(' . implode(', ', $payloadPairs) . ')';
    } elseif (isset($scheduleAuditColumns['legacy_payload'])) {
        $schedulePayloadExpr = 's.legacy_payload';
    }

    $scheduleDescriptionColumn = $db->query(
        "SELECT IS_NULLABLE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='legacy_schedule_audit_log'
           AND COLUMN_NAME='description'"
    )->fetchColumn();
    if ($scheduleDescriptionColumn !== 'YES') {
        throw new RuntimeException('V2 legacy_schedule_audit_log.description must be nullable to preserve Phase 8 source NULLs.');
    }

    $patientAuditShape = $hasPatientFieldName
        ? ($hasPatientEntityType ? 'field_name_plus_entity_identity' : 'field_name')
        : 'entity_identity';
    $scheduleAuditShape = isset($scheduleAuditColumns['change_type'])
        ? 'change_type_preserved_in_legacy_payload'
        : (isset($scheduleAuditColumns['legacy_payload']) ? 'legacy_payload' : 'common_columns_only');

    $expectedSourceRows = [];
    foreach ([
        'users','departments','doctors','appointments','visit_workflow','visit_drafts','visit_notes',
        'medical_records','medical_history_updates','prescriptions','prescription_items','ratings',
        'notifications','notification_outbox','contact_messages','admin_audit','medical_record_audit',
        'patient_audit_log','schedule_audit_log','password_resets','doctor_schedule_settings',
        'doctor_schedule_weekly','user_preferences','hospital_settings','egypt_governorates',
        'egypt_cities','registration_intake_queue','registration_email_challenges'
    ] as $table) {
        $expectedSourceRows[$table] = sourceCount($db,$table);
    }

    $db->beginTransaction();

    copyRows($db,'departments',
        'INSERT INTO departments (id,name,description,status,created_at,updated_at)
         SELECT id,name,description,status,created_at,updated_at FROM ' . SOURCE_SCHEMA . '.departments');
    copyRows($db,'egypt_governorates',
        'INSERT INTO egypt_governorates (id,name,sort_order)
         SELECT id,name,sort_order FROM ' . SOURCE_SCHEMA . '.egypt_governorates');
    copyRows($db,'egypt_cities',
        'INSERT INTO egypt_cities (id,governorate_id,name)
         SELECT id,governorate_id,name FROM ' . SOURCE_SCHEMA . '.egypt_cities');
    copyRows($db,'users',
        'INSERT INTO users
         (id,name,first_name,last_name,email,password_hash,role,patient_number,phone,national_id,
          is_active,session_version,email_verification_required,email_verified_at,created_at,updated_at)
         SELECT id,name,first_name,last_name,email,password,role,patient_number,phone,national_id,
                is_active,session_version,email_verification_required,email_verified_at,created_at,updated_at
         FROM ' . SOURCE_SCHEMA . '.users');

    copyRows($db,'patient_profiles',
        'INSERT INTO patient_profiles
         (patient_user_id,date_of_birth,gender,governorate_id,city_id,governorate_text,city_text,address,
          insurance_provider,insurance_number,emergency_name,emergency_relation,emergency_phone,created_at,updated_at)
         SELECT m.patient_id,m.date_of_birth,m.gender,g.id,c.id,m.governorate,m.city,m.address,
                m.insurance_provider,m.insurance_number,m.emergency_contact_name,m.emergency_contact_rel,
                m.emergency_contact_phone,m.created_at,m.updated_at
         FROM ' . SOURCE_SCHEMA . '.medical_records m
         LEFT JOIN ' . SOURCE_SCHEMA . '.egypt_governorates g ON g.name=m.governorate
         LEFT JOIN ' . SOURCE_SCHEMA . '.egypt_cities c ON c.governorate_id=g.id AND c.name=m.city');
    copyRows($db,'patient_medical_profiles',
        'INSERT INTO patient_medical_profiles
         (patient_user_id,blood_type,height_cm,weight_kg,allergies,chronic_diseases,current_medications,
          previous_surgeries,family_history,medical_notes,created_at,updated_at)
         SELECT patient_id,blood_type,height_cm,weight_kg,allergies,chronic_diseases,current_medications,
                previous_surgeries,family_history,medical_notes,created_at,updated_at
         FROM ' . SOURCE_SCHEMA . '.medical_records');

    copyRows($db,'doctor_profiles',
        'INSERT INTO doctor_profiles
         (doctor_user_id,department_id,specialty,experience_years,profile_icon,accepts_new_patients,profile_visible,created_at,updated_at)
         SELECT d.user_id,d.department_id,d.specialty,GREATEST(d.exp,0),d.emoji,
                COALESCE(p.accept_new_patients,1),COALESCE(p.profile_visible,1),u.created_at,u.updated_at
         FROM ' . SOURCE_SCHEMA . '.doctors d
         JOIN ' . SOURCE_SCHEMA . '.users u ON u.id=d.user_id AND u.role="doctor"
         LEFT JOIN ' . SOURCE_SCHEMA . '.user_preferences p ON p.user_id=d.user_id');

    // Preserve every legacy doctor-profile field, including stale aggregates and the duplicate identity.
    copyRows($db,'legacy_doctor_profile_state',
        'INSERT INTO legacy_doctor_profile_state
         (source_doctor_id,doctor_user_id,legacy_name,legacy_specialty,legacy_department_id,
          legacy_rating,legacy_experience,legacy_available,legacy_emoji)
         SELECT id,user_id,name,specialty,department_id,rating,exp,available,emoji
         FROM ' . SOURCE_SCHEMA . '.doctors');

    copyRows($db,'user_preferences',
        'INSERT INTO user_preferences
         (user_id,theme,notif_appointment,notif_ratings,notif_messages,notif_announcements,notif_email,created_at,updated_at)
         SELECT user_id,theme,notif_appointment,notif_ratings,notif_messages,notif_announcements,notif_email,created_at,updated_at
         FROM ' . SOURCE_SCHEMA . '.user_preferences');
    copyRows($db,'admin_preferences',
        'INSERT INTO admin_preferences (admin_user_id,default_tab,updated_at)
         SELECT p.user_id,COALESCE(p.admin_default_tab,"overview"),p.updated_at
         FROM ' . SOURCE_SCHEMA . '.user_preferences p
         JOIN ' . SOURCE_SCHEMA . '.users u ON u.id=p.user_id WHERE u.role="admin"');

    // Preserve role-specific and now-deprecated preference fields verbatim for compatibility review.
    copyRows($db,'legacy_user_preferences',
        'INSERT INTO legacy_user_preferences
         (source_preference_id,user_id,theme,notif_appointment,notif_ratings,notif_messages,notif_announcements,
          notif_email,accept_new_patients,consultation_duration,working_hours_start,working_hours_end,
          profile_visible,admin_default_tab,created_at,updated_at)
         SELECT id,user_id,theme,notif_appointment,notif_ratings,notif_messages,notif_announcements,
                notif_email,accept_new_patients,consultation_duration,working_hours_start,working_hours_end,
                profile_visible,admin_default_tab,created_at,updated_at
         FROM ' . SOURCE_SCHEMA . '.user_preferences');

    copyRows($db,'doctor_schedule_settings',
        'INSERT INTO doctor_schedule_settings
         (doctor_user_id,appointment_duration_min,max_appointments_per_day,break_start,break_end,is_available,created_at,updated_at)
         SELECT s.doctor_id,s.appointment_duration,s.max_appointments_per_day,
                CAST(s.break_start AS TIME),CAST(s.break_end AS TIME),s.is_available,s.created_at,s.updated_at
         FROM ' . SOURCE_SCHEMA . '.doctor_schedule_settings s');
    copyRows($db,'doctor_weekly_schedules',
        'INSERT INTO doctor_weekly_schedules
         (doctor_user_id,day_of_week,start_time,end_time,is_working,created_at,updated_at)
         SELECT doctor_id,day_of_week,CAST(start_time AS TIME),CAST(end_time AS TIME),is_working,created_at,updated_at
         FROM ' . SOURCE_SCHEMA . '.doctor_schedule_weekly');

    // Normalize every appointment time in PHP and preserve the exact source text beside starts_at.
    $appointments = $db->query(
        'SELECT a.*, COALESCE(s.appointment_duration,h.default_appointment_duration,30) AS duration_snapshot
         FROM ' . SOURCE_SCHEMA . '.appointments a
         LEFT JOIN ' . SOURCE_SCHEMA . '.doctor_schedule_settings s ON s.doctor_id=a.doctor_id
         LEFT JOIN ' . SOURCE_SCHEMA . '.hospital_settings h ON h.id=1
         ORDER BY a.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $insertAppointment = $db->prepare(
        'INSERT INTO appointments
         (id,patient_user_id,doctor_user_id,department_id,patient_name_snapshot,doctor_name_snapshot,
          department_name_snapshot,starts_at,source_time_snapshot,source_time_range_snapshot,duration_minutes,
          booking_status,legacy_status_snapshot,notes,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($appointments as $a) {
        $normalized = AppointmentTime::normalize((string)$a['time']);
        if (!preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $normalized)) {
            throw new RuntimeException('Appointment time cannot be normalized; blocking source appointment ID ' . $a['id']);
        }
        $combined = (string)$a['date'] . ' ' . $normalized;
        $dateTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $combined);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$dateTime || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $dateTime->format('Y-m-d H:i') !== $combined) {
            throw new RuntimeException('Appointment date/time failed round-trip; blocking source appointment ID ' . $a['id']);
        }
        $bookingStatus = match ((string)$a['status']) {
            'Pending' => 'pending',
            'Confirmed' => 'confirmed',
            'Cancelled' => 'cancelled',
            'Reschedule Requested' => ((string)$a['reschedule_status'] === 'pending'
                ? 'confirmed'
                : throw new RuntimeException('Ambiguous legacy reschedule booking status at appointment ' . $a['id'])),
            default => throw new RuntimeException('Unsupported appointment status at appointment ' . $a['id'])
        };
        $duration = (int)$a['duration_snapshot'];
        if ($duration < 5 || $duration > 240) {
            throw new RuntimeException('Appointment duration outside v2 domain at appointment ' . $a['id']);
        }
        $insertAppointment->execute([
            (int)$a['id'],
            $a['user_id'] !== null ? (int)$a['user_id'] : null,
            (int)$a['doctor_id'],
            $a['department_id'] !== null ? (int)$a['department_id'] : null,
            (string)$a['patient_name'],
            (string)$a['doctor'],
            (string)$a['department'],
            $dateTime->format('Y-m-d H:i:s'),
            (string)$a['time'],
            $a['appointment_time_range'],
            $duration,
            $bookingStatus,
            (string)$a['status'],
            $a['notes'],
            (string)$a['created_at'],
            $a['updated_at']
        ]);
    }
    echo 'COPY|appointments|' . count($appointments) . "\n";

    // The source service permits a reschedule request only from Confirmed.
    $reschedules = $db->query(
        'SELECT a.* FROM ' . SOURCE_SCHEMA . '.appointments a
         WHERE a.reschedule_status<>"none" ORDER BY a.id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $insertReschedule = $db->prepare(
        'INSERT INTO appointment_reschedule_requests
         (id,appointment_id,requested_by_user_id,responded_by_user_id,original_starts_at,requested_starts_at,
          proposed_starts_at,request_status,reason,response_notes,requested_at,responded_at,created_at,updated_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    foreach ($reschedules as $r) {
        $originalTime = AppointmentTime::normalize((string)$r['time']);
        $requestedTime = AppointmentTime::normalize((string)$r['requested_reschedule_time']);
        $originalCombined = (string)$r['date'] . ' ' . $originalTime;
        $requestedCombined = (string)$r['requested_reschedule_date'] . ' ' . $requestedTime;
        $original = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $originalCombined);
        $requested = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $requestedCombined);
        if (!$original || !$requested
            || $original->format('Y-m-d H:i') !== $originalCombined
            || $requested->format('Y-m-d H:i') !== $requestedCombined) {
            throw new RuntimeException('Could not parse reschedule timeline for appointment ' . $r['id']);
        }
        $requestStatus = match ((string)$r['reschedule_status']) {
            'pending' => 'requested',
            'suggested' => 'suggested',
            'approved' => 'approved',
            'rejected' => 'rejected',
            default => throw new RuntimeException('Unsupported reschedule state at appointment ' . $r['id'])
        };
        $proposedAt = null;
        if ((string)$r['reschedule_status'] === 'suggested') {
            $proposedTime = AppointmentTime::normalize((string)$r['pending_reschedule_time']);
            $proposedCombined = (string)$r['pending_reschedule_date'] . ' ' . $proposedTime;
            $proposed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $proposedCombined);
            if (!$proposed || $proposed->format('Y-m-d H:i') !== $proposedCombined) {
                throw new RuntimeException('Suggested time failed parse at appointment ' . $r['id']);
            }
            $proposedAt = $proposed->format('Y-m-d H:i:s');
        }
        $insertReschedule->execute([
            (int)$r['id'],(int)$r['id'],(int)$r['reschedule_requested_by'],
            $r['reschedule_responded_by'] !== null ? (int)$r['reschedule_responded_by'] : null,
            $original->format('Y-m-d H:i:s'),$requested->format('Y-m-d H:i:s'),$proposedAt,
            $requestStatus,$r['reschedule_reason'],$r['reschedule_response_notes'],
            $r['reschedule_requested_at'],$r['reschedule_responded_at'],
            (string)$r['created_at'],$r['updated_at']
        ]);
    }
    echo 'COPY|appointment_reschedule_requests|' . count($reschedules) . "\n";

    copyRows($db,'clinical_encounters',
        'INSERT INTO clinical_encounters
         (id,appointment_id,patient_user_id,doctor_user_id,workflow_status,diagnosis,symptoms,treatment,
          doctor_notes,follow_up_instructions,started_at,completed_at,created_at,updated_at)
         SELECT a.id,a.id,a.user_id,a.doctor_id,
                CASE w.status WHEN "Waiting" THEN "waiting" WHEN "In Progress" THEN "in_progress"
                     WHEN "Ready to Complete" THEN "ready_to_complete" WHEN "Completed" THEN "completed"
                     ELSE "uninitialized" END,
                n.diagnosis,n.symptoms,n.treatment,n.doctor_notes,n.follow_up_instructions,
                w.started_at,w.completed_at,COALESCE(w.created_at,n.created_at),COALESCE(w.updated_at,n.updated_at)
         FROM ' . SOURCE_SCHEMA . '.visit_workflow w
         JOIN ' . SOURCE_SCHEMA . '.appointments a ON a.id=w.appointment_id
         LEFT JOIN ' . SOURCE_SCHEMA . '.visit_notes n ON n.appointment_id=a.id');
    copyRows($db,'encounter_drafts',
        'INSERT INTO encounter_drafts
         (id,appointment_id,doctor_user_id,diagnosis,symptoms,treatment,doctor_notes,follow_up_instructions,updated_at)
         SELECT id,appointment_id,doctor_id,diagnosis,symptoms,treatment,doctor_notes,follow_up_instructions,updated_at
         FROM ' . SOURCE_SCHEMA . '.visit_drafts');
    copyRows($db,'medical_history_events',
        'INSERT INTO medical_history_events
         (id,patient_user_id,doctor_user_id,field_name,old_value,new_value,created_at)
         SELECT id,patient_id,doctor_id,field_name,old_value,new_value,created_at
         FROM ' . SOURCE_SCHEMA . '.medical_history_updates');
    copyRows($db,'prescriptions',
        'INSERT INTO prescriptions
         (id,patient_user_id,doctor_user_id,appointment_id,notes,status,cancellation_reason,created_at,updated_at)
         SELECT id,patient_id,doctor_id,appointment_id,notes,LOWER(status),cancellation_reason,created_at,updated_at
         FROM ' . SOURCE_SCHEMA . '.prescriptions');
    copyRows($db,'prescription_items',
        'INSERT INTO prescription_items
         (id,prescription_id,medication_name,strength,dosage,frequency,duration,instructions,sort_order)
         SELECT id,prescription_id,medication_name,strength,dosage,frequency,duration,instructions,sort_order
         FROM ' . SOURCE_SCHEMA . '.prescription_items');
    copyRows($db,'ratings',
        'INSERT INTO ratings (id,appointment_id,patient_user_id,doctor_user_id,stars,review,created_at,is_public)
         SELECT id,appointment_id,user_id,doctor_id,stars,review,created_at,' . $ratingVisibilityExpression . '
         FROM ' . SOURCE_SCHEMA . '.ratings');
    copyRows($db,'notifications',
        'INSERT INTO notifications (id,user_id,type,title,message,ref_type,ref_id,is_read,created_at)
         SELECT id,user_id,type,title,message,ref_type,ref_id,is_read,created_at
         FROM ' . SOURCE_SCHEMA . '.notifications');
    copyRows($db,'notification_outbox',
        'INSERT INTO notification_outbox
         (id,user_id,type,title,message,ref_type,ref_id,status,attempt_count,available_at,created_at,processed_at,last_error_code)
         SELECT id,user_id,type,title,message,ref_type,ref_id,status,attempt_count,available_at,created_at,processed_at,last_error_code
         FROM ' . SOURCE_SCHEMA . '.notification_outbox');
    copyRows($db,'contact_messages',
        'INSERT INTO contact_messages
         (id,user_id,name,email,phone,department,subject,message,reply,replied_at,is_read,created_at)
         SELECT id,user_id,name,email,phone,department,subject,message,reply,replied_at,is_read,created_at
         FROM ' . SOURCE_SCHEMA . '.contact_messages');

    copyRows($db,'legacy_admin_audit',
        'INSERT INTO legacy_admin_audit
         (id,actor_id,actor_role,action,entity_type,entity_id,patient_id,doctor_id,old_value,new_value,description,ip_address,user_agent,created_at)
         SELECT id,actor_id,actor_role,action,entity_type,entity_id,patient_id,doctor_id,old_value,new_value,description,ip_address,user_agent,created_at
         FROM ' . SOURCE_SCHEMA . '.admin_audit');
    copyRows($db,'legacy_medical_record_audit',
        'INSERT INTO legacy_medical_record_audit
         (id,patient_id,admin_id,field_name,old_value,new_value,reason,created_at)
         SELECT id,patient_id,admin_id,field_name,old_value,new_value,reason,created_at
         FROM ' . SOURCE_SCHEMA . '.medical_record_audit');
    copyRows($db,'legacy_patient_audit_log',
        'INSERT INTO legacy_patient_audit_log
         (id,actor_id,actor_role,patient_id,action,field_name,entity_type,entity_id,old_value,new_value,
          description,ip_address,user_agent,created_at,legacy_payload)
         SELECT s.id,s.actor_id,s.actor_role,s.patient_id,s.action,' . $patientFieldExpr . ',
                ' . $patientEntityTypeExpr . ',' . $patientEntityIdExpr . ',
                s.old_value,s.new_value,s.description,s.ip_address,s.user_agent,s.created_at,
                ' . $patientLegacyPayloadExpr . '
         FROM ' . SOURCE_SCHEMA . '.patient_audit_log s');
    copyRows($db,'legacy_schedule_audit_log',
        'INSERT INTO legacy_schedule_audit_log
         (id,actor_id,actor_role,doctor_id,action,old_value,new_value,description,ip_address,user_agent,created_at,legacy_payload)
         SELECT s.id,s.actor_id,s.actor_role,s.doctor_id,s.action,s.old_value,s.new_value,s.description,
                s.ip_address,s.user_agent,s.created_at,' . $schedulePayloadExpr . '
         FROM ' . SOURCE_SCHEMA . '.schedule_audit_log s');
    copyRows($db,'password_reset_tokens',
        'INSERT INTO password_reset_tokens (id,email,token_hash,request_ip,expires_at,used,created_at)
         SELECT id,email,token_hash,request_ip,expires_at,used,created_at
         FROM ' . SOURCE_SCHEMA . '.password_resets');

    copyRows($db,'registration_intake_queue',
        'INSERT INTO registration_intake_queue
         (id,request_id,payload_version,key_version,nonce,auth_tag,ciphertext,status,attempt_count,available_at,
          lease_expires_at,worker_token,created_at,started_at,processed_at,last_error_code,email_lookup_hmac,
          verification_expires_at,email_verified_at)
         SELECT id,request_id,payload_version,key_version,nonce,auth_tag,ciphertext,status,attempt_count,available_at,
                lease_expires_at,worker_token,created_at,started_at,processed_at,last_error_code,email_lookup_hmac,
                verification_expires_at,email_verified_at
         FROM ' . SOURCE_SCHEMA . '.registration_intake_queue');
    copyRows($db,'registration_email_challenges',
        'INSERT INTO registration_email_challenges
         (id,queue_id,token_hash,created_at,expires_at,consumed_at,revoked_at,send_attempt_count,
          last_send_attempt_at,last_sent_at,last_send_failure_at)
         SELECT id,queue_id,token_hash,created_at,expires_at,consumed_at,revoked_at,send_attempt_count,
                last_send_attempt_at,last_sent_at,last_send_failure_at
         FROM ' . SOURCE_SCHEMA . '.registration_email_challenges');
    copyRows($db,'hospital_settings',
        'INSERT INTO hospital_settings
         (id,hospital_name,hospital_phone,hospital_email,hospital_address,hospital_description,
          appointment_open_time,appointment_close_time,default_appointment_duration,updated_at)
         SELECT id,hospital_name,hospital_phone,hospital_email,hospital_address,hospital_description,
                CAST(appointment_open_time AS TIME),CAST(appointment_close_time AS TIME),
                default_appointment_duration,updated_at
         FROM ' . SOURCE_SCHEMA . '.hospital_settings');

    // Row-level lineage for every copied source entity or split/merged source.
    $maps = [
      ['users','users','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.users'],
      ['departments','departments','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.departments'],
      ['egypt_governorates','egypt_governorates','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.egypt_governorates'],
      ['egypt_cities','egypt_cities','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.egypt_cities'],
      ['doctors','doctor_profiles','SELECT id source_id,user_id target_id FROM ' . SOURCE_SCHEMA . '.doctors'],
      ['doctors','legacy_doctor_profile_state','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.doctors'],
      ['medical_records','patient_profiles','SELECT id source_id,patient_id target_id FROM ' . SOURCE_SCHEMA . '.medical_records'],
      ['medical_records','patient_medical_profiles','SELECT id source_id,patient_id target_id FROM ' . SOURCE_SCHEMA . '.medical_records'],
      ['user_preferences','user_preferences','SELECT id source_id,user_id target_id FROM ' . SOURCE_SCHEMA . '.user_preferences'],
      ['user_preferences','legacy_user_preferences','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.user_preferences'],
      ['user_preferences','admin_preferences','SELECT p.id source_id,p.user_id target_id FROM ' . SOURCE_SCHEMA . '.user_preferences p JOIN ' . SOURCE_SCHEMA . '.users u ON u.id=p.user_id WHERE u.role="admin"'],
      ['doctor_schedule_settings','doctor_schedule_settings','SELECT id source_id,doctor_id target_id FROM ' . SOURCE_SCHEMA . '.doctor_schedule_settings'],
      ['doctor_schedule_weekly','doctor_weekly_schedules','SELECT id source_id,CONCAT(doctor_id,":",day_of_week) target_id FROM ' . SOURCE_SCHEMA . '.doctor_schedule_weekly'],
      ['appointments','appointments','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.appointments'],
      ['appointments','appointment_reschedule_requests','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.appointments WHERE reschedule_status<>"none"'],
      ['visit_workflow','clinical_encounters','SELECT id source_id,appointment_id target_id FROM ' . SOURCE_SCHEMA . '.visit_workflow'],
      ['visit_notes','clinical_encounters','SELECT id source_id,appointment_id target_id FROM ' . SOURCE_SCHEMA . '.visit_notes'],
      ['visit_drafts','encounter_drafts','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.visit_drafts'],
      ['medical_history_updates','medical_history_events','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.medical_history_updates'],
      ['prescriptions','prescriptions','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.prescriptions'],
      ['prescription_items','prescription_items','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.prescription_items'],
      ['ratings','ratings','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.ratings'],
      ['notifications','notifications','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.notifications'],
      ['notification_outbox','notification_outbox','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.notification_outbox'],
      ['contact_messages','contact_messages','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.contact_messages'],
      ['admin_audit','legacy_admin_audit','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.admin_audit'],
      ['medical_record_audit','legacy_medical_record_audit','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.medical_record_audit'],
      ['patient_audit_log','legacy_patient_audit_log','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.patient_audit_log'],
      ['schedule_audit_log','legacy_schedule_audit_log','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.schedule_audit_log'],
      ['password_resets','password_reset_tokens','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.password_resets'],
      ['registration_intake_queue','registration_intake_queue','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.registration_intake_queue'],
      ['registration_email_challenges','registration_email_challenges','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.registration_email_challenges'],
      ['hospital_settings','hospital_settings','SELECT id source_id,id target_id FROM ' . SOURCE_SCHEMA . '.hospital_settings']
    ];
    foreach ($maps as [$sourceTable,$targetTable,$selectSql]) {
        addMap($db,$sourceTable,$targetTable,$selectSql);
    }

    copyRows($db,'rating_cache_reconciliation_warnings',
        'INSERT INTO migration_exceptions
         (source_table,source_id,exception_code,severity,safe_summary,resolution_status,resolution_note)
         SELECT "doctors",CAST(d.id AS CHAR),"RATING_CACHE_DIFFERS_FROM_FACTS","warning",
                "Legacy cached rating excluded; v2 aggregate is derived from rating facts.",
                "accepted_legacy","The v1 cached value differed from AVG(ratings.stars); no review row was changed."
         FROM ' . SOURCE_SCHEMA . '.doctors d
         JOIN ' . SOURCE_SCHEMA . '.ratings r ON r.doctor_id=d.user_id
         GROUP BY d.id,d.rating
         HAVING ABS(d.rating-ROUND(AVG(r.stars),1))>0.05');
    copyRows($db,'location_text_preservation_warnings',
        'INSERT INTO migration_exceptions
         (source_table,source_id,exception_code,severity,safe_summary,resolution_status,resolution_note)
         SELECT "medical_records",CAST(m.id AS CHAR),"LOCATION_TEXT_UNMATCHED","warning",
                "Location text retained; no unverified lookup ID was assigned.",
                "accepted_legacy","Governorate/city labels did not produce an exact same-governorate lookup match."
         FROM ' . SOURCE_SCHEMA . '.medical_records m
         LEFT JOIN ' . SOURCE_SCHEMA . '.egypt_governorates g ON g.name=m.governorate
         LEFT JOIN ' . SOURCE_SCHEMA . '.egypt_cities c ON c.governorate_id=g.id AND c.name=m.city
         WHERE (m.governorate IS NOT NULL AND g.id IS NULL)
            OR (m.city IS NOT NULL AND c.id IS NULL)');
    copyRows($db,'legacy_reschedule_status_mapping_warnings',
        'INSERT INTO migration_exceptions
         (source_table,source_id,exception_code,severity,safe_summary,resolution_status,resolution_note)
         SELECT "appointments",CAST(id AS CHAR),"RESCHEDULE_STATUS_REPRESENTED_SEPARATELY","warning",
                "Legacy Reschedule Requested status mapped to confirmed booking plus a child reschedule request.",
                "accepted_legacy","Verified against RescheduleService: requests are accepted only when original status is Confirmed."
         FROM ' . SOURCE_SCHEMA . '.appointments
         WHERE status="Reschedule Requested" AND reschedule_status="pending"');
    copyRows($db,'legacy_12_hour_time_normalization_warnings',
        'INSERT INTO migration_exceptions
         (source_table,source_id,exception_code,severity,safe_summary,resolution_status,resolution_note)
         SELECT "appointments",CAST(id AS CHAR),"TIME_NORMALIZED_FROM_12_HOUR","warning",
                "Original time text retained in source_time_snapshot; starts_at stores normalized local datetime.",
                "accepted_legacy","Parsed with the shared AppointmentTime normalizer and strict datetime round-trip."
         FROM ' . SOURCE_SCHEMA . '.appointments
         WHERE time REGEXP "^[0-9]{1,2}:[0-9]{2} ?[AP]M$"');

    // Row-count and relationship gates. Any failure rolls back all ETL inserts.
    $expected = [
      'users'=>166,'departments'=>9,'egypt_governorates'=>27,'egypt_cities'=>200,
      'patient_profiles'=>133,'patient_medical_profiles'=>133,'doctor_profiles'=>32,
      'legacy_doctor_profile_state'=>32,'user_preferences'=>166,'legacy_user_preferences'=>166,
      'admin_preferences'=>1,'doctor_schedule_settings'=>32,'doctor_weekly_schedules'=>224,
      'appointments'=>486,'appointment_reschedule_requests'=>12,'clinical_encounters'=>451,
      'encounter_drafts'=>0,'medical_history_events'=>0,'prescriptions'=>99,'prescription_items'=>186,
      'ratings'=>79,'notifications'=>558,'notification_outbox'=>13,'contact_messages'=>0,
      'legacy_admin_audit'=>13,'legacy_medical_record_audit'=>0,'legacy_patient_audit_log'=>$expectedSourceRows['patient_audit_log'],
      'legacy_schedule_audit_log'=>$expectedSourceRows['schedule_audit_log'],'password_reset_tokens'=>0,'registration_intake_queue'=>20,
      'registration_email_challenges'=>11,'hospital_settings'=>1
    ];
    foreach ($expected as $table=>$expectedCount) {
        $actual=(int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
        if ($actual !== $expectedCount) {
            throw new RuntimeException("Row-count gate failed for {$table}: expected {$expectedCount}, got {$actual}");
        }
        echo "VERIFY|{$table}|{$actual}\n";
    }

    $statusRows=$db->query(
        'SELECT booking_status,COUNT(*) AS n FROM appointments GROUP BY booking_status ORDER BY booking_status'
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statusRows as $row) echo 'BOOKING_STATUS|' . $row['booking_status'] . '|' . $row['n'] . "\n";

    $orphanCheck=(int)$db->query(
        'SELECT COUNT(*) FROM clinical_encounters e
         JOIN appointments a ON a.id=e.appointment_id
         WHERE e.patient_user_id<>a.patient_user_id OR e.doctor_user_id<>a.doctor_user_id'
    )->fetchColumn();
    if ($orphanCheck !== 0) throw new RuntimeException('Clinical encounter owner mismatch after ETL.');
    $blocking=(int)$db->query(
        "SELECT COUNT(*) FROM migration_exceptions WHERE severity='blocking' AND resolution_status='open'"
    )->fetchColumn();
    if ($blocking !== 0) throw new RuntimeException('Blocking migration exceptions found: ' . $blocking);

    $patientAuditMismatch = (int)$db->query(
        'SELECT COUNT(*) FROM ' . SOURCE_SCHEMA . '.patient_audit_log s
         LEFT JOIN legacy_patient_audit_log t ON t.id=s.id
         WHERE t.id IS NULL
            OR NOT (t.actor_id <=> s.actor_id)
            OR NOT (BINARY t.actor_role <=> BINARY s.actor_role)
            OR NOT (t.patient_id <=> s.patient_id)
            OR NOT (BINARY t.action <=> BINARY s.action)
            OR NOT (BINARY t.field_name <=> BINARY ' . $patientFieldExpr . ')
            OR NOT (BINARY t.entity_type <=> BINARY ' . $patientEntityTypeExpr . ')
            OR NOT (BINARY t.entity_id <=> BINARY ' . $patientEntityIdExpr . ')
            OR NOT (BINARY t.old_value <=> BINARY s.old_value)
            OR NOT (BINARY t.new_value <=> BINARY s.new_value)
            OR NOT (BINARY t.description <=> BINARY s.description)
            OR NOT (BINARY t.ip_address <=> BINARY s.ip_address)
            OR NOT (BINARY t.user_agent <=> BINARY s.user_agent)
            OR NOT (t.created_at <=> s.created_at)
            OR NOT (BINARY t.legacy_payload <=> BINARY ' . $patientLegacyPayloadExpr . ')'
    )->fetchColumn();
    if ($patientAuditMismatch !== 0) {
        throw new RuntimeException('Patient audit archive field-equivalence failed: ' . $patientAuditMismatch);
    }

    $scheduleAuditMismatch = (int)$db->query(
        'SELECT COUNT(*) FROM ' . SOURCE_SCHEMA . '.schedule_audit_log s
         LEFT JOIN legacy_schedule_audit_log t ON t.id=s.id
         WHERE t.id IS NULL
            OR NOT (t.actor_id <=> s.actor_id)
            OR NOT (BINARY t.actor_role <=> BINARY s.actor_role)
            OR NOT (t.doctor_id <=> s.doctor_id)
            OR NOT (BINARY t.action <=> BINARY s.action)
            OR NOT (BINARY t.old_value <=> BINARY s.old_value)
            OR NOT (BINARY t.new_value <=> BINARY s.new_value)
            OR NOT (BINARY t.description <=> BINARY s.description)
            OR NOT (BINARY t.ip_address <=> BINARY s.ip_address)
            OR NOT (BINARY t.user_agent <=> BINARY s.user_agent)
            OR NOT (t.created_at <=> s.created_at)
            OR NOT (BINARY t.legacy_payload <=> BINARY ' . $schedulePayloadExpr . ')'
    )->fetchColumn();
    if ($scheduleAuditMismatch !== 0) {
        throw new RuntimeException('Schedule audit archive field-equivalence failed: ' . $scheduleAuditMismatch);
    }

    $mapCount=(int)$db->query('SELECT COUNT(*) FROM migration_row_map')->fetchColumn();
    $exceptionCount=(int)$db->query('SELECT COUNT(*) FROM migration_exceptions')->fetchColumn();
    $hash=hash('sha256','HealthBridge v2 synthetic ETL mapping contract 2.1.2 phase8 audit preservation utc timestamps');
    $q=$db->prepare(
        'INSERT INTO schema_migrations (version,checksum_sha256,description,engine_minimum)
         VALUES (?,?,?,?)'
    );
    $q->execute([ETL_VERSION,$hash,'Synthetic clone v1-to-v2 ETL rehearsal','MariaDB 10.11+']);
    $db->commit();

    echo "SYNTHETIC_ETL_PASS\n";
    echo 'source=' . SOURCE_SCHEMA . "\n";
    echo 'target=' . TARGET_SCHEMA . "\n";
    echo "row_map_count={$mapCount}\n";
    echo "accepted_legacy_exceptions={$exceptionCount}\n";
    echo "blocking_exceptions=0\n";
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR,'SYNTHETIC_ETL_FAILED: '.$error->getMessage()."\n");
    exit(1);
}
