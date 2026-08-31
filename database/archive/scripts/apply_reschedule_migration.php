<?php
/**
 * HealthBridge — Apply Phase 10 Reschedule Workflow Migration
 * Adds reschedule tracking columns to appointments table
 */
require_once __DIR__ . '/../../../includes/db.php';

echo "=== Applying Reschedule Workflow Migration ===\n";
$db = getDB();

// Check if columns already exist
$stmt = $db->query("DESCRIBE appointments");
$columns = [];
foreach ($stmt as $row) {
    $columns[$row['Field']] = $row;
}

if (!isset($columns['reschedule_status'])) {
    echo "Adding reschedule columns to appointments table...\n";
    
    $db->exec("ALTER TABLE appointments
        ADD COLUMN pending_reschedule_date   DATE         NULL COMMENT 'Requested new date (pending approval)',
        ADD COLUMN pending_reschedule_time   VARCHAR(20)  NULL COMMENT 'Requested new time (pending approval)',
        ADD COLUMN reschedule_reason         TEXT         NULL COMMENT 'Patient reason for rescheduling',
        ADD COLUMN reschedule_requested_at   DATETIME     NULL COMMENT 'When the reschedule was requested',
        ADD COLUMN reschedule_requested_by   INT          NULL COMMENT 'User ID who requested the reschedule',
        ADD COLUMN reschedule_status         ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' COMMENT 'Current reschedule workflow status',
        ADD COLUMN reschedule_responded_at   DATETIME     NULL COMMENT 'When doctor approved/rejected',
        ADD COLUMN reschedule_responded_by   INT          NULL COMMENT 'Doctor user ID who responded',
        ADD COLUMN reschedule_response_notes TEXT         NULL COMMENT 'Doctor notes on approval/rejection',
        ADD INDEX idx_appt_reschedule_status (reschedule_status),
        ADD INDEX idx_appt_reschedule_requested (reschedule_requested_at)");
    
    echo "Added reschedule columns.\n";
} else {
    echo "Reschedule columns already exist.\n";
}

// Update the status ENUM
$stmt = $db->query("SHOW COLUMNS FROM appointments WHERE Field = 'status'");
$row = $stmt->fetch();
$type = $row['Type'] ?? '';
if (strpos($type, 'Reschedule Requested') === false) {
    echo "Updating status ENUM to include 'Reschedule Requested'...\n";
    $db->exec("ALTER TABLE appointments 
        MODIFY COLUMN status ENUM('Pending','Confirmed','Cancelled','Reschedule Requested') NOT NULL DEFAULT 'Pending'");
    echo "Status ENUM updated.\n";
} else {
    echo "Status ENUM already includes 'Reschedule Requested'.\n";
}

echo "\n=== Migration Complete ===\n";