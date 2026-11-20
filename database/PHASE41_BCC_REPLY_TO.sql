-- Select the existing CRM database in phpMyAdmin before importing.
-- Requires the Phase 37 SendGrid settings table. Safe to rerun on MySQL 5.7.
SET @crm_bcc_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_sendgrid_settings' AND COLUMN_NAME='bcc_email'), 'SELECT 1', 'ALTER TABLE crm_sendgrid_settings ADD COLUMN bcc_email VARCHAR(254) NOT NULL DEFAULT ''''');
PREPARE crm_bcc_stmt FROM @crm_bcc_sql;
EXECUTE crm_bcc_stmt;
DEALLOCATE PREPARE crm_bcc_stmt;
SET @crm_reply_sql = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='crm_sendgrid_settings' AND COLUMN_NAME='reply_to_email'), 'SELECT 1', 'ALTER TABLE crm_sendgrid_settings ADD COLUMN reply_to_email VARCHAR(254) NOT NULL DEFAULT ''''');
PREPARE crm_reply_stmt FROM @crm_reply_sql;
EXECUTE crm_reply_stmt;
DEALLOCATE PREPARE crm_reply_stmt;
