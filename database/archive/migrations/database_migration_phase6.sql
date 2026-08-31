-- ============================================================
-- HealthBridge Phase 6 - Hospital Settings & Department Management
-- Database Migration
-- ============================================================

USE healthbridge;

-- ============================================================
-- 1. Add hospital information fields to hospital_settings
-- ============================================================
ALTER TABLE hospital_settings
ADD COLUMN hospital_name VARCHAR(200) NULL DEFAULT 'HealthBridge Hospital' AFTER id,
ADD COLUMN hospital_phone VARCHAR(20) NULL,
ADD COLUMN hospital_email VARCHAR(150) NULL,
ADD COLUMN hospital_address TEXT NULL,
ADD COLUMN hospital_description TEXT NULL;

-- ============================================================
-- 2. Create departments table
-- ============================================================
CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    description TEXT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_departments_status (status),
    INDEX idx_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 3. Add department_id to doctors table
-- ============================================================
ALTER TABLE doctors
ADD COLUMN department_id INT NULL AFTER specialty,
ADD CONSTRAINT fk_doctors_department
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
ADD INDEX idx_doctors_department (department_id);

-- ============================================================
-- 4. Add department_id to appointments table for proper foreign key
-- ============================================================
-- First, add the column
ALTER TABLE appointments
ADD COLUMN department_id INT NULL AFTER doctor_id;

-- Create index for performance
ALTER TABLE appointments
ADD INDEX idx_appointments_department (department_id);

-- Note: We'll populate this after creating departments
-- Foreign key will be added after data migration

-- ============================================================
-- 5. Create admin_audit table for tracking administrative changes
-- ============================================================
CREATE TABLE IF NOT EXISTS admin_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50) NOT NULL,
    entity_id INT NULL,
    old_value TEXT NULL,
    new_value TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_admin (admin_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at),
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- 6. Insert default departments from existing hardcoded values
-- ============================================================
INSERT INTO departments (name, description, status) VALUES
('Cardiology', 'Diagnosis and treatment of heart and cardiovascular conditions', 'active'),
('Dermatology', 'Diagnosis and treatment of skin, hair, and nail conditions', 'active'),
('Neurology', 'Diagnosis and treatment of nervous system and brain disorders', 'active'),
('Pediatrics', 'Medical care for infants, children, and adolescents', 'active'),
('Orthopedics', 'Diagnosis and treatment of musculoskeletal system conditions', 'active'),
('Dentistry', 'Diagnosis and treatment of oral health and dental conditions', 'active'),
('Ophthalmology', 'Diagnosis and treatment of eye and vision conditions', 'active'),
('Gynecology', 'Women''s health and reproductive medicine', 'active'),
('General Practice', 'Primary care and general medical services', 'active');

-- ============================================================
-- 7. Update doctors.department_id based on specialty matching
-- ============================================================
UPDATE doctors d
JOIN departments dept ON d.specialty = dept.name
SET d.department_id = dept.id;

-- ============================================================
-- 8. Update appointments.department_id based on department name matching
-- ============================================================
UPDATE appointments a
JOIN departments dept ON a.department = dept.name
SET a.department_id = dept.id;

-- ============================================================
-- 9. Add foreign key constraint to appointments.department_id
-- ============================================================
ALTER TABLE appointments
ADD CONSTRAINT fk_appointments_department
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;

-- ============================================================
-- 10. Update hospital_settings with default hospital info
-- ============================================================
UPDATE hospital_settings
SET hospital_name = 'HealthBridge Hospital',
    hospital_phone = '+20123456789',
    hospital_email = 'info@healthbridge.com',
    hospital_address = '123 Medical Center Drive, Cairo, Egypt',
    hospital_description = 'Providing quality healthcare services with compassion and excellence.'
WHERE id = 1;
