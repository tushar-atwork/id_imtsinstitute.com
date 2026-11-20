<?php
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/TeamWorkflow.php';require_once __DIR__.'/../app/ui/layout.php';
$me=Phase2::current($crm);$roles=TeamWorkflow::roles();$role=$me['role']==='admin'?(string)($_GET['team']??TeamWorkflow::DOC):(string)$me['role'];
if(!TeamWorkflow::isTeam($role)){ui_error_page(403,'This queue is not available to you','Only administrators or members of a CRM team can access this queue.');}
// Phase 50: the Documents queue hides forwarded and rejected records from its working views
// and lists Pending, Rejected and Forwarded records separately.
// Phase 52: Form Filled and Verification queues work the same way.
$views=['due'=>'Due / overdue','upcoming'=>'Upcoming','all'=>'All active (including untouched)','pending'=>'Pending','rejected'=>'Rejected','forwarded'=>WorkflowPipeline::teamViews($role)['forwarded']];
$view=(string)($_GET['view']??'due');if(!isset($views[$view]))$view='due';
$q=trim((string)($_GET['q']??''));if(mb_strlen($q)>100)$q=mb_substr($q,0,100);
// Latest TEAM-specific follow-up per student; other team's activity never replaces this team's next date.
$sql=' FROM crm_students s JOIN crm_team_assignments a ON a.student_id=s.id AND a.team_role=? LEFT JOIN crm_users owner ON owner.id=a.assigned_user_id LEFT JOIN (SELECT f.* FROM crm_team_followups f JOIN (SELECT student_id,team_role,MAX(id) id FROM crm_team_followups GROUP BY student_id,team_role) latest ON latest.id=f.id) f ON f.student_id=s.id AND f.team_role=a.team_role LEFT JOIN crm_team_statuses st ON st.id=f.status_id WHERE s.is_active=1';
$args=[$role];if($me['role']!=='admin'){$sql.=' AND a.assigned_user_id=?';$args[]=(int)$me['id'];}
if($q!==''){$sql.=' AND (s.student_name LIKE ? OR s.admission_no LIKE ?)';$args[]='%'.$q.'%';$args[]='%'.$q.'%';}
require_once __DIR__.'/../app/ListFilters.php';require_once __DIR__.'/../app/RecordSort.php';
$sort=RecordSort::current([RecordSort::DUE]);$extra=[];
ListFilters::dateWhere($extra,$args,'admission','s.admission_date');ListFilters::dateWhere($extra,$args,'due','f.next_followup_at');
foreach(['university'=>'s.university','counsellor'=>'s.counsellor_name','session_filter'=>'s.session_name','admission_filter'=>'s.admission_status','contact_filter'=>'f.contact_result'] as $key=>$expr)ListFilters::equality($extra,$args,$key,$expr);
$teamStatuses=ListFilters::values('team_status');
if($teamStatuses){$parts=[];if(in_array('none',$teamStatuses,true))$parts[]='f.id IS NULL';$ids=array_values(array_filter($teamStatuses,'ctype_digit'));if($ids)$parts[]=ListFilters::in('f.status_id',$ids,$args);if($parts)$extra[]='('.implode(' OR ',$parts).')';}
ListFilters::ownerWhere($extra,$args,'responsible',$role);
if($extra)$sql.=' AND '.implode(' AND ',$extra);
$sql.=' AND '.WorkflowPipeline::teamViewSql($role,in_array($view,['pending','rejected','forwarded'],true)?$view:'active');
if($view==='due')$sql.=' AND f.next_followup_at IS NOT NULL AND f.next_followup_at<=NOW()';
if($view==='upcoming')$sql.=' AND f.next_followup_at>NOW()';
$st=$crm->prepare('SELECT COUNT(*)'.$sql);$st->execute($args);$total=(int)$st->fetchColumn();$perPage=PageSize::fromRequest();$page=max(1,(int)($_GET['page']??1));$page=min($page,max(1,(int)ceil($total/$perPage)));$offset=($page-1)*$perPage;
$st=$crm->prepare('SELECT s.id,s.first_synced_at,s.created_at,'.RecordSort::isNewExpr().' AS is_new,s.student_name,s.admission_no,s.admission_status,owner.name AS owner,st.status_label,f.next_followup_at,f.contact_result,f.delay_reason,f.remark_for_verification'.$sql.' ORDER BY '.($sort===RecordSort::DUE?'(f.next_followup_at IS NULL),f.next_followup_at ASC,s.id DESC':RecordSort::orderBy($sort)).' LIMIT '.$perPage.' OFFSET '.$offset);$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
function qh(mixed $s):string{return TeamWorkflow::e($s??'—');}
function qurl(array $merge):string{return '?'.http_build_query(array_merge($_GET,$merge));}

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$nowTs=time();
$statusHead=$role===TeamWorkflow::DOC?'Document status':($role===TeamWorkflow::FORM?'Form filled status':'Verification status');
ui_start([
 'title'=>$roles[$role].' follow-up queue',
 'lead'=>'Independent team assignments, statuses and next follow-up dates. All three assigned teams can work on the same form concurrently. Document and Form Filled completion also record automatic handoffs.',
 'user'=>$pageUser,'active'=>'team-queue.php',
 'crumbs'=>[['Work',null],['Team queue',null]],
 'actions'=>$me['role']==='admin'?'<a class="btn" href="followups.php">Student follow-ups</a>':'<a class="btn" href="my-work.php">My work</a>',
]);
?>
<?php if($me['role']==='admin'):?>
<nav class="tabs" aria-label="Team"><?php foreach($roles as $key=>$label):?><a class="tab" <?=$role===$key?'aria-current="page"':''?> href="<?=qh(qurl(['team'=>$key,'page'=>1]))?>"><?=qh($label)?></a><?php endforeach;?></nav>
<?php endif;?>
<nav class="presets" aria-label="Queue views" style="margin:0 0 var(--s4)"><?php foreach($views as $v=>$label):?><a class="btn btn-sm <?=$view===$v?'btn-primary':''?>" <?=$view===$v?'aria-current="page"':''?> href="<?=qh(qurl(['view'=>$v,'page'=>1]))?>"><?=qh($label)?></a><?php endforeach;?></nav>
<section class="card report-section">
 <?=ListFilters::styles()?>
 <details class="filter-panel" <?=($q!==''||ListFilters::any(['university','counsellor','session_filter','admission_filter','responsible','team_status','contact_filter'])||array_filter([ListFilters::value('admission_from'),ListFilters::value('admission_to'),ListFilters::value('due_from'),ListFilters::value('due_to')]))?'open':''?>>
  <summary>Search &amp; filters</summary>
  <form method="get"><input type="hidden" name="team" value="<?=qh($role)?>"><input type="hidden" name="view" value="<?=qh($view)?>"><input type="hidden" name="per_page" value="<?=$perPage?>">
   <div class="field recs-search"><label for="tq-q">Search student</label><input class="input" id="tq-q" type="search" name="q" value="<?=qh($q==='' ? '' : $q)?>" placeholder="Name or admission no."></div>
   <div class="crm-extra-filters">
    <?=RecordSort::select($sort,[RecordSort::DUE=>'Follow-up due date'])?>
    <?=ListFilters::dropdown($crm,$me,'university','University','university')?><?=ListFilters::dropdown($crm,$me,'counsellor','Counsellor','counsellor_name')?>
    <?=ListFilters::dropdown($crm,$me,'session_filter','Session','session_name')?><?=ListFilters::dropdown($crm,$me,'admission_filter','Admission status','admission_status')?>
    <?=ListFilters::owners($crm,$me,'responsible',$role)?>
    <?php $opts=['none'=>'No status yet'];foreach(TeamWorkflow::statuses($crm,$role,false) as $stRow)$opts[$stRow['id']]=$stRow['status_label'];echo ListFilters::select('team_status','Team status',$opts); ?>
    <?=ListFilters::select('contact_filter','Contact result',['Connected'=>'Connected','Not Connected'=>'Not Connected'])?>
    <?=ListFilters::dates('admission','Admission date')?><?=ListFilters::dates('due','Follow-up due date')?>
   </div>
   <div class="filter-actions"><button class="btn btn-primary" type="submit" data-loading>Filter</button><a class="btn" href="team-queue.php?team=<?=qh($role)?>&amp;view=<?=qh($view)?>">Reset</a></div>
  </form>
 </details>
 <?=PageSize::selector($perPage, $_GET)?>
 <p class="muted small"><strong><?=number_format($total)?></strong> matching students · Page <?=$page?></p>
 <div class="table-wrap"><table class="table"><thead><tr><th>Admission</th><th>Student</th><th>Owner</th><th>ERP status</th><th><?=qh($statusHead)?></th><th>Contact</th><th>Next follow-up</th><th>Synced</th><th></th></tr></thead><tbody>
 <?php foreach($rows as $r): $nts=$r['next_followup_at']?strtotime((string)$r['next_followup_at']):false;$over=$nts&&$nts<$nowTs&&in_array($view,['due','all'],true);?>
  <tr>
   <td><?=qh($r['admission_no'])?></td>
   <td><a class="rec-link" href="student.php?id=<?=(int)$r['id']?>"><?=qh($r['student_name'])?></a><?=RecordSort::badge($r['is_new'])?></td>
   <td><?=$r['owner']?qh($r['owner']):'<span class="muted"><em>Unassigned</em></span>'?></td>
   <td><?=ui_status_badge($r['admission_status'],'—')?></td>
   <td><?=ui_status_badge($r['status_label'],'No status yet',$view==='forwarded')?></td>
   <td><?=$r['contact_result']?qh($r['contact_result']):'<span class="muted">—</span>'?></td>
   <td><?php if($nts):?><div><?=qh(date('d M Y, h:i A',$nts))?></div><?=$over?'<span class="badge b-red">Overdue</span>':''?><?php else:?><span class="muted">—</span><?php endif;?></td>
   <td><?=qh(RecordSort::stamp($r['first_synced_at']??$r['created_at']??null))?></td>
   <td><a class="btn btn-sm btn-primary" href="student.php?id=<?=(int)$r['id']?>">Open</a></td>
  </tr>
 <?php endforeach;?>
 <?php if(!$rows):?><tr><td colspan="9"><div class="empty" style="padding:24px"><?=ui_icon('inbox',40)?><h3>No assigned students match this view</h3><p>Try another view or clear the filters.</p></div></td></tr><?php endif;?>
 </tbody></table></div>
 <nav class="pager" aria-label="Pagination"><span><?=number_format($total)?> students · Page <?=$page?></span><span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="<?=qh(qurl(['page'=>$page-1]))?>">Previous</a><?php endif;?><?php if($page*$perPage<$total):?><a class="btn btn-sm" href="<?=qh(qurl(['page'=>$page+1]))?>">Next</a><?php endif;?></span></nav>
</section>
<?php ui_end(); ?>
