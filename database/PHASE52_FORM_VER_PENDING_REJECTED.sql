-- IMTS CRM Phase 52. Import ONLY into the CRM database (imtsinst_id). Safe to re-run.
-- Adds the Pending / Rejected statuses for Form Filled and Verification.
-- Form Filled already has "Form pending" (counted as Pending); Verification already has "Rejected".
-- Neither status forwards the record to the next department.
INSERT IGNORE INTO crm_team_statuses(team_role,status_label,sort_order,is_active) VALUES
('form_filled','Rejected',40,1),
('verification','Pending',30,1);
