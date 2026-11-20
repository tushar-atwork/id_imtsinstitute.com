-- Phase 54: record sorting by sync time + active/inactive employees + per-team assignment mode.
-- Back up the CRM database first. Safe to re-run. Works on MySQL 5.7+/8.x and MariaDB (no "IF NOT EXISTS" in ALTER).
-- Touches only the CRM database. The ERP database is never modified.

-- Feature 1: sync timestamps already exist (first_synced_at = when the record was first synced from ERP,
-- last_synced_at = refreshed on every re-sync). Backfill any empty value and index the sort column.
UPDATE crm_students SET first_synced_at = created_at WHERE first_synced_at IS NULL;
UPDATE crm_students SET last_synced_at = COALESCE(first_synced_at, created_at) WHERE last_synced_at IS NULL;

SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE crm_students ADD INDEX idx_crm_students_first_synced (first_synced_at)',
  'SELECT 1')
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_students' AND INDEX_NAME = 'idx_crm_students_first_synced');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Feature 2: crm_users.is_active already exists (kept here so a fresh database also gets it).
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE crm_users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_users' AND COLUMN_NAME = 'is_active');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1 = admin chose "leave records unchanged" when deactivating; the sync must not release them.
SET @sql = (SELECT IF(COUNT(*) = 0,
  'ALTER TABLE crm_users ADD COLUMN keep_records_when_inactive TINYINT(1) NOT NULL DEFAULT 0',
  'SELECT 1')
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'crm_users' AND COLUMN_NAME = 'keep_records_when_inactive');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
