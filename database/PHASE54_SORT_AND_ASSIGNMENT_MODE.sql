-- Phase 54: record sorting by sync time + active/inactive employees + per-team assignment mode.
-- Back up the CRM database first. Safe to re-run. Requires MariaDB 10.0.2+ (IF NOT EXISTS) as used by cPanel/phpMyAdmin here.
-- Touches only the CRM database. The ERP database is never modified.

-- Feature 1: sync timestamps already exist (first_synced_at = when the record was first synced from ERP,
-- last_synced_at = refreshed on every re-sync). Backfill any empty value and index the sort column.
UPDATE crm_students SET first_synced_at = created_at WHERE first_synced_at IS NULL;
UPDATE crm_students SET last_synced_at = COALESCE(first_synced_at, created_at) WHERE last_synced_at IS NULL;
ALTER TABLE crm_students ADD INDEX IF NOT EXISTS idx_crm_students_first_synced (first_synced_at);

-- Feature 2: crm_users.is_active already exists (kept here so a fresh database also gets it).
ALTER TABLE crm_users ADD COLUMN IF NOT EXISTS is_active TINYINT(1) NOT NULL DEFAULT 1;
-- 1 = admin chose "leave records unchanged" when deactivating; the sync must not release them.
ALTER TABLE crm_users ADD COLUMN IF NOT EXISTS keep_records_when_inactive TINYINT(1) NOT NULL DEFAULT 0;

-- Assignment mode per team. 'auto' = current behaviour (ERP assignee, else round robin among active users).
CREATE TABLE IF NOT EXISTS crm_team_settings (
  team_role VARCHAR(40) NOT NULL,
  assignment_mode VARCHAR(10) NOT NULL DEFAULT 'auto',
  updated_by BIGINT UNSIGNED DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (team_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO crm_team_settings (team_role, assignment_mode) VALUES
  ('document_collection','auto'),('form_filled','auto'),('verification','auto');
