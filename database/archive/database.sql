-- ============================================================
-- HealthBridge Consolidated Master Database Schema
-- Character Set: utf8mb4 | Collation: utf8mb4_unicode_ci
-- MySQL / MariaDB Compatible
--
-- Single Source of Truth for Database Creation.
-- Contains all 25 tables, primary keys, foreign keys, indexes,
-- constraints, ENUMs, and essential lookup seed data.
-- ============================================================

DROP DATABASE IF EXISTS healthbridge;

CREATE DATABASE healthbridge
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE healthbridge;

-- ============================================================
-- 1. USERS TABLE
-- Stores accounts for patients, doctors, and admins.
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    name           VARCHAR(100)                     NOT NULL,
    first_name     VARCHAR(100)                     NULL,
    last_name      VARCHAR(100)                     NULL,
    email          VARCHAR(150)                     NOT NULL UNIQUE,
    password       VARCHAR(255)                     NOT NULL,
    role           ENUM('patient','doctor','admin') NOT NULL DEFAULT 'patient',
    patient_number VARCHAR(20)                      NULL UNIQUE,
    phone          VARCHAR(20)                      NULL,
    national_id    VARCHAR(14)                      NULL UNIQUE,
    is_active      TINYINT(1)                       NOT NULL DEFAULT 1,
    created_at     DATETIME                         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME                         NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_email (email),
    INDEX idx_users_role (role),
    INDEX idx_users_active (is_active),
    INDEX idx_users_patient_number (patient_number),
    INDEX idx_users_national_id (national_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 2. DEPARTMENTS TABLE
-- Hospital department categories for doctor assignments.
-- ============================================================
CREATE TABLE IF NOT EXISTS departments (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description TEXT         NULL,
    status      ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_departments_status (status),
    INDEX idx_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. DOCTORS TABLE
-- Profiles linked to users table and departments table.
-- ============================================================
CREATE TABLE IF NOT EXISTS doctors (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          NULL,
    name          VARCHAR(100) NOT NULL,
    specialty     VARCHAR(100) NOT NULL,
    department_id INT          NULL,
    rating        DECIMAL(3,1) NOT NULL DEFAULT 4.5,
    exp           INT          NOT NULL DEFAULT 5,
    available     TINYINT(1)   NOT NULL DEFAULT 1,
    emoji         VARCHAR(20)  NOT NULL DEFAULT 'fa-user-doctor',
    INDEX idx_doctors_specialty (specialty),
    INDEX idx_doctors_available (available),
    INDEX idx_doctors_department (department_id),
    CONSTRAINT fk_doctors_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_doctors_department
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 4. APPOINTMENTS TABLE
-- Appointment records including rescheduling workflow fields.
-- ============================================================
CREATE TABLE IF NOT EXISTS appointments (
    id                        INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id                 INT          NOT NULL,
    user_id                   INT          NULL,
    department_id             INT          NULL,
    patient_name              VARCHAR(100) NOT NULL,
    department                VARCHAR(100) NOT NULL,
    doctor                    VARCHAR(100) NOT NULL,
    date                      DATE         NOT NULL,
    time                      VARCHAR(20)  NOT NULL,
    appointment_time_range    VARCHAR(30)  NULL,
    notes                     TEXT         NULL,
    status                    ENUM('Pending','Confirmed','Cancelled','Reschedule Requested') NOT NULL DEFAULT 'Pending',
    pending_reschedule_date   DATE         NULL,
    pending_reschedule_time   VARCHAR(20)  NULL,
    reschedule_reason         TEXT         NULL,
    reschedule_requested_at   DATETIME     NULL,
    reschedule_requested_by   INT          NULL,
    reschedule_status         ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    reschedule_responded_at   DATETIME     NULL,
    reschedule_responded_by   INT          NULL,
    reschedule_response_notes TEXT         NULL,
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_appointments_user (user_id),
    INDEX idx_appointments_doctor (doctor_id),
    INDEX idx_appointments_date (date),
    INDEX idx_appointments_status (status),
    INDEX idx_appointments_department (department_id),
    INDEX idx_appt_reschedule_status (reschedule_status),
    INDEX idx_appt_reschedule_requested (reschedule_requested_at),
    CONSTRAINT fk_appointments_doctor
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_appointments_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_appointments_department
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_appointments_reschedule_req
        FOREIGN KEY (reschedule_requested_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_appointments_reschedule_resp
        FOREIGN KEY (reschedule_responded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 5. VISIT_WORKFLOW TABLE
-- Tracks appointment workflow status independently.
-- ============================================================
CREATE TABLE IF NOT EXISTS visit_workflow (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT          NOT NULL UNIQUE,
    status         ENUM('Waiting','In Progress','Ready to Complete','Completed') NOT NULL DEFAULT 'Waiting',
    started_at     DATETIME     NULL,
    completed_at   DATETIME     NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vw_appointment (appointment_id),
    INDEX idx_vw_status (status),
    CONSTRAINT fk_vw_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. VISIT_DRAFTS TABLE
-- Autosave drafts for visit notes in progress.
-- ============================================================
CREATE TABLE IF NOT EXISTS visit_drafts (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id         INT      NOT NULL UNIQUE,
    doctor_id              INT      NOT NULL,
    diagnosis              TEXT     NULL,
    symptoms               TEXT     NULL,
    treatment              TEXT     NULL,
    doctor_notes           TEXT     NULL,
    follow_up_instructions TEXT     NULL,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vd_appointment (appointment_id),
    INDEX idx_vd_doctor (doctor_id),
    CONSTRAINT fk_vd_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_vd_doctor
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 7. NOTIFICATIONS TABLE
-- System and user notifications.
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NOT NULL,
    type       VARCHAR(50)  NOT NULL,
    title      VARCHAR(200) NOT NULL,
    message    TEXT         NOT NULL,
    ref_type   VARCHAR(50)  NULL,
    ref_id     INT          NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif_user (user_id),
    INDEX idx_notif_read (is_read),
    INDEX idx_notif_created (created_at),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 8. CONTACT_MESSAGES TABLE
-- Patient inquiries and contact form submissions.
-- ============================================================
CREATE TABLE IF NOT EXISTS contact_messages (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT          NULL,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(150) NOT NULL,
    phone      VARCHAR(30)  NULL,
    department VARCHAR(100) NULL DEFAULT 'General Inquiry',
    subject    VARCHAR(200) NULL,
    message    TEXT         NOT NULL,
    reply      TEXT         NULL,
    replied_at DATETIME     NULL,
    is_read    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_contact_email (email),
    INDEX idx_contact_user (user_id),
    INDEX idx_contact_created (created_at),
    CONSTRAINT fk_contact_messages_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 9. RATINGS TABLE
-- Patient reviews and ratings for doctors.
-- ============================================================
CREATE TABLE IF NOT EXISTS ratings (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT      NOT NULL UNIQUE,
    user_id        INT      NOT NULL,
    doctor_id      INT      NOT NULL,
    stars          INT      NOT NULL,
    review         TEXT     NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ratings_user (user_id),
    INDEX idx_ratings_doctor (doctor_id),
    INDEX idx_ratings_appointment (appointment_id),
    CONSTRAINT fk_ratings_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_doctor
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 10. PASSWORD_RESETS TABLE
-- Password reset tokens for authentication.
-- ============================================================
CREATE TABLE IF NOT EXISTS password_resets (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    email      VARCHAR(150) NOT NULL,
    token      VARCHAR(64)  NOT NULL UNIQUE,
    expires_at DATETIME     NOT NULL,
    used       TINYINT(1)   NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pr_token (token),
    INDEX idx_pr_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 11. USER_PREFERENCES TABLE
-- User notification and display preferences.
-- ============================================================
CREATE TABLE IF NOT EXISTS user_preferences (
    id                    INT AUTO_INCREMENT PRIMARY KEY,
    user_id               INT          NOT NULL UNIQUE,
    theme                 VARCHAR(10)  NOT NULL DEFAULT 'system',
    notif_appointment     TINYINT(1)   NOT NULL DEFAULT 1,
    notif_ratings         TINYINT(1)   NOT NULL DEFAULT 1,
    notif_messages        TINYINT(1)   NOT NULL DEFAULT 1,
    notif_announcements   TINYINT(1)   NOT NULL DEFAULT 1,
    notif_email           TINYINT(1)   NOT NULL DEFAULT 0,
    accept_new_patients   TINYINT(1)   NULL DEFAULT 1,
    consultation_duration INT          NULL DEFAULT 30,
    working_hours_start   VARCHAR(5)   NULL DEFAULT '09:00',
    working_hours_end     VARCHAR(5)   NULL DEFAULT '17:00',
    profile_visible       TINYINT(1)   NULL DEFAULT 1,
    admin_default_tab     VARCHAR(50)  NULL DEFAULT 'overview',
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_pref_user (user_id),
    CONSTRAINT fk_pref_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 12. MEDICAL_HISTORY_UPDATES TABLE
-- Tracks medical history updates for patient timeline.
-- ============================================================
CREATE TABLE IF NOT EXISTS medical_history_updates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT          NOT NULL,
    doctor_id  INT          NOT NULL,
    field_name VARCHAR(50)  NOT NULL,
    old_value  TEXT         NULL,
    new_value  TEXT         NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mhu_patient (patient_id),
    INDEX idx_mhu_doctor (doctor_id),
    INDEX idx_mhu_created (created_at),
    CONSTRAINT fk_mhu_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mhu_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 13. MEDICAL_RECORDS TABLE
-- Comprehensive patient EMR medical records.
-- ============================================================
CREATE TABLE IF NOT EXISTS medical_records (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    patient_id              INT          NOT NULL UNIQUE,
    blood_type              VARCHAR(5)   NULL,
    height_cm               DECIMAL(5,1) NULL,
    weight_kg               DECIMAL(5,1) NULL,
    date_of_birth           DATE         NULL,
    gender                  VARCHAR(20)  NULL,
    governorate             VARCHAR(100) NULL,
    city                    VARCHAR(100) NULL,
    address                 TEXT         NULL,
    allergies               TEXT         NULL,
    chronic_diseases        TEXT         NULL,
    current_medications     TEXT         NULL,
    insurance_provider      VARCHAR(200) NULL,
    insurance_number        VARCHAR(100) NULL,
    previous_surgeries      TEXT         NULL,
    family_history          TEXT         NULL,
    emergency_contact_name  VARCHAR(100) NULL,
    emergency_contact_rel   VARCHAR(50)  NULL,
    emergency_contact_phone VARCHAR(20)  NULL,
    medical_notes           TEXT         NULL,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mr_patient (patient_id),
    CONSTRAINT fk_mr_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 14. VISIT_NOTES TABLE
-- Doctor clinical notes per appointment.
-- ============================================================
CREATE TABLE IF NOT EXISTS visit_notes (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT      NOT NULL UNIQUE,
    patient_id     INT      NOT NULL,
    doctor_id      INT      NOT NULL,
    diagnosis      TEXT     NULL,
    symptoms       TEXT     NULL,
    treatment      TEXT     NULL,
    doctor_notes   TEXT     NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vn_patient (patient_id),
    INDEX idx_vn_doctor (doctor_id),
    INDEX idx_vn_appt (appointment_id),
    CONSTRAINT fk_vn_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_vn_patient     FOREIGN KEY (patient_id)     REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_vn_doctor      FOREIGN KEY (doctor_id)      REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 15. PRESCRIPTIONS TABLE
-- Doctor prescriptions for patients.
-- ============================================================
CREATE TABLE IF NOT EXISTS prescriptions (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    patient_id          INT          NOT NULL,
    doctor_id           INT          NOT NULL,
    appointment_id      INT          NOT NULL UNIQUE,
    notes               TEXT         NULL,
    status              ENUM('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
    cancellation_reason TEXT         NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rx_patient (patient_id),
    INDEX idx_rx_doctor (doctor_id),
    INDEX idx_rx_appointment (appointment_id),
    INDEX idx_rx_status (status),
    CONSTRAINT fk_rx_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rx_doctor  FOREIGN KEY (doctor_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rx_appt    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 16. PRESCRIPTION_ITEMS TABLE
-- Line items for prescription medications.
-- ============================================================
CREATE TABLE IF NOT EXISTS prescription_items (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    prescription_id INT          NOT NULL,
    medication_name VARCHAR(200) NOT NULL,
    strength        VARCHAR(100) NOT NULL,
    dosage          VARCHAR(100) NOT NULL,
    frequency       VARCHAR(100) NOT NULL,
    duration        VARCHAR(100) NOT NULL,
    instructions    TEXT         NULL,
    sort_order      INT          NOT NULL DEFAULT 0,
    INDEX idx_rxi_prescription (prescription_id),
    CONSTRAINT fk_rxi_prescription FOREIGN KEY (prescription_id) REFERENCES prescriptions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 17. HOSPITAL_SETTINGS TABLE
-- Hospital operating info and global settings.
-- ============================================================
CREATE TABLE IF NOT EXISTS hospital_settings (
    id                           INT AUTO_INCREMENT PRIMARY KEY,
    hospital_name                VARCHAR(200) NULL DEFAULT 'HealthBridge Hospital',
    hospital_phone               VARCHAR(20)  NULL,
    hospital_email               VARCHAR(150) NULL,
    hospital_address             TEXT         NULL,
    hospital_description         TEXT         NULL,
    appointment_open_time        VARCHAR(5)   NOT NULL DEFAULT '08:00',
    appointment_close_time       VARCHAR(5)   NOT NULL DEFAULT '22:00',
    default_appointment_duration INT          NOT NULL DEFAULT 30,
    updated_at                   DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 18. DOCTOR_SCHEDULE_SETTINGS TABLE
-- Doctor slot configuration and daily appointment limits.
-- ============================================================
CREATE TABLE IF NOT EXISTS doctor_schedule_settings (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id                INT        NOT NULL UNIQUE,
    appointment_duration     INT        NOT NULL DEFAULT 30,
    max_appointments_per_day INT        NOT NULL DEFAULT 25,
    break_start              VARCHAR(5) NULL,
    break_end                VARCHAR(5) NULL,
    is_available             TINYINT(1) NOT NULL DEFAULT 1,
    created_at               DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME   NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dss_doctor (doctor_id),
    CONSTRAINT fk_dss_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 19. DOCTOR_SCHEDULE_WEEKLY TABLE
-- Weekly working days and hours per doctor.
-- ============================================================
CREATE TABLE IF NOT EXISTS doctor_schedule_weekly (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id   INT        NOT NULL,
    day_of_week TINYINT    NOT NULL,
    start_time  VARCHAR(5) NOT NULL,
    end_time    VARCHAR(5) NOT NULL,
    is_working  TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME   NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dsw_doctor (doctor_id),
    INDEX idx_dsw_day (day_of_week),
    UNIQUE KEY uq_doctor_day (doctor_id, day_of_week),
    CONSTRAINT fk_dsw_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 20. ADMIN_AUDIT TABLE
-- Universal action audit trail for all user roles.
-- ============================================================
CREATE TABLE IF NOT EXISTS admin_audit (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT          NOT NULL,
    actor_role  VARCHAR(20)  NOT NULL DEFAULT 'admin',
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50)  NOT NULL,
    entity_id   INT          NULL,
    patient_id  INT          NULL,
    doctor_id   INT          NULL,
    old_value   TEXT         NULL,
    new_value   TEXT         NULL,
    description TEXT         NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_actor (actor_id),
    INDEX idx_audit_role (actor_role),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_patient (patient_id),
    INDEX idx_audit_doctor (doctor_id),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 21. MEDICAL_RECORD_AUDIT TABLE
-- Audit trail for administrative corrections to patient EMRs.
-- ============================================================
CREATE TABLE IF NOT EXISTS medical_record_audit (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT          NOT NULL,
    admin_id   INT          NOT NULL,
    field_name VARCHAR(100) NOT NULL,
    old_value  TEXT         NULL,
    new_value  TEXT         NULL,
    reason     TEXT         NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mra_patient (patient_id),
    INDEX idx_mra_admin (admin_id),
    INDEX idx_mra_created (created_at),
    CONSTRAINT fk_mra_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mra_admin   FOREIGN KEY (admin_id)   REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 22. PATIENT_AUDIT_LOG TABLE
-- Detailed patient profile and EMR change audit log.
-- ============================================================
CREATE TABLE IF NOT EXISTS patient_audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT          NOT NULL,
    actor_role  VARCHAR(20)  NOT NULL DEFAULT 'admin',
    patient_id  INT          NOT NULL,
    action      VARCHAR(100) NOT NULL,
    field_name  VARCHAR(100) NULL,
    old_value   TEXT         NULL,
    new_value   TEXT         NULL,
    description TEXT         NOT NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pal_patient (patient_id),
    INDEX idx_pal_actor (actor_id),
    INDEX idx_pal_action (action),
    INDEX idx_pal_created (created_at),
    CONSTRAINT fk_pal_actor   FOREIGN KEY (actor_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pal_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 23. SCHEDULE_AUDIT_LOG TABLE
-- Audit log for doctor schedule and working hour modifications.
-- ============================================================
CREATE TABLE IF NOT EXISTS schedule_audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    actor_id    INT          NOT NULL,
    actor_role  VARCHAR(20)  NOT NULL DEFAULT 'admin',
    doctor_id   INT          NOT NULL,
    action      VARCHAR(100) NOT NULL DEFAULT 'update_schedule',
    old_value   JSON         NULL,
    new_value   JSON         NULL,
    description TEXT         NOT NULL,
    ip_address  VARCHAR(45)  NULL,
    user_agent  TEXT         NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sal_doctor (doctor_id),
    INDEX idx_sal_actor (actor_id),
    INDEX idx_sal_created (created_at),
    CONSTRAINT fk_sal_actor  FOREIGN KEY (actor_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sal_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 24. EGYPT_GOVERNORATES TABLE
-- Egyptian governorates reference table for location dropdowns.
-- ============================================================
CREATE TABLE IF NOT EXISTS egypt_governorates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL UNIQUE,
    sort_order INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 25. EGYPT_CITIES TABLE
-- Cities reference table linked to governorates.
-- ============================================================
CREATE TABLE IF NOT EXISTS egypt_cities (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    governorate_id INT          NOT NULL,
    name           VARCHAR(100) NOT NULL,
    INDEX idx_cities_governorate (governorate_id),
    CONSTRAINT fk_cities_governorate
        FOREIGN KEY (governorate_id) REFERENCES egypt_governorates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
