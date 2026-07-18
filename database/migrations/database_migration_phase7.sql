-- ============================================================
-- HealthBridge Phase 7 - Universal Audit System
-- Generalizes admin_audit to support all actor roles
-- ============================================================

USE healthbridge;

-- ============================================================
-- 1. Migrate admin_audit to support multiple actor roles
--    - Rename admin_id → actor_id (same FK to users.id)
--    - Add actor_role column (admin, doctor, patient)
--    - Add description column for human-readable summaries
-- ============================================================

-- Drop the existing FK constraint first
ALTER TABLE admin_audit
DROP FOREIGN KEY fk_audit_admin;

-- Rename column and add new columns
ALTER TABLE admin_audit
CHANGE COLUMN admin_id actor_id INT NOT NULL,
ADD COLUMN actor_role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER actor_id,
ADD COLUMN description TEXT NULL AFTER new_value;

-- Re-add FK constraint with new column name
ALTER TABLE admin_audit
ADD CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE;

-- Update index name for clarity
ALTER TABLE admin_audit
DROP INDEX idx_audit_admin,
ADD INDEX idx_audit_actor (actor_id),
ADD INDEX idx_audit_role (actor_role);