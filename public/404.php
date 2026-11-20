<?php
/** Friendly "not found" page. Not wired into any logic yet; pages can link or redirect here. */
require_once __DIR__ . '/../app/ui/layout.php';
ui_error_page(404, 'We can’t find that page', 'The link may be old or the record may have been removed. Try searching from the home page.', 'index.php', 'Back to home');
