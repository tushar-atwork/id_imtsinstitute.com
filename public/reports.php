<?php
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/ui/report.php';$u=Phase2::current($crm);if($u['role']!=='admin'){ui_error_page(403,'Administrators only','Management reports are limited to administrators.');}
$start=(string)($_GET['start']??date('Y-m-01'));$end=(string)($_GET['end']??date('Y-m-d'));foreach([$start,$end] as $d){$dt=DateTimeImmutable::createFromFormat('!Y-m-d',$d);if(!$dt||$dt->format('Y-m-d')!==$d){http_response_code(400);exit('Invalid date');}}if($start>$end){http_response_code(400);exit('Start date must be before end date');}
$summary=$crm->query('SELECT COUNT(*) total,COALESCE(SUM(approved_payment_received),0) payments,SUM(admission_status_id=1) incomplete,SUM(admission_status_id=2) pending,SUM(admission_status_id=4) rejected FROM crm_students WHERE is_active=1')->fetch();
$owners=$crm->query("SELECT COALESCE(u.name,'Unassigned') owner,COUNT(*) students,COALESCE(SUM(s.approved_payment_received),0) payments FROM crm_students s LEFT JOIN crm_student_assignments a ON a.student_id=s.id LEFT JOIN crm_users u ON u.id=a.assigned_user_id WHERE s.is_active=1 GROUP BY a.assigned_user_id,u.name ORDER BY students DESC")->fetchAll();
$st=$crm->prepare("SELECT u.name owner,COUNT(*) followups,COUNT(DISTINCT f.student_id) students_contacted,SUM(f.contact_result='Connected') connected FROM crm_followups f JOIN crm_users u ON u.id=f.created_by WHERE f.created_at>=? AND f.created_at<? GROUP BY f.created_by,u.name ORDER BY followups DESC");$st->execute([$start.' 00:00:00',(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d').' 00:00:00']);$activity=$st->fetchAll();
if(($_GET['export']??'')==='activity'){header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="imts_followup_activity.csv"');$out=fopen('php://output','w');fputcsv($out,['CRM user','Follow-ups','Students contacted','Connected']);foreach($activity as $a)fputcsv($out,array_values($a));exit;}
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$perPage=PageSize::fromRequest();$ownerTotal=count($owners);$activityTotal=count($activity);
$ownerPage=max(1,min((int)($_GET['owner_page']??1),max(1,(int)ceil($ownerTotal/$perPage))));
$activityPage=max(1,min((int)($_GET['activity_page']??1),max(1,(int)ceil($activityTotal/$perPage))));
$visibleOwners=array_slice($owners,($ownerPage-1)*$perPage,$perPage);
$visibleActivity=array_slice($activity,($activityPage-1)*$perPage,$perPage);

$ownerMax=1;foreach($owners as $r)$ownerMax=max($ownerMax,(int)$r['students']);
$pageUser=['name'=>$u['name'],'role'=>$u['role']];
ui_start([
 'title'=>'Management reports',
 'lead'=>'Student counts reflect current active ERP eligibility. Activity counts use the selected date range.',
 'user'=>$pageUser,'active'=>'reports.php',
 'crumbs'=>[['Reports',null],['Management',null]],
 'actions'=>ui_csv_button('?'.http_build_query(['start'=>$start,'end'=>$end,'export'=>'activity']),'Download activity CSV'),
]);
echo ui_kpis([['Eligible students',number_format((int)$summary['total'])],['Approved payment','₹'.number_format((float)$summary['payments'],2)],['Incomplete',number_format((int)$summary['incomplete'])],['Pending',number_format((int)$summary['pending'])],['Rejected',number_format((int)$summary['rejected'])]]);
?>
<section class="card report-section">
 <div class="card-head"><div><h2>Current assignment workload</h2><p class="help" style="margin:0">Active students per owner right now.</p></div></div>
 <?=PageSize::selector($perPage,$_GET)?>
 <div class="table-wrap"><table class="table compact"><thead><tr><th>Owner</th><th class="num">Students</th><th class="num">Approved payment</th></tr></thead><tbody>
 <?php foreach($visibleOwners as $r):?><tr><td><?=h($r['owner'])?><span class="mini-bar" aria-hidden="true"><i style="width:<?=round(((int)$r['students'])/$ownerMax*100,2)?>%"></i></span></td><td class="num"><?=number_format((int)$r['students'])?></td><td class="num">₹<?=number_format((float)$r['payments'],2)?></td></tr><?php endforeach;?>
 <?php if(!$visibleOwners):?><tr><td colspan="3"><div class="empty" style="padding:20px"><?=ui_icon('inbox',40)?><h3>No owners yet</h3></div></td></tr><?php endif;?>
 </tbody></table></div>
 <nav class="pager" aria-label="Workload pagination"><span><?=number_format($ownerTotal)?> owners · Page <?=$ownerPage?></span><span style="display:flex;gap:8px"><?php if($ownerPage>1):?><a class="btn btn-sm" href="?<?=h(http_build_query(array_merge($_GET,['owner_page'=>$ownerPage-1])))?>">Previous</a><?php endif;?><?php if($ownerPage*$perPage<$ownerTotal):?><a class="btn btn-sm" href="?<?=h(http_build_query(array_merge($_GET,['owner_page'=>$ownerPage+1])))?>">Next</a><?php endif;?></span></nav>
</section>

<section class="card report-section">
 <div class="card-head"><div><h2>Follow-up activity</h2><p class="help" style="margin:0">Follow-ups logged between <?=h($start)?> and <?=h($end)?>.</p></div></div>
 <form method="get" class="filter-bar" style="margin-bottom:var(--s3)"><input type="hidden" name="per_page" value="<?=$perPage?>">
  <div class="field"><label for="rp-start">From</label><input class="input" id="rp-start" type="date" name="start" value="<?=h($start)?>"></div>
  <div class="field"><label for="rp-end">To</label><input class="input" id="rp-end" type="date" name="end" value="<?=h($end)?>"></div>
  <button class="btn btn-primary" type="submit" data-loading>Filter</button>
  <a class="btn" href="?<?=h(http_build_query(['start'=>$start,'end'=>$end,'export'=>'activity']))?>">Download CSV</a>
 </form>
 <?php $actRows=[];foreach($activity as $r)$actRows[]=[$r['owner'],(int)$r['followups'],((int)$r['connected']).' connected'];echo ui_hbars(array_slice($actRows,0,10),'No follow-ups were logged in this range.');?>
 <div class="table-wrap" style="margin-top:var(--s4)"><table class="table compact"><thead><tr><th>CRM user</th><th class="num">Follow-ups</th><th class="num">Students contacted</th><th class="num">Connected</th></tr></thead><tbody>
 <?php foreach($visibleActivity as $r):?><tr><td><?=h($r['owner'])?></td><td class="num"><?=number_format((int)$r['followups'])?></td><td class="num"><?=number_format((int)$r['students_contacted'])?></td><td class="num"><?=number_format((int)$r['connected'])?></td></tr><?php endforeach;?>
 <?php if(!$visibleActivity):?><tr><td colspan="4"><div class="empty" style="padding:20px"><?=ui_icon('inbox',40)?><h3>No activity in this range</h3><p>Try a wider date range.</p></div></td></tr><?php endif;?>
 </tbody></table></div>
 <nav class="pager" aria-label="Activity pagination"><span><?=number_format($activityTotal)?> employees · Page <?=$activityPage?></span><span style="display:flex;gap:8px"><?php if($activityPage>1):?><a class="btn btn-sm" href="?<?=h(http_build_query(array_merge($_GET,['activity_page'=>$activityPage-1])))?>">Previous</a><?php endif;?><?php if($activityPage*$perPage<$activityTotal):?><a class="btn btn-sm" href="?<?=h(http_build_query(array_merge($_GET,['activity_page'=>$activityPage+1])))?>">Next</a><?php endif;?></span></nav>
</section>
<?php ui_end(); ?>
