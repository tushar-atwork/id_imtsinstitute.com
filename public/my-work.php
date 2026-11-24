<?php
/**
 * "My Work": task-first home for Documents / Form Filled / Verification employees.
 * Read-only view over the same assignments, follow-ups and workflow stages the existing lists use
 * (it reuses WorkflowPipeline::teamViewSql for the Active / Pending / Rejected / Forwarded definitions).
 * No writes; every action links to the existing pages (student.php, send-email.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/../app/PageSize.php';
require __DIR__ . '/../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/../app/Phase2.php';
require_once __DIR__ . '/../app/TeamWorkflow.php';
require_once __DIR__ . '/../app/ContactActions.php';
require_once __DIR__ . '/../app/RecordSort.php';
require_once __DIR__ . '/../app/ui/layout.php';

$me = Phase2::current($crm);
$role = (string)$me['role'];
$user = ['name' => $me['name'], 'role' => $role];

if (!TeamWorkflow::isTeam($role)) {
    ui_start(['title' => 'My work', 'user' => $user, 'active' => 'my-work.php']);
    echo '<div class="card"><div class="empty">' . ui_icon('inbox', 48) . '<h3>My Work is for team employees</h3><p>Administrators and other roles work from the records list and the team queues.</p><p><a class="btn btn-primary" href="index.php">Open records</a> <a class="btn" href="team-queue.php">Team queue</a></p></div></div>';
    ui_end();
    exit;
}

$uid = (int)$me['id'];
$labels = TeamWorkflow::roles();

// Outcomes that mean the stage was fully completed come from the single shared list in WorkflowPipeline (also used by advance());
// other closed outcomes count as "forwarded".
$completeOutcomes = WorkflowPipeline::COMPLETE_OUTCOMES[$role];
$in = "'" . implode("','", $completeOutcomes) . "'";   // fixed code constants, never user input
$stageDone = "COALESCE((SELECT LOWER(TRIM(mw.outcome)) FROM crm_workflow_stages mw WHERE mw.student_id=s.id AND mw.team_role='$role' AND mw.completed_at IS NOT NULL LIMIT 1),'') IN ($in)";
$active = (string)WorkflowPipeline::teamViewSql($role, 'active');
$crmRej = (string)WorkflowPipeline::teamViewSql($role, 'rejected');    // latest CRM follow-up status is Rejected
$closed = WorkflowPipeline::teamForwardedSql($role);                    // this team's stage is closed (record handed on)
$latest = WorkflowPipeline::teamStatusSql($role);                       // latest CRM status label of this team (lower-cased) or NULL
// ERP admission status synced as Rejected (crm_students.admission_status / _id 4). This is NOT a CRM workflow status.
$erpRej = "(LOWER(TRIM(COALESCE(s.admission_status,'')))='rejected' OR s.admission_status_id=4)";
// Rejected tab = CRM-rejected, or ERP-rejected that this team has not given any status yet (so it never sits in New).
$rejTab = "($crmRej OR (NOT $closed AND $erpRej AND $latest IS NULL))";
// Status tabs are mutually exclusive: every record falls in exactly one of Forwarded/Completed, Rejected, On hold, In progress, New.
$holdTab = "(NOT $closed AND NOT $rejTab AND $latest LIKE '%hold%')";
$cond = [
    // "Today" is a due-date shortcut over the status tabs (it is the only list that can overlap another tab).
    'today'     => "($active AND f.next_followup_at IS NOT NULL AND f.next_followup_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY))",
    'new'       => "(NOT $closed AND f.id IS NULL AND NOT $erpRej)",
    'progress'  => "(NOT $closed AND f.id IS NOT NULL AND NOT $rejTab AND NOT $holdTab)",
    'hold'      => $holdTab,
    'rejected'  => $rejTab,
    // Verification is the last stage: "Completed" = verified. Documents / Form Filled hand records on: "Forwarded" = this team's stage
    // is closed (crm_workflow_stages.completed_at set, any closing outcome), so the record now belongs to the next team.
    'completed' => "($closed AND NOT $rejTab AND $stageDone)",
    'forwarded' => "($closed AND NOT $rejTab)",
];
$isLast = ($role === TeamWorkflow::VER);
$nextRole = [TeamWorkflow::DOC => TeamWorkflow::FORM, TeamWorkflow::FORM => TeamWorkflow::VER][$role] ?? null;   // fixed code constants
$tabs = [
    'today' => ['Today’s follow-ups', 'Due today or overdue (shortcut; the record is also in its status tab)'],
    'new' => ['New', 'Assigned to you, not worked on yet (no follow-up or status saved)'],
    'progress' => ['In progress', 'You have started follow-up (Pending and other open statuses)'],
    'hold' => ['On hold', 'Status saved as On hold'],
    'rejected' => ['Rejected', 'Rejected in the CRM, or rejected in the ERP admission status'],
];
if ($isLast) {
    $tabs['completed'] = ['Completed', 'Verified'];
} else {
    $tabs['forwarded'] = ['Forwarded', 'Your stage is closed and the record was handed to ' . ($labels[$nextRole] ?? 'the next team')];
}
// Per-team tab configuration. Documents uses a reduced set whose lists match index.php: In progress = "Active documents"
// (WorkflowPipeline::teamViewSql 'active': stage open, latest status not Rejected), Completed = stage closed and handed over.
$isDoc = ($role === TeamWorkflow::DOC);
$teamTabs = [TeamWorkflow::DOC => ['today', 'progress', 'completed']];
if (isset($teamTabs[$role])) {
    $cond['progress'] = $active;
    $cond['completed'] = $closed;
    $tabs['progress'] = ['In progress', 'Active documents: open records, untouched ones are marked New'];
    $tabs['completed'] = ['Completed', 'Documents stage closed and the record handed to ' . ($labels[$nextRole] ?? 'the next team')];
    $tabs = array_intersect_key($tabs, array_flip($teamTabs[$role]));
    unset($tabs['forwarded']);
    $tabs = array_replace(array_flip($teamTabs[$role]), $tabs);
}
$cond = array_intersect_key($cond, $tabs);   // only the visible tabs are counted
$tab = (string)($_GET['tab'] ?? 'today');
if (!isset($tabs[$tab])) $tab = 'today';
$isClosedTab = ($tab === 'forwarded') || ($isDoc && $tab === 'completed');   // lists that show "Forwarded to <next team>"

$fromHead = ' FROM crm_students s JOIN crm_team_assignments a ON a.student_id=s.id AND a.team_role=? AND a.assigned_user_id=?'
    . ' LEFT JOIN (SELECT f.* FROM crm_team_followups f JOIN (SELECT student_id,team_role,MAX(id) id FROM crm_team_followups GROUP BY student_id,team_role) latest ON latest.id=f.id) f ON f.student_id=s.id AND f.team_role=a.team_role'
    . ' LEFT JOIN crm_team_statuses st ON st.id=f.status_id';
$fromWhere = ' WHERE s.is_active=1';
$from = $fromHead . $fromWhere;
$base = [$role, $uid];

// Form Filled and Verification receive records from the previous team: order and label them by the time that team
// forwarded the record (previous stage's completed_at). Documents is the first stage, so it keeps the ERP sync time.
// If the previous stage never completed (record assigned independently) the assignment time is used instead.
// Read-only join on crm_workflow_stages (one row per student+team), used for the list only, so the tab counts are unchanged.
$prevRole = [TeamWorkflow::FORM => TeamWorkflow::DOC, TeamWorkflow::VER => TeamWorkflow::FORM][$role] ?? null;   // fixed code constants
$fromList = $fromHead;
$recvExpr = null;
if ($prevRole !== null) {
    $fromList .= " LEFT JOIN crm_workflow_stages fwp ON fwp.student_id=s.id AND fwp.team_role='$prevRole'";
    $recvExpr = 'COALESCE(fwp.completed_at,a.assigned_at,' . RecordSort::syncedExpr() . ')';
}
// This team's own stage row (when it was closed) and, for Forwarded cards, the next team's owner and latest status. All read-only.
$fromList .= " LEFT JOIN crm_workflow_stages ows ON ows.student_id=s.id AND ows.team_role=a.team_role";
$nextCols = 'NULL AS next_owner,NULL AS next_status,';
if ($nextRole !== null) {
    $fromList .= " LEFT JOIN crm_team_assignments nta ON nta.student_id=s.id AND nta.team_role='$nextRole' LEFT JOIN crm_users ntu ON ntu.id=nta.assigned_user_id";
    $nextCols = "ntu.name AS next_owner,(SELECT nst.status_label FROM crm_team_followups ntf JOIN crm_team_statuses nst ON nst.id=ntf.status_id WHERE ntf.student_id=s.id AND ntf.team_role='$nextRole' ORDER BY ntf.id DESC LIMIT 1) AS next_status,";
}
$fromList .= $fromWhere;

// Tab counts in one pass.
$sum = [];
foreach ($cond as $k => $c) { $sum[] = "COALESCE(SUM(CASE WHEN $c THEN 1 ELSE 0 END),0) AS c_$k"; }
$sum[] = "COALESCE(SUM(CASE WHEN ($active AND f.id IS NULL) THEN 1 ELSE 0 END),0) AS c_untouched";   // open records with no follow-up yet (stat tile)
$st = $crm->prepare('SELECT ' . implode(',', $sum) . $from);
$st->execute($base);
$counts = $st->fetch(PDO::FETCH_ASSOC) ?: [];

// Today's progress: follow-ups I saved today vs. still due.
$st = $crm->prepare('SELECT COUNT(*) FROM crm_team_followups WHERE created_by=? AND team_role=? AND created_at>=CURDATE()');
$st->execute([$uid, $role]);
$doneToday = (int)$st->fetchColumn();
$dueLeft = (int)($counts['c_today'] ?? 0);
$pct = ($doneToday + $dueLeft) > 0 ? (int)round(100 * $doneToday / ($doneToday + $dueLeft)) : 0;

// Selected tab list.
$where = ' AND ' . $cond[$tab];
$args = $base;
$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) > 100) $q = mb_substr($q, 0, 100);
if ($q !== '') {
    $where .= ' AND (s.student_name LIKE ? OR s.admission_no LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)';
    for ($i = 0; $i < 4; $i++) $args[] = '%' . $q . '%';
}
$sortExtra = array_merge(['due', 'untouched'], array_keys(RecordSort::more()));
// Today's tab defaults to follow-up due order, but an explicit sort choice is honoured.
$sort = isset($_GET['sort']) ? RecordSort::current($sortExtra) : ($tab === 'today' ? 'due' : RecordSort::NEWEST);
if ($sort === 'due') {
    $order = '(f.next_followup_at IS NULL),f.next_followup_at ASC,s.id DESC';
} elseif ($sort === 'untouched') {
    $order = '(f.id IS NOT NULL),' . RecordSort::syncedExpr() . ' DESC,s.id DESC';   // records with no follow-up first
} elseif ($isClosedTab && $sort === RecordSort::NEWEST) {
    $order = 'ows.completed_at DESC,s.id DESC';   // newest forwarded first
} elseif ($isClosedTab && $sort === RecordSort::OLDEST) {
    $order = 'ows.completed_at ASC,s.id ASC';
} elseif ($recvExpr !== null && $sort === RecordSort::NEWEST) {
    $order = "$recvExpr DESC,s.id DESC";
} elseif ($recvExpr !== null && $sort === RecordSort::OLDEST) {
    $order = "$recvExpr ASC,s.id ASC";
} else {
    $order = RecordSort::orderBy($sort);
}
$st = $crm->prepare('SELECT COUNT(*)' . $from . $where);
$st->execute($args);
$total = (int)$st->fetchColumn();
$perPage = PageSize::fromRequest();
$page = max(1, (int)($_GET['page'] ?? 1));
$page = min($page, max(1, (int)ceil($total / $perPage)));
$offset = ($page - 1) * $perPage;
$st = $crm->prepare('SELECT s.id,s.student_name,s.admission_no,s.phone,s.email,s.university,s.session_name,s.first_synced_at,s.created_at,s.admission_date,s.approved_payment_received,' . RecordSort::isNewExpr() . ' AS is_new,' . ($recvExpr !== null ? $recvExpr . ' AS recv_at,(fwp.completed_at IS NOT NULL) AS recv_forwarded,' : 'NULL AS recv_at,0 AS recv_forwarded,') . 'ows.completed_at AS fwd_at,' . $nextCols . 'st.status_label,(CASE WHEN ' . $erpRej . ' THEN 1 ELSE 0 END) AS erp_rejected,f.created_at AS last_at,f.next_followup_at,f.delay_reason,f.contact_result' . $fromList . $where . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . $offset);
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

function mw_url(array $merge): string { return '?' . http_build_query(array_merge($_GET, $merge)); }
function mw_dt(?string $v): string { $t = $v ? strtotime($v) : false; return $t ? date('d M, h:i A', $t) : '—'; }

ui_start([
    'title' => 'My work',
    'lead' => $labels[$role] . ' · ' . $me['name'],
    'user' => $user,
    'active' => 'my-work.php',
    'crumbs' => [['My work', null]],
    'actions' => '<a class="btn btn-primary" href="work-report.php?from=' . date('Y-m-d') . '&amp;to=' . date('Y-m-d') . '">' . ui_icon('note', 16) . 'My today’s work report</a>',
]);
?>
<div class="work-top">
  <div class="card">
    <div class="card-head"><h2>Today</h2><span class="muted small"><?=ui_e(date('l, d M Y'))?></span></div>
    <div class="grid grid-3">
      <div class="stat"><span class="k">Due today / overdue</span><span class="v"><?=number_format($dueLeft)?></span></div>
      <div class="stat"><span class="k">Follow-ups saved today</span><span class="v"><?=number_format($doneToday)?></span></div>
      <div class="stat"><span class="k">New records</span><span class="v"><?=number_format((int)($counts['c_untouched'] ?? 0))?></span></div>
    </div>
  </div>
  <div class="card">
    <div class="card-head"><h2>Daily progress</h2><strong><?=$pct?>%</strong></div>
    <div class="progress" role="progressbar" aria-valuenow="<?=$pct?>" aria-valuemin="0" aria-valuemax="100" aria-label="Today's follow-up progress"><i style="width:<?=$pct?>%"></i></div>
    <p class="help" style="margin-top:8px"><?=$dueLeft === 0 && $doneToday > 0 ? 'Everything due today is done. Nice work!' : number_format($doneToday) . ' done, ' . number_format($dueLeft) . ' still due today.'?></p>
  </div>
</div>

<nav class="tabs work-tabs" aria-label="My work lists">
  <?php foreach ($tabs as $key => [$label, $hint]): ?>
    <a class="tab" href="<?=ui_e(mw_url(['tab' => $key, 'page' => 1]))?>" title="<?=ui_e($hint)?>"<?=$tab === $key ? ' aria-current="page"' : ''?>><?=ui_e($label)?> <span class="count"><?=number_format((int)($counts['c_' . $key] ?? 0))?></span></a>
  <?php endforeach; ?>
</nav>

<form class="work-filters" method="get">
  <input type="hidden" name="tab" value="<?=ui_e($tab)?>"><input type="hidden" name="per_page" value="<?=$perPage?>">
  <div class="field grow"><label for="mw-q">Search my records</label><input class="input" id="mw-q" type="search" name="q" value="<?=ui_e($q)?>" placeholder="Name, phone, email or admission no."></div>
  <div class="field"><?php if ($recvExpr !== null):
      $sortLabels = ['newest' => 'Newest forwarded first', 'oldest' => 'Oldest forwarded first', 'due' => 'Follow-up due date'] + RecordSort::more(); ?>
    <label>Sort by<select class="select" name="sort" onchange="this.form.submit()"><?php foreach ($sortLabels as $sv => $sl): ?><option value="<?=ui_e($sv)?>"<?=$sv === $sort ? ' selected' : ''?>><?=ui_e($sl)?></option><?php endforeach; ?></select></label>
  <?php else: ?><?=RecordSort::select($sort, ['due' => 'Follow-up due date'] + ($isDoc && $tab === 'progress' ? ['untouched' => 'Untouched first'] : []) + RecordSort::more())?><?php endif; ?></div>
  <button class="btn btn-primary" type="submit" data-loading>Search</button>
  <?php if ($q !== ''): ?><a class="btn" href="<?=ui_e(mw_url(['q' => '', 'page' => 1]))?>">Clear</a><?php endif; ?>
</form>

<p class="muted small"><?=ui_e($tabs[$tab][1])?> · <strong><?=number_format($total)?></strong> record(s)</p>

<?php if (!$rows): ?>
  <div class="card"><div class="empty"><?=ui_icon('inbox', 48)?><h3><?=$tab === 'today' && $q === '' ? 'Nothing due today' : 'No records here'?></h3><p><?=$q !== '' ? 'No record matches your search in this list.' : 'When records match this list they will appear here.'?></p><?php $firstTab = isset($tabs['new']) ? 'new' : 'progress'; if ($tab !== $firstTab): ?><a class="btn" href="<?=ui_e(mw_url(['tab' => $firstTab, 'q' => '', 'page' => 1]))?>">See new records</a><?php endif; ?></div></div>
<?php else: ?>
  <div class="work-list">
  <?php foreach ($rows as $r):
      $overdue = $r['next_followup_at'] && strtotime((string)$r['next_followup_at']) < time() && in_array($tab, ['today', 'progress', 'new'], true);
      $wa = ContactActions::phone($r['phone'] ?? '');
      $mail = filter_var(trim((string)($r['email'] ?? '')), FILTER_VALIDATE_EMAIL);
      $sid = (int)$r['id'];
      $name = (string)$r['student_name'];
  ?>
    <article class="work-card<?=$overdue ? ' is-overdue' : ''?>">
      <div>
        <h3><a href="student.php?id=<?=$sid?>"><?=ui_e($name !== '' ? $name : 'Unnamed student')?></a><?php if ((int)$r['erp_rejected'] === 1 && ($tab === 'rejected' || ($isDoc && $tab === 'progress'))): ?><?=trim((string)$r['status_label']) !== '' ? ui_status_badge($r['status_label'], 'New') : ''?><span class="badge b-red">ERP: Rejected</span><?php else: ?><?=ui_status_badge($r['status_label'], 'New', $isClosedTab)?><?php endif; ?><?php if ($recvExpr === null): ?><?=(int)$r['is_new'] === 1 ? '<span class="new-pill">Synced today</span>' : ''?><?php else: $rt = $r['recv_at'] ? strtotime((string)$r['recv_at']) : false; ?><?=($rt && $rt >= time() - 86400) ? '<span class="new-pill">' . ((int)$r['recv_forwarded'] === 1 ? 'Forwarded today' : 'Assigned today') . '</span>' : ''?><?php endif; ?></h3>
        <div class="work-meta">
          <span>#<?=ui_e($r['admission_no'])?></span>
          <?php if ($r['university']): ?><span><b><?=ui_e($r['university'])?></b></span><?php endif; ?>
          <?php if ($r['session_name']): ?><span><?=ui_e($r['session_name'])?></span><?php endif; ?>
          <?php if ($r['phone']): ?><span><?=ui_e($r['phone'])?></span><?php endif; ?>
          <?php if ($recvExpr !== null && !$isClosedTab): ?><span><?=(int)$r['recv_forwarded'] === 1 ? 'Forwarded on' : 'Assigned on'?> <b><?=ui_e(RecordSort::stamp($r['recv_at']))?></b></span><?php endif; ?>
        </div>
        <?php if ($isClosedTab && $nextRole !== null): ?>
        <div class="work-meta" style="margin-top:6px"><span>Forwarded to <b><?=ui_e($labels[$nextRole] ?? $nextRole)?></b> on <b><?=ui_e(RecordSort::stamp($r['fwd_at']))?></b></span><span>Owner now: <b><?=$r['next_owner'] ? ui_e($r['next_owner']) : 'Not assigned yet'?></b></span><span>Status: <b><?=$r['next_status'] ? ui_e($r['next_status']) : 'Not started'?></b></span></div>
        <?php endif; ?>
        <p class="work-reason"><i>Pending reason:</i> <?=$r['delay_reason'] ? ui_e(mb_strimwidth((string)$r['delay_reason'], 0, 140, '…')) : '<span class="muted">Not recorded</span>'?></p>
        <div class="work-meta" style="margin-top:6px"><span>Last follow-up: <b><?=ui_e(mw_dt($r['last_at']))?></b></span><span>Next: <b<?=$overdue ? ' style="color:var(--red)"' : ''?>><?=ui_e(mw_dt($r['next_followup_at']))?><?=$overdue ? ' (overdue)' : ''?></b></span></div>
      </div>
      <div class="work-side">
        <div class="quick" role="group" aria-label="Contact <?=ui_e($name)?>">
          <?php if ($wa !== ''): ?>
            <a class="btn btn-icon btn-sm q-call" href="tel:+<?=ui_e($wa)?>" aria-label="Call <?=ui_e($name)?>" title="Call"><?=ui_icon('phone')?></a>
            <a class="btn btn-icon btn-sm q-wa" href="https://wa.me/<?=ui_e($wa)?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp <?=ui_e($name)?>" title="WhatsApp"><?=ui_icon('chat')?></a>
          <?php endif; ?>
          <?php if ($mail): ?><a class="btn btn-icon btn-sm q-mail" href="send-email.php?id=<?=$sid?>" aria-label="Email <?=ui_e($name)?>" title="Email"><?=ui_icon('mail')?></a><?php endif; ?>
          <a class="btn btn-icon btn-sm q-note" href="student.php?id=<?=$sid?>&amp;section=followup#team-followup" aria-label="Add note for <?=ui_e($name)?>" title="Update status / add note"><?=ui_icon('note')?></a>
        </div>
        <a class="btn btn-primary btn-sm" href="student.php?id=<?=$sid?>">Open record</a>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
  <nav class="pager" aria-label="Pagination">
    <span><?=number_format($total)?> records · Page <?=$page?> of <?=max(1, (int)ceil($total / $perPage))?></span>
    <span class="group" style="display:flex;gap:8px">
      <?php if ($page > 1): ?><a class="btn btn-sm" href="<?=ui_e(mw_url(['page' => $page - 1]))?>">Previous</a><?php endif; ?>
      <?php if ($page * $perPage < $total): ?><a class="btn btn-sm" href="<?=ui_e(mw_url(['page' => $page + 1]))?>">Next</a><?php endif; ?>
    </span>
  </nav>
<?php endif; ?>
<?php ui_end(); ?>
