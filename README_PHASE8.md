# Phase 8 - repair all routes shown as File not found in the supplied screen recording

## Video observations
- Main admissions page works and shows 303 eligible records (255 pending, 48 rejected).
- Clicking a student row opens /student.php?id=... and shows `File not found.`
- /workspace.php and /reports.php also show `File not found.`
- Other Phase 2-6 pages use the same missing-root-routing pattern and should be checked after the fix.

## cPanel deployment (no terminal)
1. Back up existing files and database. In cPanel File Manager, locate the **actual document root** for id.imtsinstitute.com. Verify that the root contains the `public/` folder and `app/` folder. If not, stop and locate the correct root; do not paste the rules in the wrong folder.
2. Upload the contents of this ZIP's `public/` folder into the site's existing `public/` folder. Do not upload the entire ZIP as a nested `public/public/` folder. The package includes all Phase 1-7 page versions and missing phase3-6 CSS. If you made changes on the live server after Phase 7, merge rather than overwrite those files.
3. Open the .htaccess **in the domain document root** (same folder as `public/`), not inside `public/`. Make a copy first. Add the contents of `ROOT_HTACCESS_INSERT_ABOVE_OLD_RULES.txt` immediately below the existing `RewriteEngine On` and above the old routing rules. Do not replace the PHP handler; PHP 8.3+ is required.
4. If your domain document root is already the `public/` folder, DO NOT add these rewrite rules; just upload the files directly to that document root. A missing page then indicates incorrect placement or a different document root.
5. In a new private/incognito browser tab, test `https://id.imtsinstitute.com/student.php?id=302` (use an existing ID), `/workspace.php`, `/reports.php`, `/followups.php`, `/bulk-assign.php`, `/performance.php`, `/assignment-history.php`, `/sync-health.php`. Check administrator permissions for admin-only pages.
6. If `/public/student.php?id=302` works but `/student.php?id=302` does not, the root .htaccess is still in the wrong location or its rules are being overridden. If both fail, check the `public/student.php` file placement. If you get HTTP 500, use cPanel Metrics > Errors and share the exact PHP error (redact credentials).
7. Hard-refresh (Ctrl+F5) to load updated styles. No SQL import needed. Do not rerun ERP sync solely to repair URLs.

## Important
The video proves 404 routing errors, not database corruption. The package fixes the missing route rules and supplies the corresponding page files, but cannot verify your live cPanel document root or server configuration. Do not delete any live files or reimport the CRM database.
