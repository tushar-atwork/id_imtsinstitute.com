<?php
/** Phase 5: read-only performance dashboard, compatible with Phase 1-4. */
declare(strict_types=1);
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/ui/report.php';
$me=Phase2::current($crm);
$isAdmin=$me['role']==='admin';
$today=new DateTimeImmutable('today');
$from=(string)($_GET['from']??$today->modify('first day of this month')->format('Y-m-d'));
$to=(string)($_GET['to']??$today->format('Y-m-d'));
foreach([$from,$to] as $date){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date){http_response_code(422);exit('Invalid date.');}}
if($from>$to || (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days>366){http_response_code(422);exit('Select a range of 366 days or less.');}
$owner=filter_var($_GET['owner']??0,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if(!$isAdmin)$owner=(int)$me['id'];
$end=(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
$owners=$isAdmin?$crm->query('SELECT id,name FROM crm_users WHERE is_active=1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC):[];
$staffSql='SELECT u.id,u.name, COUNT(DISTINCT CASE WHEN s.is_active=1 THEN s.id END) active_students, COALESCE(SUM(CASE WHEN s.is_active=1 THEN s.approved_payment_received ELSE 0 END),0) approved_payment FROM crm_users u LEFT JOIN crm_student_assignments a ON a.assigned_user_id=u.id LEFT JOIN crm_students s ON s.id=a.student_id WHERE u.is_active=1';
$staffArgs=[];if($owner){$staffSql.=' AND u.id=?';$staffArgs[]=$owner;}
$staffSql.=' GROUP BY u.id,u.name ORDER BY active_students DESC,u.name';
$stmt=$crm->prepare($staffSql);$stmt->execute($staffArgs);$staff=$stmt->fetchAll(PDO::FETCH_ASSOC);
$followSql='SELECT f.created_by, COUNT(*) followups_logged, COUNT(DISTINCT f.student_id) students_contacted, SUM(CASE WHEN f.next_followup_at IS NOT NULL THEN 1 ELSE 0 END) next_dates_set FROM crm_followups f WHERE f.created_at>=? AND f.created_at<? GROUP BY f.created_by';
$stmt=$crm->prepare($followSql);$stmt->execute([$from,$end]);$activity=[];foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $r)$activity[(int)$r['created_by']]=$r;
// Due counts use the newest follow-up per active student, avoiding counting historical reminders.
$dueSql='SELECT a.assigned_user_id,COUNT(*) due_count FROM crm_followups f JOIN (SELECT student_id,MAX(id) id FROM crm_followups GROUP BY student_id) last ON last.id=f.id JOIN crm_students s ON s.id=f.student_id LEFT JOIN crm_student_assignments a ON a.student_id=s.id WHERE s.is_active=1 AND f.next_followup_at IS NOT NULL AND f.next_followup_at<NOW() GROUP BY a.assigned_user_id';
$due=[];foreach($crm->query($dueSql)->fetchAll(PDO::FETCH_ASSOC) as $r)$due[(int)$r['assigned_user_id']]=(int)$r['due_count'];
$rows=[];foreach($staff as $s){$id=(int)$s['id'];$a=$activity[$id]??[];$rows[]=['name'=>$s['name'],'active_students'=>(int)$s['active_students'],'approved_payment'=>(float)$s['approved_payment'],'followups_logged'=>(int)($a['followups_logged']??0),'students_contacted'=>(int)($a['students_contacted']??0),'next_dates_set'=>(int)($a['next_dates_set']??0),'overdue'=>(int)($due[$id]??0)];}
function p5e($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function p5csv($v):string{$s=(string)$v;return preg_match('/^[\s\x00-\x1f]*[=+@\-]/u',$s)?"'".$s:$s;}
if(($_GET['export']??'')==='csv'){
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="imts_phase5_performance.csv"');header('X-Content-Type-Options: nosniff');
 $out=fopen('php://output','w');fputcsv($out,['Owner','Active assigned students','Approved payment on active students','Follow-ups logged in period','Distinct students contacted in period','Follow-ups with next date in period','Currently overdue latest follow-ups']);
 foreach($rows as $r){fputcsv($out,[p5csv($r['name']),$r['active_students'],number_format($r['approved_payment'],2,'.',''),$r['followups_logged'],$r['students_contacted'],$r['next_dates_set'],$r['overdue']]);}fclose($out);exit;
}
$perPage=PageSize::fromRequest();$totalRows=count($rows);$page=max(1,(int)($_GET['page']??1));$page=min($page,max(1,(int)ceil($totalRows/$perPage)));$visibleRows=array_slice($rows,($page-1)*$perPage,$perPage);
$totals=['active_students'=>0,'approved_payment'=>0,'followups_logged'=>0,'students_contacted'=>0,'next_dates_set'=>0,'overdue'=>0];foreach($rows as $r)foreach($totals as $key=>$_)$totals[$key]+=$r[$key];

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$pfLink=fn(array $m)=>'?'.http_build_query(array_merge(['from'=>$from,'to'=>$to,'owner'=>$owner,'per_page'=>$perPage],$m));
$todayStr=$today->format('Y-m-d');
ui_start([
 'title'=>$isAdmin?'Team performance':'My performance',
 'lead'=>'Follow-up activity is measured for the selected period. Student and overdue figures are current snapshots, not historical totals.',
 'user'=>$pageUser,'active'=>'performance.php',
 'crumbs'=>[['Reports',null],['Performance',null]],
 'actions'=>ui_csv_button('?'.http_build_query(['from'=>$from,'to'=>$to,'owner'=>$owner,'export'=>'csv']),'Export CSV'),
]);
?>
<section class="card report-section">
 <form method="get" class="filter-bar"><input type="hidden" name="per_page" value="<?=$perPage?>">
  <div class="field"><label for="pf-from">From</label><input class="input" id="pf-from" type="date" name="from" value="<?=p5e($from)?>" required></div>
  <div class="field"><label for="pf-to">To</label><input class="input" id="pf-to" type="date" name="to" value="<?=p5e($to)?>" required></div>
  <?php if($isAdmin):?><div class="field"><label for="pf-owner">Owner</label><select class="select" id="pf-owner" name="owner"><option value="0">All active users</option><?php foreach($owners as $u):?><option value="<?=(int)$u['id']?>" <?=$owner===(int)$u['id']?'selected':''?>><?=p5e($u['name'])?></option><?php endforeach;?></select></div><?php endif;?>
  <button class="btn btn-primary" type="submit" data-loading>Apply</button>
 </form>
 <?=ui_presets([['Today',$pfLink(['from'=>$todayStr,'to'=>$todayStr])],['Last 7 days',$pfLink(['from'=>$today->modify('-6 days')->format('Y-m-d'),'to'=>$todayStr])],['This month',$pfLink(['from'=>$today->modify('first day of this month')->format('Y-m-d'),'to'=>$todayStr])]])?>
</section>
<?=ui_kpis([['Active assigned',number_format($totals['active_students'])],['Follow-ups logged',number_format($totals['followups_logged'])],['Currently overdue',number_format($totals['overdue'])],['Approved payments (snapshot)','₹'.number_format($totals['approved_payment'],2)]])?>
<?php if(count($rows)>1):?>
<div class="two-col report-section">
 <section class="card"><h2>Follow-ups logged</h2><p class="help">Top owners in the selected period.</p><?php $a=$rows;usort($a,fn($x,$y)=>$y['followups_logged']<=>$x['followups_logged']);echo ui_hbars(array_map(fn($r)=>[$r['name'],$r['followups_logged']],array_slice($a,0,8)));?></section>
 <section class="card"><h2>Currently overdue</h2><p class="help">Latest follow-up past its next date.</p><?php $a=$rows;usort($a,fn($x,$y)=>$y['overdue']<=>$x['overdue']);echo ui_hbars(array_map(fn($r)=>[$r['name'],$r['overdue']],array_slice($a,0,8)));?></section>
</div>
<?php endif;?>
<section class="card report-section">
 <h2>Owner breakdown</h2>
 <?=PageSize::selector($perPage,$_GET)?>
 <div class="table-wrap"><table class="table compact"><thead><tr><th>Owner</th><th class="num">Active assigned</th><th class="num">Approved payment</th><th class="num">Follow-ups logged</th><th class="num">Students contacted</th><th class="num">Next dates set</th><th class="num">Currently overdue</th></tr></thead><tbody>
 <?php foreach($visibleRows as $r):?><tr><td><?=p5e($r['name'])?></td><td class="num"><?=number_format($r['active_students'])?></td><td class="num">₹<?=number_format($r['approved_payment'],2)?></td><td class="num"><?=number_format($r['followups_logged'])?></td><td class="num"><?=number_format($r['students_contacted'])?></td><td class="num"><?=number_format($r['next_dates_set'])?></td><td class="num"><?=$r['overdue']>0?'<span class="badge b-red">'.number_format($r['overdue']).'</span>':'0'?></td></tr><?php endforeach;?>
 <?php if(!$rows):?><tr><td colspan="7"><div class="empty" style="padding:20px"><?=ui_icon('inbox',40)?><h3>No active owners match</h3></div></td></tr><?php endif;?>
 </tbody></table></div>
 <nav class="pager" aria-label="Pagination"><span><?=number_format($totalRows)?> owners · Page <?=$page?></span><span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="?<?=p5e(http_build_query(array_merge($_GET,['page'=>$page-1])))?>">Previous</a><?php endif;?><?php if($page*$perPage<$totalRows):?><a class="btn btn-sm" href="?<?=p5e(http_build_query(array_merge($_GET,['page'=>$page+1])))?>">Next</a><?php endif;?></span></nav>
</section>
<p class="note">Follow-ups are credited to the user who recorded them, not automatically to the current owner. Totals of distinct students contacted are per-user and can overlap across owners. No ERP data is changed by this page.</p>
<?php ui_end(); ?>
