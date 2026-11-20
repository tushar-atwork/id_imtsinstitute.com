Phase50 — Documents Pending/Rejected, multi-select filters, ERP email owner mapping, forwarded records leave the Documents list
Requires Phase42 schema. Back up the CRM database, then import database/PHASE50_EMAIL_MAPPING_DOC_HOLD.sql (safe to re-run) BEFORE uploading app/, public/ and config/.

1. Documents status "Pending" and 2. "Rejected"
- The SQL adds both statuses to the Documents team (edit order/active in Team settings > Document statuses).
- While the latest Documents follow-up is Pending or Rejected, the record is never assigned or forwarded to Form Filled or Verification: not by handoff, not by ERP sync, and not by admin assignment (single or bulk; bulk skips and reports them).
- Pending and Rejected records have their own lists on Admissions and in the Documents team queue. Changing the Documents status later releases the hold.
- Matching is on the status text: "Pending", "Rejected" or "Reject" (case-insensitive). Renaming these statuses removes the hold behaviour.

3. Multi-select filters
- Every filter dropdown on Admissions, Team queue and Follow-ups now accepts several values (records matching any selected value are shown). Empty = All.
- assets/multi-select.js shows them as checkbox dropdowns with search; without JavaScript they work as normal multi-select lists.

4. ERP-to-CRM owner mapping by email
- Enter each employee's email in Team settings > Employees (or when creating the employee). It must match that person's email in the ERP user table.
- During sync, each record's ERP assignee (latest imts_erp_student_assigned_follow_pdc.assigned_to_user_id) is looked up in imts_erp_user; the active CRM Documents / Form Filled / Verification employee with the same email gets the record in their own department. This overrides the current owner of that department; other departments are not changed.
- Round robin runs only when no CRM employee matches (and only fills an empty Documents owner).
- The ERP email column defaults to "email"; change sync.erp_user_email_column in config/local.php if it is named differently. Sync Health shows whether the column was found and how many CRM employees have an email.
- The old sync.erp_owner_map (ERP id => CRM id) is kept only as a fallback for assignees without an email match.

5. Forwarded records leave the Documents list
- When Documents saves one of the "Send with ..." statuses the record is forwarded to Form Filled (round robin or ERP email owner) and disappears from the Documents employee's active Admissions list and Documents queue.
- Forwarded records stay available under "Forwarded (completed)" and in the student's history/timeline.
- Admins get the same lists as tabs, with "All records" as the default.

Previous phases: see Phase49 notes (Documents-first assignment, CRM data reset) — unchanged.
Static review only; PHP/MySQL/ERP integration was not available here. Test on a backup/staging copy first.
