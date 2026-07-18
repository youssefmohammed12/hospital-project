<?php
/**
 * HealthBridge — Database Migration: Medical History Updates Table
 * Phase 5.3.2
 * 
 * Run this file once to create the medical_history_updates table.
 * After running, delete this file.
 */

require_once __DIR__ . '/../../includes/auth.php';

try {
    $db = getDB();
    
    $sql = "CREATE TABLE IF NOT EXISTS medical_history_updates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        patient_id INT NOT NULL,
        doctor_id INT NOT NULL,
        field_name VARCHAR(50) NOT NULL,
        old_value TEXT NULL,
        new_value TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mhu_patient (patient_id),
        INDEX idx_mhu_doctor (doctor_id),
        INDEX idx_mhu_created (created_at),
        CONSTRAINT fk_mhu_patient FOREIGN KEY (patient_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_mhu_doctor FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $db->exec($sql);
    
    echo "<h3>Migration Successful</h3>";
    echo "<p>The medical_history_updates table has been created successfully.</p>";
    echo "<p><strong>Important:</strong> Delete this migration file after verification.</p>";
    
} catch (Exception $e) {
    echo "<h3>Migration Failed</h3>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
