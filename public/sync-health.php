<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/../app/Phase2.php';
require_once __DIR__ . '/../app/ui/report.php';
$user = Phase2::current($crm);
if (($user['role'] ?? '') !== 'admin') { ui_error_page(403, 'Administrators only', 'Sync health is limited to administrators.'); }
function h6($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function n6($v): string { return number_format((int)$v); }
$preflight = []; $counts = []; $error = null;
$tables = ['student','student_assigned_follow_pdc','student_payment','student_assigned','admission_confirmation_status','university','session','lead','user'];
try {
    $dbName = (string)$erp->query('SELECT DATABASE()')->fetchColumn();
    $preflight['ERP connection'] = 'Connected';
    $preflight['ERP database'] = $dbName;
    $preflight['Configured ERP database'] = (string)($cfg['erp_db']['database'] ?? 'Not configured');
    $preflight['Database name contains test'] = stripos($dbName, 'test') !== false ? 'YES — verify this is the intended ERP source' : 'No';
    $preflight['Minimum approved payment'] = '₹' . number_format((float)($cfg['sync']['minimum_approved_payment'] ?? 4800), 2);
    $preflight['Approved payment status ID'] = (string)($cfg['sync']['approved_payment_status'] ?? 2);
    $preflight['Eligible admission status IDs'] = implode(', ', array_map('intval', $cfg['sync']['eligible_follow_statuses'] ?? [1,2,4]));
    $schemaStmt = $erp->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    foreach ($tables as $table) {
        $name = 'imts_erp_' . $table;
        $schemaStmt->execute([$name]);
        $preflight[$name] = (int)$schemaStmt->fetchColumn() ? 'Found' : 'MISSING';
    }
    $emailCol = (string)($cfg['sync']['erp_user_email_column'] ?? 'email');
    if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $emailCol)) {
        $colStmt = $erp->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $colStmt->execute(['imts_erp_user', $emailCol]);
        $preflight['ERP user email column (imts_erp_user.' . $emailCol . ')'] = (int)$colStmt->fetchColumn() ? 'Found' : 'MISSING — set sync.erp_user_email_column';
    } else {
        $preflight['ERP user email column'] = 'Invalid name in config';
    }
    try {
        $preflight['CRM team employees with email'] = (string)(int)$crm->query("SELECT COUNT(*) FROM crm_users WHERE is_active=1 AND email IS NOT NULL AND TRIM(email)<>'' AND role IN ('document_collection','form_filled','verification')")->fetchColumn();
    } catch (PDOException $ex) {
        $preflight['CRM team employees with email'] = 'Import PHASE50 SQL';
    }
    $required = ['imts_erp_student','imts_erp_student_assigned_follow_pdc','imts_erp_student_payment'];
    $missing = array_filter($required, fn($t) => ($preflight[$t] ?? '') !== 'Found');
    if (!$missing) {
        $counts['ERP student rows'] = (int)$erp->query('SELECT COUNT(*) FROM imts_erp_student WHERE deleted_at IS NULL')->fetchColumn();
        $counts['ERP latest status rows'] = (int)$erp->query('SELECT COUNT(DISTINCT student_id) FROM imts_erp_student_assigned_follow_pdc')->fetchColumn();
        $approved = (int)($cfg['sync']['approved_payment_status'] ?? 2);
        $minimum = (float)($cfg['sync']['minimum_approved_payment'] ?? 4800);
        $statuses = array_values(array_unique(array_map('intval', $cfg['sync']['eligible_follow_statuses'] ?? [1,2,4])));
        if (!$statuses || count($statuses) > 20) { throw new RuntimeException('Invalid configured status list'); }
        $sql = 'SELECT COUNT(*) FROM imts_erp_student s
          INNER JOIN (SELECT sf.student_id, sf.follow_status FROM imts_erp_student_assigned_follow_pdc sf
            INNER JOIN (SELECT student_id, MAX(id) id FROM imts_erp_student_assigned_follow_pdc GROUP BY student_id) last_sf ON last_sf.id = sf.id) latest ON latest.student_id=s.id
          INNER JOIN (SELECT student_id, SUM(pay) paid FROM imts_erp_student_payment WHERE status = ? AND deleted_at IS NULL GROUP BY student_id HAVING SUM(pay) >= ?) paid ON paid.student_id=s.id
          WHERE s.deleted_at IS NULL AND latest.follow_status IN (' . implode(',', $statuses) . ')';
        $stmt = $erp->prepare($sql); $stmt->execute([$approved, $minimum]);
        $counts['ERP eligible student preview (read-only)'] = (int)$stmt->fetchColumn();
    }
    $counts['CRM active students'] = (int)$crm->query('SELECT COUNT(*) FROM crm_students WHERE is_active=1')->fetchColumn();
    $counts['CRM inactive students'] = (int)$crm->query('SELECT COUNT(*) FROM crm_students WHERE is_active=0')->fetchColumn();
    $counts['CRM sync log entries'] = (int)$crm->query('SELECT COUNT(*) FROM crm_sync_logs')->fetchColumn();
} catch (Throwable $ex) { $error = 'Preflight failed: ' . $ex->getMessage(); }
$logs = $crm->query('SELECT id, trigger_type, status, eligible_read, inserted_count, updated_count, deactivated_count, error_count, duration_seconds, started_at, finished_at FROM crm_sync_logs ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);

$lastLog = $logs[0] ?? null;
$missingTables = array_filter(array_keys($preflight), fn($k) => str_starts_with((string)$k, 'imts_erp_') && ($preflight[$k] ?? '') === 'MISSING');
$colMissing = false; foreach ($preflight as $k => $v) { if (str_contains((string)$k, 'email column') && str_contains((string)$v, 'MISSING')) $colMissing = true; }
$healthy = !$error && !$missingTables && !$colMissing && (!$lastLog || $lastLog['status'] === 'success');
$noEligible = isset($counts['ERP eligible student preview (read-only)']) && (int)$counts['ERP eligible student preview (read-only)'] === 0;
$csrfToken = $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$pageUser = ['name' => $user['name'], 'role' => $user['role']];
$runForm = '<form method="post" action="run-sync.php" data-confirm="Run the ERP sync now? It can take a minute."><input type="hidden" name="csrf" value="' . h6($csrfToken) . '"><button class="btn btn-primary" type="submit" data-loading>Run sync now</button></form>';
ui_start([
    'title' => 'Sync health',
    'lead' => 'Administrator-only, read-only diagnostics. This page does not run a sync or change ERP/CRM records.',
    'user' => $pageUser, 'active' => 'sync-health.php',
    'crumbs' => [['Sync', null], ['Sync health', null]],
    'actions' => $runForm,
]);
?>
<div class="alert <?=$healthy ? 'alert-success' : 'alert-error'?>" role="status">
  <?=ui_icon($healthy ? 'shield' : 'alert')?>
  <div><strong><?=$healthy ? 'Sync looks healthy' : 'Attention needed'?></strong>
  <div class="small"><?php
    if ($healthy) echo $lastLog ? 'Last sync finished successfully ' . h6($lastLog['finished_at'] ?: $lastLog['started_at']) . '.' : 'Preflight checks passed. No sync has run yet.';
    else { $why = []; if ($error) $why[] = 'preflight failed'; if ($missingTables) $why[] = count($missingTables) . ' ERP table(s) missing'; if ($colMissing) $why[] = 'ERP email column missing'; if ($lastLog && $lastLog['status'] !== 'success') $why[] = 'last sync status: ' . h6($lastLog['status']); echo ucfirst(implode(', ', $why)) . '.'; }
  ?></div></div>
</div>
<?php if ($error): ?><div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><?=h6($error)?></div></div><?php endif; ?>
<?php if ($noEligible): ?><div class="alert alert-warn" role="alert"><?=ui_icon('alert')?><div>No eligible ERP students were found. Check source database, latest admission status and approved payment conditions before running Sync ERP Now.</div></div><?php endif; ?>
<?php if (($preflight['Database name contains test'] ?? '') !== 'No'): ?><div class="alert alert-warn" role="alert"><?=ui_icon('alert')?><div>Configured ERP database may be a test database. Confirm the source before syncing production admissions.</div></div><?php endif; ?>

<?php
$kp = [['Last sync', $lastLog ? ucfirst((string)$lastLog['status']) : 'Never', $lastLog ? (string)($lastLog['finished_at'] ?: $lastLog['started_at']) : '']];
foreach (['CRM active students', 'CRM inactive students', 'ERP eligible student preview (read-only)'] as $ck) if (isset($counts[$ck])) $kp[] = [str_replace(' (read-only)', '', $ck), number_format((int)$counts[$ck]), $ck === 'ERP eligible student preview (read-only)' ? 'read-only preview' : ''];
echo ui_kpis($kp);
?>

<div class="two-col report-section">
  <section class="card"><h2>Source &amp; configuration</h2>
    <div class="table-wrap"><table class="table"><tbody>
    <?php foreach ($preflight as $k => $v): $bad = in_array($v, ['MISSING', 'Invalid name in config'], true) || str_starts_with((string)$v, 'MISSING') || str_starts_with((string)$v, 'YES');
          $good = in_array($v, ['Found', 'Connected'], true); ?>
      <tr><th scope="row" style="text-align:left;font-weight:600"><?=h6($k)?></th><td><?=$good ? '<span class="badge b-green">' . h6($v) . '</span>' : ($bad ? '<span class="badge b-red">' . h6($v) . '</span>' : h6($v))?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </section>
  <section class="card"><h2>Read-only counts</h2>
    <div class="table-wrap"><table class="table"><tbody>
    <?php foreach ($counts as $k => $v): ?><tr><th scope="row" style="text-align:left;font-weight:600"><?=h6($k)?></th><td class="num"><?=n6($v)?></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
</div>

<section class="card report-section"><h2>Last 20 sync attempts</h2>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>ID</th><th>Trigger</th><th>Status</th><th class="num">Eligible</th><th class="num">Inserted</th><th class="num">Updated</th><th class="num">Deactivated</th><th class="num">Errors</th><th class="num">Duration (s)</th><th>Started</th><th>Finished</th></tr></thead><tbody>
  <?php foreach ($logs as $l): ?>
    <tr><td><?=h6($l['id'])?></td><td><?=h6($l['trigger_type'] ?? '—')?></td><td><?=$l['status'] === 'success' ? '<span class="badge b-green">success</span>' : '<span class="badge b-red">' . h6($l['status'] ?? '—') . '</span>'?></td><td class="num"><?=h6($l['eligible_read'] ?? '—')?></td><td class="num"><?=h6($l['inserted_count'] ?? '—')?></td><td class="num"><?=h6($l['updated_count'] ?? '—')?></td><td class="num"><?=h6($l['deactivated_count'] ?? '—')?></td><td class="num"><?=(int)($l['error_count'] ?? 0) > 0 ? '<span class="badge b-red">' . h6($l['error_count']) . '</span>' : h6($l['error_count'] ?? '—')?></td><td class="num"><?=h6($l['duration_seconds'] ?? '—')?></td><td><?=h6($l['started_at'] ?? '—')?></td><td><?=h6($l['finished_at'] ?? '—')?></td></tr>
  <?php endforeach; ?>
  <?php if (!$logs): ?><tr><td colspan="11"><div class="empty" style="padding:20px"><?=ui_icon('inbox', 40)?><h3>No sync has run yet</h3><p>Use “Run sync now” above to start the first one.</p></div></td></tr><?php endif; ?>
  </tbody></table></div>
</section>
<p class="note">If preflight succeeds but sync fails, check cPanel error logs and the latest crm_sync_logs.error_message in phpMyAdmin. Do not share credentials or student data.</p>
<?php ui_end(); ?>
