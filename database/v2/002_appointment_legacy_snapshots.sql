-- v2.0.1: preserve original appointment date/time display inputs during normalization.
-- Additive to the isolated v2 prototype. No v1 database is modified.
ALTER TABLE appointments
    ADD COLUMN source_time_snapshot VARCHAR(20) NULL AFTER starts_at,
    ADD COLUMN source_time_range_snapshot VARCHAR(30) NULL AFTER source_time_snapshot;
