-- Diagnostic only (read-only). Run on the ERP database.
-- These are the 71 records the CRM showed as Pradosh / Documents / Pending (active, not forwarded) on 2026-11-13.
-- The query lists the ones whose LATEST ERP follow row is NOT assigned to Pradosh (ERP user 77439).
-- Expected: about 16 rows. These are the records the fixed sync takes away from Pradosh.
SELECT sf.student_id, s.admission_no, sf.follow_status, sf.assigned_to_user_id, u.full_name AS erp_assignee, u.email AS erp_assignee_email
FROM imts_erp_student_assigned_follow_pdc sf
JOIN (
  SELECT student_id, MAX(id) id FROM imts_erp_student_assigned_follow_pdc
  WHERE student_id IN (33996,42474,43315,45099,45136,45254,45431,45601,45804,45871,45889,45895,46249,46274,46325,46388,46389,46402,46432,46450,46457,46466,46483,46489,46587,46596,46681,46689,46707,46730,46825,46856,46870,46885,46892,46908,46924,46985,46988,46989,46992,46996,46999,47017,47028,47030,47046,47047,47052,47064,47079,47084,47100,47138,47149,47166,47185,47188,47189,47202,47158,47186,47194,47195,47204,47210,47212,47214,47215,47218,47223)
  GROUP BY student_id
) latest ON latest.id = sf.id
JOIN imts_erp_student s ON s.id = sf.student_id
LEFT JOIN imts_erp_user u ON u.id = sf.assigned_to_user_id
WHERE sf.assigned_to_user_id IS NULL OR sf.assigned_to_user_id <> 77439 OR sf.follow_status <> 2
ORDER BY sf.student_id;
