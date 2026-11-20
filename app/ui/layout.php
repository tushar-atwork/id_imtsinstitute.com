<?php
declare(strict_types=1);
/**
 * Shared UI shell (presentation only). Pages call ui_start([...]) before their content and ui_end() after.
 * Menus are filtered by role purely for convenience; every page still enforces its own server-side access checks.
 * Assets are referenced relative to public/ (pages live in public/).
 */
function ui_e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function ui_icon(string $name, int $size = 18): string {
    static $p = [
        'home' => '<path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6 6.5-6s6.5 2.4 6.5 6"/><path d="M16 4.5a3.5 3.5 0 010 7M18 14.4c2.2.6 3.5 2.4 3.5 5.6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'assign' => '<path d="M4 7h12M4 12h8M4 17h12"/><path d="M18 14l3 3-3 3"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'sync' => '<path d="M20 11a8 8 0 00-14-4L4 9M4 4v5h5M4 13a8 8 0 0014 4l2-2M20 20v-5h-5"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.9l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.9-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.9.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.9 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.9l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.9.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.9-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.9V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
        'history' => '<path d="M3 12a9 9 0 109-9 9 9 0 00-7 3.3L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'alert' => '<path d="M12 3l10 18H2L12 3z"/><path d="M12 10v5M12 18h.01"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'bell' => '<path d="M6 8a6 6 0 1112 0c0 7 3 8 3 8H3s3-1 3-8M10 20a2 2 0 004 0"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'logout' => '<path d="M9 4H5a2 2 0 00-2 2v12a2 2 0 002 2h4M16 17l5-5-5-5M21 12H9"/>',
        'chevron' => '<path d="M6 9l6 6 6-6"/>',
        'panel' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'phone' => '<path d="M22 16.9v3a2 2 0 01-2.2 2 19.8 19.8 0 01-8.6-3.1 19.5 19.5 0 01-6-6A19.8 19.8 0 012.1 4.2 2 2 0 014.1 2h3a2 2 0 012 1.7c.1 1 .4 1.9.7 2.8a2 2 0 01-.5 2.1L8.1 9.9a16 16 0 006 6l1.3-1.3a2 2 0 012.1-.4c.9.3 1.8.6 2.8.7a2 2 0 011.7 2z"/>',
        'chat' => '<path d="M21 12a8 8 0 01-11.8 7L3 21l2-5.2A8 8 0 1121 12z"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
        'note' => '<path d="M14 3H6a2 2 0 00-2 2v14a2 2 0 002 2h12a2 2 0 002-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5h13L22 12v6a2 2 0 01-2 2H4a2 2 0 01-2-2v-6z"/>',
        'shield' => '<path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/>',
    ];
    $d = $p[$name] ?? $p['inbox'];
    return '<svg class="ico" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

/** Where a user lands after login: admin to the Dashboard, team employees to My Work, other roles to the records page. */
function ui_landing_url(PDO $db, int $userId): string {
    $q = $db->prepare('SELECT role FROM crm_users WHERE id=? LIMIT 1');
    $q->execute([$userId]);
    $role = (string)$q->fetchColumn();
    if ($role === 'admin') return 'dashboard.php';
    return in_array($role, ['document_collection', 'form_filled', 'verification'], true) ? 'my-work.php' : 'index.php';
}

function ui_role_label(string $role): string {
    return ['admin' => 'Administrator', 'document_collection' => 'Documents team', 'form_filled' => 'Form Filled team', 'verification' => 'Verification team'][$role] ?? ucfirst(str_replace('_', ' ', $role));
}

/**
 * Status badge from a status label: green = completed/verified, amber = pending, red = rejected,
 * grey = forwarded, blue = new / in progress. Colour is presentation only; the label text is what the data says.
 */
function ui_status_badge(?string $label, string $emptyLabel = 'New', bool $forwarded = false): string {
    $text = trim((string)$label);
    if ($text === '') { return '<span class="badge b-blue">' . ui_e($emptyLabel) . '</span>'; }
    $l = strtolower($text);
    $cls = 'b-blue';
    if ($forwarded) $cls = 'b-grey';
    elseif (str_contains($l, 'reject')) $cls = 'b-red';
    elseif (str_contains($l, 'pending')) $cls = 'b-amber';
    elseif (str_contains($l, 'verified') || str_contains($l, 'complete') || $l === 'form filled' || $l === 'approved') $cls = 'b-green';
    return '<span class="badge ' . $cls . '">' . ui_e($text) . '</span>';
}

/** Menu groups for a role. Item: [label, href, icon] or ['post'=>true] for the Run sync form. */
function ui_menu(string $role): array {
    if ($role === 'admin') {
        return [
            ['Work', [['Dashboard', 'dashboard.php', 'home'], ['Records', 'index.php', 'users'], ['Follow-ups', 'followups.php', 'clock'], ['Team queue', 'team-queue.php', 'users']]],
            ['Assignment', [['Assign (single owner)', 'bulk-assign.php', 'assign'], ['Assign by team', 'team-bulk-assign.php', 'assign']]],
            ['Reports', [['Analysis', 'analytics.php', 'chart'], ['Work report', 'work-report.php', 'chart'], ['Performance', 'performance.php', 'chart'], ['Management reports', 'reports.php', 'chart']]],
            ['Sync', [['Sync health', 'sync-health.php', 'sync'], ['Run sync', 'run-sync.php', 'sync', 'post']]],
            ['Settings', [['Team settings', 'team-settings.php', 'settings']]],
            ['Audit', [['Assignment history', 'assignment-history.php', 'history']]],
        ];
    }
    if (in_array($role, ['document_collection', 'form_filled', 'verification'], true)) {
        return [['My work', [['My work', 'my-work.php', 'home'], ['All my records', 'index.php', 'users'], ['Follow-up queue', 'team-queue.php', 'clock'], ["Today's work report", 'work-report.php', 'note'], ['My performance', 'analytics.php', 'chart']]]];
    }
    return [['Work', [['My records', 'index.php', 'home'], ['Follow-ups', 'followups.php', 'clock'], ['Workspace', 'workspace.php', 'users']]]];
}

/**
 * Opens the page. Options: title (required), user ['name','role'], active (script name, default current),
 * crumbs [[label, href|null], ...], lead, actions (trusted HTML), toast [type, message], extra_head (trusted HTML), body_class.
 */
function ui_start(array $o): void {
    $title = (string)($o['title'] ?? 'IMTS CRM');
    $user = $o['user'] ?? null;
    $role = is_array($user) ? (string)($user['role'] ?? '') : '';
    $active = (string)($o['active'] ?? basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $crumbs = $o['crumbs'] ?? [];
    $home = in_array($role, ['document_collection', 'form_filled', 'verification'], true) ? 'my-work.php' : ($role === 'admin' ? 'dashboard.php' : 'index.php');
    $dueLink = $role === 'admin' ? 'followups.php?view=due' : (in_array($role, ['document_collection', 'form_filled', 'verification'], true) ? 'team-queue.php?view=due' : 'followups.php?view=due');
    echo "<!doctype html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">";
    echo '<title>' . ui_e($title) . ' · IMTS CRM</title>';
    echo '<script>try{var t=localStorage.getItem("crm-theme");if(t)document.documentElement.setAttribute("data-theme",t);if(localStorage.getItem("crm-nav")==="1")document.documentElement.classList.add("pre-collapsed")}catch(e){}</script>';
    echo '<link rel="stylesheet" href="assets/css/app.css?v=1"><script src="assets/js/app.js?v=1" defer></script>';
    echo (string)($o['extra_head'] ?? '');
    echo '</head><body class="ui ' . ui_e($o['body_class'] ?? '') . '"><a class="skip-link" href="#main">Skip to content</a>';
    if (!$user) { // bare shell (login/error pages call their own markup)
        return;
    }
    echo '<div class="app" id="app"><aside class="sidebar" id="sidebar" aria-label="Main navigation">';
    echo '<a class="brand" href="' . $home . '"><span class="brand-mark">IMTS</span><span class="label">Admission CRM</span></a>';
    echo '<nav>';
    foreach (ui_menu($role) as [$group, $items]) {
        $hasCurrent = false;
        foreach ($items as $it0) { if (($it0[3] ?? '') !== 'post' && basename(parse_url($it0[1], PHP_URL_PATH) ?: $it0[1]) === $active) { $hasCurrent = true; break; } }
        echo '<details class="nav-group" data-group="' . ui_e($group) . '"' . ($hasCurrent ? ' open data-current="1"' : '') . '><summary class="nav-title"><span class="label">' . ui_e($group) . '</span>' . ui_icon('chevron', 14) . '</summary>';
        foreach ($items as $it) {
            [$label, $href, $icon] = $it;
            if (($it[3] ?? '') === 'post') {
                $csrf = $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
                echo '<form method="post" action="' . ui_e($href) . '" data-confirm="Run the ERP sync now? It can take a minute."><input type="hidden" name="csrf" value="' . ui_e($csrf) . '"><button class="nav-link" type="submit" title="' . ui_e($label) . '">' . ui_icon($icon) . '<span class="label">' . ui_e($label) . '</span></button></form>';
                continue;
            }
            $cur = basename(parse_url($href, PHP_URL_PATH) ?: $href) === $active ? ' aria-current="page"' : '';
            echo '<a class="nav-link" href="' . ui_e($href) . '"' . $cur . ' title="' . ui_e($label) . '">' . ui_icon($icon) . '<span class="label">' . ui_e($label) . '</span></a>';
        }
        echo '</details>';
    }
    echo '</nav>';
    if ($role === 'admin') {
        echo '<div class="nav-danger"><div class="nav-title">Danger zone</div><a class="nav-link" href="crm-reset.php"' . ($active === 'crm-reset.php' ? ' aria-current="page"' : '') . ' title="CRM data reset">' . ui_icon('alert') . '<span class="label">CRM data reset</span></a></div>';
    }
    echo '</aside><div class="scrim" id="scrim" data-nav-close></div><div class="main">';
    echo '<header class="topbar"><button class="btn btn-ghost btn-icon menu-btn" type="button" data-nav-toggle aria-label="Open menu" aria-controls="sidebar">' . ui_icon('menu', 20) . '</button>';
    echo '<button class="btn btn-ghost btn-icon collapse-btn" type="button" data-collapse aria-label="Collapse sidebar">' . ui_icon('panel', 20) . '</button>';
    echo '<form class="search" role="search" method="get" action="index.php"><label class="sr-only" for="gsearch">Search students by name, phone, email or admission number</label>' . ui_icon('search', 16);
    echo '<input id="gsearch" class="" type="search" name="q" placeholder="Search name, phone, email or ID" maxlength="254" autocomplete="off"></form>';
    echo '<div class="top-right"><a class="btn btn-ghost btn-icon" href="' . ui_e($dueLink) . '" aria-label="Follow-ups due" title="Follow-ups due">' . ui_icon('bell', 20) . '</a>';
    echo '<div class="dropdown"><button class="btn btn-ghost" type="button" data-dropdown aria-haspopup="true" aria-expanded="false">' . ui_icon('users', 18) . '<span class="user-name">' . ui_e($user['name'] ?? '') . '</span>' . ui_icon('chevron', 14) . '</button>';
    echo '<div class="dropdown-menu" hidden><div class="dropdown-head"><strong>' . ui_e($user['name'] ?? '') . '</strong><div class="small muted">' . ui_e(ui_role_label($role)) . '</div></div>';
    echo '<button class="nav-link" type="button" data-theme-toggle>' . ui_icon('sun') . '<span>Dark / light mode</span></button>';
    echo '<a class="nav-link" href="logout.php">' . ui_icon('logout') . '<span>Log out</span></a></div></div></div></header>';
    echo '<main class="content" id="main" tabindex="-1">';
    echo '<div class="page-head"><div>';
    if ($crumbs) {
        echo '<nav class="crumbs" aria-label="Breadcrumb"><a href="' . $home . '">Home</a>';
        foreach ($crumbs as $i => $c) {
            echo '<span aria-hidden="true">/</span>' . (!empty($c[1]) ? '<a href="' . ui_e($c[1]) . '">' . ui_e($c[0]) . '</a>' : '<span aria-current="page">' . ui_e($c[0]) . '</span>');
        }
        echo '</nav>';
    }
    echo '<h1>' . ui_e($title) . '</h1>';
    if (!empty($o['lead'])) echo '<p class="lead">' . ui_e($o['lead']) . '</p>';
    echo '</div>';
    if (!empty($o['actions'])) echo '<div class="page-actions">' . $o['actions'] . '</div>';
    echo '</div>';
    if (!empty($o['toast'][1])) echo '<div id="ui-flash" hidden data-type="' . ui_e($o['toast'][0]) . '" data-msg="' . ui_e($o['toast'][1]) . '"></div>';
}

function ui_end(): void {
    echo '</main><footer class="footer">IMTS Admission Recovery CRM</footer></div></div><div class="toasts" id="toasts" role="status" aria-live="polite"></div>';
    echo '<dialog class="modal" id="confirm-dialog" aria-labelledby="confirm-title"><form method="dialog"><div class="modal-body"><h2 id="confirm-title">Please confirm</h2><p id="confirm-msg" class="muted"></p>';
    echo '<div class="field" id="confirm-type" hidden><label for="confirm-input">Type <strong id="confirm-word"></strong> to continue</label><input class="input" id="confirm-input" autocomplete="off"></div></div>';
    echo '<div class="modal-foot"><button class="btn" value="cancel">Cancel</button><button class="btn btn-primary" id="confirm-ok" value="ok">Confirm</button></div></form></dialog>';
    echo '</body></html>';
}

/** Friendly full-page message (403/404/etc.). Sends the status code and exits. */
function ui_error_page(int $code, string $heading, string $message, string $backHref = 'index.php', string $backLabel = 'Back to home'): never {
    if (!headers_sent()) http_response_code($code);
    ui_start(['title' => $heading]);
    echo '<main class="errpage" id="main"><div><div class="code">' . $code . '</div><h1>' . ui_e($heading) . '</h1><p class="muted">' . ui_e($message) . '</p>';
    echo '<p><a class="btn btn-primary" href="' . ui_e($backHref) . '">' . ui_e($backLabel) . '</a></p></div></main></body></html>';
    exit;
}
