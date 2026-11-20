-- IMTS CRM Phase 11. Import ONLY into the NEW CRM database (imtsinst_id).
-- Non-destructive; existing assignments and follow-ups remain untouched.
CREATE TABLE IF NOT EXISTS crm_team_assignments (
  student_id BIGINT UNSIGNED NOT NULL,
  team_role VARCHAR(40) NOT NULL,
  assigned_user_id BIGINT UNSIGNED DEFAULT NULL,
  assigned_by BIGINT UNSIGNED DEFAULT NULL,
  assigned_at DATETIME DEFAULT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY(student_id, team_role),
  KEY idx_team_assignment_user (team_role, assigned_user_id),
  CONSTRAINT fk_team_assignment_student FOREIGN KEY(student_id) REFERENCES crm_students(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_assignment_user FOREIGN KEY(assigned_user_id) REFERENCES crm_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_team_statuses (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  team_role VARCHAR(40) NOT NULL,
  status_label VARCHAR(180) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_team_status_label (team_role, status_label(150)),
  KEY idx_team_status_active (team_role,is_active,sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_team_followups (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  team_role VARCHAR(40) NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  status_id BIGINT UNSIGNED NOT NULL,
  contact_result VARCHAR(30) NOT NULL,
  delay_reason TEXT NULL,
  remark_for_verification TEXT NULL,
  remarks TEXT NULL,
  next_followup_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_team_followup_student (student_id,team_role,id),
  KEY idx_team_followup_due (team_role,next_followup_at),
  KEY idx_team_followup_user (created_by,team_role,created_at),
  CONSTRAINT fk_team_followup_student FOREIGN KEY(student_id) REFERENCES crm_students(id) ON DELETE CASCADE,
  CONSTRAINT fk_team_followup_creator FOREIGN KEY(created_by) REFERENCES crm_users(id),
  CONSTRAINT fk_team_followup_status FOREIGN KEY(status_id) REFERENCES crm_team_statuses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_team_assignment_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  student_id BIGINT UNSIGNED NOT NULL,
  team_role VARCHAR(40) NOT NULL,
  previous_user_id BIGINT UNSIGNED NULL,
  new_user_id BIGINT UNSIGNED NULL,
  changed_by BIGINT UNSIGNED NOT NULL,
  changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_team_hist_student (student_id,team_role,changed_at),
  CONSTRAINT fk_team_hist_student FOREIGN KEY(student_id) REFERENCES crm_students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO crm_team_statuses(team_role,status_label,sort_order) VALUES
('document_collection','Send with All Completed Docx',10),
('document_collection','Send with Docs pending by students',20),
('document_collection','send with Docks pending by IMTS',30),
('verification','Form filled',10),
('verification','Form pending',20),
('verification','Correction required',30);
