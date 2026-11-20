<?php
/** Phase 4: admin-only audit of Phase 3 assignment changes. */
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/ui/report.php';$me=Phase2::current($crm);
if($me['role']!=='admin'){ui_error_page(403,'Administrators only','Assignment history is limited to administrators.');}
$q=trim((string)($_GET['q']??''));if(mb_strlen($q)>100)$q=mb_substr($q,0,100);
$where='1=1';$args=[];if($q!==''){$where='(s.student_name LIKE ? OR s.admission_no LIKE ?)';$args=['%'.$q.'%','%'.$q.'%'];}
$from=' FROM crm_assignment_history h JOIN crm_students s ON s.id=h.student_id LEFT JOIN crm_users old_user ON old_user.id=h.previous_user_id LEFT JOIN crm_users new_user ON new_user.id=h.new_user_id JOIN crm_users actor ON actor.id=h.changed_by WHERE '.$where;
$columns='SELECT h.id,h.student_id,h.changed_at,s.student_name,s.admission_no,old_user.name AS previous_owner,new_user.name AS new_owner,actor.name AS changed_by';
$count=$crm->prepare('SELECT COUNT(*)'.$from);$count->execute($args);$total=(int)$count->fetchColumn();
if(($_GET['export']??'')==='csv'){
 if($total>10000){http_response_code(422);exit('Export limited to 10,000 rows; narrow your search.');}
 $st=$crm->prepare($columns.$from.' ORDER BY h.changed_at DESC,h.id DESC LIMIT 10000');$st->execute($args);
 header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="imts_assignment_history.csv"');header('X-Content-Type-Options: nosniff');$out=fopen('php://output','w');fputcsv($out,['Changed at','Admission','Student','Previous owner','New owner','Changed by']);
 while($r=$st->fetch(PDO::FETCH_ASSOC)){$v=[$r['changed_at'],$r['admission_no'],$r['student_name'],$r['previous_owner']??'Unassigned',$r['new_owner']??'Unassigned',$r['changed_by']];foreach([1,2,3,4,5] as $i){if(preg_match('/^[\s\x00-\x1f]*[=+@\-]/u',(string)$v[$i]))$v[$i]="'".$v[$i];}fputcsv($out,$v);}fclose($out);exit;
}
$perPage=PageSize::fromRequest();$page=max(1,(int)($_GET['page']??1));$page=min($page,max(1,(int)ceil($total/$perPage)));$st=$crm->prepare($columns.$from.' ORDER BY h.changed_at DESC,h.id DESC LIMIT '.$perPage.' OFFSET '.(($page-1)*$perPage));$st->execute($args);$rows=$st->fetchAll();
function h4a($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
ui_start([
 'title'=>'Assignment history','lead'=>'Audit of changes made through single-owner bulk assignment. Existing records are not backfilled.',
 'user'=>$pageUser,'active'=>'assignment-history.php',
 'crumbs'=>[['Audit',null],['Assignment history',null]],
 'actions'=>ui_csv_button('?'.http_build_query(['q'=>$q,'export'=>'csv']),'Export CSV'),
]);
?>
<section class="card">
 <form method="get" class="filter-bar" style="margin-bottom:var(--s3)"><input type="hidden" name="per_page" value="<?=$perPage?>">
  <div class="field grow"><label for="ah-q">Search</label><input class="input" id="ah-q" type="search" name="q" value="<?=h4a($q)?>" placeholder="Student name or admission number"></div>
  <button class="btn btn-primary" type="submit" data-loading>Search</button>
  <?php if($q!==''):?><a class="btn" href="?per_page=<?=$perPage?>">Clear</a><?php endif;?>
 </form>
 <?=PageSize::selector($perPage, $_GET)?>
 <p class="muted small"><strong><?=number_format($total)?></strong> changes · Page <?=$page?></p>
 <div class="table-wrap"><table class="table compact"><thead><tr><th>Date</th><th>Admission</th><th>Student</th><th>Previous</th><th>New</th><th>Changed by</th></tr></thead><tbody>
 <?php foreach($rows as $r):?><tr><td><?=h4a($r['changed_at'])?></td><td><a href="student.php?id=<?=(int)$r['student_id']?>"><?=h4a($r['admission_no'])?></a></td><td><?=h4a($r['student_name'])?></td><td><?=$r['previous_owner']?h4a($r['previous_owner']):'<span class="muted"><em>Unassigned</em></span>'?></td><td><?=$r['new_owner']?h4a($r['new_owner']):'<span class="muted"><em>Unassigned</em></span>'?></td><td><?=h4a($r['changed_by'])?></td></tr><?php endforeach;?>
 <?php if(!$rows):?><tr><td colspan="6"><div class="empty" style="padding:20px"><?=ui_icon('history',40)?><h3>No assignment changes found</h3><?php if($q!==''):?><p>Try a different search.</p><?php endif;?></div></td></tr><?php endif;?>
 </tbody></table></div>
 <nav class="pager" aria-label="Pagination"><span><?=number_format($total)?> changes · Page <?=$page?></span><span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="?<?=h4a(http_build_query(['q'=>$q,'page'=>$page-1,'per_page'=>$perPage]))?>">Previous</a><?php endif;?><?php if($page*$perPage<$total):?><a class="btn btn-sm" href="?<?=h4a(http_build_query(['q'=>$q,'page'=>$page+1,'per_page'=>$perPage]))?>">Next</a><?php endif;?></span></nav>
</section>
<?php ui_end(); ?>
