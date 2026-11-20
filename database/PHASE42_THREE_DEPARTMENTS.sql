-- MySQL 5.7+. Back up the CRM database first. Import before PHP files.
CREATE TABLE IF NOT EXISTS crm_workflow_migrations (version INT PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS crm_workflow_rotation (team_role VARCHAR(40) PRIMARY KEY,last_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS crm_workflow_stages (
 student_id BIGINT UNSIGNED NOT NULL,team_role VARCHAR(40) NOT NULL,entered_at DATETIME NOT NULL,
 completed_at DATETIME NULL,completed_by BIGINT UNSIGNED NULL,outcome VARCHAR(180) NULL,
 PRIMARY KEY(student_id,team_role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
START TRANSACTION;
-- Existing Verification accounts actually performed Form Filled work.
UPDATE crm_users SET role='form_filled' WHERE role='verification' AND NOT EXISTS(SELECT 1 FROM crm_workflow_migrations WHERE version=42);
UPDATE crm_team_assignments SET team_role='form_filled' WHERE team_role='verification' AND NOT EXISTS(SELECT 1 FROM crm_workflow_migrations WHERE version=42);
UPDATE crm_team_assignment_history SET team_role='form_filled' WHERE team_role='verification' AND NOT EXISTS(SELECT 1 FROM crm_workflow_migrations WHERE version=42);
UPDATE crm_team_followups SET team_role='form_filled' WHERE team_role='verification' AND NOT EXISTS(SELECT 1 FROM crm_workflow_migrations WHERE version=42);
UPDATE crm_team_statuses SET team_role='form_filled' WHERE team_role='verification' AND NOT EXISTS(SELECT 1 FROM crm_workflow_migrations WHERE version=42);
UPDATE crm_record_activity SET area='form_filled' WHERE area='verification' AND NOT EXISTS(SELECT 1 FROM crm_workflow_migrations WHERE version=42);
INSERT IGNORE INTO crm_team_statuses(team_role,status_label,sort_order,is_active) VALUES ('verification','Verified',10,1),('verification','Rejected',20,1);
UPDATE crm_team_statuses SET status_label='Send with Docs pending by IMTS' WHERE team_role='document_collection' AND LOWER(status_label)='send with docks pending by imts';
INSERT IGNORE INTO crm_workflow_rotation(team_role) VALUES('document_collection'),('form_filled'),('verification');
-- Preserve known historical intake; never claim sync time was the admission time.
INSERT IGNORE INTO crm_workflow_stages(student_id,team_role,entered_at)
SELECT s.id,'document_collection',COALESCE((SELECT MIN(a.assigned_at) FROM crm_team_assignments a WHERE a.student_id=s.id AND a.team_role='document_collection'),(SELECT MIN(f.created_at) FROM crm_team_followups f WHERE f.student_id=s.id AND f.team_role='document_collection'),s.first_synced_at) FROM crm_students s;
-- Recover handoffs from recorded statuses, including the old IMTS spelling.
UPDATE crm_workflow_stages t JOIN (SELECT f.student_id,MIN(f.id) id FROM crm_team_followups f JOIN crm_team_statuses st ON st.id=f.status_id WHERE f.team_role='document_collection' AND LOWER(TRIM(st.status_label)) IN ('send with all completed docx','send with docs pending by students','send with docs pending by imts','send with docks pending by imts') GROUP BY f.student_id) h ON h.student_id=t.student_id JOIN crm_team_followups f ON f.id=h.id JOIN crm_team_statuses st ON st.id=f.status_id SET t.completed_at=f.created_at,t.completed_by=f.created_by,t.outcome=st.status_label WHERE t.team_role='document_collection' AND t.completed_at IS NULL;
INSERT IGNORE INTO crm_workflow_stages(student_id,team_role,entered_at) SELECT student_id,'form_filled',completed_at FROM crm_workflow_stages WHERE team_role='document_collection' AND completed_at IS NOT NULL;
INSERT IGNORE INTO crm_workflow_stages(student_id,team_role,entered_at) SELECT student_id,'form_filled',MIN(created_at) FROM crm_team_followups WHERE team_role='form_filled' GROUP BY student_id;
UPDATE crm_workflow_stages t JOIN (SELECT f.student_id,MIN(f.id) id FROM crm_team_followups f JOIN crm_team_statuses st ON st.id=f.status_id WHERE f.team_role='form_filled' AND LOWER(TRIM(st.status_label))='form filled' GROUP BY f.student_id) h ON h.student_id=t.student_id JOIN crm_team_followups f ON f.id=h.id SET t.completed_at=f.created_at,t.completed_by=f.created_by,t.outcome='Form filled' WHERE t.team_role='form_filled' AND t.completed_at IS NULL;
INSERT IGNORE INTO crm_workflow_stages(student_id,team_role,entered_at) SELECT student_id,'verification',completed_at FROM crm_workflow_stages WHERE team_role='form_filled' AND completed_at IS NOT NULL;
INSERT IGNORE INTO crm_workflow_migrations(version) VALUES(42);
COMMIT;
