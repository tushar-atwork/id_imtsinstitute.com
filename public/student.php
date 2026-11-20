<?php
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/Phase2.php';
require_once __DIR__.'/../app/TeamWorkflow.php';
require_once __DIR__.'/../app/ContactActions.php';
require_once __DIR__.'/../app/ui/layout.php';
$me=Phase2::current($crm);$id=max(0,(int)($_GET['id']??$_POST['id']??0));$s=Phase2::student($crm,$id);
if(!TeamWorkflow::canView($crm,$s,$me)){ui_error_page(403,'This record is not assigned to you','This student is not assigned to your team account.',TeamWorkflow::isTeam((string)$me['role'])?'my-work.php':'index.php',TeamWorkflow::isTeam((string)$me['role'])?'Back to my work':'Back to records');}
require_once __DIR__.'/../app/RecordAudit.php';
if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') RecordAudit::log($crm,$id,(int)$me['id'],'view','student','Student record opened');
$error='';$success=isset($_GET['saved']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 Phase2::checkCsrf();
 try{
  $action=(string)($_POST['action']??'');
  if($action==='team_assign'){
    if($me['role']!=='admin')throw new RuntimeException('Administrator only.');
    $role=(string)($_POST['team_role']??'');$owner=($_POST['assigned_user_id']??'')===''?null:(int)$_POST['assigned_user_id'];
    TeamWorkflow::assign($crm,$id,$role,$owner,(int)$me['id']);
  }elseif($action==='team_followup'){
    TeamWorkflow::saveFollowup($crm,$id,$me,$_POST);
  }else throw new RuntimeException('Invalid action.');
  header('Location: student.php?id='.$id.'&saved=1&section='.($action==='team_assign'?'assignment':'followup'),true,303);exit;
 }catch(Throwable $ex){$error=$ex->getMessage();}
}
$s=Phase2::student($crm,$id);$ass=TeamWorkflow::assignments($crm,$id);$roles=TeamWorkflow::roles();
$visibleRoles=$me['role']==='admin'?array_keys($roles):(TeamWorkflow::isTeam((string)$me['role'])?[$me['role']]:[]);
$users=[];$statuses=[];foreach($roles as $role=>$label){$users[$role]=TeamWorkflow::teamUsers($crm,$role);$statuses[$role]=TeamWorkflow::statuses($crm,$role);}
$perPage=PageSize::fromRequest();$teamTotal=$crm->prepare('SELECT COUNT(*) FROM crm_team_followups WHERE student_id=?');$teamTotal->execute([$id]);$teamTotal=(int)$teamTotal->fetchColumn();
$teamPage=max(1,(int)($_GET['team_page']??1));$teamPage=min($teamPage,max(1,(int)ceil($teamTotal/$perPage)));$teamOffset=($teamPage-1)*$perPage;
$q=$crm->prepare('SELECT f.*,u.name AS author,st.status_label FROM crm_team_followups f JOIN crm_users u ON u.id=f.created_by JOIN crm_team_statuses st ON st.id=f.status_id WHERE f.student_id=? ORDER BY f.id DESC LIMIT '.$perPage.' OFFSET '.$teamOffset);$q->execute([$id]);$hist=$q->fetchAll(PDO::FETCH_ASSOC);
$legacyTotal=$crm->prepare('SELECT COUNT(*) FROM crm_followups WHERE student_id=?');$legacyTotal->execute([$id]);$legacyTotal=(int)$legacyTotal->fetchColumn();
$legacyPage=max(1,(int)($_GET['legacy_page']??1));$legacyPage=min($legacyPage,max(1,(int)ceil($legacyTotal/$perPage)));$legacyOffset=($legacyPage-1)*$perPage;
$q=$crm->prepare('SELECT f.*,u.name AS author FROM crm_followups f JOIN crm_users u ON u.id=f.created_by WHERE f.student_id=? ORDER BY f.id DESC LIMIT '.$perPage.' OFFSET '.$legacyOffset);$q->execute([$id]);$oldHist=$q->fetchAll(PDO::FETCH_ASSOC);
$legacyUsers=$me['role']==='admin'?$crm->query("SELECT id,name FROM crm_users WHERE is_active=1 AND role NOT IN ('document_collection','form_filled','verification') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC):[];
// Read-only stage state for the presentation stepper (same table the department timeline below reads).
$stages=[];$q=$crm->prepare('SELECT team_role,completed_at,outcome FROM crm_workflow_stages WHERE student_id=?');$q->execute([$id]);foreach($q->fetchAll(PDO::FETCH_ASSOC) as $stageRow)$stages[$stageRow['team_role']]=$stageRow;
$stepCurrent=null;foreach(array_keys($roles) as $stageRole){if(empty($stages[$stageRole]['completed_at'])){$stepCurrent=$stageRole;break;}}
function h11(mixed $v):string{return TeamWorkflow::e($v??'—');}

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$formVal=function(string $role,string $key) use($error,$id){return ($_SERVER['REQUEST_METHOD']==='POST' && $error!=='' && (string)($_POST['team_role']??'')===$role && (int)($_POST['id']??0)===$id)?(string)($_POST[$key]??''):'';};
$statusTitle=['document_collection'=>'Document status','form_filled'=>'Form filled status','verification'=>'Verification status'];
ui_start([
 'title'=>(string)($s['student_name']??'Student'),
 'lead'=>trim((string)$s['admission_no']).' · ERP #'.(int)$s['erp_student_id'],
 'user'=>$pageUser,
 'active'=>TeamWorkflow::isTeam((string)$me['role'])?'my-work.php':'index.php',
 'crumbs'=>[[TeamWorkflow::isTeam((string)$me['role'])?'My work':'Records',TeamWorkflow::isTeam((string)$me['role'])?'my-work.php':'index.php'],[(string)($s['student_name']??'Student'),null]],
 'toast'=>$success?['success','Saved successfully.']:null,
 'actions'=>'<a class="btn" href="record-history.php?id='.$id.'">Full activity log</a>',
]);
?>
<div class="rec-head">
 <?=ui_status_badge($s['admission_status'],'ERP status')?>
 <span class="sub">ERP admission status</span>
 <?php if(!(int)$s['is_active']):?><span class="badge b-grey">Inactive in ERP</span><?php endif;?>
</div>

<?php if($error):?><div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><strong>Could not save.</strong> <?=h11($error)?></div></div><?php endif;?>
<?php $docHold=WorkflowPipeline::documentsHold($crm,$id); if($docHold!==null):?><div class="alert <?=$docHold==='rejected'?'alert-error':'alert-warn'?>" role="status"><?=ui_icon('alert')?><div><strong>Documents status: <?=h11(ucfirst($docHold))?>.</strong> This record stays with the Documents team and will not be assigned or forwarded to Form Filled or Verification until the Documents status changes.</div></div><?php endif;?>

<section class="card" aria-label="Progress through teams" style="margin-bottom:var(--s4)">
 <div class="card-head"><h2>Where this record is</h2><span class="muted small">Documents → Form Filled → Verification</span></div>
 <ol class="stepper">
 <?php foreach($roles as $role=>$title):
   $done=!empty($stages[$role]['completed_at']);$isCur=($role===$stepCurrent);
   $state=$done?'Done'.(!empty($stages[$role]['outcome'])?' · '.ucfirst((string)$stages[$role]['outcome']):''):($isCur?'In progress':'Not started');?>
  <li class="step<?=$done?' is-done':''?><?=$isCur?' is-current':''?>"<?=$isCur?' aria-current="step"':''?>><?=h11($title)?><br><span class="small muted"><?=h11($ass[$role]['name']??'Unassigned')?> · <?=h11($state)?></span></li>
 <?php endforeach;?>
 </ol>
</section>

<div class="rec-grid">
 <div class="rec-col rec-left">
  <?php require_once __DIR__.'/../app/AdmissionTiming.php'; $tm=AdmissionTiming::forStudents($crm,[$s])[(int)$s['id']]; ?>
  <section class="card" id="erp-details" data-section="details">
   <div class="card-head"><h2>Admission details</h2><span class="muted small">From ERP · read-only</span></div>
   <div class="facts">
   <?php foreach(['admission_date'=>'Admission date','counsellor_name'=>'ERP counsellor','counsellor_form_assigned_erp_date'=>'Counsellor assigned date','latest_erp_assignment_date'=>'Last ERP assignment','admission_confirmation_status'=>'Admission confirmation','university'=>'University','session_name'=>'Session','phone'=>'Mobile','email'=>'Email'] as $key=>$label):?>
    <div><small><?=h11($label)?></small><strong><?=h11($s[$key])?></strong></div>
   <?php endforeach;?>
    <div><small>Approved payment</small><strong>₹<?=number_format((float)$s['approved_payment_received'],2)?></strong></div>
   </div>
  </section>
  <section class="card">
   <div class="card-head"><h2>Processing timeline</h2></div>
   <p class="help">Calculated from ERP dates and Document Collection follow-ups.</p>
   <div class="metrics">
   <?php $timingHelp=[
   'send_delay_days'=>'ERP counsellor assignment date minus admission date, in calendar days.',
   'documents_delay_days'=>'Latest All Docs Complete follow-up date minus ERP counsellor assignment date. Reopened documents remain pending until completed again.',
   'approval_days'=>'First recorded ERP approval date minus admission date.',
   'verification_processing_days'=>'First recorded ERP approval date minus latest All Docs Complete date.',
   'pending_days'=>'Today minus admission date while the ERP approval date is not recorded.'
   ]; foreach(['send_delay_days'=>'Delay sending for verification','documents_delay_days'=>'Documents delay','approval_days'=>'Total approval time','verification_processing_days'=>'Verification processing','pending_days'=>'Current pending days'] as $key=>$label):?>
    <div class="metric"><div class="metric-head"><small><?=h11($label)?></small><details class="info"><summary aria-label="How <?=h11($label)?> is calculated" title="Calculation information">i</summary><div class="info-text"><?=h11($timingHelp[$key])?></div></details></div><strong><?=h11(($key==='documents_delay_days' && $tm['docs_reopened'])?'Reopened – pending':AdmissionTiming::fmt($tm[$key]))?></strong></div>
   <?php endforeach;?>
    <div class="metric"><div class="metric-head"><small>Latest documents completion</small><details class="info"><summary aria-label="Latest documents completion information">i</summary><div class="info-text">Date of the latest All Docs Complete follow-up. A subsequent incomplete status means documents are reopened.</div></details></div><strong><?=($tm['docs_reopened']?'Reopened – pending':AdmissionTiming::date($tm['docs_completed_at']))?></strong></div>
    <div class="metric"><div class="metric-head"><small>ERP verification date</small><details class="info"><summary aria-label="ERP verification date information">i</summary><div class="info-text">First recorded date the admission became Approved in ERP. Historical dates require ERP approval history.</div></details></div><strong><?=AdmissionTiming::date($tm['verification_date'])?></strong></div>
   </div>
  </section>
 </div>

 <div class="rec-col rec-centre">
  <div id="team-followup" data-section="followup" class="rec-col">
  <?php foreach($roles as $role=>$title):?><?php if(!in_array($role,$visibleRoles,true))continue;?>
   <section class="card team-card<?=$role===$stepCurrent?' is-current':''?>">
    <div class="team-card-head"><div><h2><?=h11($title)?></h2><div class="small muted">Owner: <?=h11($ass[$role]['name']??'Unassigned')?></div></div><?=$role===$stepCurrent?'<span class="badge b-blue">Current stage</span>':''?></div>
    <?php if(!(int)$s['is_active']):?>
     <p class="muted">Inactive in ERP. Existing history remains available.</p>
    <?php else:?>
     <p class="help">Record the contact result, the new status and what happens next.</p>
     <form method="post">
      <input type="hidden" name="csrf" value="<?=h11(Phase2::csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="team_followup"><input type="hidden" name="team_role" value="<?=h11($role)?>">
      <div class="form-grid">
       <div class="field"><label for="cr-<?=h11($role)?>">Contact result</label><select class="select" id="cr-<?=h11($role)?>" name="contact_result" required><option value="">Select</option><?php foreach(['Connected','Not Connected'] as $opt):?><option<?=$formVal($role,'contact_result')===$opt?' selected':''?>><?=$opt?></option><?php endforeach;?></select></div>
       <div class="field"><label for="st-<?=h11($role)?>"><?=h11($statusTitle[$role]??'Status')?></label><select class="select" id="st-<?=h11($role)?>" name="status_id" required><option value="">Select status</option><?php foreach($statuses[$role] as $status):?><option value="<?=(int)$status['id']?>"<?=$formVal($role,'status_id')===(string)$status['id']?' selected':''?>><?=h11($status['status_label'])?></option><?php endforeach;?></select></div>
       <div class="field span-2"><label for="dr-<?=h11($role)?>">Delay reason</label><textarea class="textarea" id="dr-<?=h11($role)?>" name="delay_reason" maxlength="5000" rows="2" placeholder="Reason for delay, if any"><?=h11($formVal($role,'delay_reason')?:'')?></textarea></div>
       <div class="field span-2"><label for="rv-<?=h11($role)?>">Remark for Verification</label><textarea class="textarea" id="rv-<?=h11($role)?>" name="remark_for_verification" maxlength="5000" rows="3" placeholder="Notes for verification"><?=h11($formVal($role,'remark_for_verification')?:'')?></textarea></div>
       <div class="field span-2"><label for="rm-<?=h11($role)?>">Other remarks</label><textarea class="textarea" id="rm-<?=h11($role)?>" name="remarks" maxlength="5000" rows="2"><?=h11($formVal($role,'remarks')?:'')?></textarea></div>
       <div class="field"><label for="nf-<?=h11($role)?>">Next follow-up</label><input class="input" id="nf-<?=h11($role)?>" type="datetime-local" name="next_followup_at" value="<?=h11($formVal($role,'next_followup_at')?:'')?>"><span class="help">Optional. The record appears in “Today” on that date.</span></div>
      </div>
      <button class="btn btn-primary" type="submit" data-loading>Save follow-up</button>
     </form>
    <?php endif;?>
   </section>
  <?php endforeach;?>
  <?php if(!$visibleRoles):?><section class="card"><p class="muted" style="margin:0">Follow-up updates are done by the assigned team. Their entries appear in the history.</p></section><?php endif;?>
  </div>

  <?php if($me['role']==='admin'):?>
  <section class="card" id="team-assignment" data-section="assignment">
   <div class="card-head"><h2>Team assignment</h2><span class="muted small">Choose who is responsible, per team</span></div>
   <?php foreach($roles as $role=>$title):?><?php if(!in_array($role,$visibleRoles,true))continue;?>
    <form method="post" class="assign-row">
     <input type="hidden" name="csrf" value="<?=h11(Phase2::csrf())?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="team_assign"><input type="hidden" name="team_role" value="<?=h11($role)?>">
     <div class="field"><label for="as-<?=h11($role)?>">Assign <?=h11($title)?></label><select class="select" id="as-<?=h11($role)?>" name="assigned_user_id"><option value="">Unassigned</option><?php foreach($users[$role] as $person):?><option value="<?=(int)$person['id']?>" <?=(int)($ass[$role]['assigned_user_id']??0)===(int)$person['id']?'selected':''?>><?=h11($person['name'])?> (<?=h11($person['username'])?>)</option><?php endforeach;?></select></div>
     <button class="btn" type="submit" data-loading>Save <?=h11($title)?> assignment</button>
    </form>
   <?php endforeach;?>
   <p class="help" style="margin:8px 0 0">Only administrators can change assignments. <a href="assignment-history.php">Assignment history</a></p>
  </section>
  <?php else:?>
  <section class="card"><p class="muted small" style="margin:0">Only administrators can change assignments.</p></section>
  <?php endif;?>
 </div>

 <div class="rec-col rec-right" data-tabs>
  <section class="card" data-section="history">
   <div class="tabs" role="tablist" aria-label="History">
    <button class="tab" type="button" data-tab="team">Follow-up history <span class="count"><?=number_format($teamTotal)?></span></button>
    <?php if($legacyTotal):?><button class="tab" type="button" data-tab="legacy">Previous CRM <span class="count"><?=number_format($legacyTotal)?></span></button><?php endif;?>
   </div>
   <div data-panel="team" id="team-history">
    <?=PageSize::selector($perPage,$_GET)?>
    <p class="help">Append-only entries. Each team has independent statuses and follow-up dates.</p>
    <div class="hist-list">
    <?php foreach($hist as $row):?>
     <article class="hist-item">
      <div class="hist-top"><span><span class="badge b-blue"><?=h11($roles[$row['team_role']]??$row['team_role'])?></span> <?=ui_status_badge($row['status_label'])?></span><span class="when"><?=h11($row['created_at'])?> · <?=h11($row['author'])?></span></div>
      <div class="hist-body">
       <div><small>Contact</small><?=h11($row['contact_result'])?></div>
       <?php if(trim((string)$row['delay_reason'])!==''):?><div><small>Delay reason</small><p><?=nl2br(h11($row['delay_reason']))?></p></div><?php endif;?>
       <?php if(trim((string)$row['remark_for_verification'])!==''):?><div><small>Remark for Verification</small><p><?=nl2br(h11($row['remark_for_verification']))?></p></div><?php endif;?>
       <?php if(trim((string)$row['remarks'])!==''):?><div><small>Other remarks</small><p><?=nl2br(h11($row['remarks']))?></p></div><?php endif;?>
       <div><small>Next follow-up</small><?=h11($row['next_followup_at'])?></div>
      </div>
     </article>
    <?php endforeach;?>
    <?php if(!$hist):?><div class="empty" style="padding:24px 8px"><?=ui_icon('note',40)?><h3>No follow-ups yet</h3><p>Use the form to record the first contact.</p></div><?php endif;?>
    </div>
    <nav class="crm-page-links"><span><?=number_format($teamTotal)?> records · Page <?=$teamPage?></span><?php if($teamPage>1):?><a href="?<?=h11(http_build_query(array_merge($_GET,['id'=>$id,'team_page'=>$teamPage-1])))?>#team-history">Previous</a><?php endif;?><?php if($teamPage*$perPage<$teamTotal):?><a href="?<?=h11(http_build_query(array_merge($_GET,['id'=>$id,'team_page'=>$teamPage+1])))?>#team-history">Next</a><?php endif;?></nav>
   </div>
   <?php if($legacyTotal):?>
   <div data-panel="legacy" id="legacy-history" hidden>
    <p class="help">Previous CRM follow-ups (retained).</p>
    <div class="hist-list">
    <?php foreach($oldHist as $row):?>
     <article class="hist-item">
      <div class="hist-top"><span class="badge b-grey"><?=h11($row['outcome'])?></span><span class="when"><?=h11($row['created_at'])?> · <?=h11($row['author'])?></span></div>
      <div class="hist-body"><div><small>Contact</small><?=h11($row['contact_result'])?></div><?php if(trim((string)$row['remarks'])!==''):?><div><small>Remarks</small><p><?=nl2br(h11($row['remarks']))?></p></div><?php endif;?><div><small>Next</small><?=h11($row['next_followup_at'])?></div></div>
     </article>
    <?php endforeach;?>
    </div>
    <nav class="crm-page-links"><span><?=number_format($legacyTotal)?> records · Page <?=$legacyPage?></span><?php if($legacyPage>1):?><a href="?<?=h11(http_build_query(array_merge($_GET,['id'=>$id,'legacy_page'=>$legacyPage-1])))?>#legacy-history">Previous</a><?php endif;?><?php if($legacyPage*$perPage<$legacyTotal):?><a href="?<?=h11(http_build_query(array_merge($_GET,['id'=>$id,'legacy_page'=>$legacyPage+1])))?>#legacy-history">Next</a><?php endif;?></nav>
   </div>
   <?php endif;?>
  </section>
 </div>
</div>

<div class="rec-full rec-embed"><?php WorkflowPipeline::renderTimeline($crm,$s); ?></div>

<div class="action-bar" role="region" aria-label="Record actions">
 <div class="group contact-actions"><?=ContactActions::html($s)?></div>
 <div class="group">
  <a class="btn" href="record-history.php?id=<?=$id?>">Activity log</a>
  <?php if($visibleRoles && (int)$s['is_active']):?><a class="btn btn-primary" href="#team-followup" data-focus-form>Update status</a><?php endif;?>
 </div>
</div>
<?php ui_end(); ?>
