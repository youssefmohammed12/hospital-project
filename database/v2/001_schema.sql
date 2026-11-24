-- HealthBridge database v2 prototype
-- Target engine: MariaDB 10.11+
-- Creates a new empty schema only. It does not alter or migrate v1.
-- Apply only to a separately named disposable database after checking the target.
SET NAMES utf8mb4;

CREATE TABLE schema_migrations (
    version VARCHAR(40) NOT NULL,
    checksum_sha256 CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    description VARCHAR(255) NOT NULL,
    engine_minimum VARCHAR(40) NOT NULL,
    applied_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (version),
    UNIQUE KEY uq_schema_migrations_checksum (checksum_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('patient','doctor','admin') NOT NULL DEFAULT 'patient',
    patient_number VARCHAR(20) NULL,
    phone VARCHAR(20) NULL,
    phone_normalized VARCHAR(20) GENERATED ALWAYS AS (
        NULLIF(
            REPLACE(
              REPLACE(
                REPLACE(
                  REPLACE(
                    REPLACE(
                      REPLACE(
                        REPLACE(
                          REPLACE(
                            REPLACE(
                              REPLACE(
                                REPLACE(
                                  CONVERT(TRIM(phone) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                                  _utf8mb4' ' COLLATE utf8mb4_unicode_ci,
                                  _utf8mb4'' COLLATE utf8mb4_unicode_ci
                                ),
                                CONVERT(CHAR(9) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                                _utf8mb4'' COLLATE utf8mb4_unicode_ci
                              ),
                              CONVERT(CHAR(10) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                              _utf8mb4'' COLLATE utf8mb4_unicode_ci
                            ),
                            CONVERT(CHAR(11) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                            _utf8mb4'' COLLATE utf8mb4_unicode_ci
                          ),
                          CONVERT(CHAR(12) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                          _utf8mb4'' COLLATE utf8mb4_unicode_ci
                        ),
                        CONVERT(CHAR(13) USING utf8mb4) COLLATE utf8mb4_unicode_ci,
                        _utf8mb4'' COLLATE utf8mb4_unicode_ci
                      ),
                      _utf8mb4'(' COLLATE utf8mb4_unicode_ci,
                      _utf8mb4'' COLLATE utf8mb4_unicode_ci
                    ),
                    _utf8mb4')' COLLATE utf8mb4_unicode_ci,
                    _utf8mb4'' COLLATE utf8mb4_unicode_ci
                  ),
                  _utf8mb4'.' COLLATE utf8mb4_unicode_ci,
                  _utf8mb4'' COLLATE utf8mb4_unicode_ci
                ),
                _utf8mb4'-' COLLATE utf8mb4_unicode_ci,
                _utf8mb4'' COLLATE utf8mb4_unicode_ci
              ),
              _utf8mb4'+20' COLLATE utf8mb4_unicode_ci,
              _utf8mb4'0' COLLATE utf8mb4_unicode_ci
            ),
            _utf8mb4'' COLLATE utf8mb4_unicode_ci
        )
    ) STORED,
    national_id VARCHAR(14) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    email_verification_required TINYINT(1) NOT NULL DEFAULT 0,
    email_verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_patient_number (patient_number),
    UNIQUE KEY uq_users_national_id (national_id),
    UNIQUE KEY uq_users_phone_normalized (phone_normalized),
    KEY idx_users_role_active (role, is_active),
    CONSTRAINT chk_users_is_active CHECK (is_active IN (0,1)),
    CONSTRAINT chk_users_session_version CHECK (session_version >= 1),
    CONSTRAINT chk_users_email_verification CHECK (email_verification_required IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    description TEXT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_departments_name (name),
    KEY idx_departments_status_name (status, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE egypt_governorates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_egypt_governorates_name (name),
    KEY idx_egypt_governorates_sort (sort_order, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE egypt_cities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    governorate_id INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_egypt_cities_governorate_name (governorate_id, name),
    CONSTRAINT fk_v2_cities_governorate
        FOREIGN KEY (governorate_id) REFERENCES egypt_governorates(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patient_profiles (
    patient_user_id INT UNSIGNED NOT NULL,
    date_of_birth DATE NULL,
    gender VARCHAR(20) NULL,
    governorate_id INT UNSIGNED NULL,
    city_id INT UNSIGNED NULL,
    governorate_text VARCHAR(100) NULL,
    city_text VARCHAR(100) NULL,
    address TEXT NULL,
    insurance_provider VARCHAR(200) NULL,
    insurance_number VARCHAR(100) NULL,
    emergency_name VARCHAR(100) NULL,
    emergency_relation VARCHAR(50) NULL,
    emergency_phone VARCHAR(20) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (patient_user_id),
    KEY idx_patient_profiles_location (governorate_id, city_id),
    CONSTRAINT fk_patient_profiles_user
        FOREIGN KEY (patient_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_patient_profiles_governorate
        FOREIGN KEY (governorate_id) REFERENCES egypt_governorates(id)
        ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_patient_profiles_city
        FOREIGN KEY (city_id) REFERENCES egypt_cities(id)
        ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doctor_profiles (
    doctor_user_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NULL,
    specialty VARCHAR(100) NOT NULL,
    experience_years SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    profile_icon VARCHAR(40) NOT NULL DEFAULT 'fa-user-doctor',
    accepts_new_patients TINYINT(1) NOT NULL DEFAULT 1,
    profile_visible TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (doctor_user_id),
    KEY idx_doctor_profiles_department (department_id),
    CONSTRAINT fk_doctor_profiles_user
        FOREIGN KEY (doctor_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_doctor_profiles_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT chk_doctor_accepts_new CHECK (accepts_new_patients IN (0,1)),
    CONSTRAINT chk_doctor_profile_visible CHECK (profile_visible IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_preferences (
    user_id INT UNSIGNED NOT NULL,
    theme ENUM('light','dark','system') NOT NULL DEFAULT 'system',
    notif_appointment TINYINT(1) NOT NULL DEFAULT 1,
    notif_ratings TINYINT(1) NOT NULL DEFAULT 1,
    notif_messages TINYINT(1) NOT NULL DEFAULT 1,
    notif_announcements TINYINT(1) NOT NULL DEFAULT 1,
    notif_email TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_v2_user_preferences_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_user_preferences_flags CHECK (
        notif_appointment IN (0,1) AND notif_ratings IN (0,1)
        AND notif_messages IN (0,1) AND notif_announcements IN (0,1)
        AND notif_email IN (0,1)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_preferences (
    admin_user_id INT UNSIGNED NOT NULL,
    default_tab VARCHAR(50) NOT NULL DEFAULT 'overview',
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_user_id),
    CONSTRAINT fk_admin_preferences_user
        FOREIGN KEY (admin_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doctor_schedule_settings (
    doctor_user_id INT UNSIGNED NOT NULL,
    appointment_duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    max_appointments_per_day SMALLINT UNSIGNED NOT NULL DEFAULT 25,
    break_start TIME NULL,
    break_end TIME NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (doctor_user_id),
    CONSTRAINT fk_v2_schedule_settings_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_schedule_duration CHECK (appointment_duration_min BETWEEN 5 AND 240),
    CONSTRAINT chk_schedule_capacity CHECK (max_appointments_per_day BETWEEN 1 AND 200),
    CONSTRAINT chk_schedule_availability CHECK (is_available IN (0,1)),
    CONSTRAINT chk_schedule_break CHECK (break_start IS NULL OR break_end IS NULL OR break_start < break_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doctor_weekly_schedules (
    doctor_user_id INT UNSIGNED NOT NULL,
    day_of_week TINYINT UNSIGNED NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    is_working TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (doctor_user_id, day_of_week),
    KEY idx_weekly_schedule_day (day_of_week, is_working),
    CONSTRAINT fk_v2_weekly_schedule_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_weekly_schedule_day CHECK (day_of_week BETWEEN 1 AND 7),
    CONSTRAINT chk_weekly_schedule_time CHECK (start_time < end_time),
    CONSTRAINT chk_weekly_schedule_working CHECK (is_working IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_user_id INT UNSIGNED NULL,
    doctor_user_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NULL,
    patient_name_snapshot VARCHAR(100) NOT NULL,
    doctor_name_snapshot VARCHAR(100) NOT NULL,
    department_name_snapshot VARCHAR(100) NOT NULL,
    starts_at DATETIME NOT NULL,
    duration_minutes SMALLINT UNSIGNED NOT NULL,
    booking_status ENUM('pending','confirmed','cancelled') NOT NULL DEFAULT 'pending',
    legacy_status_snapshot VARCHAR(40) NULL,
    notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_appointments_doctor_start_status (doctor_user_id, starts_at, booking_status),
    KEY idx_appointments_patient_start (patient_user_id, starts_at),
    KEY idx_appointments_department_start (department_id, starts_at),
    CONSTRAINT fk_v2_appointments_patient
        FOREIGN KEY (patient_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_v2_appointments_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_v2_appointments_department
        FOREIGN KEY (department_id) REFERENCES departments(id)
        ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT chk_appointments_duration CHECK (duration_minutes BETWEEN 5 AND 1440)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_reschedule_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    requested_by_user_id INT UNSIGNED NOT NULL,
    responded_by_user_id INT UNSIGNED NULL,
    original_starts_at DATETIME NOT NULL,
    requested_starts_at DATETIME NOT NULL,
    proposed_starts_at DATETIME NULL,
    request_status ENUM('requested','suggested','approved','rejected','cancelled') NOT NULL DEFAULT 'requested',
    reason TEXT NULL,
    response_notes TEXT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_reschedule_appointment_status (appointment_id, request_status, created_at),
    KEY idx_reschedule_requested_by (requested_by_user_id, created_at),
    CONSTRAINT fk_reschedule_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reschedule_requested_by
        FOREIGN KEY (requested_by_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_reschedule_responded_by
        FOREIGN KEY (responded_by_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE appointment_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    actor_user_id INT UNSIGNED NULL,
    actor_role_snapshot VARCHAR(20) NULL,
    event_type VARCHAR(50) NOT NULL,
    from_status VARCHAR(40) NULL,
    to_status VARCHAR(40) NULL,
    event_details LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_appointment_events_timeline (appointment_id, created_at),
    KEY idx_appointment_events_actor (actor_user_id, created_at),
    CONSTRAINT fk_appointment_events_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_appointment_events_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE clinical_encounters (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    patient_user_id INT UNSIGNED NOT NULL,
    doctor_user_id INT UNSIGNED NOT NULL,
    workflow_status ENUM('uninitialized','waiting','in_progress','ready_to_complete','completed') NOT NULL DEFAULT 'uninitialized',
    diagnosis TEXT NULL,
    symptoms TEXT NULL,
    treatment TEXT NULL,
    doctor_notes TEXT NULL,
    follow_up_instructions TEXT NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_encounters_appointment (appointment_id),
    KEY idx_encounters_patient (patient_user_id, created_at),
    KEY idx_encounters_doctor (doctor_user_id, created_at),
    KEY idx_encounters_status (workflow_status, updated_at),
    CONSTRAINT fk_encounters_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_encounters_patient
        FOREIGN KEY (patient_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_encounters_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE encounter_drafts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    doctor_user_id INT UNSIGNED NOT NULL,
    diagnosis TEXT NULL,
    symptoms TEXT NULL,
    treatment TEXT NULL,
    doctor_notes TEXT NULL,
    follow_up_instructions TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_encounter_drafts_appointment (appointment_id),
    KEY idx_encounter_drafts_doctor (doctor_user_id, updated_at),
    CONSTRAINT fk_encounter_drafts_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_encounter_drafts_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patient_medical_profiles (
    patient_user_id INT UNSIGNED NOT NULL,
    blood_type VARCHAR(5) NULL,
    height_cm DECIMAL(5,1) NULL,
    weight_kg DECIMAL(5,1) NULL,
    allergies TEXT NULL,
    chronic_diseases TEXT NULL,
    current_medications TEXT NULL,
    previous_surgeries TEXT NULL,
    family_history TEXT NULL,
    medical_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (patient_user_id),
    CONSTRAINT fk_patient_medical_profiles_patient
        FOREIGN KEY (patient_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_patient_medical_height CHECK (height_cm IS NULL OR height_cm BETWEEN 20 AND 300),
    CONSTRAINT chk_patient_medical_weight CHECK (weight_kg IS NULL OR weight_kg BETWEEN 0.5 AND 1000)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medical_history_events (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_user_id INT UNSIGNED NOT NULL,
    doctor_user_id INT UNSIGNED NOT NULL,
    field_name VARCHAR(50) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_medical_history_patient (patient_user_id, created_at),
    KEY idx_medical_history_doctor (doctor_user_id, created_at),
    CONSTRAINT fk_medical_history_patient
        FOREIGN KEY (patient_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_medical_history_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prescriptions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_user_id INT UNSIGNED NOT NULL,
    doctor_user_id INT UNSIGNED NOT NULL,
    appointment_id INT UNSIGNED NOT NULL,
    notes TEXT NULL,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    cancellation_reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prescriptions_appointment (appointment_id),
    KEY idx_prescriptions_patient_status (patient_user_id, status, created_at),
    KEY idx_prescriptions_doctor_status (doctor_user_id, status, created_at),
    CONSTRAINT fk_v2_prescriptions_patient
        FOREIGN KEY (patient_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_v2_prescriptions_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_v2_prescriptions_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE prescription_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    prescription_id INT UNSIGNED NOT NULL,
    medication_name VARCHAR(200) NOT NULL,
    strength VARCHAR(100) NOT NULL,
    dosage VARCHAR(100) NOT NULL,
    frequency VARCHAR(100) NOT NULL,
    duration VARCHAR(100) NOT NULL,
    instructions TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_prescription_items_order (prescription_id, sort_order, id),
    CONSTRAINT fk_v2_prescription_items_prescription
        FOREIGN KEY (prescription_id) REFERENCES prescriptions(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ratings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    appointment_id INT UNSIGNED NOT NULL,
    patient_user_id INT UNSIGNED NOT NULL,
    doctor_user_id INT UNSIGNED NOT NULL,
    stars TINYINT UNSIGNED NOT NULL,
    review TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ratings_appointment (appointment_id),
    KEY idx_ratings_doctor_created (doctor_user_id, created_at),
    KEY idx_ratings_patient_created (patient_user_id, created_at),
    CONSTRAINT fk_v2_ratings_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_v2_ratings_patient
        FOREIGN KEY (patient_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_v2_ratings_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_ratings_stars CHECK (stars BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE VIEW v_doctor_rating_summary AS
SELECT doctor_user_id, ROUND(AVG(stars), 1) AS average_rating, COUNT(*) AS rating_count
FROM ratings
GROUP BY doctor_user_id;

CREATE TABLE notifications (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    ref_type VARCHAR(50) NULL,
    ref_id INT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_read_created (user_id, is_read, created_at),
    CONSTRAINT fk_v2_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT chk_notifications_is_read CHECK (is_read IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL COMMENT 'Intentionally no users FK, preserve lock ordering',
    type VARCHAR(50) NOT NULL,
    title VARCHAR(200) NOT NULL,
    message TEXT NOT NULL,
    ref_type VARCHAR(50) NULL,
    ref_id INT UNSIGNED NULL,
    status ENUM('pending','delivered','skipped','failed') NOT NULL DEFAULT 'pending',
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    last_error_code VARCHAR(64) NULL,
    PRIMARY KEY (id),
    KEY idx_notification_outbox_ready (status, available_at, id),
    KEY idx_notification_outbox_processed (status, processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    department VARCHAR(100) NULL DEFAULT 'General Inquiry',
    subject VARCHAR(200) NULL,
    message TEXT NOT NULL,
    reply TEXT NULL,
    replied_at DATETIME NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_messages_created (created_at),
    KEY idx_contact_messages_email (email),
    KEY idx_contact_messages_user (user_id, created_at),
    CONSTRAINT fk_v2_contact_messages_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT chk_contact_messages_read CHECK (is_read IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT UNSIGNED NULL,
    actor_role_snapshot VARCHAR(20) NOT NULL,
    action_code VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id VARCHAR(64) NULL,
    patient_user_id INT UNSIGNED NULL,
    doctor_user_id INT UNSIGNED NULL,
    old_value LONGTEXT NULL,
    new_value LONGTEXT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_events_actor_time (actor_user_id, occurred_at),
    KEY idx_audit_events_patient_time (patient_user_id, occurred_at),
    KEY idx_audit_events_doctor_time (doctor_user_id, occurred_at),
    KEY idx_audit_events_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_events_actor
        FOREIGN KEY (actor_user_id) REFERENCES users(id)
        ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_audit_events_patient
        FOREIGN KEY (patient_user_id) REFERENCES users(id)
        ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_audit_events_doctor
        FOREIGN KEY (doctor_user_id) REFERENCES doctor_profiles(doctor_user_id)
        ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Read-only compatibility archives. New writes belong in audit_events.
CREATE TABLE legacy_admin_audit (
    id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    actor_role VARCHAR(20) NOT NULL DEFAULT 'admin',
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    patient_id INT NULL,
    doctor_id INT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_legacy_admin_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_medical_record_audit (
    id INT UNSIGNED NOT NULL,
    patient_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    reason TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_legacy_mra_patient_created (patient_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_patient_audit_log (
    id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    actor_role VARCHAR(20) NOT NULL DEFAULT 'admin',
    patient_id INT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL,
    field_name VARCHAR(100) NULL,
    entity_type VARCHAR(50) NULL,
    entity_id VARCHAR(64) NULL,
    old_value LONGTEXT NULL,
    new_value LONGTEXT NULL,
    description LONGTEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent LONGTEXT NULL,
    created_at DATETIME NULL,
    legacy_payload LONGTEXT NULL,
    PRIMARY KEY (id),
    KEY idx_legacy_patient_audit_patient_time (patient_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_schedule_audit_log (
    id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NOT NULL,
    actor_role VARCHAR(20) NOT NULL DEFAULT 'admin',
    doctor_id INT UNSIGNED NOT NULL,
    action VARCHAR(100) NOT NULL DEFAULT 'update_schedule',
    old_value LONGTEXT NULL,
    new_value LONGTEXT NULL,
    description LONGTEXT NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent LONGTEXT NULL,
    created_at DATETIME NULL,
    legacy_payload LONGTEXT NULL,
    PRIMARY KEY (id),
    KEY idx_legacy_schedule_audit_doctor_time (doctor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_reset_tokens (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(150) NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_ip VARCHAR(45) NULL,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_reset_token_hash (token_hash),
    KEY idx_password_reset_email_created (email, created_at),
    KEY idx_password_reset_ip_created (request_ip, created_at),
    CONSTRAINT chk_password_reset_used CHECK (used IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registration_intake_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_version SMALLINT UNSIGNED NOT NULL,
    key_version VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    nonce BINARY(12) NULL,
    auth_tag BINARY(16) NULL,
    ciphertext MEDIUMBLOB NULL,
    status ENUM('pending','processing','awaiting_verification','completed','duplicate','failed','expired') NOT NULL DEFAULT 'pending',
    attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_expires_at DATETIME NULL,
    worker_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    processed_at DATETIME NULL,
    last_error_code VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    email_lookup_hmac BINARY(32) NULL,
    verification_expires_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_intake_request (request_id),
    KEY idx_registration_intake_ready (status, available_at, id),
    KEY idx_registration_intake_lease (status, lease_expires_at),
    KEY idx_registration_intake_processed (status, processed_at),
    KEY idx_registration_intake_email_lookup (status, key_version, email_lookup_hmac, verification_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registration_email_challenges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    queue_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    revoked_at DATETIME NULL,
    send_attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_send_attempt_at DATETIME NULL,
    last_sent_at DATETIME NULL,
    last_send_failure_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_registration_email_challenge_hash (token_hash),
    KEY idx_registration_email_challenge_queue (queue_id, consumed_at, revoked_at, expires_at),
    CONSTRAINT fk_v2_registration_challenge_queue
        FOREIGN KEY (queue_id) REFERENCES registration_intake_queue(id)
        ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hospital_settings (
    id TINYINT UNSIGNED NOT NULL DEFAULT 1,
    hospital_name VARCHAR(200) NULL DEFAULT 'HealthBridge Hospital',
    hospital_phone VARCHAR(20) NULL,
    hospital_email VARCHAR(150) NULL,
    hospital_address TEXT NULL,
    hospital_description TEXT NULL,
    appointment_open_time TIME NOT NULL DEFAULT '08:00:00',
    appointment_close_time TIME NOT NULL DEFAULT '22:00:00',
    default_appointment_duration SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT chk_hospital_settings_singleton CHECK (id = 1),
    CONSTRAINT chk_hospital_hours CHECK (appointment_open_time < appointment_close_time),
    CONSTRAINT chk_hospital_duration CHECK (default_appointment_duration BETWEEN 5 AND 240)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE migration_row_map (
    source_table VARCHAR(64) NOT NULL,
    source_id VARCHAR(128) NOT NULL,
    target_table VARCHAR(64) NOT NULL,
    target_id VARCHAR(128) NOT NULL,
    mapping_version VARCHAR(40) NOT NULL,
    mapped_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (source_table, source_id, target_table),
    KEY idx_migration_row_map_target (target_table, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE migration_exceptions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_table VARCHAR(64) NOT NULL,
    source_id VARCHAR(128) NOT NULL,
    exception_code VARCHAR(64) NOT NULL,
    severity ENUM('warning','blocking') NOT NULL DEFAULT 'blocking',
    safe_summary VARCHAR(500) NOT NULL,
    resolution_status ENUM('open','resolved','accepted_legacy') NOT NULL DEFAULT 'open',
    resolution_note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_migration_exception_source_code (source_table, source_id, exception_code),
    KEY idx_migration_exceptions_status (resolution_status, severity, source_table)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
