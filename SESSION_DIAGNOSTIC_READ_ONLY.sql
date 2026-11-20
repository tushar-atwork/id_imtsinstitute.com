-- Run block 1 in your CRM database (e.g., imtsinst_id). Read-only.
SELECT COUNT(*) AS total_records,
 SUM(session_name IS NULL OR TRIM(session_name) = '') AS missing_session,
 SUM(session_name IS NOT NULL AND TRIM(session_name) <> '') AS populated_session
FROM crm_students;
SELECT admission_no, erp_student_id, session_name, last_synced_at
FROM crm_students WHERE session_name IS NULL OR TRIM(session_name) = ''
ORDER BY last_synced_at DESC LIMIT 15;

-- Run block 2 separately in the ERP database specified by your actual LIVE config. Read-only.
SELECT COUNT(*) AS erp_students,
 SUM(s.session_id IS NULL) AS missing_erp_session_id,
 SUM(s.session_id IS NOT NULL AND ses.id IS NULL) AS session_id_without_matching_row,
 SUM(s.session_id IS NOT NULL AND ses.id IS NOT NULL AND ses.deleted_at IS NOT NULL) AS soft_deleted_session,
 SUM(s.session_id IS NOT NULL AND ses.id IS NOT NULL AND ses.deleted_at IS NULL AND (ses.name IS NULL OR TRIM(ses.name)='')) AS matching_session_name_blank,
 SUM(s.session_id IS NOT NULL AND ses.id IS NOT NULL AND ses.deleted_at IS NULL AND ses.name IS NOT NULL AND TRIM(ses.name)<>'') AS matching_session_name_present
FROM imts_erp_student s
LEFT JOIN imts_erp_session ses ON ses.id = s.session_id;
SELECT s.admission_no,s.session_id,ses.name AS erp_session_name,ses.deleted_at
FROM imts_erp_student s
LEFT JOIN imts_erp_session ses ON ses.id = s.session_id
WHERE s.session_id IS NOT NULL LIMIT 15;
