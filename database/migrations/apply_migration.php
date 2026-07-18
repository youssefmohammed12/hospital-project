<?php
/**
 * HealthBridge — Apply Phase 7 Context Columns Migration
 * Adds patient_id and doctor_id contextual columns to admin_audit
 */
require_once __DIR__ . '/../../includes/auth.php';

echo "=== Checking admin_audit table ===\n";
$db = getDB();

// Check current schema
$stmt = $db->query("DESCRIBE admin_audit");
$columns = [];
foreach ($stmt as $row) {
    $columns[$row['Field']] = $row;
    echo implode(' | ', $row) . "\n";
}

// Add patient_id context column if missing
if (!isset($columns['patient_id'])) {
    echo "\nAdding patient_id column...\n";
    $db->exec("ALTER TABLE admin_audit 
        ADD COLUMN patient_id INT NULL AFTER entity_id,
        ADD INDEX idx_audit_patient (patient_id)");
    echo "Added patient_id column\n";
} else {
    echo "\npatient_id column already exists\n";
}

// Add doctor_id context column if missing
if (!isset($columns['doctor_id'])) {
    echo "Adding doctor_id column...\n";
    $db->exec("ALTER TABLE admin_audit 
        ADD COLUMN doctor_id INT NULL AFTER patient_id,
        ADD INDEX idx_audit_doctor (doctor_id)");
    echo "Added doctor_id column\n";
} else {
    echo "doctor_id column already exists\n";
}
