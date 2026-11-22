<?php
declare(strict_types=1);

/** Read-only catalog checks plus rolled-back negative constraint tests for v2. */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(10);
}
require_once __DIR__ . '/../../includes/db.php';

const SOURCE_SCHEMA = 'healthbridge_hb_auth_email_verify_20261114';
const TARGET_SCHEMA = 'healthbridge_v2_prototype_20261118';

function expectRejected(PDO $db, string $label, callable $action, array &$results): void
{
    $db->beginTransaction();
    try {
        $action($db);
        $db->rollBack();
        $results[$label] = 'FAIL: invalid operation was accepted';
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        $results[$label] = 'PASS: rejected (' . $error->getCode() . ')';
    }
}

function expectAccepted(PDO $db, string $label, callable $action, array &$results): void
{
    $db->beginTransaction();
    try {
        $action($db);
        $db->rollBack();
        $results[$label] = 'PASS: accepted and rolled back';
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        $results[$label] = 'FAIL: unexpected rejection (' . $error->getCode() . ')';
    }
}

try {
    $db = getDB();
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== SOURCE_SCHEMA) {
        throw new RuntimeException('Refusing to run: source connection is not the exact disposable clone.');
    }
    $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
    $exists->execute([TARGET_SCHEMA]);
    if ((int)$exists->fetchColumn() !== 1) {
        throw new RuntimeException('The isolated v2 prototype database is missing.');
    }
    $db->exec('USE ' . TARGET_SCHEMA);
    if ((string)$db->query('SELECT DATABASE()')->fetchColumn() !== TARGET_SCHEMA) {
        throw new RuntimeException('Could not switch into the exact v2 prototype target.');
    }

    $tables = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'"
    )->fetchColumn();
    $views = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()"
    )->fetchColumn();
    $foreignKeys = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA=DATABASE()"
    )->fetchColumn();
    $checks = (int)$db->query(
        "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK'"
    )->fetchColumn();

    if ($tables !== 37 || $views !== 1 || $foreignKeys !== 42 || $checks !== 23) {
        throw new RuntimeException("Unexpected schema object count: tables={$tables}, views={$views}, FKs={$foreignKeys}, checks={$checks}");
    }

    $column = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $hasColumn = static function (string $table, string $name) use ($column): bool {
        $column->execute([$table, $name]);
        return (int)$column->fetchColumn() === 1;
    };
    $requiredColumns = [
        ['doctor_profiles', 'doctor_user_id'],
        ['appointments', 'starts_at'],
        ['appointments', 'duration_minutes'],
        ['clinical_encounters', 'workflow_status'],
        ['doctor_weekly_schedules', 'start_time'],
        ['ratings', 'stars'],
        ['users', 'phone_normalized'],
    ];
    foreach ($requiredColumns as [$table, $name]) {
        if (!$hasColumn($table, $name)) {
            throw new RuntimeException("Required v2 column missing: {$table}.{$name}");
        }
    }

    $forbiddenColumns = [
        ['doctor_profiles', 'id'],
        ['doctor_profiles', 'rating'],
        ['doctor_profiles', 'available'],
        ['appointments', 'time'],
        ['appointments', 'reschedule_status'],
    ];
    foreach ($forbiddenColumns as [$table, $name]) {
        if ($hasColumn($table, $name)) {
            throw new RuntimeException("Legacy duplicate column unexpectedly present: {$table}.{$name}");
        }
    }

    $fk = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    foreach (['notification_outbox', 'registration_intake_queue'] as $table) {
        $fk->execute([$table]);
        if ((int)$fk->fetchColumn() !== 0) {
            throw new RuntimeException("Intentional no-user-FK table unexpectedly has a foreign key: {$table}");
        }
    }

    $results = [];
    expectRejected($db, 'hospital_singleton_check', static function (PDO $db): void {
        $db->exec('INSERT INTO hospital_settings (id) VALUES (2)');
    }, $results);

    expectRejected($db, 'foreign_key_doctor_profile_requires_user', static function (PDO $db): void {
        $db->exec(
            "INSERT INTO doctor_profiles (doctor_user_id, specialty)
             VALUES (4294967294, 'Constraint test')"
        );
    }, $results);

    expectRejected($db, 'foreign_key_registration_challenge_requires_queue', static function (PDO $db): void {
        $db->exec(
            "INSERT INTO registration_email_challenges (queue_id, token_hash, expires_at)
             VALUES (18446744073709550000, REPEAT('a',64), DATE_ADD(NOW(), INTERVAL 1 HOUR))"
        );
    }, $results);

    expectAccepted($db, 'notification_outbox_no_user_fk_is_preserved', static function (PDO $db): void {
        $db->exec(
            "INSERT INTO notification_outbox
             (user_id, type, title, message, status)
             VALUES (4294967294, 'prototype_test', 'Test', 'Rolled-back test', 'pending')"
        );
    }, $results);

    expectAccepted($db, 'registration_queue_no_user_fk_is_preserved', static function (PDO $db): void {
        $db->exec(
            "INSERT INTO registration_intake_queue
             (request_id, payload_version, key_version, status)
             VALUES ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 1, 'prototype-key-v1', 'pending')"
        );
    }, $results);

    expectRejected($db, 'weekly_schedule_day_check', static function (PDO $db): void {
        $db->exec(
            "INSERT INTO users (name,email,password_hash,role)
             VALUES ('V2 Constraint Doctor','v2-weekly-check@example.invalid','not-a-real-password-hash','doctor')"
        );
        $id = (int)$db->lastInsertId();
        $q = $db->prepare("INSERT INTO doctor_profiles (doctor_user_id,specialty) VALUES (?, 'Test')");
        $q->execute([$id]);
        $q = $db->prepare(
            'INSERT INTO doctor_weekly_schedules (doctor_user_id,day_of_week,start_time,end_time,is_working)
             VALUES (?,8,?,?,1)'
        );
        $q->execute([$id,'09:00:00','17:00:00']);
    }, $results);

    expectRejected($db, 'rating_stars_check', static function (PDO $db): void {
        $db->exec(
            "INSERT INTO users (name,email,password_hash,role)
             VALUES ('V2 Constraint Patient','v2-rating-patient@example.invalid','not-a-real-password-hash','patient')"
        );
        $patientId = (int)$db->lastInsertId();
        $db->exec(
            "INSERT INTO users (name,email,password_hash,role)
             VALUES ('V2 Constraint Doctor','v2-rating-doctor@example.invalid','not-a-real-password-hash','doctor')"
        );
        $doctorId = (int)$db->lastInsertId();
        $q = $db->prepare("INSERT INTO doctor_profiles (doctor_user_id,specialty) VALUES (?, 'Test')");
        $q->execute([$doctorId]);
        $q = $db->prepare(
            "INSERT INTO appointments
             (patient_user_id,doctor_user_id,patient_name_snapshot,doctor_name_snapshot,
              department_name_snapshot,starts_at,duration_minutes,booking_status)
             VALUES (?,?, 'Patient','Doctor','Test','2026-12-01 09:00:00',30,'confirmed')"
        );
        $q->execute([$patientId,$doctorId]);
        $appointmentId = (int)$db->lastInsertId();
        $q = $db->prepare(
            'INSERT INTO ratings (appointment_id,patient_user_id,doctor_user_id,stars)
             VALUES (?,?,?,6)'
        );
        $q->execute([$appointmentId,$patientId,$doctorId]);
    }, $results);

    $appointment = $db->query(
        "SELECT a.id,a.doctor_user_id,a.patient_user_id
         FROM appointments a
         LEFT JOIN clinical_encounters e ON e.appointment_id=a.id
         WHERE e.id IS NULL AND a.patient_user_id IS NOT NULL
         ORDER BY a.id LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!$appointment) {
        throw new RuntimeException('No unused appointment is available for the composite-ownership negative test.');
    }
    $wrongPatient = (int)$db->query(
        'SELECT id FROM users WHERE role="patient" AND id<>' . (int)$appointment['patient_user_id'] . ' ORDER BY id LIMIT 1'
    )->fetchColumn();
    if ($wrongPatient <= 0) throw new RuntimeException('No alternate patient is available for the composite-ownership negative test.');
    expectRejected($db, 'encounter_must_match_appointment_patient_and_doctor', static function (PDO $db) use ($appointment,$wrongPatient): void {
        $q=$db->prepare(
            "INSERT INTO clinical_encounters (appointment_id,patient_user_id,doctor_user_id,workflow_status)
             VALUES (?,?,?,'uninitialized')"
        );
        $q->execute([(int)$appointment['id'],$wrongPatient,(int)$appointment['doctor_user_id']]);
    }, $results);

    $failed = array_filter($results, static fn(string $value): bool => str_starts_with($value, 'FAIL'));
    echo "V2_SCHEMA_VALIDATION\n";
    echo 'schema=' . TARGET_SCHEMA . "\n";
    echo "tables={$tables}\nviews={$views}\nforeign_keys={$foreignKeys}\ncheck_constraints={$checks}\n";
    foreach ($results as $name => $result) echo $name . '|' . $result . "\n";
    echo 'NEGATIVE_TESTS=' . count($results) . "\n";
    echo 'FAILED_TESTS=' . count($failed) . "\n";
    if ($failed !== []) exit(2);
    echo "V2_SCHEMA_VALIDATION_PASS\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'V2_SCHEMA_VALIDATION_FAILED: ' . $error->getMessage() . "\n");
    exit(1);
}
