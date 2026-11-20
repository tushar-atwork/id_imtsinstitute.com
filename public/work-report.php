<?php
/**
 * Phase 51: daily work report for the three teams (Documents, Form Filled, Verification).
 * Read-only. Work done = team statuses saved in the selected period, credited to the employee who saved them.
 * Workload = current snapshot of records assigned to each employee.
 * Admins see everyone (optional team / employee filter); team employees see only their own figures.
 */
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/Phase2.php';
require_once __DIR__.'/../app/TeamWorkflow.php';require_once __DIR__.'/../app/ui/report.php';require_once __DIR__.'/../app/ContactActions.php';
$me = Phase2::current($crm);
$isAdmin = $me['role'] === 'admin';
if (!$isAdmin && !TeamWorkflow::isTeam((string)$me['role'])) {
    ui_error_page(403, 'This report is not available to you', 'Only administrators and team employees can open the work report.');
}

$today = date('Y-m-d');
$from = (string)($_GET['from'] ?? $today);
$to = (string)($_GET['to'] ?? $today);
foreach ([$from, $to] as $d) {
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) { http_response_code(422); exit('Invalid date.'); }
}
if ($from > $to) { [$from, $to] = [$to, $from]; }
if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > 366) { http_response_code(422); exit('Select 366 days or less.'); }
$end = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');

$roles = TeamWorkflow::roles();
$team = $isAdmin ? (string)($_GET['team'] ?? '') : (string)$me['role'];
if ($team !== '' && !isset($roles[$team])) $team = '';
$employee = $isAdmin ? (int)($_GET['employee'] ?? 0) : (int)$me['id'];
$employees = $crm->query("SELECT id,name,role,is_active FROM crm_users WHERE role IN ('document_collection','form_filled','verification') ORDER BY is_active DESC,name")->fetchAll(PDO::FETCH_ASSOC);
$names = array_column($employees, 'name', 'id');
$userRole = array_column($employees, 'role', 'id');

// Metric => [label, team, SQL condition on the saved status label]. Distinct students per day/employee.
$label = 'LOWER(TRIM(st.status_label))';
$metrics = [
    'docs_completed' => ['Documents completed', TeamWorkflow::DOC, "$label='send with all completed docx'"],
    'docs_sent_pending' => ['Sent with docs pending', TeamWorkflow::DOC, "($label LIKE 'send with docs pending%' OR $label LIKE 'send with docks pending%')"],
    'docs_pending' => ['Documents: Pending', TeamWorkflow::DOC, "$label='pending'"],
    'docs_rejected' => ['Documents: Rejected', TeamWorkflow::DOC, "$label IN ('rejected','reject')"],
    'forms_filled' => ['Forms filled', TeamWorkflow::FORM, "$label='form filled'"],
    'forms_pending' => ['Form pending', TeamWorkflow::FORM, "$label IN ('form pending','pending')"],
    'forms_rejected' => ['Form rejected', TeamWorkflow::FORM, "$label IN ('rejected','reject')"],
    'forms_correction' => ['Correction required', TeamWorkflow::FORM, "$label='correction required'"],
    'verified' => ['Verified', TeamWorkflow::VER, "$label='verified'"],
    'ver_pending' => ['Verification pending', TeamWorkflow::VER, "$label='pending'"],
    'ver_rejected' => ['Verification rejected', TeamWorkflow::VER, "$label='rejected'"],
];
$shown = array_filter($metrics, static fn(array $m): bool => $team === '' || $m[1] === $team);

$select = [];
foreach ($metrics as $key => [, $role, $cond]) {
    $select[] = "COUNT(DISTINCT CASE WHEN f.team_role='$role' AND $cond THEN f.student_id END) AS $key";
}
$where = ['f.created_at >= ?', 'f.created_at < ?'];
$args = [$from.' 00:00:00', $end.' 00:00:00'];
if ($team !== '') { $where[] = 'f.team_role = ?'; $args[] = $team; }
if ($employee > 0) { $where[] = 'f.created_by = ?'; $args[] = $employee; }
$base = 'FROM crm_team_followups f LEFT JOIN crm_team_statuses st ON st.id=f.status_id WHERE '.implode(' AND ', $where);
$cols = 'COUNT(*) AS followups, COUNT(DISTINCT f.student_id) AS students, '.implode(', ', $select);

$q = $crm->prepare("SELECT f.created_by, $cols $base GROUP BY f.created_by");
$q->execute($args);
$byEmployee = $q->fetchAll(PDO::FETCH_ASSOC);
usort($byEmployee, static fn(array $a, array $b): int => strcmp((string)($names[$a['created_by']] ?? ''), (string)($names[$b['created_by']] ?? '')));

$q = $crm->prepare("SELECT DATE(f.created_at) AS day, $cols $base GROUP BY DATE(f.created_at) ORDER BY day DESC");
$q->execute($args);
$byDay = $q->fetchAll(PDO::FETCH_ASSOC);

$totals = ['followups' => 0, 'students' => 0] + array_fill_keys(array_keys($metrics), 0);
foreach ($byDay as $r) foreach ($totals as $k => $_) $totals[$k] += (int)$r[$k];
// Students are distinct per day; the period total of students is counted separately.
$q = $crm->prepare("SELECT COUNT(DISTINCT f.student_id) $base");
$q->execute($args);
$totals['students'] = (int)$q->fetchColumn();

// Current workload (snapshot, not limited to the dates): records assigned to each employee in their own team.
// Phase 52: Pending / Rejected per employee's own team (Documents, Form Filled and Verification).
$holdPending = $holdRejected = [];
foreach (array_keys(TeamWorkflow::roles()) as $r) {
    $holdPending[] = "(a.team_role='$r' AND ".WorkflowPipeline::teamViewSql($r, 'pending').')';
    $holdRejected[] = "(a.team_role='$r' AND ".WorkflowPipeline::teamViewSql($r, 'rejected').')';
}
$wl = "SELECT a.team_role, a.assigned_user_id,
        COUNT(*) AS assigned,
        SUM(ws.completed_at IS NULL) AS open_work,
        SUM(ws.completed_at IS NOT NULL) AS completed,
        SUM(s.admission_status_id=1) AS erp_incomplete,
        SUM(s.admission_status_id=2) AS erp_pending,
        SUM(s.admission_status_id=4) AS erp_rejected,
        SUM(".implode(' OR ', $holdPending).") AS hold_pending,
        SUM(".implode(' OR ', $holdRejected).") AS hold_rejected
      FROM crm_team_assignments a
      JOIN crm_students s ON s.id=a.student_id AND s.is_active=1
      LEFT JOIN crm_workflow_stages ws ON ws.student_id=s.id AND ws.team_role=a.team_role
      WHERE a.assigned_user_id IS NOT NULL";
$wlArgs = [];
if ($team !== '') { $wl .= ' AND a.team_role=?'; $wlArgs[] = $team; }
if ($employee > 0) { $wl .= ' AND a.assigned_user_id=?'; $wlArgs[] = $employee; }
$wl .= ' GROUP BY a.team_role, a.assigned_user_id';
$q = $crm->prepare($wl);
$q->execute($wlArgs);
$workload = $q->fetchAll(PDO::FETCH_ASSOC);
usort($workload, static fn(array $a, array $b): int => [$a['team_role'], (string)($names[$a['assigned_user_id']] ?? '')] <=> [$b['team_role'], (string)($names[$b['assigned_user_id']] ?? '')]);

// --- Students worked on in the selected dates (one row per student + team + employee, showing the latest entry) ---
$workedPer = 25;
$workedPage = max(1, (int)($_GET['wp'] ?? 1));
$q = $crm->prepare("SELECT COUNT(*) FROM (SELECT 1 FROM crm_team_followups f WHERE ".implode(' AND ', $where)." GROUP BY f.student_id, f.team_role, f.created_by) x");
$q->execute($args);
$workedTotal = (int)$q->fetchColumn();
$workedPage = min($workedPage, max(1, (int)ceil($workedTotal / $workedPer)));
$q = $crm->prepare("SELECT f.created_at, f.created_by, f.team_role, f.contact_result, f.delay_reason, f.remark_for_verification, f.remarks, f.next_followup_at,
        st.status_label, cnt.n AS entries, s.id AS student_id, s.student_name, s.admission_no, s.phone, s.email, s.university, s.admission_status
    FROM crm_team_followups f
    JOIN (SELECT MAX(f.id) AS id, COUNT(*) AS n FROM crm_team_followups f WHERE ".implode(' AND ', $where)." GROUP BY f.student_id, f.team_role, f.created_by) cnt ON cnt.id=f.id
    JOIN crm_students s ON s.id=f.student_id
    LEFT JOIN crm_team_statuses st ON st.id=f.status_id
    ORDER BY f.created_at DESC, f.id DESC LIMIT $workedPer OFFSET ".(($workedPage - 1) * $workedPer));
$q->execute($args);
$workedRows = $q->fetchAll(PDO::FETCH_ASSOC);

// --- Current Pending / Rejected students in the same scope (team + employee filter), with their latest team follow-up ---
$holdWhere = 'a.assigned_user_id IS NOT NULL AND (('.implode(' OR ', $holdPending).') OR ('.implode(' OR ', $holdRejected).'))';
$holdArgs = [];
if ($team !== '') { $holdWhere .= ' AND a.team_role=?'; $holdArgs[] = $team; }
if ($employee > 0) { $holdWhere .= ' AND a.assigned_user_id=?'; $holdArgs[] = $employee; }
$holdFrom = " FROM crm_team_assignments a JOIN crm_students s ON s.id=a.student_id AND s.is_active=1
    LEFT JOIN (SELECT f.* FROM crm_team_followups f JOIN (SELECT student_id,team_role,MAX(id) id FROM crm_team_followups GROUP BY student_id,team_role) latest ON latest.id=f.id) lf ON lf.student_id=a.student_id AND lf.team_role=a.team_role
    LEFT JOIN crm_team_statuses lst ON lst.id=lf.status_id WHERE $holdWhere";
$holdLimit = 100;
$q = $crm->prepare("SELECT s.id AS student_id, s.student_name, s.admission_no, s.phone, s.email, s.university, s.admission_status, a.team_role, a.assigned_user_id,
        (".implode(' OR ', $holdRejected).") AS is_rejected, lst.status_label, lf.created_at AS last_at, lf.next_followup_at, lf.delay_reason, lf.remarks
    $holdFrom ORDER BY is_rejected DESC, lf.created_at DESC, s.id DESC LIMIT $holdLimit");
$q->execute($holdArgs);
$holdRows = $q->fetchAll(PDO::FETCH_ASSOC);
$holdPendingTotal = array_sum(array_map(static fn($r) => (int)$r['hold_pending'], $workload));
$holdRejectedTotal = array_sum(array_map(static fn($r) => (int)$r['hold_rejected'], $workload));

function wr($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function wrcsv($v): string { $s = (string)$v; return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u', $s) ? "'".$s : $s; }

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="imts_work_report_'.$from.'_'.$to.'.csv"');
    header('X-Content-Type-Options: nosniff');
    $out = fopen('php://output', 'w');
    $head = ['Follow-ups saved', 'Students worked'];
    foreach ($shown as [$l]) $head[] = $l;
    fputcsv($out, array_merge(['Date', 'Employee', 'Team'], $head));
    $q = $crm->prepare("SELECT DATE(f.created_at) AS day, f.created_by, $cols $base GROUP BY DATE(f.created_at), f.created_by ORDER BY day, f.created_by");
    $q->execute($args);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $row = [$r['day'], wrcsv($names[$r['created_by']] ?? ('User #'.$r['created_by'])), $roles[$userRole[$r['created_by']] ?? ''] ?? '', $r['followups'], $r['students']];
        foreach (array_keys($shown) as $k) $row[] = $r[$k];
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}
$qs = static fn(array $merge): string => '?'.http_build_query(array_merge(['from' => $from, 'to' => $to, 'team' => $team, 'employee' => $employee], $merge));

$pageUser = ['name' => $me['name'], 'role' => $me['role']];
$d = new DateTimeImmutable($today);
$rangeLabel = $from === $to ? $from : "$from to $to";
ui_start([
    'title' => $isAdmin ? 'Team work report' : 'My work report',
    'lead' => 'Work done counts the statuses saved in the selected dates, credited to the employee who saved them. Each student is counted once per day per status. Workload is the current position.',
    'user' => $pageUser, 'active' => 'work-report.php',
    'crumbs' => [['Reports', null], ['Work report', null]],
    'actions' => ui_csv_button($qs(['export' => 'csv']), 'Download CSV (day × employee)'),
]);
?>
<section class="card report-section">
 <form method="get" class="filter-bar">
  <div class="field"><label for="wr-from">From</label><input class="input" id="wr-from" type="date" name="from" value="<?=wr($from)?>" required></div>
  <div class="field"><label for="wr-to">To</label><input class="input" id="wr-to" type="date" name="to" value="<?=wr($to)?>" required></div>
  <?php if ($isAdmin): ?>
  <div class="field"><label for="wr-team">Team</label><select class="select" id="wr-team" name="team"><option value="">All teams</option><?php foreach ($roles as $k => $l): ?><option value="<?=wr($k)?>" <?=$team === $k ? 'selected' : ''?>><?=wr($l)?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="wr-emp">Employee</label><select class="select" id="wr-emp" name="employee"><option value="0">All employees</option><?php foreach ($employees as $u): ?><option value="<?=(int)$u['id']?>" <?=$employee === (int)$u['id'] ? 'selected' : ''?>><?=wr($u['name'].($u['is_active'] ? '' : ' (inactive)'))?></option><?php endforeach; ?></select></div>
  <?php endif; ?>
  <button class="btn btn-primary" type="submit" data-loading>Show report</button>
 </form>
 <?=ui_presets([
    ['Today', $qs(['from' => $today, 'to' => $today])],
    ['Yesterday', $qs(['from' => $d->modify('-1 day')->format('Y-m-d'), 'to' => $d->modify('-1 day')->format('Y-m-d')])],
    ['Last 7 days', $qs(['from' => $d->modify('-6 days')->format('Y-m-d'), 'to' => $today])],
    ['This month', $qs(['from' => $d->modify('first day of this month')->format('Y-m-d'), 'to' => $today])],
 ])?>
</section>

<?php
$kp = [['Follow-ups saved', number_format($totals['followups'])], ['Students worked', number_format($totals['students'])], ['Pending now', number_format($holdPendingTotal), 'records on hold, all dates'], ['Rejected now', number_format($holdRejectedTotal), 'records rejected, all dates']];
foreach ($shown as $k => [$l]) $kp[] = [$l, number_format($totals[$k])];
echo ui_kpis($kp);
$wrPage = fn(array $m) => '?' . http_build_query(array_merge($_GET, $m));
$phoneBtns = function (array $r): string {
    $wa = ContactActions::phone($r['phone'] ?? ''); $mail = filter_var(trim((string)($r['email'] ?? '')), FILTER_VALIDATE_EMAIL);
    $h = '<span class="quick">';
    if ($wa !== '') $h .= '<a class="btn btn-icon btn-sm q-call" href="tel:+' . wr($wa) . '" aria-label="Call ' . wr($r['student_name']) . '" title="Call">' . ui_icon('phone') . '</a><a class="btn btn-icon btn-sm q-wa" href="https://wa.me/' . wr($wa) . '" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp ' . wr($r['student_name']) . '" title="WhatsApp">' . ui_icon('chat') . '</a>';
    if ($mail) $h .= '<a class="btn btn-icon btn-sm q-mail" href="send-email.php?id=' . (int)$r['student_id'] . '" aria-label="Email ' . wr($r['student_name']) . '" title="Email">' . ui_icon('mail') . '</a>';
    return $h . '</span>';
};
?>

<section class="card report-section" id="worked">
 <div class="card-head"><div><h2><?=$from === $to && $from === $today ? 'Students worked on today' : 'Students worked on · ' . wr($rangeLabel)?></h2><p class="help" style="margin:0">Every student a status was saved for in these dates, with the latest entry. <strong><?=number_format($workedTotal)?></strong> student/team entr<?=$workedTotal === 1 ? 'y' : 'ies'?>.</p></div></div>
 <div class="table-wrap"><table class="table"><thead><tr><th>Student</th><?php if ($isAdmin): ?><th>Employee</th><?php endif; ?><th>Team</th><th>Status saved</th><th>Contact</th><th>Notes</th><th>Next follow-up</th><th>Time</th><th></th></tr></thead><tbody>
 <?php foreach ($workedRows as $r): ?>
  <tr>
   <td><a class="rec-link" href="student.php?id=<?=(int)$r['student_id']?>"><?=wr($r['student_name'])?></a><div class="small muted">#<?=wr($r['admission_no'])?> · <?=wr($r['university'] ?: '—')?></div><div class="small"><?=wr($r['phone'] ?: '—')?></div><div class="small muted"><?=wr($r['email'] ?: '')?></div><div style="margin-top:4px"><?=ui_status_badge($r['admission_status'], '—')?></div></td>
   <?php if ($isAdmin): ?><td><?=wr($names[$r['created_by']] ?? ('User #'.$r['created_by']))?></td><?php endif; ?>
   <td><?=wr($roles[$r['team_role']] ?? $r['team_role'])?></td>
   <td><?=ui_status_badge($r['status_label'], '—')?><?php if ((int)$r['entries'] > 1): ?><div class="small muted"><?=(int)$r['entries']?> entries</div><?php endif; ?></td>
   <td><?=$r['contact_result'] ? wr($r['contact_result']) : '—'?></td>
   <td style="max-width:260px"><?php foreach (['delay_reason' => 'Delay', 'remark_for_verification' => 'For verification', 'remarks' => 'Remarks'] as $k => $lab): if (trim((string)$r[$k]) !== ''): ?><div class="small"><b><?=wr($lab)?>:</b> <?=wr(mb_strimwidth((string)$r[$k], 0, 140, '…'))?></div><?php endif; endforeach; ?></td>
   <td><?=$r['next_followup_at'] ? wr(date('d M Y, h:i A', strtotime((string)$r['next_followup_at']))) : '—'?></td>
   <td><?=wr(date('d M, h:i A', strtotime((string)$r['created_at'])))?></td>
   <td><?=$phoneBtns($r)?> <a class="btn btn-sm btn-primary" href="student.php?id=<?=(int)$r['student_id']?>">Open</a></td>
  </tr>
 <?php endforeach; ?>
 <?php if (!$workedRows): ?><tr><td colspan="<?=$isAdmin ? 9 : 8?>"><div class="empty" style="padding:24px"><?=ui_icon('inbox', 40)?><h3>No statuses saved in these dates</h3><p>When you save a follow-up for a student it appears here.</p></div></td></tr><?php endif; ?>
 </tbody></table></div>
 <nav class="pager" aria-label="Pagination"><span>Page <?=$workedPage?> of <?=max(1, (int)ceil($workedTotal / $workedPer))?></span><span style="display:flex;gap:8px"><?php if ($workedPage > 1): ?><a class="btn btn-sm" href="<?=wr($wrPage(['wp' => $workedPage - 1]))?>#worked">Previous</a><?php endif; ?><?php if ($workedPage * $workedPer < $workedTotal): ?><a class="btn btn-sm" href="<?=wr($wrPage(['wp' => $workedPage + 1]))?>#worked">Next</a><?php endif; ?></span></nav>
</section>

<section class="card report-section" id="onhold">
 <div class="card-head"><div><h2>Pending &amp; Rejected students</h2><p class="help" style="margin:0">Current position (not limited to the dates above): <strong><?=number_format($holdPendingTotal)?></strong> pending · <strong><?=number_format($holdRejectedTotal)?></strong> rejected<?=count($holdRows) >= $holdLimit ? ' · showing the first ' . $holdLimit : ''?>.</p></div>
  <?php if (!$isAdmin): ?><a class="btn btn-sm" href="my-work.php?tab=hold">Open in My work</a><?php else: ?><a class="btn btn-sm" href="team-queue.php?team=<?=wr($team !== '' ? $team : 'document_collection')?>&amp;view=pending">Open team queue</a><?php endif; ?></div>
 <div class="table-wrap"><table class="table"><thead><tr><th>Student</th><?php if ($isAdmin): ?><th>Owner</th><?php endif; ?><th>Team</th><th>Kind</th><th>Last status</th><th>Last follow-up</th><th>Notes</th><th>Next follow-up</th><th></th></tr></thead><tbody>
 <?php foreach ($holdRows as $r): ?>
  <tr>
   <td><a class="rec-link" href="student.php?id=<?=(int)$r['student_id']?>"><?=wr($r['student_name'])?></a><div class="small muted">#<?=wr($r['admission_no'])?> · <?=wr($r['university'] ?: '—')?></div><div class="small"><?=wr($r['phone'] ?: '—')?></div><div class="small muted"><?=wr($r['email'] ?: '')?></div></td>
   <?php if ($isAdmin): ?><td><?=wr($names[$r['assigned_user_id']] ?? ('User #'.$r['assigned_user_id']))?></td><?php endif; ?>
   <td><?=wr($roles[$r['team_role']] ?? $r['team_role'])?></td>
   <td><?=(int)$r['is_rejected'] ? '<span class="badge b-red">Rejected</span>' : '<span class="badge b-amber">Pending</span>'?></td>
   <td><?=ui_status_badge($r['status_label'], '—')?></td>
   <td><?=$r['last_at'] ? wr(date('d M Y, h:i A', strtotime((string)$r['last_at']))) : '—'?></td>
   <td style="max-width:240px"><?php foreach (['delay_reason' => 'Delay', 'remarks' => 'Remarks'] as $k => $lab): if (trim((string)$r[$k]) !== ''): ?><div class="small"><b><?=wr($lab)?>:</b> <?=wr(mb_strimwidth((string)$r[$k], 0, 120, '…'))?></div><?php endif; endforeach; ?></td>
   <td><?=$r['next_followup_at'] ? wr(date('d M Y, h:i A', strtotime((string)$r['next_followup_at']))) : '—'?></td>
   <td><?=$phoneBtns($r)?> <a class="btn btn-sm btn-primary" href="student.php?id=<?=(int)$r['student_id']?>">Open</a></td>
  </tr>
 <?php endforeach; ?>
 <?php if (!$holdRows): ?><tr><td colspan="<?=$isAdmin ? 9 : 8?>"><div class="empty" style="padding:24px"><?=ui_icon('shield', 40)?><h3>Nothing pending or rejected</h3></div></td></tr><?php endif; ?>
 </tbody></table></div>
</section>

<?php if (count($byDay) > 1 && count($byDay) <= 62): ?>
<section class="card report-section"><h2>Follow-ups per day</h2><p class="help"><?=wr($rangeLabel)?></p>
<?php $dd = array_reverse($byDay); echo ui_vbars(array_map(fn($r) => substr((string)$r['day'], 8, 2), $dd), ['Follow-ups' => array_map(fn($r) => (int)$r['followups'], $dd)], 'Follow-ups saved per day', array_map(fn($r) => (string)$r['day'], $dd)); ?>
</section>
<?php endif; ?>

<section class="card report-section"><h2>By employee · <?=wr($rangeLabel)?></h2>
<div class="table-wrap"><table class="table compact"><thead><tr><th>Employee</th><th>Team</th><th class="num">Follow-ups</th><th class="num">Students</th><?php foreach ($shown as [$l]): ?><th class="num"><?=wr($l)?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($byEmployee as $r): ?><tr><td><?=wr($names[$r['created_by']] ?? ('User #'.$r['created_by']))?></td><td><?=wr($roles[$userRole[$r['created_by']] ?? ''] ?? '—')?></td><td class="num"><?=number_format((int)$r['followups'])?></td><td class="num"><?=number_format((int)$r['students'])?></td><?php foreach (array_keys($shown) as $k): ?><td class="num"><?=number_format((int)$r[$k])?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$byEmployee): ?><tr><td colspan="<?=4 + count($shown)?>"><div class="empty" style="padding:20px"><?=ui_icon('inbox', 40)?><h3>No statuses saved in these dates</h3><p>Try a wider date range.</p></div></td></tr><?php endif; ?>
</tbody></table></div></section>

<section class="card report-section"><h2>Day by day</h2>
<div class="table-wrap"><table class="table compact"><thead><tr><th>Date</th><th class="num">Follow-ups</th><th class="num">Students</th><?php foreach ($shown as [$l]): ?><th class="num"><?=wr($l)?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($byDay as $r): ?><tr><td><?=wr($r['day'])?></td><td class="num"><?=number_format((int)$r['followups'])?></td><td class="num"><?=number_format((int)$r['students'])?></td><?php foreach (array_keys($shown) as $k): ?><td class="num"><?=number_format((int)$r[$k])?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if (!$byDay): ?><tr><td colspan="<?=3 + count($shown)?>"><div class="empty" style="padding:20px"><?=ui_icon('inbox', 40)?><h3>Nothing to show</h3></div></td></tr><?php endif; ?>
</tbody></table></div></section>

<section class="card report-section"><h2>Current workload</h2>
<p class="help">Active records assigned right now. Open = not yet finished by that team. Admission status columns are the ERP status (Pending forms, Rejected forms).</p>
<div class="table-wrap"><table class="table compact"><thead><tr><th>Employee</th><th>Team</th><th class="num">Assigned</th><th class="num">Open</th><th class="num">Finished / forwarded</th><th class="num">ERP Incomplete</th><th class="num">ERP Pending</th><th class="num">ERP Rejected</th><th class="num">Team status: Pending</th><th class="num">Team status: Rejected</th></tr></thead><tbody>
<?php foreach ($workload as $r): ?><tr><td><?=wr($names[$r['assigned_user_id']] ?? ('User #'.$r['assigned_user_id']))?></td><td><?=wr($roles[$r['team_role']] ?? $r['team_role'])?></td><td class="num"><?=number_format((int)$r['assigned'])?></td><td class="num"><?=number_format((int)$r['open_work'])?></td><td class="num"><?=number_format((int)$r['completed'])?></td><td class="num"><?=number_format((int)$r['erp_incomplete'])?></td><td class="num"><?=number_format((int)$r['erp_pending'])?></td><td class="num"><?=number_format((int)$r['erp_rejected'])?></td><td class="num"><?=number_format((int)$r['hold_pending'])?></td><td class="num"><?=number_format((int)$r['hold_rejected'])?></td></tr><?php endforeach; ?>
<?php if (!$workload): ?><tr><td colspan="10"><div class="empty" style="padding:20px"><?=ui_icon('inbox', 40)?><h3>No records are assigned</h3></div></td></tr><?php endif; ?>
</tbody></table></div></section>
<?php ui_end(); ?>
