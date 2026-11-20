<?php
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/ui/layout.php';$u=Phase2::current($crm);
if(in_array((string)$u['role'],['document_collection','form_filled','verification'],true)){header('Location: team-queue.php',true,302);exit;}

$filter=(string)($_GET['view']??'due');if(!in_array($filter,['due','upcoming','all'],true))$filter='due';
$where=['s.is_active=1'];$params=[];if($u['role']!=='admin'){$where[]='a.assigned_user_id=?';$params[]=$u['id'];}
if($filter==='due')$where[]='f.next_followup_at<=NOW()';elseif($filter==='upcoming')$where[]='f.next_followup_at>NOW()';
require_once __DIR__.'/../app/ListFilters.php';require_once __DIR__.'/../app/RecordSort.php';
$sort=RecordSort::current([RecordSort::DUE]);
ListFilters::dateWhere($where,$params,'admission','s.admission_date');
ListFilters::dateWhere($where,$params,'due','f.next_followup_at');
foreach(['university'=>'s.university','counsellor'=>'s.counsellor_name','session_filter'=>'s.session_name','admission_filter'=>'s.admission_status','outcome_filter'=>'f.outcome','contact_filter'=>'f.contact_result'] as $key=>$expr)ListFilters::equality($where,$params,$key,$expr);
ListFilters::ownerWhere($where,$params,'responsible','legacy');
$search=ListFilters::value('q');if($search!==''){$where[]='(s.student_name LIKE ? OR s.admission_no LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)';for($i=0;$i<4;$i++)$params[]='%'.$search.'%';}
$where[]='f.next_followup_at IS NOT NULL';$sql=implode(' AND ',$where);
// Latest follow-up per student only, so rescheduling removes the previous due date.
$perPage=PageSize::fromRequest();$page=max(1,(int)($_GET['page']??1));
$count=$crm->prepare("SELECT COUNT(*) FROM crm_followups f JOIN (SELECT student_id,MAX(id) id FROM crm_followups GROUP BY student_id) latest ON latest.id=f.id JOIN crm_students s ON s.id=f.student_id LEFT JOIN crm_student_assignments a ON a.student_id=s.id WHERE $sql");$count->execute($params);$total=(int)$count->fetchColumn();$page=min($page,max(1,(int)ceil($total/$perPage)));$offset=($page-1)*$perPage;
$q=$crm->prepare("SELECT f.*,s.first_synced_at,s.created_at,".RecordSort::isNewExpr()." AS is_new,s.student_name,s.admission_no,s.admission_status,s.approved_payment_received,a.assigned_user_id FROM crm_followups f JOIN (SELECT student_id,MAX(id) id FROM crm_followups GROUP BY student_id) latest ON latest.id=f.id JOIN crm_students s ON s.id=f.student_id LEFT JOIN crm_student_assignments a ON a.student_id=s.id WHERE $sql ORDER BY ".($sort===RecordSort::DUE?'f.next_followup_at ASC':RecordSort::orderBy($sort))." LIMIT {$perPage} OFFSET {$offset}");$q->execute($params);$items=$q->fetchAll();
function e2(mixed $x):string{return Phase2::e($x);} 
$pageUser=['name'=>$u['name'],'role'=>$u['role']];
$tabsFu=['due'=>'Due & overdue','upcoming'=>'Upcoming','all'=>'All scheduled'];
$leadFu=['due'=>'Students whose latest follow-up date has arrived or passed.','upcoming'=>'Students with a follow-up scheduled in the future.','all'=>'Every student with a scheduled follow-up.'][$filter];
$fuUrl=fn(array $m)=>'?'.http_build_query(array_merge($_GET,$m));
$nowTs=time();
ui_start([
 'title'=>'Follow-ups','lead'=>$leadFu.' Latest scheduled follow-up for each active student.',
 'user'=>$pageUser,'active'=>'followups.php',
 'crumbs'=>[['Work',null],['Follow-ups',null]],
 'actions'=>'<a class="btn" href="team-queue.php">Team queue</a>',
]);
?>
<nav class="tabs" aria-label="Follow-up lists">
 <?php foreach($tabsFu as $key=>$label):?><a class="tab" <?=$filter===$key?'aria-current="page"':''?> href="<?=e2($fuUrl(['view'=>$key,'page'=>1]))?>"><?=e2($label)?></a><?php endforeach;?>
</nav>
<section class="card report-section">
 <?=ListFilters::styles()?>
 <details class="filter-panel" <?=(ListFilters::value('q')!==''||ListFilters::any(['university','counsellor','session_filter','admission_filter','responsible','outcome_filter','contact_filter'])||array_filter([ListFilters::value('admission_from'),ListFilters::value('admission_to'),ListFilters::value('due_from'),ListFilters::value('due_to')]))?'open':''?>>
  <summary>Search &amp; filters</summary>
  <form method="get"><input type="hidden" name="view" value="<?=e2($filter)?>"><input type="hidden" name="per_page" value="<?=$perPage?>">
   <div class="field recs-search"><label for="fu-q">Search student</label><input class="input" id="fu-q" type="search" name="q" value="<?=e2(ListFilters::value('q'))?>" placeholder="Name / admission / mobile / email"></div>
   <div class="crm-extra-filters">
    <?=RecordSort::select($sort,[RecordSort::DUE=>'Follow-up due date'])?>
    <?=ListFilters::dropdown($crm,$u,'university','University','university')?>
    <?=ListFilters::dropdown($crm,$u,'counsellor','Counsellor','counsellor_name')?>
    <?=ListFilters::dropdown($crm,$u,'session_filter','Session','session_name')?>
    <?=ListFilters::dropdown($crm,$u,'admission_filter','Admission status','admission_status')?>
    <?=ListFilters::owners($crm,$u,'responsible','legacy')?>
    <?php [$access,$accessArgs]=ListFilters::scope($crm,$u);foreach(['outcome_filter'=>'outcome','contact_filter'=>'contact_result'] as $key=>$column){$o=$crm->prepare("SELECT DISTINCT f.$column FROM crm_followups f JOIN crm_students s ON s.id=f.student_id WHERE $access AND f.$column<>'' ORDER BY f.$column");$o->execute($accessArgs);$v=$o->fetchAll(PDO::FETCH_COLUMN);echo ListFilters::select($key,$column==='outcome'?'Outcome':'Contact result',array_combine($v,$v));} ?>
    <?=ListFilters::dates('admission','Admission date')?><?=ListFilters::dates('due','Follow-up due date')?>
   </div>
   <div class="filter-actions"><button class="btn btn-primary" type="submit" data-loading>Filter</button><a class="btn" href="followups.php?view=<?=e2($filter)?>">Reset</a></div>
  </form>
 </details>
 <?=PageSize::selector($perPage,$_GET)?>
 <p class="muted small"><strong><?=number_format($total)?></strong> matching · Page <?=$page?></p>
 <div class="table-wrap"><table class="table compact"><thead><tr><th>Due</th><th>Synced</th><th>Student</th><th>Admission</th><th>Status</th><th>Outcome</th><th class="num">Approved payment</th><th></th></tr></thead><tbody>
 <?php foreach($items as $item): $dueTs=strtotime((string)$item['next_followup_at']);$overdue=$dueTs&&$dueTs<$nowTs;?>
  <tr>
   <td><div><?=$dueTs?e2(date('d M Y, h:i A',$dueTs)):'—'?></div><?=$overdue?'<span class="badge b-red">Overdue</span>':'<span class="badge b-blue">Scheduled</span>'?></td>
   <td><?=e2(RecordSort::stamp($item['first_synced_at']??$item['created_at']??null))?></td>
   <td><a class="rec-link" href="student.php?id=<?=(int)$item['student_id']?>"><?=e2($item['student_name'])?></a><?=RecordSort::badge($item['is_new'])?></td>
   <td><?=e2($item['admission_no'])?></td>
   <td><?=ui_status_badge($item['admission_status'],'—')?></td>
   <td><?=trim((string)$item['outcome'])!==''?e2($item['outcome']):'<span class="muted">—</span>'?></td>
   <td class="num">₹<?=number_format((float)$item['approved_payment_received'],2)?></td>
   <td><a class="btn btn-sm btn-primary" href="student.php?id=<?=(int)$item['student_id']?>">View</a></td>
  </tr>
 <?php endforeach;?>
 <?php if(!$items):?><tr><td colspan="8"><div class="empty" style="padding:24px"><?=ui_icon('clock',40)?><h3>No scheduled follow-ups in this view</h3><p>Try another tab or clear the filters.</p></div></td></tr><?php endif;?>
 </tbody></table></div>
 <nav class="pager" aria-label="Pagination"><span><?=number_format($total)?> records · Page <?=$page?></span><span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="<?=e2($fuUrl(['page'=>$page-1]))?>">Previous</a><?php endif;?><?php if($page*$perPage<$total):?><a class="btn btn-sm" href="<?=e2($fuUrl(['page'=>$page+1]))?>">Next</a><?php endif;?></span></nav>
</section>
<?php ui_end(); ?>
