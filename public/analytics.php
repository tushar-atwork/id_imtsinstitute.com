<?php
/** Phase 35: Read-only analytics, separate overall and employee sections. */
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/ui/report.php';
$me=Phase2::current($crm);
$isAdmin=$me['role']==='admin';
$view=(string)($_GET['view']??($isAdmin?'overall':'employees'));
if(!in_array($view,['overall','employees'],true))$view=$isAdmin?'overall':'employees';
if(!$isAdmin)$view='employees';
$today=new DateTimeImmutable('today');
$from=(string)($_GET['from']??$today->modify('first day of this month')->format('Y-m-d'));
$to=(string)($_GET['to']??$today->format('Y-m-d'));
function a35date(string $str):bool{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$str);return $d!==false&&$d->format('Y-m-d')===$str;}
if(!a35date($from)||!a35date($to)||$from>$to||(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366){http_response_code(422);exit('Choose valid dates within 366 days.');}
$until=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
$month=(string)($_GET['month']??substr($to,0,7));
if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/',$month))$month=substr($to,0,7);
$monthStart=$month.'-01';$monthEnd=(new DateTimeImmutable($monthStart))->modify('+1 month')->format('Y-m-d');
$employees=$isAdmin?$crm->query("SELECT id,name,role FROM crm_users WHERE is_active=1 AND role IN ('document_collection','form_filled','verification') ORDER BY role,name,id")->fetchAll(PDO::FETCH_ASSOC):[];
$employee=$isAdmin?max(0,(int)($_GET['employee']??0)):(int)$me['id'];
if($isAdmin&&$employee){$valid=false;foreach($employees as $e){if((int)$e['id']===$employee){$valid=true;break;}}if(!$valid)$employee=0;}
function a35h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function a35money($v):string{return '₹'.number_format((float)$v,0);}
function a35csv($v):string{$v=(string)$v;return preg_match('/^[\x00-\x20]*[=+@\-]/u',$v)?"'".$v:$v;}
function a35link(array $change):string{return 'analytics.php?'.http_build_query(array_merge($_GET,$change));}
function a35fetch(PDO $db,string $sql,array $args=[]):array{$q=$db->prepare($sql);$q->execute($args);return $q->fetchAll(PDO::FETCH_ASSOC);}
$roleLabels=['document_collection'=>'Document Collection','form_filled'=>'Form Filled','verification'=>'Verification'];
$overall=[];$dailyAdmissions=[];$dailyActions=[];$staff=[];$selected=null;$employeeDaily=[];$documentMetrics=[];$statusBreakdown=[];
if($view==='overall'){
 // Admission-cohort measurements are restricted to admissions dated within the filter window.
 $overall=a35fetch($crm,"SELECT COUNT(*) students,COALESCE(SUM(approved_payment_received),0) approved_amount,
 SUM(admission_status_id=1) incomplete_count,SUM(admission_status_id=2) pending_count,SUM(admission_status_id=4) rejected_count,
 SUM(erp_verified_at IS NOT NULL) verified_count,
 AVG(CASE WHEN counsellor_form_assigned_erp_date IS NOT NULL AND DATEDIFF(DATE(counsellor_form_assigned_erp_date),admission_date)>=0 THEN DATEDIFF(DATE(counsellor_form_assigned_erp_date),admission_date) END) avg_send_days,
 AVG(CASE WHEN erp_verified_at IS NOT NULL AND DATEDIFF(DATE(erp_verified_at),admission_date)>=0 THEN DATEDIFF(DATE(erp_verified_at),admission_date) END) avg_approval_days
 FROM crm_students WHERE is_active=1 AND admission_date>=? AND admission_date<?",[$from,$until])[0];
 $documentMetrics=a35fetch($crm,"SELECT COUNT(*) completed_count,AVG(CASE WHEN DATEDIFF(DATE(f.created_at),DATE(s.counsellor_form_assigned_erp_date))>=0 THEN DATEDIFF(DATE(f.created_at),DATE(s.counsellor_form_assigned_erp_date)) END) avg_documents_delay FROM crm_students s JOIN crm_team_followups f ON f.id=(SELECT MAX(z.id) FROM crm_team_followups z WHERE z.student_id=s.id AND z.team_role='document_collection') JOIN crm_team_statuses st ON st.id=f.status_id WHERE s.is_active=1 AND s.admission_date>=? AND s.admission_date<? AND LOWER(st.status_label) REGEXP 'all[[:space:]]+(completed|complete)[[:space:]]+doc|all[[:space:]]+doc.*complet|documents?[[:space:]]+complet'",[$from,$until])[0];
 $statusBreakdown=a35fetch($crm,"SELECT f.team_role,st.status_label,COUNT(*) total FROM crm_team_followups f JOIN crm_team_statuses st ON st.id=f.status_id WHERE f.created_at>=? AND f.created_at<? GROUP BY f.team_role,st.id,st.status_label ORDER BY total DESC LIMIT 12",[$from,$until]);
 $overallActivity=a35fetch($crm,"SELECT COUNT(*) followups,COUNT(DISTINCT CONCAT(f.team_role,':',f.student_id)) team_cases,COUNT(DISTINCT f.student_id) students_contacted,
 SUM(f.contact_result='Connected') connected,COUNT(DISTINCT f.created_by) active_employees
 FROM crm_team_followups f WHERE f.created_at>=? AND f.created_at<?",[$from,$until])[0];
 $teamBreakdown=a35fetch($crm,"SELECT f.team_role,COUNT(*) followups,COUNT(DISTINCT f.student_id) students_contacted,SUM(f.contact_result='Connected') connected FROM crm_team_followups f WHERE f.created_at>=? AND f.created_at<? GROUP BY f.team_role",[$from,$until]);
 $currentWorkload=a35fetch($crm,"SELECT a.team_role,COUNT(*) assigned_count FROM crm_team_assignments a INNER JOIN crm_students s ON s.id=a.student_id AND s.is_active=1 WHERE a.assigned_user_id IS NOT NULL GROUP BY a.team_role");
 $dailyAdmissions=a35fetch($crm,"SELECT DATE(admission_date) day,COUNT(*) total FROM crm_students WHERE is_active=1 AND admission_date>=? AND admission_date<? GROUP BY DATE(admission_date)",[$monthStart,$monthEnd]);
 $dailyActions=a35fetch($crm,"SELECT DATE(created_at) day,COUNT(*) total FROM crm_team_followups WHERE created_at>=? AND created_at<? GROUP BY DATE(created_at)",[$monthStart,$monthEnd]);
}else{
 // Current ownership is a snapshot; activity and employee productivity use selected dates.
 $where=$employee?' AND u.id=?':'';$wArgs=$employee?[$employee]:[];
 $staff=a35fetch($crm,"SELECT u.id,u.name,u.role,
 (SELECT COUNT(*) FROM crm_team_assignments a INNER JOIN crm_students s ON s.id=a.student_id AND s.is_active=1 WHERE a.assigned_user_id=u.id AND a.team_role=u.role) active_cases,
 (SELECT COUNT(*) FROM crm_team_followups f WHERE f.created_by=u.id AND f.team_role=u.role AND f.created_at>=? AND f.created_at<?) followups,
 (SELECT COUNT(DISTINCT f.student_id) FROM crm_team_followups f WHERE f.created_by=u.id AND f.team_role=u.role AND f.created_at>=? AND f.created_at<?) contacted,
 (SELECT COUNT(*) FROM crm_team_followups f WHERE f.created_by=u.id AND f.team_role=u.role AND f.contact_result='Connected' AND f.created_at>=? AND f.created_at<?) connected,
 (SELECT COUNT(*) FROM crm_team_assignments a INNER JOIN crm_students s ON s.id=a.student_id AND s.is_active=1
  INNER JOIN crm_team_followups f ON f.id=(SELECT MAX(z.id) FROM crm_team_followups z WHERE z.student_id=a.student_id AND z.team_role=a.team_role)
  WHERE a.assigned_user_id=u.id AND a.team_role=u.role AND f.next_followup_at IS NOT NULL AND f.next_followup_at<NOW()) overdue
 FROM crm_users u WHERE u.is_active=1 AND u.role IN ('document_collection','form_filled','verification')".$where." ORDER BY active_cases DESC,followups DESC,u.name,u.id",array_merge([$from,$until,$from,$until,$from,$until],$wArgs));
 if($employee){foreach($staff as $r){if((int)$r['id']===$employee){$selected=$r;break;}}}
 if(!$employee && !$isAdmin){$selected=$staff[0]??null;}
 $employeeDaily=a35fetch($crm,"SELECT DATE(f.created_at) day,COUNT(*) total FROM crm_team_followups f WHERE f.created_at>=? AND f.created_at<?".($employee?' AND f.created_by=?':'')." GROUP BY DATE(f.created_at) ORDER BY day",array_merge([$monthStart,$monthEnd],$employee?[$employee]:[]));
}
$exportFmt=(string)($_GET['export']??'');
if(in_array($exportFmt,['csv','xls','pdf'],true)&&$view==='employees'){
 require_once __DIR__.'/../app/ReportExport.php';
 $xr=[];foreach($staff as $r){$xr[]=[$r['name'],$roleLabels[$r['role']]??$r['role'],(int)$r['active_cases'],(int)$r['followups'],(int)$r['contacted'],(int)$r['connected'],(int)$r['overdue']];}
 ReportExport::send($exportFmt,'imts_employee_analytics','Employee analytics '.$from.' to '.$to,['Employee','Team','Currently assigned active students','Follow-ups in period','Unique students contacted','Connected follow-ups','Currently overdue latest follow-ups'],$xr);
}
if(in_array($exportFmt,['csv','xls','pdf'],true)&&$view==='overall'){
 require_once __DIR__.'/../app/ReportExport.php';
 $xr=[];foreach(['Admission cohort'=>$overall['students'],'Approved payments on cohort'=>$overall['approved_amount'],'Pending admissions'=>$overall['pending_count'],'Incomplete admissions'=>$overall['incomplete_count'],'Rejected admissions'=>$overall['rejected_count'],'First ERP approvals captured'=>$overall['verified_count'],'Average sending delay days'=>$overall['avg_send_days'],'Average approval days'=>$overall['avg_approval_days'],'Follow-up actions in period'=>$overallActivity['followups'],'Distinct students contacted'=>$overallActivity['students_contacted'],'Connected follow-ups'=>$overallActivity['connected']] as $k=>$v)$xr[]=[$k,$v];
 ReportExport::send($exportFmt,'imts_overall_analytics','Overall analytics '.$from.' to '.$to,['Metric','Value'],$xr);
}
$events=[];$admitEvents=[];
foreach(($view==='overall'?$dailyActions:$employeeDaily) as $d)$events[$d['day']]=(int)$d['total'];
foreach($dailyAdmissions as $d)$admitEvents[$d['day']]=(int)$d['total'];
$calendarStart=new DateTimeImmutable($monthStart);$days=(int)$calendarStart->format('t');$leading=(int)$calendarStart->format('N')-1;
$peak=max(1,...array_values($events?:[0]));

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$a36adm=[];$a36follow=[];
foreach($dailyAdmissions as $rr)$a36adm[$rr['day']]=(int)$rr['total'];
foreach($dailyActions as $rr)$a36follow[$rr['day']]=(int)$rr['total'];
$dayLabels=[];$dayTitles=[];$sAdm=[];$sFol=[];$sEmp=[];
$a36daily=[];foreach($employeeDaily as $rr)$a36daily[$rr['day']]=(int)$rr['total'];
for($dn=1;$dn<=$days;$dn++){$dt=$month.'-'.str_pad((string)$dn,2,'0',STR_PAD_LEFT);$dayLabels[]=str_pad((string)$dn,2,'0',STR_PAD_LEFT);$dayTitles[]=$dt;$sAdm[]=$a36adm[$dt]??0;$sFol[]=$a36follow[$dt]??0;$sEmp[]=$a36daily[$dt]??0;}
ui_start([
 'title'=>$isAdmin?'Analysis report':'My performance',
 'lead'=>'Separate management-wide and employee-wise analysis, with a date-range filter and activity calendar.',
 'user'=>$pageUser,'active'=>'analytics.php',
 'crumbs'=>[['Reports',null],['Analysis',null]],
 'actions'=>'<div class="dropdown"><button class="btn" type="button" data-dropdown aria-haspopup="true" aria-expanded="false">'.ui_icon('note',16).'Export'.ui_icon('chevron',14).'</button><div class="dropdown-menu" hidden><a class="nav-link" href="'.a35h(a35link(['export'=>'csv'])).'"><span>Export as CSV</span></a><a class="nav-link" href="'.a35h(a35link(['export'=>'xls'])).'"><span>Export as Excel (.xls)</span></a><a class="nav-link" href="'.a35h(a35link(['export'=>'pdf'])).'"><span>Export as PDF</span></a></div></div>',
]);
?>
<nav class="tabs" aria-label="Analytics sections">
 <?php if($isAdmin):?><a class="tab" <?=$view==='overall'?'aria-current="page"':''?> href="<?=a35h(a35link(['view'=>'overall','export'=>null]))?>">Overall analysis</a><?php endif;?>
 <a class="tab" <?=$view==='employees'?'aria-current="page"':''?> href="<?=a35h(a35link(['view'=>'employees','export'=>null]))?>"><?= $isAdmin?'Employee-wise analysis':'My performance' ?></a>
</nav>
<section class="card report-section">
 <form method="get" class="filter-bar"><input type="hidden" name="view" value="<?=a35h($view)?>"><input type="hidden" name="month" value="<?=a35h($month)?>">
  <div class="field"><label for="an-from">From</label><input class="input" id="an-from" type="date" name="from" value="<?=a35h($from)?>" required></div>
  <div class="field"><label for="an-to">To</label><input class="input" id="an-to" type="date" name="to" value="<?=a35h($to)?>" required></div>
  <?php if($view==='employees'&&$isAdmin):?><div class="field"><label for="an-emp">Employee</label><select class="select" id="an-emp" name="employee"><option value="0">All team employees</option><?php foreach($employees as $e):?><option value="<?=(int)$e['id']?>" <?=$employee===(int)$e['id']?'selected':''?>><?=a35h($e['name'].' · '.($roleLabels[$e['role']]??$e['role']))?></option><?php endforeach;?></select></div><?php endif;?>
  <button class="btn btn-primary" type="submit" data-loading>Apply filters</button>
 </form>
</section>

<?php if($view==='overall'):?>
<h2 style="margin:0 0 4px">Overall admission analysis</h2>
<p class="muted">Admissions dated <?=a35h($from)?> to <?=a35h($to)?>. Counts exclude currently inactive ERP records.</p>
<?=ui_kpis([
 ['Admissions in period',number_format((int)$overall['students'])],['Approved amount',a35money($overall['approved_amount'])],['Pending',number_format((int)$overall['pending_count'])],['Incomplete',number_format((int)$overall['incomplete_count'])],['Rejected',number_format((int)$overall['rejected_count'])],
 ['ERP verification date captured',number_format((int)$overall['verified_count'])],['Average send delay',$overall['avg_send_days']===null?'No data':number_format((float)$overall['avg_send_days'],1).' days'],['Average approval time',$overall['avg_approval_days']===null?'No data':number_format((float)$overall['avg_approval_days'],1).' days'],
 ['Latest docs completed',number_format((int)($documentMetrics['completed_count']??0))],['Average documents delay',($documentMetrics['avg_documents_delay']??null)===null?'No data':number_format((float)$documentMetrics['avg_documents_delay'],1).' days'],
])?>
<section class="card report-section"><div class="card-head"><div><h2>Monthly admissions &amp; follow-up trend</h2><p class="help" style="margin:0">Daily counts for <?=a35h($month)?>. These are two different event dates, not a conversion rate.</p></div></div>
 <?=ui_vbars($dayLabels,['Admissions'=>$sAdm,'Team follow-ups'=>$sFol],'Daily admission and follow-up bar chart for '.$month,$dayTitles)?>
 <p class="help" style="margin-top:8px">Hover over a day for exact counts. The calendar below lets you open that date.</p>
</section>
<section class="card report-section"><h2>Team follow-up activity</h2><p class="help">Logged actions in the selected date range, by team.</p>
 <?php $tv=[];foreach($roleLabels as $role=>$label){$v=0;foreach($teamBreakdown as $r)if($r['team_role']===$role)$v=(int)$r['followups'];$tv[]=[$label,$v];}echo ui_hbars($tv);?>
</section>
<div class="two-col report-section">
 <article class="card"><h2>Team activity in selected period</h2>
  <div class="statline"><span>Logged follow-ups</span><b><?=number_format((int)$overallActivity['followups'])?></b></div>
  <div class="statline"><span>Unique students contacted</span><b><?=number_format((int)$overallActivity['students_contacted'])?></b></div>
  <div class="statline"><span>Connected follow-ups</span><b><?=number_format((int)$overallActivity['connected'])?></b></div>
  <div class="statline"><span>Active employees who logged work</span><b><?=number_format((int)$overallActivity['active_employees'])?></b></div>
 </article>
 <article class="card"><h2>Team breakdown</h2>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Team</th><th class="num">Follow-ups</th><th class="num">Students contacted</th><th class="num">Connected</th><th class="num">Current assignments</th></tr></thead><tbody>
  <?php foreach($roleLabels as $role=>$label): $a=['followups'=>0,'students_contacted'=>0,'connected'=>0];foreach($teamBreakdown as $r)if($r['team_role']===$role)$a=$r;$load=0;foreach($currentWorkload as $w)if($w['team_role']===$role)$load=(int)$w['assigned_count'];?>
   <tr><td><?=a35h($label)?></td><td class="num"><?=number_format((int)$a['followups'])?></td><td class="num"><?=number_format((int)$a['students_contacted'])?></td><td class="num"><?=number_format((int)$a['connected'])?></td><td class="num"><?=number_format($load)?></td></tr>
  <?php endforeach;?></tbody></table></div>
 </article>
</div>
<article class="card report-section"><h2>Most-used team statuses in selected period</h2>
 <div class="table-wrap"><table class="table compact"><thead><tr><th>Team</th><th>Status</th><th class="num">Updates logged</th></tr></thead><tbody>
 <?php foreach($statusBreakdown as $r):?><tr><td><?=a35h($roleLabels[$r['team_role']]??$r['team_role'])?></td><td><?=a35h($r['status_label'])?></td><td class="num"><?=number_format((int)$r['total'])?></td></tr><?php endforeach;?>
 </tbody></table></div>
 <?php if(!$statusBreakdown):?><p class="muted" style="margin-top:12px">No team status activity was logged in this period.</p><?php endif;?>
</article>

<?php else:?>
<h2 style="margin:0 0 4px"><?= $isAdmin?'Employee-wise analysis':'My performance' ?></h2>
<p class="muted">Current assignments and overdue work are live snapshots; follow-up activity uses the selected period.</p>
<?php if($selected):?><?=ui_kpis([['Employee',$selected['name']],['Team',$roleLabels[$selected['role']]??$selected['role']],['Current assignments',number_format((int)$selected['active_cases'])],['Follow-ups logged',number_format((int)$selected['followups'])],['Students contacted',number_format((int)$selected['contacted'])],['Connected actions',number_format((int)$selected['connected'])],['Overdue latest follow-ups',number_format((int)$selected['overdue'])]])?><?php endif;?>
<section class="card report-section"><h2>Employee follow-up comparison</h2><p class="help">Logged actions by employee during the selected date range. Top 12 by count.</p>
 <?php $rank=[];foreach(array_slice($staff,0,12) as $r)$rank[]=[$r['name'],(int)$r['followups'],$roleLabels[$r['role']]??$r['role']];echo ui_hbars($rank,'No employee activity records in this selection.');?>
</section>
<section class="card report-section"><h2>Daily follow-up trend</h2><p class="help">Daily actions in <?=a35h($month)?><?php if($selected):?> for <?=a35h($selected['name'])?><?php endif;?>.</p>
 <?=ui_vbars($dayLabels,['Follow-ups'=>$sEmp],'Employee daily follow-up chart for '.$month,$dayTitles)?>
</section>
<article class="card report-section"><h2><?= $isAdmin?'Employee performance table':'My activity summary' ?></h2>
 <div class="table-wrap"><table class="table compact"><thead><tr><th>Employee</th><th>Team</th><th class="num">Current assignments</th><th class="num">Follow-ups</th><th class="num">Students contacted</th><th class="num">Connected</th><th class="num">Overdue</th><?php if($isAdmin):?><th></th><?php endif;?></tr></thead><tbody>
 <?php foreach($staff as $r):?><tr><td><?=a35h($r['name'])?></td><td><?=a35h($roleLabels[$r['role']]??$r['role'])?></td><td class="num"><?=number_format((int)$r['active_cases'])?></td><td class="num"><?=number_format((int)$r['followups'])?></td><td class="num"><?=number_format((int)$r['contacted'])?></td><td class="num"><?=number_format((int)$r['connected'])?></td><td class="num"><?=(int)$r['overdue']>0?'<span class="badge b-red">'.number_format((int)$r['overdue']).'</span>':'0'?></td><?php if($isAdmin):?><td><a href="<?=a35h(a35link(['view'=>'employees','employee'=>$r['id'],'export'=>null]))?>">View calendar</a></td><?php endif;?></tr><?php endforeach;?>
 </tbody></table></div>
 <?php if(!$staff):?><div class="empty" style="padding:20px"><?=ui_icon('inbox',40)?><h3>No employee records for this selection</h3></div><?php endif;?>
</article>
<?php endif;?>

<section class="card report-section"><div class="card-head"><div><h2><?= $view==='overall'?'Admission & follow-up calendar':'Employee activity calendar' ?></h2><p class="help" style="margin:0">Each day shows logged team follow-ups<?= $view==='overall'?' and new admission dates':'' ?>. Choose a day to filter the report.</p></div>
 <form method="get" class="filter-bar"><input type="hidden" name="view" value="<?=a35h($view)?>"><input type="hidden" name="from" value="<?=a35h($from)?>"><input type="hidden" name="to" value="<?=a35h($to)?>"><?php if($employee):?><input type="hidden" name="employee" value="<?=$employee?>"><?php endif;?><div class="field"><label for="an-month">Month</label><input class="input" id="an-month" type="month" name="month" value="<?=a35h($month)?>"></div><button class="btn" type="submit" data-loading>View</button></form></div>
 <div class="cal" role="group" aria-label="Activity calendar for <?=a35h($month)?>">
  <?php foreach(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $wd):?><div class="wd"><?=$wd?></div><?php endforeach;?>
  <?php for($n=0;$n<$leading;$n++):?><div class="blank"></div><?php endfor;?>
  <?php for($dn=1;$dn<=$days;$dn++):$date=$month.'-'.str_pad((string)$dn,2,'0',STR_PAD_LEFT);$count=$events[$date]??0;$new=$admitEvents[$date]??0;$level=$count===0?0:($count/$peak<.34?1:($count/$peak<.67?2:3));?>
   <a class="day lv<?=$level?>" href="<?=a35h(a35link(['from'=>$date,'to'=>$date,'month'=>$month,'export'=>null]))?>" title="<?=a35h($date)?>: <?=$count?> follow-ups<?= $view==='overall'?', '.$new.' admissions':'' ?>"><strong><?=$dn?></strong><span><?=$count?> follow-ups</span><?php if($view==='overall'):?><small><?=$new?> admissions</small><?php endif;?></a>
  <?php endfor;?>
 </div>
 <p class="note" style="margin-top:var(--s4)"><b>How to read:</b> The calendar uses actual follow-up timestamps. Zero means no logged follow-ups; it does not necessarily mean the employee was absent. Current assignments and overdue figures are snapshots, not historical measurements. ERP verification counts reflect dates already captured by synchronization.</p>
</section>
<?php ui_end(); ?>
