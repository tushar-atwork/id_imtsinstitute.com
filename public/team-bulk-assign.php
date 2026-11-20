<?php
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/TeamWorkflow.php';require_once __DIR__.'/../app/AssignmentSettings.php';require_once __DIR__.'/../app/ui/assign.php';
$me=Phase2::current($crm);if($me['role']!=='admin'){ui_error_page(403,'Administrators only','Assigning records is limited to administrators.');}
$roles=TeamWorkflow::roles();$role=(string)($_POST['team_role']??$_GET['team']??TeamWorkflow::DOC);if(!TeamWorkflow::isTeam($role))$role=TeamWorkflow::DOC;
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 Phase2::checkCsrf();
 try{
  $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['student_ids']??[])),static fn(int $v):bool=>$v>0)));
  if(!$ids||count($ids)>100)throw new RuntimeException('Select between 1 and 100 students on this page.');
  $owner=($_POST['owner']??'')===''?null:(int)$_POST['owner'];
  if($owner!==null){$check=$crm->prepare('SELECT 1 FROM crm_users WHERE id=? AND role=? AND is_active=1');$check->execute([$owner,$role]);if(!$check->fetchColumn())throw new RuntimeException('Choose an active user from this team.');}
  $checkStudent=$crm->prepare('SELECT 1 FROM crm_students WHERE id=? AND is_active=1');$done=0;$held=0;
  foreach($ids as $sid){$checkStudent->execute([$sid]);if(!$checkStudent->fetchColumn())continue;
   if($owner!==null && $role!==TeamWorkflow::DOC && WorkflowPipeline::documentsHold($crm,$sid)!==null){$held++;continue;}
   TeamWorkflow::assign($crm,$sid,$role,$owner,(int)$me['id']);$done++;}
  $success="$done selected student(s) processed. Existing follow-ups remain untouched.".($held?" $held skipped because their Documents status is Pending or Rejected.":'');
 }catch(Throwable $ex){$error=$ex->getMessage();}
}
require_once __DIR__.'/../app/RecordSort.php';$sort=RecordSort::current();$q=trim((string)($_GET['q']??''));if(mb_strlen($q)>100)$q=mb_substr($q,0,100);$where='s.is_active=1';$args=[];if($q!==''){$where.=' AND (s.student_name LIKE ? OR s.admission_no LIKE ?)';$args=['%'.$q.'%','%'.$q.'%'];}
$st=$crm->prepare("SELECT COUNT(*) FROM crm_students s WHERE $where");$st->execute($args);$total=(int)$st->fetchColumn();$perPage=PageSize::fromRequest();$page=max(1,(int)($_GET['page']??1));$page=min($page,max(1,(int)ceil($total/$perPage)));$offset=($page-1)*$perPage;
$st=$crm->prepare("SELECT s.id,s.first_synced_at,s.created_at,".RecordSort::isNewExpr()." AS is_new,s.admission_no,s.student_name,s.admission_status,u.name owner FROM crm_students s LEFT JOIN crm_team_assignments a ON a.student_id=s.id AND a.team_role='$role' LEFT JOIN crm_users u ON u.id=a.assigned_user_id WHERE $where ORDER BY ".RecordSort::orderBy($sort)." LIMIT $perPage OFFSET $offset");$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
$users=TeamWorkflow::teamUsers($crm,$role);
function bh(mixed $v):string{return TeamWorkflow::e($v??'—');}

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$pagerUrl=fn(array $m)=>'?'.http_build_query(array_merge(['team'=>$role,'q'=>$q,'sort'=>$sort,'per_page'=>$perPage],$m));
ui_start([
 'title'=>'Assign records by team',
 'lead'=>'Assign each team separately. Assigning one team never removes the other teams’ owners or follow-ups.',
 'user'=>$pageUser,'active'=>'team-bulk-assign.php',
 'crumbs'=>[['Assignment',null],['By team',null]],
 'toast'=>$success!==''?['success',$success]:null,
]);
?>
<?=ui_assign_steps()?>
<?php if($error):?><div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><strong>Nothing was changed.</strong> <?=bh($error)?></div></div><?php endif;?>
<nav class="tabs" aria-label="Team">
 <?php foreach($roles as $key=>$label):?><a class="tab" <?=$role===$key?'aria-current="page"':''?> href="?<?=bh(http_build_query(['team'=>$key,'q'=>$q,'sort'=>$sort,'per_page'=>$perPage]))?>"><?=bh($label)?></a><?php endforeach;?>
</nav>
<?=AssignmentSettings::warningHtml($crm)?>
<?php if(AssignmentSettings::isManual($crm,$role)):?><div class="alert alert-info" role="status"><?=ui_icon('alert')?><div><strong><?=bh($roles[$role])?> is in Manual mode.</strong> The sync does not assign its records, so assign them here.</div></div><?php endif;?>
<section class="card">
 <form method="get" class="assign-toolbar"><input type="hidden" name="per_page" value="<?=$perPage?>"><input type="hidden" name="team" value="<?=bh($role)?>">
  <div class="field grow"><label for="tb-q">Search</label><input class="input" id="tb-q" type="search" name="q" value="<?=bh($q)?>" placeholder="Name or admission no."></div>
  <div class="field"><?=RecordSort::select($sort)?></div>
  <button class="btn btn-primary" type="submit" data-loading>Search</button>
 </form>
 <?=PageSize::selector($perPage, $_GET)?>
 <form method="post" data-assign>
  <input type="hidden" name="csrf" value="<?=bh(Phase2::csrf())?>"><input type="hidden" name="team_role" value="<?=bh($role)?>">
  <p class="muted small"><strong><?=number_format($total)?></strong> eligible students · Page <?=$page?> · tick the records to change for <strong><?=bh($roles[$role])?></strong> (up to 100).</p>
  <div class="table-wrap">
   <table class="table">
    <thead><tr><th style="width:44px"><input class="chk" type="checkbox" id="sel-all" aria-label="Select all records on this page"></th><th>Admission</th><th>Student</th><th>Status</th><th>Current <?=bh($roles[$role])?> owner</th><th>Synced</th></tr></thead>
    <tbody>
    <?php foreach($rows as $r):?>
     <tr>
      <td><input class="chk t11check js-sel" type="checkbox" name="student_ids[]" value="<?=(int)$r['id']?>" data-name="<?=bh($r['student_name'])?>" data-adm="<?=bh($r['admission_no'])?>" data-owner="<?=bh($r['owner']??'')?>" aria-label="Select <?=bh($r['admission_no'])?>"></td>
      <td><a href="student.php?id=<?=(int)$r['id']?>"><?=bh($r['admission_no'])?></a></td>
      <td><?=bh($r['student_name'])?><?=RecordSort::badge($r['is_new'])?></td>
      <td><?=ui_status_badge($r['admission_status'],'—')?></td>
      <td><?=$r['owner']?bh($r['owner']):'<span class="muted"><em>Unassigned</em></span>'?></td>
      <td><?=bh(RecordSort::stamp($r['first_synced_at']??$r['created_at']??null))?></td>
     </tr>
    <?php endforeach;?>
    <?php if(!$rows):?><tr><td colspan="6"><div class="empty" style="padding:24px"><?=ui_icon('inbox',40)?><h3>No students</h3><p>Try a different search.</p></div></td></tr><?php endif;?>
    </tbody>
   </table>
  </div>
  <?=ui_assign_bar('<select class="select" name="owner" id="bulk-owner" aria-label="Assign selected records to"><option value="" data-name="">Unassign this team only</option>'.implode('',array_map(fn($x)=>'<option value="'.(int)$x['id'].'" data-name="'.bh($x['name']).'">'.bh($x['name']).' ('.bh($x['username']).')</option>',$users)).'</select>','Review & assign',100)?>
 </form>
 <nav class="pager" aria-label="Pagination">
  <span><?=number_format($total)?> students · Page <?=$page?></span>
  <span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="<?=bh($pagerUrl(['page'=>$page-1]))?>">Previous</a><?php endif;?><?php if($page*$perPage<$total):?><a class="btn btn-sm" href="<?=bh($pagerUrl(['page'=>$page+1]))?>">Next</a><?php endif;?></span>
 </nav>
</section>
<?=ui_assign_dialog($role!==TeamWorkflow::DOC?'Records whose Documents status is Pending or Rejected are skipped for this team; the result message tells you how many.':'')?>
<script src="assets/js/assign.js?v=1" defer></script>
<?php ui_end(); ?>
