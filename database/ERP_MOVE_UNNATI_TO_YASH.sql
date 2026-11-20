-- Move every Incomplete / Pending / Rejected admission whose CURRENT ERP assignee is
-- Unnati Verma (IMTS1468, ERP user 76497) to Yash Parihar (IMTS1803, ERP user 81479).
--
-- RUN ON THE ERP DATABASE (imtsinst_imts_erp_test2), NOT on the CRM database.
-- Run STEP 1 first and check the list. Then run STEP 2 and STEP 3 together in one go.
-- Afterwards run "Sync ERP Now" in the CRM: the records move to yash automatically.
--
-- How the ERP stores an assignment (checked in the dump):
--   imts_erp_student_assigned            = assignment history; current row has last_status=1, times in UTC
--   imts_erp_student_assigned_follow_pdc = one summary row per student (what the ERP lists and the CRM read),
--                                          times in IST (UTC + 5:30)
-- Both are updated so the ERP history and the summary agree.

-- STEP 1: preview (read-only). These are the records that will move.
SELECT sf.student_id, s.admission_no, s.name, sf.follow_status,
       CASE sf.follow_status WHEN 1 THEN 'Incomplete' WHEN 2 THEN 'Pending' WHEN 4 THEN 'Rejected' END AS status_name,
       sf.assigned_at
FROM imts_erp_student_assigned_follow_pdc sf
JOIN (SELECT student_id, MAX(id) id FROM imts_erp_student_assigned_follow_pdc GROUP BY student_id) latest ON latest.id = sf.id
JOIN imts_erp_student s ON s.id = sf.student_id
WHERE sf.assigned_to_user_id = 76497
  AND sf.follow_status IN (1, 2, 4)
  AND s.deleted_at IS NULL
ORDER BY sf.follow_status, s.admission_no;


-- STEP 2: backup copies (keep them until the change is confirmed; drop later).
CREATE TABLE bk_20261113_unnati_pdc AS
  SELECT * FROM imts_erp_student_assigned_follow_pdc WHERE assigned_to_user_id = 76497;
CREATE TABLE bk_20261113_unnati_assigned AS
  SELECT * FROM imts_erp_student_assigned WHERE student_id IN (SELECT student_id FROM bk_20261113_unnati_pdc);


-- STEP 3: the move (same session as the temporary table; run as one block).
CREATE TEMPORARY TABLE tmp_unnati_move (student_id BIGINT PRIMARY KEY)
  SELECT sf.student_id
  FROM imts_erp_student_assigned_follow_pdc sf
  JOIN (SELECT student_id, MAX(id) id FROM imts_erp_student_assigned_follow_pdc GROUP BY student_id) latest ON latest.id = sf.id
  JOIN imts_erp_student s ON s.id = sf.student_id
  WHERE sf.assigned_to_user_id = 76497
    AND sf.follow_status IN (1, 2, 4)
    AND s.deleted_at IS NULL;

START TRANSACTION;

-- close Unnati's current assignment row
UPDATE imts_erp_student_assigned
SET last_status = 0
WHERE last_status = 1
  AND student_id IN (SELECT student_id FROM tmp_unnati_move);

-- new current assignment to Yash (assigned by ERP user 1 = Admin; change if another user should show)
INSERT INTO imts_erp_student_assigned (uid, student_id, assigned_to_user_id, assigned_by_user_id, message, assigned_at, last_status)
SELECT CONCAT(REPLACE(UUID(), '-', ''), REPLACE(UUID(), '-', '')), student_id, 81479, 1,
       'Unnati has left. Records transferred to Yash.', UTC_TIMESTAMP(), 1
FROM tmp_unnati_move;

-- summary row the ERP lists and the CRM sync reads (IST time)
UPDATE imts_erp_student_assigned_follow_pdc sf
JOIN tmp_unnati_move t ON t.student_id = sf.student_id
SET sf.assigned_to_user_id   = 81479,
    sf.assigned_to_user_name = 'Yash Parihar (IMTS1803)',
    sf.assigned_by_user_id   = 1,
    sf.assigned_by_user_name = 'Admin',
    sf.assigned_message      = 'Unnati has left. Records transferred to Yash.',
    sf.assigned_at           = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 330 MINUTE)
WHERE sf.assigned_to_user_id = 76497;

COMMIT;

-- Check: should return 0 rows for Unnati and the same count (from STEP 1) for Yash's new rows.
SELECT COUNT(*) AS still_with_unnati FROM imts_erp_student_assigned_follow_pdc sf
JOIN tmp_unnati_move t ON t.student_id = sf.student_id WHERE sf.assigned_to_user_id = 76497;
SELECT COUNT(*) AS moved_to_yash FROM imts_erp_student_assigned_follow_pdc sf
JOIN tmp_unnati_move t ON t.student_id = sf.student_id WHERE sf.assigned_to_user_id = 81479;


-- UNDO (only if needed): put both tables back from the backups.
-- START TRANSACTION;
-- DELETE a FROM imts_erp_student_assigned a JOIN bk_20261113_unnati_pdc b ON b.student_id = a.student_id
--   WHERE a.assigned_to_user_id = 81479 AND a.message = 'Unnati has left. Records transferred to Yash.';
-- UPDATE imts_erp_student_assigned a JOIN bk_20261113_unnati_assigned b ON b.id = a.id SET a.last_status = b.last_status;
-- UPDATE imts_erp_student_assigned_follow_pdc a JOIN bk_20261113_unnati_pdc b ON b.id = a.id
--   SET a.assigned_to_user_id = b.assigned_to_user_id, a.assigned_to_user_name = b.assigned_to_user_name,
--       a.assigned_by_user_id = b.assigned_by_user_id, a.assigned_by_user_name = b.assigned_by_user_name,
--       a.assigned_message = b.assigned_message, a.assigned_at = b.assigned_at;
-- COMMIT;
