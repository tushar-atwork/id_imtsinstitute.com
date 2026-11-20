-- Optional speed-up for Phase 4 on large follow-up histories.
-- Import ONCE in the NEW CRM database only. Skip if this index already exists.
ALTER TABLE crm_followups ADD INDEX idx_phase4_student_latest (student_id, id);
