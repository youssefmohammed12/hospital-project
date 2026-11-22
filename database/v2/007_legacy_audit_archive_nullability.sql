-- v2.0.7: preserve nullable descriptions from historical Phase 8 schedule audit rows.
-- Source Phase 8 declares schedule_audit_log.description TEXT NULL.
ALTER TABLE legacy_schedule_audit_log
    MODIFY COLUMN description LONGTEXT NULL;
