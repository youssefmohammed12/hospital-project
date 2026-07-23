-- ============================================
-- HealthBridge Phase 8 Final Migration
-- Fixes: Audit schema, ID conventions, EMR logging
-- ============================================

-- Step 1: Migrate admin_audit table to universal actor model
-- (Only run if you haven't applied Phase 7)
ALTER TABLE admin_audit 
    CHANGE COLUMN admin_id actor_id INT NOT NULL,
    ADD COLUMN actor_role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER actor_id,
    ADD COLUMN description TEXT NULL AFTER new_value,
    ADD INDEX idx_actor (actor_id, actor_role),
    ADD INDEX idx_entity (entity_type, entity_id);

-- Step 2: Create patient EMR audit table (lightweight, separate from admin audit for performance)
CREATE TABLE IF NOT EXISTS patient_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    actor_role VARCHAR(20) NOT NULL,
    patient_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT,
    old_value JSON,
    new_value JSON,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_patient (patient_id),
    INDEX idx_actor (actor_id, actor_role),
    INDEX idx_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 3: Create schedule audit table (dedicated, human-readable)
CREATE TABLE IF NOT EXISTS schedule_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NOT NULL,
    actor_role VARCHAR(20) NOT NULL,
    doctor_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    change_type VARCHAR(50) NOT NULL,
    old_value JSON,
    new_value JSON,
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_doctor (doctor_id),
    INDEX idx_actor (actor_id, actor_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Step 4: Fix get_audit_log.php compatibility view (optional helper)
-- If you need backward compat, keep this commented out and handle in PHP