CREATE TABLE IF NOT EXISTS crm_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(150) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'admin',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_users_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_students (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    erp_student_id BIGINT UNSIGNED NOT NULL,
    erp_student_uid VARCHAR(100) NULL,
    admission_no VARCHAR(100) NULL,
    student_name VARCHAR(500) NULL,

    admission_date DATE NULL,
    counsellor_name VARCHAR(1000) NULL,
    counsellor_erp_user_id BIGINT UNSIGNED NULL,
    counsellor_form_assigned_erp_date DATETIME NULL,
    latest_erp_assignment_date DATETIME NULL,

    admission_status_id BIGINT NULL,
    admission_status VARCHAR(50) NULL,
    admission_confirmation_status VARCHAR(150) NULL,
    university VARCHAR(255) NULL,
    session_name VARCHAR(150) NULL,

    phone VARCHAR(100) NULL,
    email VARCHAR(500) NULL,

    approved_payment_received DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    erp_change_stamp BIGINT UNSIGNED NOT NULL DEFAULT 0,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    ineligible_at DATETIME NULL,
    first_synced_at DATETIME NULL,
    last_synced_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_crm_students_erp_student (erp_student_id),
    KEY idx_crm_students_active_status (is_active, admission_status_id),
    KEY idx_crm_students_counsellor (counsellor_erp_user_id),
    KEY idx_crm_students_university (university(100)),
    KEY idx_crm_students_admission_date (admission_date),
    KEY idx_crm_students_phone (phone),
    KEY idx_crm_students_admission_no (admission_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS crm_sync_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trigger_type VARCHAR(30) NOT NULL DEFAULT 'manual',
    status VARCHAR(30) NOT NULL,
    eligible_read INT UNSIGNED NOT NULL DEFAULT 0,
    inserted_count INT UNSIGNED NOT NULL DEFAULT 0,
    updated_count INT UNSIGNED NOT NULL DEFAULT 0,
    deactivated_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    duration_seconds DECIMAL(10,3) NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sync_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
