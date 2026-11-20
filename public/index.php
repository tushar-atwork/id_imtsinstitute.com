<?php
require_once __DIR__.'/../app/PageSize.php';
require __DIR__ . '/../app/bootstrap.php';
Auth::requireLogin();
require_once __DIR__ . '/../app/Phase2.php';
require_once __DIR__ . '/../app/TeamWorkflow.php';
$phase2user = Phase2::current($crm);
// Phase 11 migration guard: do not let missing new tables take the whole dashboard down.
$phase11Tables = ['crm_team_assignments','crm_team_statuses','crm_team_followups','crm_team_assignment_history'];
$phase11Missing = [];
foreach ($phase11Tables as $table) {
    try { $crm->query('SELECT 1 FROM `'.$table.'` LIMIT 0'); }
    catch (PDOException $ex) { $phase11Missing[] = $table; }
}
$phase11Ready = !$phase11Missing;
if (!$phase11Ready && TeamWorkflow::isTeam((string)$phase2user['role'])) {
    http_response_code(503);
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Setup required · IMTS CRM</title><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/phase10.css"><link rel="stylesheet" href="assets/phase12.css?v=1"></head><body><main class="container"><section class="panel"><h1>Team workflow setup is not complete</h1><p>Please ask the CRM administrator to import the Phase 11 SQL file into the CRM database. Your student records and earlier follow-ups have not been changed by this message.</p><a href="logout.php">Sign out</a></section></main></body></html><?php exit;
}


$_SESSION['csrf'] ??= bin2hex(random_bytes(24));

require_once __DIR__.'/../app/ListFilters.php';
require_once __DIR__.'/../app/RecordSort.php';require_once __DIR__.'/../app/AssignmentSettings.php';require_once __DIR__.'/../app/ui/layout.php';
$sort = RecordSort::current(array_keys(RecordSort::more())); // Phase 54: newest synced first by default
$q = trim((string)($_GET['q'] ?? ''));
// Phase 50: these filters accept several values (status[]=1&status[]=2).
$statusValues = array_values(array_unique(array_filter(array_map('intval', ListFilters::values('status')), static fn(int $v): bool => $v >= 1 && $v <= 4)));
$universityValues = ListFilters::values('university');
$counsellorValues = ListFilters::values('counsellor');

// Phase 50: Documents record views. Documents employees no longer see records they have
// forwarded in their active list; Pending, Rejected and Forwarded each have their own list.
// Phase 52: Form Filled and Verification employees get the same tabs for their own team.
$isDocUser = $phase11Ready && $phase2user['role'] === TeamWorkflow::DOC;
$isTeamUser = $phase11Ready && TeamWorkflow::isTeam((string)$phase2user['role']);
$viewTeam = $isTeamUser ? (string)$phase2user['role'] : TeamWorkflow::DOC;
$docViews = [];
if ($isTeamUser) {
    // "All my records" = every record assigned to this employee (matches the ERP count).
    $docViews = WorkflowPipeline::teamViews($viewTeam);
} elseif ($phase11Ready && $phase2user['role'] === 'admin') {
    $docViews = ['all'=>'All records','active'=>'In Documents','pending'=>'Documents: Pending','rejected'=>'Documents: Rejected','forwarded'=>'Forwarded from Documents'];
}
$docView = (string)($_GET['records'] ?? '');
if (!isset($docViews[$docView])) $docView = $docViews ? (string)array_key_first($docViews) : 'all';
$docCondition = $docViews ? WorkflowPipeline::teamViewSql($viewTeam, $docView) : null;

$where = ["s.is_active = 1"];
$params = [];
if ($phase11Ready && TeamWorkflow::isTeam((string)$phase2user['role'])) {
    $where[] = 'EXISTS (SELECT 1 FROM crm_team_assignments mine WHERE mine.student_id=s.id AND mine.team_role=? AND mine.assigned_user_id=?)';
    $params[]=$phase2user['role'];$params[]=(int)$phase2user['id'];
} elseif ($phase2user['role'] !== 'admin') {
    $where[] = 'a.assigned_user_id = ?';$params[]=(int)$phase2user['id'];
}

if ($q !== '') {
    $where[] = "(s.student_name LIKE ? OR s.admission_no LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)";
    $like = "%{$q}%";
    array_push($params, $like, $like, $like, $like);
}
if ($statusValues) $where[] = ListFilters::in('s.admission_status_id', $statusValues, $params);
if ($universityValues) $where[] = ListFilters::in('s.university', $universityValues, $params);
if ($counsellorValues) $where[] = ListFilters::in('s.counsellor_name', $counsellorValues, $params);
if ($docCondition) $where[] = $docCondition;

// Phase 21: database-side filters are applied BEFORE pagination and count.
$verifyFrom = trim((string)($_GET['verification_from'] ?? ''));
$verifyTo = trim((string)($_GET['verification_to'] ?? ''));
$docsMin = trim((string)($_GET['documents_delay_min'] ?? ''));
$docsMax = trim((string)($_GET['documents_delay_max'] ?? ''));
$sendMin = trim((string)($_GET['send_delay_min'] ?? ''));
$sendMax = trim((string)($_GET['send_delay_max'] ?? ''));
$latestDocStatus = "(SELECT LOWER(TRIM(ts.status_label)) FROM crm_team_followups tf JOIN crm_team_statuses ts ON ts.id=tf.status_id WHERE tf.student_id=s.id AND tf.team_role='document_collection' ORDER BY tf.created_at DESC,tf.id DESC LIMIT 1)";
$latestDocDate = "(SELECT tf.created_at FROM crm_team_followups tf JOIN crm_team_statuses ts ON ts.id=tf.status_id WHERE tf.student_id=s.id AND tf.team_role='document_collection' ORDER BY tf.created_at DESC,tf.id DESC LIMIT 1)";
$docComplete = "($latestDocStatus REGEXP 'all[[:space:]]+(completed|complete)[[:space:]]+doc|all[[:space:]]+doc.*complet|documents?[[:space:]]+complet')";
$docDelayExpr = "DATEDIFF(DATE($latestDocDate),DATE(s.counsellor_form_assigned_erp_date))";
$sendDelayExpr = 'DATEDIFF(DATE(s.counsellor_form_assigned_erp_date),DATE(s.admission_date))';
foreach (['verification_from'=>$verifyFrom,'verification_to'=>$verifyTo] as $name=>$value) {
 if($value!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$value) && checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4))){$where[]='DATE(s.erp_verified_at) '.($name==='verification_from'?'>=':'<=').' ?';$params[]=$value;}
}
foreach ([[$docsMin,'>=',$docDelayExpr,true],[$docsMax,'<=',$docDelayExpr,true],[$sendMin,'>=',$sendDelayExpr,false],[$sendMax,'<=',$sendDelayExpr,false]] as [$value,$op,$expr,$docs]) {
 if($value!=='' && ctype_digit($value) && (int)$value<=36500){$where[]=($docs?"($docComplete AND $expr $op ?)":"($expr $op ?)");$params[]=(int)$value;}
}
ListFilters::dateWhere($where,$params,'admission','s.admission_date');
ListFilters::dateWhere($where,$params,'synced','s.first_synced_at');
ListFilters::equality($where,$params,'session_filter','s.session_name');
ListFilters::equality($where,$params,'confirmation_filter','s.admission_confirmation_status');
foreach(TeamWorkflow::roles() as $team=>$label){
 ListFilters::ownerWhere($where,$params,$team.'_owner',$team);
 ListFilters::teamStatusWhere($where,$params,$team.'_status',$team);
}
$whereSql = implode(' AND ', $where);

$count = $crm->prepare("SELECT COUNT(*) FROM crm_students s LEFT JOIN crm_student_assignments a ON a.student_id=s.id WHERE {$whereSql}");
$count->execute($params);
$total = (int)$count->fetchColumn();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = PageSize::fromRequest();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stmt = $crm->prepare(
    "SELECT s.*,".RecordSort::isNewExpr()." AS crm_is_new,u.name AS local_owner, " . ($phase11Ready ? "du.name AS document_owner,vu.name AS verification_owner,fu.name AS form_owner" : "NULL AS document_owner,NULL AS verification_owner,NULL AS form_owner") . "
     FROM crm_students s
     LEFT JOIN crm_student_assignments a ON a.student_id=s.id
     LEFT JOIN crm_users u ON u.id=a.assigned_user_id
     " . ($phase11Ready ? "LEFT JOIN crm_team_assignments da ON da.student_id=s.id AND da.team_role='document_collection'
     LEFT JOIN crm_users du ON du.id=da.assigned_user_id
     LEFT JOIN crm_team_assignments fa ON fa.student_id=s.id AND fa.team_role='form_filled' LEFT JOIN crm_users fu ON fu.id=fa.assigned_user_id
     LEFT JOIN crm_team_assignments va ON va.student_id=s.id AND va.team_role='verification'
     LEFT JOIN crm_users vu ON vu.id=va.assigned_user_id" : "") . "
     WHERE {$whereSql}
     ORDER BY " . RecordSort::orderBy($sort) . "
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$students = $stmt->fetchAll();
require_once __DIR__.'/../app/AdmissionTiming.php';
$admissionTiming=AdmissionTiming::forStudents($crm,$students);

$summaryWhere = 's.is_active=1';$summaryParams=[];
if($phase11Ready && TeamWorkflow::isTeam((string)$phase2user['role'])){
 $summaryWhere.=' AND EXISTS (SELECT 1 FROM crm_team_assignments ta WHERE ta.student_id=s.id AND ta.team_role=? AND ta.assigned_user_id=?)';
 $summaryParams=[$phase2user['role'],(int)$phase2user['id']];
}elseif($phase2user['role']!=='admin'){
 $summaryWhere.=' AND EXISTS (SELECT 1 FROM crm_student_assignments a WHERE a.student_id=s.id AND a.assigned_user_id=?)';
 $summaryParams=[(int)$phase2user['id']];
}
// Phase 51: documents completed, counted over the user's whole scope (not limited to the selected tab).
$docDone=['complete'=>0,'sent_pending'=>0];
if($phase11Ready){
 $dq=$crm->prepare("SELECT COALESCE(SUM(LOWER(TRIM(ws.outcome))='send with all completed docx'),0) complete,COALESCE(SUM(LOWER(TRIM(ws.outcome))<>'send with all completed docx'),0) sent_pending FROM crm_students s JOIN crm_workflow_stages ws ON ws.student_id=s.id AND ws.team_role='document_collection' AND ws.completed_at IS NOT NULL WHERE $summaryWhere");
 $dq->execute($summaryParams);$docDone=$dq->fetch(PDO::FETCH_ASSOC)?:$docDone;
}
// Summary cards cover the user's whole scope so they stay the same when switching tabs
// (the tab only changes the list and the "record(s)" count below).
$sum=$crm->prepare("SELECT COUNT(*) total,SUM(s.admission_status_id=1) incomplete_count,SUM(s.admission_status_id=2) pending_count,SUM(s.admission_status_id=4) rejected_count,COALESCE(SUM(s.approved_payment_received),0) payment_total FROM crm_students s WHERE $summaryWhere");
$sum->execute($summaryParams);$summary=$sum->fetch();

$universities = $crm->query(
    "SELECT DISTINCT university FROM crm_students
     WHERE is_active=1 AND university IS NOT NULL AND university<>''
     ORDER BY university"
)->fetchAll(PDO::FETCH_COLUMN);

$counsellors = $crm->query(
    "SELECT DISTINCT counsellor_name FROM crm_students
     WHERE is_active=1 AND counsellor_name IS NOT NULL AND counsellor_name<>''
     ORDER BY counsellor_name"
)->fetchAll(PDO::FETCH_COLUMN);

$lastSync = $crm->query(
    "SELECT * FROM crm_sync_logs ORDER BY id DESC LIMIT 1"
)->fetch();

$syncResult = $_SESSION['sync_result'] ?? null;
unset($_SESSION['sync_result']);

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return '₹' . number_format((float)$v, 0); }
function crmPhone($value): string {
    $number = preg_replace('/[^0-9]/', '', (string)$value);
    if (strlen($number) === 10) $number = '91'.$number;
    elseif (strlen($number) === 11 && $number[0] === '0') $number = '91'.substr($number, 1);
    return preg_match('/^[1-9][0-9]{9,14}$/D', $number) ? $number : '';
}


$isAdminUser = $phase2user['role'] === 'admin';
$pageActions = '<a class="btn" href="team-queue.php">Team follow-ups</a>';
if ($isAdminUser) {
    $pageActions .= '<form method="post" action="run-sync.php" data-confirm="Run the ERP sync now? It can take a minute."><input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '"><button class="btn btn-primary" type="submit" data-loading>Sync ERP now</button></form>';
}
ui_start([
    'title' => $isTeamUser ? 'All my records' : 'Admissions',
    'lead' => 'Incomplete, Pending and Rejected · Approved payment ≥ ₹4,800',
    'user' => ['name' => $phase2user['name'], 'role' => $phase2user['role']],
    'active' => 'index.php',
    'crumbs' => [[$isTeamUser ? 'All my records' : 'Records', null]],
    'actions' => $pageActions,
]);
?>
<?php if ($syncResult): ?>
  <div class="alert <?=$syncResult['ok'] ? 'alert-success' : 'alert-error'?>" role="status">
    <div>
      Sync <?=$syncResult['ok'] ? 'completed' : 'failed'?>.
      Read: <?=e($syncResult['eligible_read'])?>,
      Inserted: <?=e($syncResult['inserted'])?>,
      Updated: <?=e($syncResult['updated'])?>,
      Deactivated: <?=e($syncResult['deactivated'])?>,
      Owners assigned from ERP: <?=e($syncResult['owners_changed'] ?? 0)?>.
      <?php if (!$syncResult['ok']): ?><?=e($syncResult['error'] ?? '')?><?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($isAdminUser): ?>
  <p class="muted small" style="margin:0 0 var(--s3)">Team administration: <a href="team-settings.php?view=employees">Employees</a> · <a href="team-settings.php?view=create-user">Add employee</a> · <a href="team-settings.php">Settings</a></p>
  <?php if (!$phase11Ready): ?><div class="alert alert-error" role="alert"><div><strong>Phase 11 database migration required.</strong> Missing tables: <?=e(implode(', ', $phase11Missing))?>. Import <code>PHASE11_IMPORT.sql</code> into your existing <code>imtsinst_id</code> database through phpMyAdmin. Existing Phase 1 students will still display here.</div></div><?php endif; ?>
  <?=AssignmentSettings::warningHtml($crm)?>
<?php endif; ?>

<section class="stats-row" aria-label="Summary">
  <div class="card stat"><span class="k">Eligible students</span><span class="v"><?=number_format((int)$summary['total'])?></span></div>
  <div class="card stat"><span class="k">Incomplete</span><span class="v"><?=number_format((int)$summary['incomplete_count'])?></span></div>
  <div class="card stat"><span class="k">Pending</span><span class="v"><?=number_format((int)$summary['pending_count'])?></span></div>
  <div class="card stat"><span class="k">Rejected</span><span class="v"><?=number_format((int)$summary['rejected_count'])?></span></div>
  <div class="card stat"><span class="k">Approved payment</span><span class="v"><?=money($summary['payment_total'])?></span></div>
  <?php if ($phase11Ready): ?><div class="card stat"><span class="k">Documents completed</span><span class="v"><?=number_format((int)$docDone['complete'])?></span><span class="n"><?=number_format((int)$docDone['sent_pending'])?> sent with docs pending · <a href="work-report.php">Work report</a></span></div><?php endif; ?>
</section>

<section class="card">
  <div class="meta-line">
    <span>Last sync:
      <?php if ($lastSync): ?><strong><?=e($lastSync['status'])?></strong> · <?=e($lastSync['finished_at'] ?: $lastSync['started_at'])?><?php else: ?>Never<?php endif; ?>
    </span>
  </div>

  <?php if ($docViews): ?>
  <nav class="tabs" aria-label="Record lists">
    <?php foreach ($docViews as $key => $label): ?>
      <a class="tab" <?=$docView===$key?'aria-current="page"':''?> href="?<?=e(http_build_query(array_merge($_GET, ['records'=>$key, 'page'=>1])))?>"><?=e($label)?></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($isTeamUser && $docView === 'forwarded'): ?><p class="help"><?=e([TeamWorkflow::DOC=>'Records you completed and forwarded to Form Filled.',TeamWorkflow::FORM=>'Records you marked Form filled and forwarded to Verification.',TeamWorkflow::VER=>'Records you verified.'][$viewTeam] ?? '')?> Kept here for reference; open a record to see its full history.</p><?php endif; ?>
  <?php endif; ?>

  <?=ListFilters::styles()?>
  <details class="filter-panel" open>
    <summary>Search &amp; filters</summary>
    <form class="filters" method="get"><input type="hidden" name="per_page" value="<?=$perPage?>"><?php if ($docViews): ?><input type="hidden" name="records" value="<?=e($docView)?>"><?php endif; ?>
      <div class="field recs-search"><label for="rec-q">Search</label><input class="input" id="rec-q" type="search" name="q" value="<?=e($q)?>" placeholder="Search name / admission no / phone / email"></div>
      <div class="crm-extra-filters">
        <?=RecordSort::select($sort, RecordSort::more())?>
        <?=ListFilters::select('status', 'Admission status (ERP)', [1=>'Incomplete', 2=>'Pending', 3=>'Approved', 4=>'Rejected'])?>
        <?=ListFilters::select('university', 'University', array_combine($universities, $universities))?>
        <?=ListFilters::select('counsellor', 'Counsellor', array_combine($counsellors, $counsellors))?>
      </div>
<fieldset class="crm-filter-section"><legend>Team statuses &amp; responsible employees</legend><div class="crm-extra-filters">
<?php foreach(TeamWorkflow::roles() as $team=>$label): ?>
<?php $opts=['none'=>'No status yet'];foreach(TeamWorkflow::statuses($crm,$team,false) as $st)$opts[$st['id']]=$st['status_label'];echo ListFilters::select($team.'_status',['document_collection'=>'Documents status','form_filled'=>'Form Filled status','verification'=>'Verification status'][$team],$opts); ?>
<?php endforeach; ?>
<?php foreach(TeamWorkflow::roles() as $team=>$label)echo ListFilters::owners($crm,$phase2user,$team.'_owner',$team); ?>
</div></fieldset>
<details class="crm-filter-section" <?=(array_filter([ListFilters::value('admission_from'),ListFilters::value('admission_to'),ListFilters::value('synced_from'),ListFilters::value('synced_to')]) || ListFilters::any(['session_filter','confirmation_filter']))?'open':''?>><summary>Admission dates, session &amp; confirmation</summary><div class="crm-extra-filters">
<?=ListFilters::dates('admission','Admission date')?><?=ListFilters::dates('synced','First synced date')?>
<?=ListFilters::dropdown($crm,$phase2user,'session_filter','Session','session_name')?><?=ListFilters::dropdown($crm,$phase2user,'confirmation_filter','Confirmation','admission_confirmation_status')?>
</div></details>
<details class="crm-filter-section" <?=array_filter([$verifyFrom,$verifyTo,$docsMin,$docsMax,$sendMin,$sendMax])?'open':''?>><summary>Verification dates &amp; delay ranges</summary><div class="crm-extra-filters">
<label>Verification date from<input type="date" name="verification_from" value="<?=e($verifyFrom)?>"></label><label>Verification date to<input type="date" name="verification_to" value="<?=e($verifyTo)?>"></label>
<label>Documents delay min (days)<input type="number" min="0" max="36500" name="documents_delay_min" value="<?=e($docsMin)?>"></label><label>Documents delay max (days)<input type="number" min="0" max="36500" name="documents_delay_max" value="<?=e($docsMax)?>"></label>
<label>Send delay min (days)<input type="number" min="0" max="36500" name="send_delay_min" value="<?=e($sendMin)?>"></label><label>Send delay max (days)<input type="number" min="0" max="36500" name="send_delay_max" value="<?=e($sendMax)?>"></label>
</div></details>
      <div class="filter-actions">
        <button class="btn btn-primary" type="submit" data-loading>Filter</button>
        <a class="btn" href="index.php">Reset</a>
      </div>
    </form>
  </details>

  <?php echo PageSize::selector($perPage, $_GET); ?><div class="meta-line"><span><strong><?=number_format($total)?></strong> record(s) · Showing <?=number_format(count($students))?> on this page</span></div>

  <div class="column-toolbar"><button type="button" id="column-settings-toggle" aria-expanded="false" aria-controls="column-settings">⚙ Columns: show and arrange</button><span>The first five columns stay visible while scrolling horizontally. Scroll down to see more students.</span></div>
  <div id="column-settings" class="column-settings" hidden><div id="column-settings-list"></div><button type="button" id="column-reset">Reset columns</button></div><div class="p26-scroll-top" aria-label="Horizontal table scroll"><div class="p26-scroll-top-inner"></div></div><div class="table-wrap" id="p26-admissions-table">
    <table class="table recs" data-user-id="<?= (int)Auth::user()['id'] ?>">
      <thead>
        <tr>
          <th>Student Name</th>
          <th>University</th>
          <th>Admission Date</th>
          <th>Contact Number</th>
          <th>Email ID</th>
          <th>Admission No.</th>
          <th>Counsellor</th>
          <th>Session</th>
          <th>Date of Verification</th>
          <th>Documents Delay Days</th>
          <th>Delay sending for verification</th>
          <th>Admission Confirmation Status</th>
          <th>Admission Status</th>
          <th>Approved Amount</th>
          <th>Document Team</th><th>Form Filled owner</th><th>Verification Team</th>
          <th>Counsellor Form Assigned ERP Date</th><th>Last ERP Assignment</th>
          <th>Latest Docs Complete</th><th>Total Approval Days</th><th>Verification Processing Days</th><th>Current Pending Days</th><th>Synced</th></tr>
      </thead>
      <tbody>
      <?php foreach ($students as $s): ?>
        <tr>
          <?php $t=$admissionTiming[(int)$s['id']]??[]; ?>
          <td><a class="rec-link" href="student.php?id=<?=(int)$s['id']?>"><?=e($s['student_name'])?></a><?=RecordSort::badge($s['crm_is_new'] ?? 0)?></td>
          <td><?=e($s['university'] ?? '')?></td>
          <td><?=e($s['admission_date'] ?? '')?></td>
          <td><?=e($s['phone'])?><?php $phoneLink=crmPhone($s['phone']); if($phoneLink!==''): ?><span class="contact-actions"><a class="contact-icon" href="https://wa.me/<?=e($phoneLink)?>" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp <?=e($s['student_name'])?>">&#x2706; WhatsApp</a><a class="contact-icon" href="tel:+<?=e($phoneLink)?>" aria-label="Call <?=e($s['student_name'])?>">&#x260E; Call</a></span><?php endif; ?></td>
          <td><?=e($s['email'])?><?php if(filter_var($s['email'], FILTER_VALIDATE_EMAIL)): ?><span class="contact-actions"><a class="contact-icon" href="send-email.php?id=<?=(int)$s['id']?>" aria-label="Email <?=e($s['student_name'])?>">&#x2709; Email</a></span><?php endif; ?></td>
          <td><a href="student.php?id=<?=(int)$s['id']?>"><?=e($s['admission_no'])?></a></td>
          <td><?=e($s['counsellor_name'] ?? '')?></td>
          <td><?=e($s['session_name'] ?? '')?></td>
          <td><?=AdmissionTiming::date($t['verification_date']??null)?></td>
          <td><?=($t['docs_reopened']??false)?'Reopened – pending':e(AdmissionTiming::fmt($t['documents_delay_days']??null))?></td>
          <td><?=e(AdmissionTiming::fmt($t['send_delay_days']??null))?></td>
          <td><?=e($s['admission_confirmation_status'])?></td>
          <td><?=ui_status_badge($s['admission_status'], '—')?></td>
          <td><strong><?=money($s['approved_payment_received'])?></strong></td>
          <td><?=$s['document_owner'] ? e($s['document_owner']) : '<span class="unassigned">Unassigned</span>'?></td>
          <td><?=$s['form_owner'] ? e($s['form_owner']) : '<span class="unassigned">Unassigned</span>'?></td>
          <td><?=$s['verification_owner'] ? e($s['verification_owner']) : '<span class="unassigned">Unassigned</span>'?></td>
          <td><?=e($s['counsellor_form_assigned_erp_date'])?></td>
          <td><?=e($s['latest_erp_assignment_date'] ?? '')?></td>
          <td><?=($t['docs_reopened']??false)?'Reopened – pending':AdmissionTiming::date($t['docs_completed_at']??null)?></td>
          <td><?=e(AdmissionTiming::fmt($t['approval_days']??null))?></td>
          <td><?=e(AdmissionTiming::fmt($t['verification_processing_days']??null))?></td>
          <td><?=($t['verification_date']??null)?'Approved':e(AdmissionTiming::fmt($t['pending_days']??null))?></td>
          <td><?=e(RecordSort::stamp($s['first_synced_at'] ?? $s['created_at'] ?? null))?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$students): ?>
        <tr><td colspan="24" class="empty">No eligible records found.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
  <script src="assets/columns.js?v=1" defer></script>
  <script src="assets/phase26.js?v=1" defer></script>

  <nav class="pagination" aria-label="Pagination">
    <?php
    $base = $_GET;
    if ($page > 1) { $base['page'] = $page - 1; echo '<a href="?' . e(http_build_query($base)) . '" rel="prev">&larr; Prev</a>'; }
    for ($p=max(1,$page-2); $p<=min($pages,$page+2); $p++):
      $base['page']=$p;
    ?>
      <a class="<?=$p===$page?'active':''?>" <?=$p===$page?'aria-current="page"':''?> href="?<?=e(http_build_query($base))?>"><?=$p?></a>
    <?php endfor;
    if ($page < $pages) { $base['page'] = $page + 1; echo '<a href="?' . e(http_build_query($base)) . '" rel="next">Next &rarr;</a>'; }
    ?>
  </nav>
</section>
<?php ui_end(); ?>
