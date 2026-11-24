-- v2.0.2: preserve all source doctor-profile and mixed user-preference values.
-- These tables are immutable migration archives, not the new write model.
CREATE TABLE legacy_doctor_profile_state (
    source_doctor_id INT UNSIGNED NOT NULL,
    doctor_user_id INT UNSIGNED NOT NULL,
    legacy_name VARCHAR(100) NOT NULL,
    legacy_specialty VARCHAR(100) NOT NULL,
    legacy_department_id INT NULL,
    legacy_rating DECIMAL(3,1) NOT NULL,
    legacy_experience INT NOT NULL,
    legacy_available TINYINT(1) NOT NULL,
    legacy_emoji VARCHAR(20) NOT NULL,
    PRIMARY KEY (source_doctor_id),
    UNIQUE KEY uq_legacy_doctor_profile_user (doctor_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_user_preferences (
    source_preference_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    theme VARCHAR(10) NOT NULL,
    notif_appointment TINYINT(1) NOT NULL,
    notif_ratings TINYINT(1) NOT NULL,
    notif_messages TINYINT(1) NOT NULL,
    notif_announcements TINYINT(1) NOT NULL,
    notif_email TINYINT(1) NOT NULL,
    accept_new_patients TINYINT(1) NULL,
    consultation_duration INT NULL,
    working_hours_start VARCHAR(5) NULL,
    working_hours_end VARCHAR(5) NULL,
    profile_visible TINYINT(1) NULL,
    admin_default_tab VARCHAR(50) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (source_preference_id),
    KEY idx_legacy_user_preferences_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
