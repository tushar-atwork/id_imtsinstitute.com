-- Run once in NEW CRM database imtsinst_id. No ERP tables are changed.
CREATE TABLE IF NOT EXISTS crm_assignment_history (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 student_id BIGINT UNSIGNED NOT NULL,
 previous_user_id BIGINT UNSIGNED NULL,
 new_user_id BIGINT UNSIGNED NULL,
 changed_by BIGINT UNSIGNED NOT NULL,
 changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_student_date(student_id,changed_at),
 KEY idx_changed_by(changed_by),
 CONSTRAINT fk_p3_history_student FOREIGN KEY(student_id) REFERENCES crm_students(id) ON DELETE CASCADE,
 CONSTRAINT fk_p3_history_actor FOREIGN KEY(changed_by) REFERENCES crm_users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
