<?php
/** Friendly "no access" page. Not wired into any logic yet; pages can link or redirect here. */
require_once __DIR__ . '/../app/ui/layout.php';
ui_error_page(403, 'You don’t have access to this page', 'This area is for another role. If you think you should have access, ask your administrator.', 'index.php', 'Back to my work');
