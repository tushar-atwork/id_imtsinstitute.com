-- Import once in CRM database using phpMyAdmin. Safe: no existing records deleted.
ALTER TABLE crm_students ADD COLUMN erp_verified_at DATETIME NULL DEFAULT NULL;
CREATE INDEX idx_crm_students_erp_verified ON crm_students(erp_verified_at);
