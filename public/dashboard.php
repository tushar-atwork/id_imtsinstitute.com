<?php
/**
 * Admin dashboard. 100% read-only: every query below only SELECTs from existing CRM tables and
 * reuses the same definitions as the queue/report pages (WorkflowPipeline::teamViewSql, latest follow-up per team,
 * crm_workflow_stages.completed_at), so its numbers match those pages. Nothing here changes data.
 */
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/../app/Phase2.php';
require_once __DIR__ . '/../app/TeamWorkflow.php';
require_once __DIR__ . '/../app/AssignmentSettings.php';
require_once __DIR__ . '/../app/RecordSort.php';
require_once __DIR__ . '/../app/ui/report.php';

$me = Phase2::current($crm);
if ($me['role'] !== 'admin') {
    ui_error_page(403, 'Administrators only', 'The dashboard is for administrators. Team employees can use My work.', 'index.php', 'Back to records');
}
function dh(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function dq(PDO $db, string $sql, array $args = []): array { $q = $db->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC); }
function d1(PDO $db, string $sql, array $args = []): int { $q = $db->prepare($sql); $q->execute($args); return (int)$q->fetchColumn(); }

$roles = TeamWorkflow::roles();
$syncedExpr = RecordSort::syncedExpr('s');

// --- Headline numbers (today = server date) ---
$newToday = d1($crm, "SELECT COUNT(*) FROM crm_students s WHERE s.is_active=1 AND $syncedExpr >= CURDATE()");
$new24h = d1($crm, "SELECT COUNT(*) FROM crm_students s WHERE s.is_active=1 AND " . RecordSort::isNewExpr('s'));
$followupsToday = d1($crm, 'SELECT COUNT(*) FROM crm_team_followups WHERE created_at >= CURDATE()');

// --- Per team: open / pending / rejected (same definitions as the team queue), unassigned, finished today, overdue ---
$teams = [];
foreach ($roles as $role => $label) {
    $active = (string)WorkflowPipeline::teamViewSql($role, 'active');
    $pending = (string)WorkflowPipeline::teamViewSql($role, 'pending');
    $rejected = (string)WorkflowPipeline::teamViewSql($role, 'rejected');
    $r = dq($crm, "SELECT COALESCE(SUM(CASE WHEN $active THEN 1 ELSE 0 END),0) open_cnt,
            COALESCE(SUM(CASE WHEN $active AND a.assigned_user_id IS NULL THEN 1 ELSE 0 END),0) unassigned,
            COALESCE(SUM(CASE WHEN $pending THEN 1 ELSE 0 END),0) pending_cnt,
            COALESCE(SUM(CASE WHEN $rejected THEN 1 ELSE 0 END),0) rejected_cnt
         FROM crm_students s JOIN crm_team_assignments a ON a.student_id=s.id AND a.team_role='$role' WHERE s.is_active=1")[0];
    $teams[$role] = ['label' => $label, 'open' => (int)$r['open_cnt'], 'unassigned' => (int)$r['unassigned'], 'pending' => (int)$r['pending_cnt'], 'rejected' => (int)$r['rejected_cnt'], 'finished' => 0, 'overdue' => 0, 'active_staff' => AssignmentSettings::activeCount($crm, $role), 'manual' => AssignmentSettings::isManual($crm, $role)];
}
foreach (dq($crm, 'SELECT ws.team_role, COUNT(*) c FROM crm_workflow_stages ws JOIN crm_students s ON s.id=ws.student_id WHERE ws.completed_at >= CURDATE() GROUP BY ws.team_role') as $r) {
    if (isset($teams[$r['team_role']])) $teams[$r['team_role']]['finished'] = (int)$r['c'];
}
$overdueSql = "FROM crm_team_assignments a JOIN crm_students s ON s.id=a.student_id AND s.is_active=1
    JOIN crm_team_followups f ON f.id=(SELECT MAX(z.id) FROM crm_team_followups z WHERE z.student_id=a.student_id AND z.team_role=a.team_role)
    LEFT JOIN crm_workflow_stages ws ON ws.student_id=a.student_id AND ws.team_role=a.team_role
    WHERE a.assigned_user_id IS NOT NULL AND ws.completed_at IS NULL AND f.next_followup_at IS NOT NULL AND f.next_followup_at < NOW()";
foreach (dq($crm, "SELECT a.team_role, COUNT(*) c $overdueSql GROUP BY a.team_role") as $r) {
    if (isset($teams[$r['team_role']])) $teams[$r['team_role']]['overdue'] = (int)$r['c'];
}
$overdueByUser = array_column(dq($crm, "SELECT a.assigned_user_id u, COUNT(*) c $overdueSql GROUP BY a.assigned_user_id"), 'c', 'u');
$totOpen = array_sum(array_column($teams, 'open'));
$totOverdue = array_sum(array_column($teams, 'overdue'));
$totFinished = array_sum(array_column($teams, 'finished'));
$totUnassigned = array_sum(array_column($teams, 'unassigned'));

// --- Employee workload (same "open" definition as the Work report: assigned, stage not finished) ---
$openByUser = array_column(dq($crm, "SELECT a.assigned_user_id u, COUNT(*) c FROM crm_team_assignments a JOIN crm_students s ON s.id=a.student_id AND s.is_active=1
    LEFT JOIN crm_workflow_stages ws ON ws.student_id=a.student_id AND ws.team_role=a.team_role WHERE a.assigned_user_id IS NOT NULL AND ws.completed_at IS NULL GROUP BY a.assigned_user_id"), 'c', 'u');
$todayByUser = array_column(dq($crm, 'SELECT created_by u, COUNT(*) c FROM crm_team_followups WHERE created_at >= CURDATE() GROUP BY created_by'), 'c', 'u');
$staff = dq($crm, "SELECT id,name,role,is_active FROM crm_users WHERE role IN ('document_collection','form_filled','verification') ORDER BY role, is_active DESC, name");
$maxOpen = 1; foreach ($staff as $s0) $maxOpen = max($maxOpen, (int)($openByUser[$s0['id']] ?? 0));

// --- 14-day trend ---
$days = []; for ($i = 13; $i >= 0; $i--) $days[] = date('Y-m-d', strtotime("-$i day"));
$synced14 = array_column(dq($crm, "SELECT DATE($syncedExpr) d, COUNT(*) c FROM crm_students s WHERE s.is_active=1 AND $syncedExpr >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE($syncedExpr)"), 'c', 'd');
$follow14 = array_column(dq($crm, 'SELECT DATE(created_at) d, COUNT(*) c FROM crm_team_followups WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at)'), 'c', 'd');

// --- Sync health ---
$last = null;
try { $last = dq($crm, 'SELECT id,trigger_type,status,eligible_read,inserted_count,updated_count,deactivated_count,error_count,error_message,duration_seconds,started_at,finished_at FROM crm_sync_logs ORDER BY id DESC LIMIT 1')[0] ?? null; } catch (Throwable $e) { $last = null; }
$ageH = null;
if ($last) { $ref = strtotime((string)($last['finished_at'] ?: $last['started_at'])); if ($ref) $ageH = (time() - $ref) / 3600; }
if (!$last) { $health = 'none'; }
elseif ($last['status'] !== 'success' || (int)$last['error_count'] > 0) { $health = 'bad'; }
elseif ($ageH !== null && $ageH > 48) { $health = 'stale'; }
else { $health = 'good'; }
$healthText = ['good' => ['Sync is healthy', 'alert-success', 'shield'], 'stale' => ['Last sync is more than 48 hours old', 'alert-warn', 'alert'], 'bad' => ['Last sync had a problem', 'alert-error', 'alert'], 'none' => ['No sync has run yet', 'alert-warn', 'alert']][$health];
$ageText = $ageH === null ? '' : ($ageH < 1 ? 'less than an hour ago' : ($ageH < 48 ? floor($ageH) . ' hour(s) ago' : floor($ageH / 24) . ' day(s) ago'));

$latestNew = dq($crm, "SELECT s.id,s.student_name,s.admission_no,s.first_synced_at,s.created_at, " . RecordSort::isNewExpr('s') . " AS is_new FROM crm_students s WHERE s.is_active=1 ORDER BY " . RecordSort::orderBy('newest', 's') . " LIMIT 8");

$csrfToken = $_SESSION['csrf'] ??= bin2hex(random_bytes(24));
$runForm = '<form method="post" action="run-sync.php" data-confirm="Run the ERP sync now? It can take a minute."><input type="hidden" name="csrf" value="' . dh($csrfToken) . '"><button class="btn btn-primary" type="submit" data-loading>Run sync now</button></form>';
ui_start([
    'title' => 'Dashboard',
    'lead' => 'Today at a glance: new records, team workload, overdue follow-ups and sync health. ' . date('l, d M Y'),
    'user' => ['name' => $me['name'], 'role' => $me['role']],
    'active' => 'dashboard.php',
    'crumbs' => [['Dashboard', null]],
    'actions' => '<a class="btn" href="index.php">All records</a>' . $runForm,
]);
?>
<div class="alert <?=$healthText[1]?>" role="status"><?=ui_icon($healthText[2])?>
  <div><strong><?=dh($healthText[0])?></strong>
  <div class="small"><?php if ($last): ?>Last sync: <?=dh(ucfirst((string)$last['status']))?> · <?=dh($last['finished_at'] ?: $last['started_at'])?> (<?=dh($ageText)?>) · <?=number_format((int)$last['inserted_count'])?> new, <?=number_format((int)$last['updated_count'])?> updated, <?=number_format((int)$last['deactivated_count'])?> deactivated, <?=number_format((int)$last['error_count'])?> error(s)<?php if ($last['duration_seconds'] !== null): ?> · <?=dh(number_format((float)$last['duration_seconds'], 1))?>s<?php endif; ?>. <?php else: ?>Run a sync to bring ERP records in. <?php endif; ?><a href="sync-health.php">Sync health →</a></div></div>
</div>
<?php if ($health === 'bad' && !empty($last['error_message'])): ?><div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><strong>Error from the last sync:</strong> <?=dh(mb_strimwidth((string)$last['error_message'], 0, 300, '…'))?></div></div><?php endif; ?>
<?=AssignmentSettings::warningHtml($crm)?>
<?php if ($totUnassigned > 0): ?><div class="alert alert-warn" role="status"><?=ui_icon('alert')?><div><strong><?=number_format($totUnassigned)?> open record(s) have no owner</strong> in their current team. <a href="team-bulk-assign.php">Assign by team →</a></div></div><?php endif; ?>

<?=ui_kpis([
    ['New synced today', number_format($newToday), number_format($new24h) . ' in the last 24 hours'],
    ['Follow-ups saved today', number_format($followupsToday)],
    ['Stages finished today', number_format($totFinished), 'forwarded or completed'],
    ['Overdue follow-ups', number_format($totOverdue), 'across the three teams'],
    ['Open records', number_format($totOpen), 'in team queues'],
    ['Without an owner', number_format($totUnassigned), 'open and unassigned'],
])?>

<section class="card report-section"><div class="card-head"><div><h2>Pending by team</h2><p class="help" style="margin:0">Current position of each team’s records. Numbers match the team queues.</p></div></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Team</th><th>Assignment</th><th class="num">Active employees</th><th class="num">Open</th><th class="num">Pending</th><th class="num">Rejected</th><th class="num">No owner</th><th class="num">Finished today</th><th class="num">Overdue</th><th></th></tr></thead><tbody>
  <?php foreach ($teams as $role => $t): ?>
    <tr><td><strong><?=dh($t['label'])?></strong></td>
     <td><?=$t['manual'] ? '<span class="badge b-amber">Manual</span>' : '<span class="badge b-green">Auto</span>'?></td>
     <td class="num"><?=$t['active_staff'] === 0 ? '<span class="badge b-red">0</span>' : number_format($t['active_staff'])?></td>
     <td class="num"><?=number_format($t['open'])?></td><td class="num"><?=number_format($t['pending'])?></td><td class="num"><?=number_format($t['rejected'])?></td>
     <td class="num"><?=$t['unassigned'] > 0 ? '<span class="badge b-amber">' . number_format($t['unassigned']) . '</span>' : '0'?></td>
     <td class="num"><?=number_format($t['finished'])?></td>
     <td class="num"><?=$t['overdue'] > 0 ? '<span class="badge b-red">' . number_format($t['overdue']) . '</span>' : '0'?></td>
     <td><a class="btn btn-sm" href="team-queue.php?team=<?=dh($role)?>&amp;view=all">Open queue</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>

<section class="card report-section"><div class="card-head"><div><h2>Last 14 days</h2><p class="help" style="margin:0">New records synced from ERP and team follow-ups saved, per day.</p></div></div>
  <?=ui_vbars(array_map(fn($d) => substr($d, 8, 2), $days), ['New synced' => array_map(fn($d) => (int)($synced14[$d] ?? 0), $days), 'Follow-ups saved' => array_map(fn($d) => (int)($follow14[$d] ?? 0), $days)], 'New synced records and follow-ups per day, last 14 days', $days)?>
</section>

<div class="two-col report-section">
 <section class="card"><div class="card-head"><div><h2>Employee workload</h2><p class="help" style="margin:0">Open records per employee, including inactive ones who still hold records.</p></div></div>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Employee</th><th>Team</th><th>State</th><th class="num">Open</th><th class="num">Overdue</th><th class="num">Saved today</th></tr></thead><tbody>
  <?php foreach ($staff as $s): $open = (int)($openByUser[$s['id']] ?? 0); $od = (int)($overdueByUser[$s['id']] ?? 0); ?>
    <tr><td><strong><?=dh($s['name'])?></strong><span class="mini-bar" aria-hidden="true"><i style="width:<?=round($open / $maxOpen * 100, 2)?>%"></i></span></td><td><?=dh($roles[$s['role']] ?? $s['role'])?></td>
     <td><?=(int)$s['is_active'] ? '<span class="badge b-green">Active</span>' : '<span class="badge b-grey">Inactive</span>' . ($open > 0 ? ' <span class="badge b-amber">holds ' . number_format($open) . '</span>' : '')?></td>
     <td class="num"><?=number_format($open)?></td><td class="num"><?=$od > 0 ? '<span class="badge b-red">' . number_format($od) . '</span>' : '0'?></td><td class="num"><?=number_format((int)($todayByUser[$s['id']] ?? 0))?></td></tr>
  <?php endforeach; ?>
  <?php if (!$staff): ?><tr><td colspan="6"><div class="empty" style="padding:20px"><?=ui_icon('users', 40)?><h3>No team employees yet</h3><p><a href="team-settings.php?view=create-user">Create an employee</a></p></div></td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="help" style="margin-top:8px"><a href="work-report.php">Full work report →</a> · <a href="team-settings.php?view=employees">Manage employees →</a></p>
 </section>
 <section class="card"><div class="card-head"><div><h2>Latest synced records</h2><p class="help" style="margin:0">Newest first. “New” = synced in the last 24 hours.</p></div></div>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Student</th><th>Admission</th><th>Synced</th></tr></thead><tbody>
  <?php foreach ($latestNew as $r): ?><tr><td><a class="rec-link" href="student.php?id=<?=(int)$r['id']?>"><?=dh($r['student_name'])?></a><?=RecordSort::badge($r['is_new'])?></td><td><?=dh($r['admission_no'])?></td><td><?=dh(RecordSort::stamp($r['first_synced_at'] ?? $r['created_at'] ?? null))?></td></tr><?php endforeach; ?>
  <?php if (!$latestNew): ?><tr><td colspan="3"><div class="empty" style="padding:20px"><?=ui_icon('inbox', 40)?><h3>No records yet</h3><p>Run a sync to bring records in.</p></div></td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="help" style="margin-top:8px"><a href="index.php?sort=newest">All records →</a> · <a href="analytics.php">Analysis →</a></p>
 </section>
</div>
<?php ui_end(); ?>
