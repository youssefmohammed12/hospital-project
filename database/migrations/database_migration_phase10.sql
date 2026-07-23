-- ============================================================
-- HealthBridge Database Migration — Phase 10
-- Adds patient_audit_log and schedule_audit_log tables
-- For upgrading EXISTING installations.
-- Run this against your live database after Phase 9.
-- ============================================================

-- ── PATIENT_AUDIT_LOG TABLE ──
-- Tracks all changes to patient profiles and medical records.
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
    INDEX idx_pal_actor   (actor_id),
    INDEX idx_pal_action  (action),
    INDEX idx_pal_created (created_at),
    CONSTRAINT fk_pal_actor   FOREIGN KEY (actor_id)   REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pal_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── SCHEDULE_AUDIT_LOG TABLE ──
-- Tracks all schedule changes separately for easy lookup.
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
    INDEX idx_sal_doctor  (doctor_id),
    INDEX idx_sal_actor   (actor_id),
    INDEX idx_sal_created (created_at),
    CONSTRAINT fk_sal_actor  FOREIGN KEY (actor_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_sal_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
