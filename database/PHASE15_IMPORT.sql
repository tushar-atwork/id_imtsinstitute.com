-- Import once into the CRM database using phpMyAdmin (MySQL 5.7 compatible).
CREATE TABLE IF NOT EXISTS crm_record_activity (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 student_id BIGINT UNSIGNED NOT NULL,
 actor_user_id BIGINT UNSIGNED NULL,
 event_type VARCHAR(32) NOT NULL,
 area VARCHAR(50) NOT NULL,
 description VARCHAR(1000) NOT NULL,
 ip_address VARCHAR(45) NULL,
 user_agent VARCHAR(255) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_record_timeline(student_id,id),
 KEY idx_record_actor(actor_user_id,created_at),
 KEY idx_record_type(event_type,created_at),
 CONSTRAINT fk_activity_student FOREIGN KEY(student_id) REFERENCES crm_students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- The following triggers capture writes across single and bulk actions, not just student.php.
DELIMITER $$
CREATE TRIGGER audit_team_followup_insert AFTER INSERT ON crm_team_followups FOR EACH ROW
BEGIN
 INSERT INTO crm_record_activity(student_id,actor_user_id,event_type,area,description)
 VALUES(NEW.student_id,NEW.created_by,'followup',NEW.team_role,CONCAT('Team follow-up created; status ID ',NEW.status_id,'; contact ',NEW.contact_result,'; next ',COALESCE(CAST(NEW.next_followup_at AS CHAR),'not set')));
END$$
CREATE TRIGGER audit_legacy_followup_insert AFTER INSERT ON crm_followups FOR EACH ROW
BEGIN
 INSERT INTO crm_record_activity(student_id,actor_user_id,event_type,area,description)
 VALUES(NEW.student_id,NEW.created_by,'followup','legacy',CONCAT('Legacy follow-up created; outcome ',NEW.outcome,'; next ',COALESCE(CAST(NEW.next_followup_at AS CHAR),'not set')));
END$$
CREATE TRIGGER audit_team_assignment_insert AFTER INSERT ON crm_team_assignments FOR EACH ROW
BEGIN
 INSERT INTO crm_record_activity(student_id,actor_user_id,event_type,area,description)
 VALUES(NEW.student_id,NEW.assigned_by,'assignment',NEW.team_role,CONCAT('Assigned user ID: ',COALESCE(CAST(NEW.assigned_user_id AS CHAR),'Unassigned')));
END$$
CREATE TRIGGER audit_team_assignment_update AFTER UPDATE ON crm_team_assignments FOR EACH ROW
BEGIN
 IF NOT (OLD.assigned_user_id <=> NEW.assigned_user_id) THEN
 INSERT INTO crm_record_activity(student_id,actor_user_id,event_type,area,description)
 VALUES(NEW.student_id,NEW.assigned_by,'assignment',NEW.team_role,CONCAT('Owner changed from ',COALESCE(CAST(OLD.assigned_user_id AS CHAR),'Unassigned'),' to ',COALESCE(CAST(NEW.assigned_user_id AS CHAR),'Unassigned')));
 END IF;
END$$
CREATE TRIGGER audit_legacy_assignment_insert AFTER INSERT ON crm_student_assignments FOR EACH ROW
BEGIN
 INSERT INTO crm_record_activity(student_id,actor_user_id,event_type,area,description)
 VALUES(NEW.student_id,NEW.assigned_by,'assignment','legacy',CONCAT('Assigned user ID: ',COALESCE(CAST(NEW.assigned_user_id AS CHAR),'Unassigned')));
END$$
CREATE TRIGGER audit_legacy_assignment_update AFTER UPDATE ON crm_student_assignments FOR EACH ROW
BEGIN
 IF NOT (OLD.assigned_user_id <=> NEW.assigned_user_id) THEN
 INSERT INTO crm_record_activity(student_id,actor_user_id,event_type,area,description)
 VALUES(NEW.student_id,NEW.assigned_by,'assignment','legacy',CONCAT('Owner changed from ',COALESCE(CAST(OLD.assigned_user_id AS CHAR),'Unassigned'),' to ',COALESCE(CAST(NEW.assigned_user_id AS CHAR),'Unassigned')));
 END IF;
END$$
CREATE TRIGGER audit_student_erp_update AFTER UPDATE ON crm_students FOR EACH ROW
BEGIN
 IF NOT (OLD.admission_status <=> NEW.admission_status) OR NOT (OLD.approved_payment_received <=> NEW.approved_payment_received) OR NOT (OLD.is_active <=> NEW.is_active) THEN
 INSERT INTO crm_record_activity(student_id,actor_user_id,event_type,area,description)
 VALUES(NEW.id,NULL,'erp_update','erp',CONCAT('ERP status: ',COALESCE(OLD.admission_status,'—'),' → ',COALESCE(NEW.admission_status,'—'),'; payment ',OLD.approved_payment_received,' → ',NEW.approved_payment_received,'; active ',OLD.is_active,' → ',NEW.is_active));
 END IF;
END$$
DELIMITER ;
