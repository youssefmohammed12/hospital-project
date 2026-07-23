-- ============================================================
-- HealthBridge — Phase 10: Appointment Rescheduling Workflow
-- 
-- Extends the appointments table with reschedule tracking fields.
-- Does NOT modify existing columns or destroy appointment history.
-- ============================================================

-- Add reschedule tracking fields to appointments table
ALTER TABLE appointments
  ADD COLUMN IF NOT EXISTS pending_reschedule_date   DATE         NULL COMMENT 'Requested new date (pending approval)',
  ADD COLUMN IF NOT EXISTS pending_reschedule_time   VARCHAR(20)  NULL COMMENT 'Requested new time (pending approval)',
  ADD COLUMN IF NOT EXISTS reschedule_reason         TEXT         NULL COMMENT 'Patient reason for rescheduling',
  ADD COLUMN IF NOT EXISTS reschedule_requested_at   DATETIME     NULL COMMENT 'When the reschedule was requested',
  ADD COLUMN IF NOT EXISTS reschedule_requested_by   INT          NULL COMMENT 'User ID who requested the reschedule',
  ADD COLUMN IF NOT EXISTS reschedule_status         ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none' COMMENT 'Current reschedule workflow status',
  ADD COLUMN IF NOT EXISTS reschedule_responded_at   DATETIME     NULL COMMENT 'When doctor approved/rejected',
  ADD COLUMN IF NOT EXISTS reschedule_responded_by   INT          NULL COMMENT 'Doctor user ID who responded',
  ADD COLUMN IF NOT EXISTS reschedule_response_notes TEXT         NULL COMMENT 'Doctor notes on approval/rejection',
  ADD INDEX IF NOT EXISTS idx_appt_reschedule_status (reschedule_status),
  ADD INDEX IF NOT EXISTS idx_appt_reschedule_requested (reschedule_requested_at);

-- Update the appointments status ENUM to include 'Reschedule Requested'
-- MySQL does not support removing ENUM values safely, so we modify the column
ALTER TABLE appointments 
  MODIFY COLUMN status ENUM('Pending','Confirmed','Cancelled','Reschedule Requested') NOT NULL DEFAULT 'Pending';