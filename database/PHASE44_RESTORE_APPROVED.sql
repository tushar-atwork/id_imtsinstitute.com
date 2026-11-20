-- Run only AFTER Phase42 database migration. Restores visibility, no ownership changes.
UPDATE crm_students s SET s.is_active=1,s.ineligible_at=NULL
WHERE s.is_active=0 AND (s.admission_status_id=3 OR LOWER(TRIM(s.admission_status))='approved'
OR EXISTS (SELECT 1 FROM crm_workflow_stages ws WHERE ws.student_id=s.id AND ws.team_role='verification' AND LOWER(TRIM(ws.outcome)) IN ('verified','approved')));
