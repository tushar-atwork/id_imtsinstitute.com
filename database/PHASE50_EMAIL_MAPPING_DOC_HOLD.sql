-- IMTS CRM Phase 50. Import ONLY into the CRM database. Back up first. Safe to re-run.
-- 1) Employee email used to match ERP assignees during sync.
SET @crm_email_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_users' AND COLUMN_NAME='email'), 'SELECT 1', 'ALTER TABLE crm_users ADD COLUMN email VARCHAR(254) NULL DEFAULT NULL AFTER name');
PREPARE crm_email_stmt FROM @crm_email_sql; EXECUTE crm_email_stmt; DEALLOCATE PREPARE crm_email_stmt;
SET @crm_email_idx_sql = IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_users' AND INDEX_NAME='uq_crm_users_email'), 'SELECT 1', 'ALTER TABLE crm_users ADD UNIQUE KEY uq_crm_users_email (email)');
PREPARE crm_email_idx_stmt FROM @crm_email_idx_sql; EXECUTE crm_email_idx_stmt; DEALLOCATE PREPARE crm_email_idx_stmt;

-- 2) Documents hold statuses. Neither one forwards the record to Form Filled or Verification.
INSERT IGNORE INTO crm_team_statuses(team_role,status_label,sort_order,is_active) VALUES
('document_collection','Pending',40,1),
('document_collection','Rejected',50,1);
