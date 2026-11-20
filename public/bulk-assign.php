<?php
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/ui/assign.php';
$u=Phase2::current($crm);if($u['role']!=='admin'){ui_error_page(403,'Administrators only','Assigning records is limited to administrators.');}
$error='';$success='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 Phase2::checkCsrf();
 try{
  $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['student_ids']??[])),fn($x)=>$x>0)));
  if(!$ids || count($ids)>200)throw new RuntimeException('Select 1 to 200 students on the current page.');
  $owner=($_POST['owner']??'')===''?null:(int)$_POST['owner'];
  if($owner!==null){$s=$crm->prepare('SELECT COUNT(*) FROM crm_users WHERE id=? AND is_active=1');$s->execute([$owner]);if(!(int)$s->fetchColumn())throw new RuntimeException('Invalid active user');}
  $crm->beginTransaction();
  $check=$crm->prepare('SELECT id FROM crm_students WHERE id=? AND is_active=1');
  $old=$crm->prepare('SELECT assigned_user_id FROM crm_student_assignments WHERE student_id=?');
  $save=$crm->prepare('INSERT INTO crm_student_assignments(student_id,assigned_user_id,assigned_by,assigned_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE assigned_user_id=VALUES(assigned_user_id),assigned_by=VALUES(assigned_by),assigned_at=NOW()');
  $log=$crm->prepare('INSERT INTO crm_assignment_history(student_id,previous_user_id,new_user_id,changed_by,changed_at) VALUES(?,?,?,?,NOW())');$changed=0;
  foreach($ids as $id){$check->execute([$id]);if(!$check->fetchColumn())continue;$old->execute([$id]);$prev=$old->fetchColumn();$prev=$prev===false||$prev===null?null:(int)$prev;if($prev===$owner)continue;$save->execute([$id,$owner,$u['id']]);$log->execute([$id,$prev,$owner,$u['id']]);$changed++;}
  $crm->commit();$success="$changed assignment(s) updated.";
 }catch(Throwable $e){if($crm->inTransaction())$crm->rollBack();$error=$e->getMessage();}
}
require_once __DIR__.'/../app/RecordSort.php';$sort=RecordSort::current();$perPage=PageSize::fromRequest();$page=max(1,(int)($_GET['page']??1));$q=trim((string)($_GET['q']??''));$like='%'.$q.'%';$where='s.is_active=1';$args=[];if($q!==''){$where.=' AND (s.student_name LIKE ? OR s.admission_no LIKE ?)';$args=[$like,$like];}
$count=$crm->prepare("SELECT COUNT(*) FROM crm_students s WHERE $where");$count->execute($args);$total=(int)$count->fetchColumn();$page=min($page,max(1,(int)ceil($total/$perPage)));$offset=($page-1)*$perPage;
$st=$crm->prepare("SELECT s.id,s.first_synced_at,s.created_at,".RecordSort::isNewExpr()." AS is_new,s.student_name,s.admission_no,s.admission_status,a.assigned_user_id,u.name owner FROM crm_students s LEFT JOIN crm_student_assignments a ON a.student_id=s.id LEFT JOIN crm_users u ON u.id=a.assigned_user_id WHERE $where ORDER BY ".RecordSort::orderBy($sort)." LIMIT $perPage OFFSET $offset");$st->execute($args);$rows=$st->fetchAll();$users=$crm->query('SELECT id,name FROM crm_users WHERE is_active=1 ORDER BY name')->fetchAll();
function h($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

$pageUser=['name'=>$u['name'],'role'=>$u['role']];
$pagerUrl=fn(array $m)=>'?'.http_build_query(array_merge(['q'=>$q,'sort'=>$sort,'per_page'=>$perPage],$m));
ui_start([
 'title'=>'Assign records',
 'lead'=>'Pick records, choose one employee, review the change, then confirm. Only the selected records change; follow-up history is kept.',
 'user'=>$pageUser,'active'=>'bulk-assign.php',
 'crumbs'=>[['Assignment',null],['Single owner',null]],
 'toast'=>$success!==''?['success',$success]:null,
]);
?>
<?=ui_assign_steps()?>
<?php if($error):?><div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><strong>Nothing was changed.</strong> <?=h($error)?></div></div><?php endif;?>
<section class="card">
 <form method="get" class="assign-toolbar"><input type="hidden" name="per_page" value="<?=$perPage?>">
  <div class="field grow"><label for="as-q">Search</label><input class="input" id="as-q" type="search" name="q" placeholder="Name or admission number" value="<?=h($q)?>"></div>
  <div class="field"><?=RecordSort::select($sort)?></div>
  <button class="btn btn-primary" type="submit" data-loading>Search</button>
 </form>
 <?=PageSize::selector($perPage, $_GET)?>
 <form method="post" data-assign>
  <input type="hidden" name="csrf" value="<?=h(Phase2::csrf())?>">
  <p class="muted small"><strong><?=number_format($total)?></strong> active students · Page <?=$page?> · tick the records to change (up to 200).</p>
  <div class="table-wrap">
   <table class="table">
    <thead><tr><th style="width:44px"><input class="chk" type="checkbox" id="sel-all" aria-label="Select all records on this page"></th><th>Admission</th><th>Student</th><th>Status</th><th>Current owner</th><th>Synced</th></tr></thead>
    <tbody>
    <?php foreach($rows as $r):?>
     <tr>
      <td><input class="chk p3-check js-sel" type="checkbox" name="student_ids[]" value="<?=(int)$r['id']?>" data-name="<?=h($r['student_name'])?>" data-adm="<?=h($r['admission_no'])?>" data-owner="<?=h($r['owner']??'')?>" aria-label="Select <?=h($r['admission_no'])?>"></td>
      <td><a href="student.php?id=<?=(int)$r['id']?>"><?=h($r['admission_no'])?></a></td>
      <td><?=h($r['student_name'])?><?=RecordSort::badge($r['is_new'])?></td>
      <td><?=ui_status_badge($r['admission_status'],'—')?></td>
      <td><?=$r['owner']?h($r['owner']):'<span class="muted"><em>Unassigned</em></span>'?></td>
      <td><?=h(RecordSort::stamp($r['first_synced_at']??$r['created_at']??null))?></td>
     </tr>
    <?php endforeach;?>
    <?php if(!$rows):?><tr><td colspan="6"><div class="empty" style="padding:24px"><?=ui_icon('inbox',40)?><h3>No students found</h3><p>Try a different search.</p></div></td></tr><?php endif;?>
    </tbody>
   </table>
  </div>
  <?=ui_assign_bar('<select class="select" name="owner" id="bulk-owner" aria-label="Assign selected records to"><option value="" data-name="">Unassigned</option>'.implode('',array_map(fn($x)=>'<option value="'.(int)$x['id'].'" data-name="'.h($x['name']).'">'.h($x['name']).'</option>',$users)).'</select>','Review & assign',200)?>
 </form>
 <nav class="pager" aria-label="Pagination">
  <span><?=number_format($total)?> students · Page <?=$page?></span>
  <span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="<?=h($pagerUrl(['page'=>$page-1]))?>">Previous</a><?php endif;?><?php if($page*$perPage<$total):?><a class="btn btn-sm" href="<?=h($pagerUrl(['page'=>$page+1]))?>">Next</a><?php endif;?></span>
 </nav>
</section>
<?=ui_assign_dialog()?>
<script src="assets/js/assign.js?v=1" defer></script>
<?php ui_end(); ?>
