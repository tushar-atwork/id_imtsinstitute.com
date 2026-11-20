<?php
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/CrmDataReset.php';require_once __DIR__.'/../app/ui/layout.php';
$me=Phase2::current($crm);if($me['role']!=='admin'){ui_error_page(403,'Administrators only','CRM data reset is limited to administrators.');}
$error='';$success=$_SESSION['crm_reset_success']??'';unset($_SESSION['crm_reset_success']);
if($_SERVER['REQUEST_METHOD']==='POST'){
 Phase2::checkCsrf();try{
  if((string)($_POST['confirmation']??'')!=='RESET CRM DATA')throw new RuntimeException('Type RESET CRM DATA to confirm.');
  $q=$crm->prepare('SELECT password_hash FROM crm_users WHERE id=? AND role=? AND is_active=1');$q->execute([$me['id'],'admin']);$hash=$q->fetchColumn();if(!$hash||!password_verify((string)($_POST['password']??''),$hash))throw new RuntimeException('Administrator password is incorrect.');
  $count=CrmDataReset::clear($crm,$erp,$cfg);$_SESSION['crm_reset_success']=$count.' CRM student records cleared. You can now run ERP sync for fresh Documents assignments.';header('Location: crm-reset.php');exit;
 }catch(Throwable $e){$error=$e instanceof PDOException?'Reset could not complete; the transaction was rolled back. Please contact the developer.':$e->getMessage();}
}
function resetEsc($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
ui_start([
 'title'=>'CRM data reset','lead'=>'Danger zone. Clears every local student record so a fresh ERP sync can rebuild them.',
 'user'=>$pageUser,'active'=>'crm-reset.php',
 'crumbs'=>[['Settings','team-settings.php'],['Danger zone',null]],
 'toast'=>$success!==''?['success',$success]:null,
]);
?>
<?php if($error):?><div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><strong>Nothing was cleared.</strong> <?=resetEsc($error)?></div></div><?php endif;?>
<?php if($success):?><div class="alert alert-success" role="status"><?=ui_icon('shield')?><div><?=resetEsc($success)?></div></div><?php endif;?>

<div class="danger-card">
 <div class="alert alert-error" style="margin-bottom:var(--s4)"><?=ui_icon('alert')?><div><strong>This cannot be undone from the CRM.</strong> Export a database backup first.</div></div>
 <div class="two-col">
  <div>
   <h2>What will be deleted</h2>
   <ul class="plain">
    <li>Every local student record, including approved records</li>
    <li>Assignments and assignment history</li>
    <li>Follow-ups, activity and department timelines</li>
    <li>Email history</li>
   </ul>
   <h2 style="margin-top:var(--s4)">What is kept</h2>
   <ul class="plain">
    <li>Employee accounts and passwords</li>
    <li>Department statuses and SendGrid settings</li>
    <li>Sync logs. ERP data is never changed.</li>
   </ul>
   <p class="help">After reset, run the ERP sync: Documents gets an owner first; Form Filled and Verification get owners at their handoffs.</p>
  </div>
  <form method="post" id="reset-form" data-confirm="This will permanently clear all CRM student data. Are you absolutely sure?">
   <input type="hidden" name="csrf" value="<?=resetEsc(Phase2::csrf())?>">
   <h2>Confirm</h2>
   <label class="check" style="margin:var(--s3) 0"><input class="chk" type="checkbox" id="reset-ack"> I have exported a database backup and understand this is permanent.</label>
   <div class="field"><label for="reset-pass">Your administrator password</label><input class="input" id="reset-pass" type="password" name="password" required autocomplete="current-password"></div>
   <div class="field"><label for="reset-phrase">Type <strong>RESET CRM DATA</strong> to confirm</label><input class="input" id="reset-phrase" name="confirmation" required autocomplete="off" spellcheck="false" placeholder="RESET CRM DATA"><span class="help">Exact capitals. The button stays disabled until it matches.</span></div>
   <button class="btn btn-danger" type="submit" id="reset-go" disabled data-loading>Clear CRM student data</button>
  </form>
 </div>
</div>
<script>
(function(){var a=document.getElementById('reset-ack'),p=document.getElementById('reset-phrase'),b=document.getElementById('reset-go'),w=document.getElementById('reset-pass');
function s(){b.disabled=!(a.checked&&p.value==='RESET CRM DATA'&&w.value!=='');}
[a,p,w].forEach(function(e){e.addEventListener('input',s);e.addEventListener('change',s);});s();})();
</script>
<?php ui_end(); ?>
