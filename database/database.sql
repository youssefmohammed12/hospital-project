-- ============================================================
-- HealthBridge Complete Database Schema
-- XAMPP MySQL/MariaDB Compatible
-- Character Set: utf8mb4
-- 
-- SINGLE SOURCE OF TRUTH for fresh installations.
-- Import this file into an empty MySQL database to create
-- the complete HealthBridge database with all tables,
-- indexes, constraints, and seed data.
--
-- For upgrading an EXISTING database, use the numbered
-- migration files (database_migration_phase*.sql) instead.
-- ============================================================

-- Reset and Recreate database
DROP DATABASE IF EXISTS healthbridge;

CREATE DATABASE healthbridge
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE healthbridge;

-- ============================================================
-- USERS TABLE
-- Stores all user accounts: patients, doctors, and admins.
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100)                     NOT NULL,
    email      VARCHAR(150)                     NOT NULL UNIQUE,
    password   VARCHAR(255)                     NOT NULL,
    role       ENUM('patient','doctor','admin') NOT NULL DEFAULT 'patient',
    phone      VARCHAR(20)                      NULL,
    is_active  TINYINT(1)                       NOT NULL DEFAULT 1,
    created_at DATETIME                         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME                         NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_email (email),
    INDEX idx_users_role  (role),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DEPARTMENTS TABLE
-- Manages hospital departments for doctor assignments.
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
-- DOCTORS TABLE
-- Stores doctor profile information.
-- Links to departments via department_id.
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
    INDEX idx_doctors_specialty  (specialty),
    INDEX idx_doctors_available  (available),
    INDEX idx_doctors_department (department_id),
    CONSTRAINT fk_doctors_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_doctors_department
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- APPOINTMENTS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS appointments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id    INT          NOT NULL,
    user_id      INT          NULL,
    department_id INT         NULL,
    patient_name VARCHAR(100) NOT NULL,
    department   VARCHAR(100) NOT NULL,
    doctor       VARCHAR(100) NOT NULL,
    date         DATE         NOT NULL,
    time         VARCHAR(20)  NOT NULL,
    appointment_time_range VARCHAR(30) NULL,
    notes        TEXT         NULL,
    status       ENUM('Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_appointments_user        (user_id),
    INDEX idx_appointments_doctor      (doctor_id),
    INDEX idx_appointments_date        (date),
    INDEX idx_appointments_status      (status),
    INDEX idx_appointments_department  (department_id),
    CONSTRAINT fk_appointments_doctor
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_appointments_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_appointments_department
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- VISIT WORKFLOW TABLE
-- Dedicated workflow state per appointment.
-- One-to-one with appointments. Does NOT overload appointments.status.
-- ============================================================
CREATE TABLE IF NOT EXISTS visit_workflow (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT          NOT NULL UNIQUE,
    status          ENUM('Waiting','In Progress','Ready to Complete','Completed') NOT NULL DEFAULT 'Waiting',
    started_at      DATETIME     NULL,
    completed_at    DATETIME     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vw_appointment (appointment_id),
    INDEX idx_vw_status      (status),
    CONSTRAINT fk_vw_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- VISIT_DRAFTS TABLE
-- Stores autosave drafts for visit notes in progress.
-- One-to-one with appointments.
-- ============================================================
CREATE TABLE IF NOT EXISTS visit_drafts (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT          NOT NULL UNIQUE,
    doctor_id       INT          NOT NULL,
    diagnosis       TEXT         NULL,
    symptoms        TEXT         NULL,
    treatment       TEXT         NULL,
    doctor_notes    TEXT         NULL,
    follow_up_instructions TEXT NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vd_appointment (appointment_id),
    INDEX idx_vd_doctor (doctor_id),
    CONSTRAINT fk_vd_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_vd_doctor
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- NOTIFICATIONS TABLE
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
-- CONTACT_MESSAGES TABLE
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
    INDEX idx_contact_email   (email),
    INDEX idx_contact_user    (user_id),
    INDEX idx_contact_created (created_at),
    CONSTRAINT fk_contact_messages_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- RATINGS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS ratings (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT          NOT NULL UNIQUE,
    user_id        INT          NOT NULL,
    doctor_id      INT          NOT NULL,
    stars          INT          NOT NULL,
    review         TEXT         NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ratings_user     (user_id),
    INDEX idx_ratings_doctor   (doctor_id),
    INDEX idx_ratings_appointment (appointment_id),
    CONSTRAINT fk_ratings_appointment
        FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_ratings_doctor
        FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PASSWORD_RESETS TABLE
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
-- USER_PREFERENCES TABLE
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
-- MEDICAL_HISTORY_UPDATES TABLE
-- Tracks medical history updates for timeline integration.
-- ============================================================
CREATE TABLE IF NOT EXISTS medical_history_updates (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    patient_id      INT          NOT NULL,
    doctor_id       INT          NOT NULL,
    field_name      VARCHAR(50)  NOT NULL,
    old_value       TEXT         NULL,
    new_value       TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mhu_patient (patient_id),
    INDEX idx_mhu_doctor (doctor_id),
    INDEX idx_mhu_created (created_at),
    CONSTRAINT fk_mhu_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mhu_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MEDICAL_RECORDS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS medical_records (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    patient_id               INT          NOT NULL UNIQUE,
    blood_type               VARCHAR(5)   NULL,
    height_cm                DECIMAL(5,1) NULL,
    weight_kg                DECIMAL(5,1) NULL,
    date_of_birth            DATE         NULL,
    gender                   VARCHAR(20)  NULL,
    allergies                TEXT         NULL,
    chronic_diseases         TEXT         NULL,
    current_medications      TEXT         NULL,
    previous_surgeries       TEXT         NULL,
    family_history           TEXT         NULL,
    emergency_contact_name   VARCHAR(100) NULL,
    emergency_contact_rel    VARCHAR(50)  NULL,
    emergency_contact_phone  VARCHAR(20)  NULL,
    medical_notes            TEXT         NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mr_patient (patient_id),
    CONSTRAINT fk_mr_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- VISIT_NOTES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS visit_notes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id  INT          NOT NULL UNIQUE,
    patient_id      INT          NOT NULL,
    doctor_id       INT          NOT NULL,
    diagnosis       TEXT         NULL,
    symptoms        TEXT         NULL,
    treatment       TEXT         NULL,
    doctor_notes    TEXT         NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vn_patient  (patient_id),
    INDEX idx_vn_doctor   (doctor_id),
    INDEX idx_vn_appt     (appointment_id),
    CONSTRAINT fk_vn_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE,
    CONSTRAINT fk_vn_patient     FOREIGN KEY (patient_id)     REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_vn_doctor      FOREIGN KEY (doctor_id)      REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PRESCRIPTIONS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS prescriptions (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    patient_id      INT          NOT NULL,
    doctor_id       INT          NOT NULL,
    appointment_id  INT          NOT NULL UNIQUE,
    notes           TEXT         NULL,
    status          ENUM('Active','Completed','Cancelled') NOT NULL DEFAULT 'Active',
    cancellation_reason TEXT     NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rx_patient      (patient_id),
    INDEX idx_rx_doctor       (doctor_id),
    INDEX idx_rx_appointment  (appointment_id),
    INDEX idx_rx_status       (status),
    CONSTRAINT fk_rx_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rx_doctor  FOREIGN KEY (doctor_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rx_appt    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- PRESCRIPTION_ITEMS TABLE
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
-- HOSPITAL_SETTINGS TABLE
-- Includes fields from Phase 6 migration (hospital info).
-- ============================================================
CREATE TABLE IF NOT EXISTS hospital_settings (
    id                         INT AUTO_INCREMENT PRIMARY KEY,
    hospital_name              VARCHAR(200) NULL DEFAULT 'HealthBridge Hospital',
    hospital_phone             VARCHAR(20)  NULL,
    hospital_email             VARCHAR(150) NULL,
    hospital_address           TEXT         NULL,
    hospital_description       TEXT         NULL,
    appointment_open_time      VARCHAR(5)   NOT NULL DEFAULT '08:00',
    appointment_close_time     VARCHAR(5)   NOT NULL DEFAULT '22:00',
    default_appointment_duration INT        NOT NULL DEFAULT 30,
    updated_at                 DATETIME     NULL ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DOCTOR_SCHEDULE_SETTINGS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS doctor_schedule_settings (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id               INT          NOT NULL UNIQUE,
    appointment_duration    INT          NOT NULL DEFAULT 30,
    max_appointments_per_day INT         NOT NULL DEFAULT 25,
    break_start             VARCHAR(5)   NULL,
    break_end               VARCHAR(5)   NULL,
    is_available            TINYINT(1)   NOT NULL DEFAULT 1,
    created_at              DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              DATETIME     NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dss_doctor (doctor_id),
    CONSTRAINT fk_dss_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DOCTOR_SCHEDULE_WEEKLY TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS doctor_schedule_weekly (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    doctor_id   INT          NOT NULL,
    day_of_week TINYINT      NOT NULL,
    start_time  VARCHAR(5)   NOT NULL,
    end_time    VARCHAR(5)   NOT NULL,
    is_working  TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NULL     ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_dsw_doctor (doctor_id),
    INDEX idx_dsw_day (day_of_week),
    UNIQUE KEY uq_doctor_day (doctor_id, day_of_week),
    CONSTRAINT fk_dsw_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- ADMIN_AUDIT TABLE (Generalized Audit Log)
-- Records meaningful actions by any authenticated user
-- (admin, doctor, patient) with structured old/new values
-- and human-readable descriptions.
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
    INDEX idx_audit_actor  (actor_id),
    INDEX idx_audit_role   (actor_role),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_patient (patient_id),
    INDEX idx_audit_doctor (doctor_id),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- MEDICAL_RECORD_AUDIT TABLE
-- Tracks administrative corrections to medical records.
-- Every correction must be logged with reason.
-- ============================================================
CREATE TABLE IF NOT EXISTS medical_record_audit (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    patient_id      INT          NOT NULL,
    admin_id        INT          NOT NULL,
    field_name      VARCHAR(100) NOT NULL,
    old_value       TEXT         NULL,
    new_value       TEXT         NULL,
    reason          TEXT         NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mra_patient (patient_id),
    INDEX idx_mra_admin   (admin_id),
    INDEX idx_mra_created (created_at),
    CONSTRAINT fk_mra_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mra_admin   FOREIGN KEY (admin_id)   REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEED DATA
-- ============================================================

-- Insert default hospital settings row
INSERT IGNORE INTO hospital_settings (id, hospital_name, hospital_phone, hospital_email, appointment_open_time, appointment_close_time, default_appointment_duration)
VALUES (1, 'HealthBridge Hospital', '+20123456789', 'info@healthbridge.com', '08:00', '22:00', 30);

-- Default departments
INSERT IGNORE INTO departments (name, description, status) VALUES
('Cardiology', 'Diagnosis and treatment of heart and cardiovascular conditions', 'active'),
('Dermatology', 'Diagnosis and treatment of skin, hair, and nail conditions', 'active'),
('Neurology', 'Diagnosis and treatment of nervous system and brain disorders', 'active'),
('Pediatrics', 'Medical care for infants, children, and adolescents', 'active'),
('Orthopedics', 'Diagnosis and treatment of musculoskeletal system conditions', 'active'),
('Dentistry', 'Diagnosis and treatment of oral health and dental conditions', 'active'),
('Ophthalmology', 'Diagnosis and treatment of eye and vision conditions', 'active'),
('Gynecology', 'Women''s health and reproductive medicine', 'active'),
('General Practice', 'Primary care and general medical services', 'active');

-- Default users: admin + demo patient (password: password)
INSERT IGNORE INTO users (name, email, password, role) VALUES
('Admin', 'admin@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'admin'),
('Ahmed Hassan', 'patient@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'patient');

-- Demo doctors (user accounts) (password: password)
INSERT IGNORE INTO users (name, email, password, role, phone) VALUES
('Dr. Ahmed Hassan',  'ahmed.hassan@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456789'),
('Dr. Sarah Johnson', 'sarah.johnson@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456780'),
('Dr. Mohamed Ali',   'mohamed.ali@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456781'),
('Dr. Fatima Nour',   'fatima.nour@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456782'),
('Dr. Karim Salah',   'karim.salah@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456783'),
('Dr. Layla Ibrahim', 'layla.ibrahim@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456784'),
('Dr. Omar Khaled',   'omar.khaled@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456785'),
('Dr. Nadia Rashid',  'nadia.rashid@healthbridge.com',
 '$2y$12$fih1RK9ubiSX4PTRokU7/ObwovH/e8JJr3HGFlTyE20L/y.r66YBu', 'doctor', '+20123456786');

-- Doctor profiles (linked to user accounts, linked to departments)
INSERT IGNORE INTO doctors (user_id, name, specialty, department_id, rating, exp, available, emoji) VALUES
(3,  'Dr. Ahmed Hassan',  'Cardiology',    1, 4.9, 12, 1, 'fa-user-doctor'),
(4,  'Dr. Sarah Johnson', 'Dermatology',   2, 4.8,  8, 1, 'fa-user-doctor'),
(5,  'Dr. Mohamed Ali',   'Neurology',     3, 4.7, 15, 0, 'fa-user-doctor'),
(6,  'Dr. Fatima Nour',   'Pediatrics',    4, 4.9, 10, 1, 'fa-user-doctor'),
(7,  'Dr. Karim Salah',   'Orthopedics',   5, 4.6,  9, 1, 'fa-user-doctor'),
(8,  'Dr. Layla Ibrahim', 'Dentistry',     6, 4.8,  6, 1, 'fa-user-doctor'),
(9,  'Dr. Omar Khaled',   'Ophthalmology', 7, 4.7, 11, 0, 'fa-user-doctor'),
(10, 'Dr. Nadia Rashid',  'Gynecology',    8, 4.9, 14, 1, 'fa-user-doctor');

-- Demo appointments
INSERT IGNORE INTO appointments
    (id, doctor_id, user_id, department_id, patient_name, department, doctor, date, time, status)
VALUES
    (1, 3, 2, 1, 'Ahmed Hassan', 'Cardiology',  'Dr. Ahmed Hassan',  '2026-06-15', '10:00 AM', 'Confirmed'),
    (3, 3, 2, 1, 'Ahmed Hassan', 'Cardiology', 'Dr. Ahmed Hassan', '2026-06-10', '09:00 AM', 'Confirmed'),
    (4, 4, 2, 2, 'Ahmed Hassan', 'Dermatology', 'Dr. Sarah Johnson', '2026-06-12', '11:00 AM', 'Confirmed'),
    (5, 6, 2, 4, 'Ahmed Hassan', 'Pediatrics', 'Dr. Fatima Nour', '2026-06-08', '02:00 PM', 'Confirmed'),
    (6, 4, 2, 2, 'Ahmed Hassan', 'Dermatology', 'Dr. Sarah Johnson', '2026-07-01', '02:00 PM', 'Pending');

-- Visit workflow records for confirmed appointments
INSERT IGNORE INTO visit_workflow (appointment_id, status, started_at, completed_at) VALUES
(1, 'Waiting', NULL, NULL),
(3, 'Waiting', NULL, NULL),
(4, 'Waiting', NULL, NULL),
(5, 'Completed', '2026-06-08 14:00:00', '2026-06-08 14:30:00');

-- Create default schedule settings for existing doctors
INSERT IGNORE INTO doctor_schedule_settings (doctor_id, appointment_duration, max_appointments_per_day, is_available)
SELECT id, 30, 25, 1 FROM users WHERE role = 'doctor';

-- Create default weekly schedules for all doctors
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 1, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 2, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 3, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 4, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 5, '09:00', '17:00', 1 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 6, '09:00', '17:00', 0 FROM users u WHERE u.role = 'doctor';
INSERT IGNORE INTO doctor_schedule_weekly (doctor_id, day_of_week, start_time, end_time, is_working)
SELECT u.id, 7, '09:00', '17:00', 0 FROM users u WHERE u.role = 'doctor';

-- Create default preferences for all existing users
INSERT IGNORE INTO user_preferences (user_id)
SELECT id FROM users;

-- Create medical_records for existing patients who have appointments
INSERT IGNORE INTO medical_records (patient_id)
SELECT DISTINCT user_id FROM appointments WHERE user_id IS NOT NULL AND user_id > 0;

-- Also create for any patient users who may not have appointments yet
INSERT IGNORE INTO medical_records (patient_id)
SELECT id FROM users WHERE role = 'patient' AND id NOT IN (SELECT patient_id FROM medical_records);