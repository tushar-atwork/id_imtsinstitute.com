<?php
/** Phase 4: follow-up workspace. Additive; no Phase 1-3 files replaced. */
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/ui/layout.php';require_once __DIR__.'/../app/ui/report.php';
$me=Phase2::current($crm);
if(in_array((string)$me['role'],['document_collection','form_filled','verification'],true)){header('Location: team-queue.php',true,302);exit;}

$view=(string)($_GET['view']??'due');
if(!in_array($view,['due','today','upcoming','all'],true))$view='due';
$q=trim((string)($_GET['q']??''));if(mb_strlen($q)>100)$q=mb_substr($q,0,100);
$owner=filter_var($_GET['owner']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if($me['role']!=='admin')$owner=(int)$me['id'];
$where=['s.is_active=1','f.next_followup_at IS NOT NULL'];$args=[];
if($owner){$where[]='a.assigned_user_id=?';$args[]=$owner;}
if($q!==''){$where[]='(s.student_name LIKE ? OR s.admission_no LIKE ?)';$args[]='%'.$q.'%';$args[]='%'.$q.'%';}
if($view==='due')$where[]='f.next_followup_at<=NOW()';
elseif($view==='today')$where[]='f.next_followup_at>=CURDATE() AND f.next_followup_at<DATE_ADD(CURDATE(),INTERVAL 1 DAY)';
elseif($view==='upcoming')$where[]='f.next_followup_at>NOW()';
$condition=implode(' AND ',$where);
$from=' FROM crm_followups f JOIN (SELECT student_id,MAX(id) AS id FROM crm_followups GROUP BY student_id) latest ON latest.id=f.id JOIN crm_students s ON s.id=f.student_id LEFT JOIN crm_student_assignments a ON a.student_id=s.id LEFT JOIN crm_users u ON u.id=a.assigned_user_id WHERE '.$condition;
$count=$crm->prepare('SELECT COUNT(*)'.$from);$count->execute($args);$total=(int)$count->fetchColumn();
$perPage=PageSize::fromRequest();$page=max(1,(int)($_GET['page']??1));$page=min($page,max(1,(int)ceil($total/$perPage)));$offset=($page-1)*$perPage;
require_once __DIR__.'/../app/RecordSort.php';$sort=RecordSort::current([RecordSort::DUE]);
$columns='SELECT s.first_synced_at,s.created_at,'.RecordSort::isNewExpr().' AS is_new,f.student_id,f.next_followup_at,f.contact_result,f.outcome,s.student_name,s.admission_no,s.admission_status,s.approved_payment_received,u.name AS owner';
$export=($_GET['export']??'')==='csv';
if($export){
 if($total>10000){http_response_code(422);exit('Export limited to 10,000 records. Narrow the filters.');}
 $st=$crm->prepare($columns.$from.' ORDER BY f.next_followup_at ASC,f.id DESC LIMIT 10000');$st->execute($args);
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="imts_phase4_followup_workspace.csv"');header('X-Content-Type-Options: nosniff');
 $out=fopen('php://output','w');fputcsv($out,['Due','Admission','Student','Status','Approved payment','Owner','Contact result','Outcome']);
 while($r=$st->fetch(PDO::FETCH_ASSOC)){
  $fields=[$r['next_followup_at'],$r['admission_no'],$r['student_name'],$r['admission_status'],$r['approved_payment_received'],$r['owner'],$r['contact_result'],$r['outcome']];
  // Neutralize spreadsheet formula injection for text values.
  foreach([1,2,3,5,6,7] as $i){if(preg_match('/^[\s\x00-\x1f]*[=+@\-]/u',(string)$fields[$i]))$fields[$i]="'".$fields[$i];}
  fputcsv($out,$fields);
 }fclose($out);exit;
}
$st=$crm->prepare($columns.$from.' ORDER BY '.($sort===RecordSort::DUE?'f.next_followup_at ASC,f.id DESC':RecordSort::orderBy($sort)).' LIMIT '.$perPage.' OFFSET '.$offset);$st->execute($args);$rows=$st->fetchAll();
$users=$me['role']==='admin'?$crm->query('SELECT id,name FROM crm_users WHERE is_active=1 ORDER BY name')->fetchAll():[];
function h4($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function url4(array $changes=[]):string{return '?'.http_build_query(array_merge(array_intersect_key($_GET,array_flip(['view','q','owner','page','per_page','sort'])),$changes));}

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$tabsWs=['due'=>'Due & overdue','today'=>'Today','upcoming'=>'Upcoming','all'=>'All scheduled'];
$nowTs=time();
ui_start([
 'title'=>'Follow-up workspace',
 'lead'=>'Latest follow-up per active student. Your assigned records only unless you are an administrator.',
 'user'=>$pageUser,'active'=>'workspace.php',
 'crumbs'=>[['Work',null],['Workspace',null]],
 'actions'=>ui_csv_button(url4(['export'=>'csv','page'=>1]),'Export filtered CSV'),
]);
?>
<nav class="tabs" aria-label="Workspace lists"><?php foreach($tabsWs as $key=>$label):?><a class="tab" <?=$view===$key?'aria-current="page"':''?> href="<?=h4(url4(['view'=>$key,'page'=>1]))?>"><?=h4($label)?></a><?php endforeach;?></nav>
<section class="card report-section">
 <form method="get" class="filter-bar" style="margin-bottom:var(--s3)"><input type="hidden" name="per_page" value="<?=$perPage?>"><input type="hidden" name="view" value="<?=h4($view)?>">
  <div class="field grow"><label for="ws-q">Search</label><input class="input" id="ws-q" type="search" name="q" placeholder="Name or admission number" value="<?=h4($q)?>"></div>
  <div class="field"><?=RecordSort::select($sort,[RecordSort::DUE=>'Follow-up due date'])?></div>
  <?php if($me['role']==='admin'):?><div class="field"><label for="ws-owner">Owner</label><select class="select" id="ws-owner" name="owner"><option value="">All owners</option><?php foreach($users as $uRow):?><option value="<?=(int)$uRow['id']?>" <?=$owner===(int)$uRow['id']?'selected':''?>><?=h4($uRow['name'])?></option><?php endforeach;?></select></div><?php endif;?>
  <button class="btn btn-primary" type="submit" data-loading>Filter</button>
 </form>
 <?=PageSize::selector($perPage, $_GET)?>
 <p class="muted small"><strong><?=number_format($total)?></strong> matching records · Page <?=$page?></p>
 <div class="table-wrap"><table class="table"><thead><tr><th>Next follow-up</th><th>Synced</th><th>Admission</th><th>Student</th><th>Status</th><th>Owner</th><th class="num">Payment</th><th>Outcome</th><th></th></tr></thead><tbody>
 <?php foreach($rows as $r): $nts=strtotime((string)$r['next_followup_at']);$over=$nts&&$nts<$nowTs;?>
  <tr>
   <td><div><?=$nts?h4(date('d M Y, h:i A',$nts)):'—'?></div><?=$over?'<span class="badge b-red">Overdue</span>':'<span class="badge b-blue">Scheduled</span>'?></td>
   <td><?=h4(RecordSort::stamp($r['first_synced_at']??$r['created_at']??null))?></td>
   <td><?=h4($r['admission_no'])?></td>
   <td><a class="rec-link" href="student.php?id=<?=(int)$r['student_id']?>"><?=h4($r['student_name'])?></a><?=RecordSort::badge($r['is_new'])?></td>
   <td><?=ui_status_badge($r['admission_status'],'—')?></td>
   <td><?=$r['owner']?h4($r['owner']):'<span class="muted"><em>Unassigned</em></span>'?></td>
   <td class="num">₹<?=number_format((float)$r['approved_payment_received'],2)?></td>
   <td><?=trim((string)$r['outcome'])!==''?h4($r['outcome']):'<span class="muted">—</span>'?></td>
   <td><a class="btn btn-sm btn-primary" href="student.php?id=<?=(int)$r['student_id']?>">Open</a></td>
  </tr>
 <?php endforeach;?>
 <?php if(!$rows):?><tr><td colspan="9"><div class="empty" style="padding:24px"><?=ui_icon('clock',40)?><h3>No records match your filters</h3><p>Try another tab or clear the search.</p></div></td></tr><?php endif;?>
 </tbody></table></div>
 <nav class="pager" aria-label="Pagination"><span><?=number_format($total)?> records · Page <?=$page?></span><span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="<?=h4(url4(['page'=>$page-1]))?>">Previous</a><?php endif;?><?php if($page*$perPage<$total):?><a class="btn btn-sm" href="<?=h4(url4(['page'=>$page+1]))?>">Next</a><?php endif;?></span></nav>
</section>
<?php ui_end(); ?>
