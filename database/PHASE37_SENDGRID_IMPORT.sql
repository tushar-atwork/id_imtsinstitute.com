-- Import once into the existing CRM database via phpMyAdmin.
CREATE TABLE IF NOT EXISTS crm_sendgrid_settings (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 sender_email VARCHAR(254) NOT NULL,
 sender_name VARCHAR(120) NOT NULL,
 api_key_cipher TEXT NOT NULL,
 updated_by BIGINT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS crm_email_send_log (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 student_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 recipient_email VARCHAR(254) NOT NULL,
 subject VARCHAR(200) NOT NULL,
 status VARCHAR(20) NOT NULL,
 provider_message_id VARCHAR(255) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_sender_time (user_id,created_at),
 INDEX idx_student_time (student_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
