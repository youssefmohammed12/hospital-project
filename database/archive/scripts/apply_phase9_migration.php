<?php
/**
 * CLI script to apply Phase 9 database migration.
 * Run: php tools/apply_phase9_migration.php
 */
require_once __DIR__ . '/../../../includes/db.php';

echo "Applying Phase 9 migration...\n";

try {
    $db = getDB();
    
    // Add columns to users table
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL AFTER name");
    echo "  - users.first_name: OK\n";
    
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) NULL AFTER first_name");
    echo "  - users.last_name: OK\n";
    
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS patient_number VARCHAR(50) NULL AFTER role");
    echo "  - users.patient_number: OK\n";
    
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS national_id VARCHAR(50) NULL AFTER patient_number");
    echo "  - users.national_id: OK\n";
    
    // Add columns to medical_records table
    $db->exec("ALTER TABLE medical_records ADD COLUMN IF NOT EXISTS governorate VARCHAR(100) NULL AFTER emergency_contact_phone");
    echo "  - medical_records.governorate: OK\n";
    
    $db->exec("ALTER TABLE medical_records ADD COLUMN IF NOT EXISTS city VARCHAR(100) NULL AFTER governorate");
    echo "  - medical_records.city: OK\n";
    
    $db->exec("ALTER TABLE medical_records ADD COLUMN IF NOT EXISTS address TEXT NULL AFTER city");
    echo "  - medical_records.address: OK\n";
    
    $db->exec("ALTER TABLE medical_records ADD COLUMN IF NOT EXISTS insurance_provider VARCHAR(200) NULL AFTER address");
    echo "  - medical_records.insurance_provider: OK\n";
    
    $db->exec("ALTER TABLE medical_records ADD COLUMN IF NOT EXISTS insurance_number VARCHAR(100) NULL AFTER insurance_provider");
    echo "  - medical_records.insurance_number: OK\n";
    
    // Set patient_number for existing users
    $db->exec("UPDATE users SET patient_number = CONCAT('HB-', YEAR(created_at), '-', LPAD(id, 6, '0')) WHERE patient_number IS NULL");
    echo "  - patient_number generated: OK\n";
    
    // Set first_name for existing users
    $db->exec("UPDATE users SET first_name = SUBSTRING_INDEX(name, ' ', 1) WHERE first_name IS NULL");
    $db->exec("UPDATE users SET last_name = SUBSTRING_INDEX(name, ' ', -1) WHERE last_name IS NULL AND name LIKE '% %'");
    echo "  - first_name/last_name extracted: OK\n";
    
    echo "\nPhase 9 migration applied successfully!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}