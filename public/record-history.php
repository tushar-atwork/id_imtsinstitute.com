<?php
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/Phase2.php';
require_once __DIR__.'/../app/TeamWorkflow.php';
require_once __DIR__.'/../app/PageSize.php';require_once __DIR__.'/../app/ui/layout.php';
$me=Phase2::current($crm);$id=max(1,(int)($_GET['id']??0));$student=Phase2::student($crm,$id);
if(!TeamWorkflow::canView($crm,$student,$me)){ui_error_page(403,'This record is not assigned to you','You can only view the activity of records assigned to you.');}
$per=PageSize::fromRequest();$page=max(1,(int)($_GET['page']??1));$filter=(string)($_GET['type']??'');
$allowed=['view','followup','assignment','erp_update'];if($filter!==''&&!in_array($filter,$allowed,true))$filter='';
$where='student_id=?'.($filter!==''?' AND event_type=?':'');$params=[$id];if($filter!=='')$params[]=$filter;
$c=$crm->prepare('SELECT COUNT(*) FROM crm_record_activity WHERE '.$where);$c->execute($params);$total=(int)$c->fetchColumn();$page=min($page,max(1,(int)ceil($total/$per)));$offset=($page-1)*$per;
$q=$crm->prepare('SELECT a.*,u.name AS actor_name,u.role AS actor_role FROM crm_record_activity a LEFT JOIN crm_users u ON u.id=a.actor_user_id WHERE '.$where.' ORDER BY a.id DESC LIMIT '.$per.' OFFSET '.$offset);$q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
function ah($x){return htmlspecialchars((string)($x??''),ENT_QUOTES,'UTF-8');}
function urlq($values){return '?'.http_build_query(array_merge($_GET,$values));}

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$isTeamMember=TeamWorkflow::isTeam((string)$me['role']);
$pages=max(1,(int)ceil($total/$per));
ui_start([
 'title'=>'Activity history','lead'=>'Every recorded action for this student, in one place.','user'=>$pageUser,
 'active'=>$isTeamMember?'my-work.php':'index.php',
 'crumbs'=>[[$isTeamMember?'My work':'Records',$isTeamMember?'my-work.php':'index.php'],[(string)$student['student_name'],'student.php?id='.$id],['Activity',null]],
 'actions'=>'<a class="btn" href="student.php?id='.$id.'">← Back to student</a>',
]);
?>
<section class="card" style="margin-bottom:var(--s4)">
 <div class="rec-head" style="margin:0"><span class="badge b-blue"><?=number_format($total)?> recorded event<?=$total===1?'':'s'?></span><strong><?=ah($student['student_name'])?></strong><span class="sub">Admission no. <?=ah($student['admission_no'])?></span><a class="right" href="student.php?id=<?=$id?>">View student ↗</a></div>
</section>
<section class="card">
 <form class="filter-bar" method="get" style="margin-bottom:var(--s4)"><input type="hidden" name="id" value="<?=$id?>">
  <div class="field"><label for="rh-type">Activity type</label><select class="select" id="rh-type" name="type"><option value="">All activity</option><?php foreach($allowed as $type):?><option value="<?=ah($type)?>" <?=$filter===$type?'selected':''?>><?=ah(ucfirst(str_replace('_',' ',$type)))?></option><?php endforeach;?></select></div>
  <div class="field"><label for="rh-per">Show per page</label><select class="select" id="rh-per" name="per_page"><?php foreach([10,20,50,100] as $n):?><option value="<?=$n?>" <?=$per===$n?'selected':''?>><?=$n?> records</option><?php endforeach;?></select></div>
  <button class="btn btn-primary" type="submit" data-loading>Apply filters</button>
  <?php if($filter!==''):?><a class="btn" href="?id=<?=$id?>">Clear</a><?php endif;?>
 </form>
 <?php if(!$rows):?>
  <div class="empty"><?=ui_icon('history',48)?><h3>No activity recorded yet</h3><p><?= $filter!==''?'No events match this filter. Try viewing all activity.':'New record views, assignments, follow-ups and ERP changes will appear here once they are recorded. Earlier events cannot be recovered automatically.' ?></p><?php if($filter!==''):?><a class="btn" href="?id=<?=$id?>">View all activity</a><?php else:?><a class="btn" href="student.php?id=<?=$id?>">Return to student</a><?php endif;?></div>
 <?php else:?>
  <ol class="timeline">
  <?php foreach($rows as $r): $kind=(string)$r['event_type'];$glyph=['view'=>'◉','assignment'=>'↔','erp_update'=>'↻'][$kind]??'✓';$tone=['view'=>'grey','followup'=>'green','assignment'=>'blue','erp_update'=>'amber'][$kind]??'grey';?>
   <li class="tl-item"><span class="tl-dot tl-<?=$tone?>" aria-hidden="true"><?=$glyph?></span>
    <div class="tl-body"><div class="tl-head"><strong><?=ah(ucfirst(str_replace('_',' ',$kind)))?></strong><time datetime="<?=ah($r['created_at'])?>"><?=ah($r['created_at'])?></time></div>
     <p><?=ah($r['description'])?></p>
     <div class="tl-meta"><span><?=ah($r['actor_name']??'ERP / system')?></span><?php if(!empty($r['actor_role'])):?><span><?=ah($r['actor_role'])?></span><?php endif;?><?php if(!empty($r['area'])):?><span><?=ah($r['area'])?></span><?php endif;?><?php if($me['role']==='admin'&&!empty($r['ip_address'])):?><span>IP <?=ah($r['ip_address'])?></span><?php endif;?></div></div></li>
  <?php endforeach;?>
  </ol>
 <?php endif;?>
 <nav class="pager" aria-label="Activity pagination"><span>Page <?=$page?> of <?=$pages?></span><span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="<?=ah(urlq(['page'=>$page-1]))?>">← Previous</a><?php endif;?><?php if($page*$per<$total):?><a class="btn btn-sm" href="<?=ah(urlq(['page'=>$page+1]))?>">Next →</a><?php endif;?></span></nav>
</section>
<?php ui_end(); ?>
