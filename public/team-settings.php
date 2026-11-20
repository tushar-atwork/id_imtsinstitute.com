<?php
require_once __DIR__.'/../app/PageSize.php';
require __DIR__.'/../app/bootstrap.php';require_once __DIR__.'/../app/Phase2.php';require_once __DIR__.'/../app/TeamWorkflow.php';require_once __DIR__.'/../app/ui/layout.php';
$me=Phase2::current($crm);if($me['role']!=='admin'){ui_error_page(403,'Administrators only','Team settings are limited to administrators.');}
$roles=TeamWorkflow::roles();$error='';$success='';
if(!empty($_SESSION['employee_password_changed'])){$success='Password changed successfully.';unset($_SESSION['employee_password_changed']);}
$view=(string)($_GET['view']??'overview');
$validViews=['overview','employees','create-user','document-statuses','form-statuses','verification-statuses','email-settings','assignment'];
require_once __DIR__.'/../app/SendGridService.php';require_once __DIR__.'/../app/AssignmentSettings.php';
if(!in_array($view,$validViews,true))$view='overview';

// Diagnose incomplete SQL migration before querying missing tables.
foreach (['crm_team_assignments','crm_team_statuses','crm_team_followups','crm_team_assignment_history'] as $requiredTable) {
 try {$crm->query('SELECT 1 FROM `'.$requiredTable.'` LIMIT 0');}
 catch(PDOException $ex) {ui_error_page(503,'Team settings needs the Phase 11 database import','Import database/PHASE11_IMPORT.sql into the CRM database using phpMyAdmin, then reload this page. Missing or inaccessible table: '.$requiredTable.'.','index.php','Back to admissions');}
}

// Phase 50: employee email used for ERP assignee matching (column added by PHASE50 SQL).
$hasEmail=(bool)$crm->query("SHOW COLUMNS FROM crm_users LIKE 'email'")->fetch();
$cleanEmail=static function(string $value):?string{$value=trim($value);if($value==='')return null;if(strlen($value)>254||!filter_var($value,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Enter a valid email address or leave it blank.');return strtolower($value);};
if($_SERVER['REQUEST_METHOD']==='POST'){
 Phase2::checkCsrf();
 try{
  $action=(string)($_POST['action']??'');
  if($action==='save_sendgrid'){
    $sender=trim((string)($_POST['sender_email']??''));$name=trim((string)($_POST['sender_name']??''));$key=trim((string)($_POST['api_key']??''));
    $bcc=trim((string)($_POST['bcc_email']??''));$replyTo=trim((string)($_POST['reply_to_email']??''));
    foreach(['BCC'=>$bcc,'Reply-To'=>$replyTo] as $label=>$address){if($address!=='' && (strlen($address)>254 || !filter_var($address,FILTER_VALIDATE_EMAIL)))throw new RuntimeException('Enter one valid '.$label.' email address or leave it blank.');}
    if(!filter_var($sender,FILTER_VALIDATE_EMAIL)||strlen($sender)>254)throw new RuntimeException('Enter a valid verified sender email.');
    if($name===''||mb_strlen($name)>120)throw new RuntimeException('Enter a sender name (up to 120 characters).');
    if($key!=='' && (strlen($key)>512||!preg_match('/^SG\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+$/D',$key)))throw new RuntimeException('Enter a valid SendGrid API key.');
    $old=SendGridService::settings($crm);if($key==='' && (!$old||!$old['api_key_cipher']))throw new RuntimeException('Enter the SendGrid API key.');
    $cipher=$key!==''?SendGridService::encrypt($key):$old['api_key_cipher'];
    $q=$crm->prepare('INSERT INTO crm_sendgrid_settings(id,sender_email,sender_name,api_key_cipher,updated_by,bcc_email,reply_to_email) VALUES(1,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sender_email=VALUES(sender_email),sender_name=VALUES(sender_name),api_key_cipher=VALUES(api_key_cipher),updated_by=VALUES(updated_by),bcc_email=VALUES(bcc_email),reply_to_email=VALUES(reply_to_email)');
    $q->execute([$sender,$name,$cipher,(int)$me['id'],$bcc,$replyTo]);
  }elseif($action==='create_user'){
    $username=trim((string)($_POST['username']??''));$name=trim((string)($_POST['name']??''));$password=(string)($_POST['password']??'');$role=(string)($_POST['role']??'');
    if(!preg_match('/^[a-zA-Z0-9_.-]{3,50}$/',$username))throw new RuntimeException('Username must be 3–50 letters, numbers, underscore, dot or hyphen.');
    if($name===''||mb_strlen($name)>150)throw new RuntimeException('Name must be 1–150 characters.');
    if(strlen($password)<12||strlen($password)>128)throw new RuntimeException('Temporary password must contain 12–128 characters.');
    if($role!=='admin' && !TeamWorkflow::isTeam($role))throw new RuntimeException('Select Administrator or a valid department role.');
    $email=$hasEmail?$cleanEmail((string)($_POST['email']??'')):null;
    try{
     if($hasEmail){$q=$crm->prepare('INSERT INTO crm_users(username,password_hash,name,email,role,is_active) VALUES(?,?,?,?,?,1)');$q->execute([$username,password_hash($password,PASSWORD_DEFAULT),$name,$email,$role]);}
     else{$q=$crm->prepare('INSERT INTO crm_users(username,password_hash,name,role,is_active) VALUES(?,?,?,?,1)');$q->execute([$username,password_hash($password,PASSWORD_DEFAULT),$name,$role]);}
    }catch(PDOException $ex){if($ex->getCode()==='23000')throw new RuntimeException('That username or email is already used by another employee.');throw $ex;}
  }elseif($action==='set_email'){
    if(!$hasEmail)throw new RuntimeException('Import database/PHASE50_EMAIL_MAPPING_DOC_HOLD.sql first.');
    $targetId=(int)($_POST['user_id']??0);$email=$cleanEmail((string)($_POST['email']??''));
    $q=$crm->prepare('SELECT id FROM crm_users WHERE id=?');$q->execute([$targetId]);if(!$q->fetchColumn())throw new RuntimeException('Employee not found.');
    try{$q=$crm->prepare('UPDATE crm_users SET email=?,updated_at=NOW() WHERE id=?');$q->execute([$email,$targetId]);}
    catch(PDOException $ex){if($ex->getCode()==='23000')throw new RuntimeException('That email is already used by another employee.');throw $ex;}
  }elseif($action==='add_status'){
    $role=(string)($_POST['team_role']??'');$name=trim((string)($_POST['status_label']??''));
    if(!TeamWorkflow::isTeam($role)||$name===''||mb_strlen($name)>180)throw new RuntimeException('Choose a team and a status (1–180 characters).');
    $order=max(-10000,min(10000,(int)($_POST['sort_order']??100)));
    $q=$crm->prepare('INSERT INTO crm_team_statuses(team_role,status_label,sort_order,is_active) VALUES(?,?,?,1)');$q->execute([$role,$name,$order]);
  }elseif($action==='edit_status'){
    $id=(int)($_POST['status_id']??0);$name=trim((string)($_POST['status_label']??''));$active=isset($_POST['is_active'])?1:0;
    if($id<=0||$name===''||mb_strlen($name)>180)throw new RuntimeException('Enter a valid status.');
    $q=$crm->prepare('UPDATE crm_team_statuses SET status_label=?,sort_order=?,is_active=? WHERE id=?');$q->execute([$name,(int)($_POST['sort_order']??100),$active,$id]);
    if(!$q->rowCount()){$q=$crm->prepare('SELECT 1 FROM crm_team_statuses WHERE id=?');$q->execute([$id]);if(!$q->fetchColumn())throw new RuntimeException('Status not found.');}
  }elseif($action==='change_password'){
    $targetId=(int)($_POST['user_id']??0);$newPassword=(string)($_POST['new_password']??'');$confirmPassword=(string)($_POST['confirm_password']??'');
    if($targetId<=0)throw new RuntimeException('Select a valid employee.');
    if(strlen($newPassword)<12||strlen($newPassword)>72)throw new RuntimeException('New password must contain 12–72 bytes.');
    if(!hash_equals($newPassword,$confirmPassword))throw new RuntimeException('New password and confirmation do not match.');
    $q=$crm->prepare('SELECT id FROM crm_users WHERE id=?');$q->execute([$targetId]);if(!$q->fetchColumn())throw new RuntimeException('Employee not found.');
    $hash=password_hash($newPassword,PASSWORD_DEFAULT);if($hash===false)throw new RuntimeException('Could not secure the password.');
    $q=$crm->prepare('UPDATE crm_users SET password_hash=?,updated_at=NOW() WHERE id=?');$q->execute([$hash,$targetId]);
    $_SESSION['employee_password_changed']=true;
    header('Location: team-settings.php?view=employees&page='.max(1,(int)($_POST['return_page']??1)).'&per_page='.PageSize::fromRequest());exit;
  }elseif($action==='set_active'){
    // Phase 54: activate/deactivate an employee; deactivating a team member needs a policy for their open records.
    $success=AssignmentSettings::setActive($crm,(int)($_POST['user_id']??0),(string)($_POST['active']??'')==='1',(string)($_POST['policy']??''),(int)$me['id']);
  }elseif($action==='set_assignment_mode'){
    $teamRole=(string)($_POST['team_role']??'');AssignmentSettings::setMode($crm,$teamRole,(string)($_POST['mode']??''),(int)$me['id']);
    $success=($roles[$teamRole]??'Team').' assignment mode saved: '.(AssignmentSettings::isManual($crm,$teamRole)?'Manual':'Auto').'.';
  }elseif($action==='set_role'){
    $id=(int)($_POST['user_id']??0);$role=(string)($_POST['role']??'');
    if($id===(int)$me['id'])throw new RuntimeException('You cannot change your own administrator role here.');
    if(!TeamWorkflow::isTeam($role))throw new RuntimeException('Only Documents, Form Filled or Verification roles are allowed here.');
    $q=$crm->prepare('SELECT role FROM crm_users WHERE id=? AND is_active=1');$q->execute([$id]);$existing=$q->fetchColumn();
    if($existing===false)throw new RuntimeException('User not found or inactive.');
    if($existing==='admin')throw new RuntimeException('Cannot change another administrator through this page.');
    $crm->beginTransaction();
    // Remove old team memberships if changing roles so the user cannot retain access to the previous team's records.
    try{$q=$crm->prepare('INSERT INTO crm_team_assignment_history(student_id,team_role,previous_user_id,new_user_id,changed_by) SELECT student_id,team_role,assigned_user_id,NULL,? FROM crm_team_assignments WHERE assigned_user_id=? AND team_role<>?');$q->execute([$me['id'],$id,$role]);$q=$crm->prepare('UPDATE crm_team_assignments SET assigned_user_id=NULL,assigned_by=?,assigned_at=NOW() WHERE assigned_user_id=? AND team_role<>?');$q->execute([$me['id'],$id,$role]);$q=$crm->prepare('UPDATE crm_users SET role=? WHERE id=?');$q->execute([$role,$id]);$crm->commit();}
    catch(Throwable $e){if($crm->inTransaction())$crm->rollBack();throw $e;}
  }else throw new RuntimeException('Invalid action.');
  if($success==='')$success='Saved successfully.';
 }catch(Throwable $e){$error=$e->getMessage();}
}
$statuses=[];
if(in_array($view,['document-statuses','form-statuses','verification-statuses'],true)){
 $selectedRole=['document-statuses'=>TeamWorkflow::DOC,'form-statuses'=>TeamWorkflow::FORM,'verification-statuses'=>TeamWorkflow::VER][$view];
 $statuses[$selectedRole]=TeamWorkflow::statuses($crm,$selectedRole,false);
}

$perPage=PageSize::fromRequest();$totalUsers=0;$page=1;$users=[];
if($view==='employees'){
 $totalUsers=(int)$crm->query('SELECT COUNT(*) FROM crm_users')->fetchColumn();
 $page=max(1,(int)($_GET['page']??1));$page=min($page,max(1,(int)ceil($totalUsers/$perPage)));$offset=($page-1)*$perPage;
 $users=$crm->query("SELECT id,username,name,".($hasEmail?'email':'NULL AS email').",role,is_active FROM crm_users ORDER BY is_active DESC,name LIMIT $perPage OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
}

if($view==='email-settings'){try{$sendgrid=SendGridService::settings($crm);}catch(PDOException $ex){$sendgrid=null;$error='Import PHASE37_SENDGRID_IMPORT.sql using phpMyAdmin before saving email settings.';}}
function hset(mixed $v):string{return TeamWorkflow::e($v);}

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$titles=['overview'=>'Team settings','employees'=>'Employee directory','create-user'=>'Create employee login','document-statuses'=>'Document Collection statuses','form-statuses'=>'Form Filled statuses','verification-statuses'=>'Verification statuses','email-settings'=>'Email settings','assignment'=>'Assignment mode'];
$leads=['overview'=>'Everything about your teams in one place: people, workflow statuses, email and assignment.','employees'=>'Roles, active state, email for ERP matching and passwords.','create-user'=>'Create a department employee or an Administrator login.','document-statuses'=>'Statuses the Documents team can pick for a follow-up.','form-statuses'=>'Statuses the Form Filled team can pick for a follow-up.','verification-statuses'=>'Statuses the Verification team can pick for a follow-up.','email-settings'=>'SendGrid sender used for emails to students.','assignment'=>'Choose how new records reach each team.'];
$tabs=['overview'=>'Overview','employees'=>'Employees','create-user'=>'Create employee','document-statuses'=>'Document statuses','form-statuses'=>'Form Filled statuses','verification-statuses'=>'Verification statuses','email-settings'=>'Email settings','assignment'=>'Assignment mode'];
ui_start([
 'title'=>$titles[$view],'lead'=>$leads[$view],'user'=>$pageUser,'active'=>'team-settings.php',
 'crumbs'=>[['Settings','team-settings.php'],[$tabs[$view],null]],
 'toast'=>$success!==''?['success',$success]:null,
]);
?>
<nav class="tabs" aria-label="Management sections">
 <?php foreach($tabs as $key=>$text):?><a class="tab" <?=$view===$key?'aria-current="page"':''?> href="team-settings.php<?=$key==='overview'?'':'?view='.hset($key)?>"><?=hset($text)?></a><?php endforeach;?>
</nav>
<?php if($error):?><div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><strong>Not saved.</strong> <?=hset($error)?></div></div><?php endif;?>
<?=AssignmentSettings::warningHtml($crm)?>

<?php if($view==='overview'):?>
 <div class="grid grid-3">
  <?php foreach([['employees','Employee directory','Browse employees, change team roles, activate or deactivate people.','users'],['create-user','Create employee','Set up a department employee or Administrator login.','users'],['assignment','Assignment mode','Auto or Manual assignment for each team.','assign'],['document-statuses','Document statuses','Edit and add Document Collection follow-up statuses.','note'],['form-statuses','Form Filled statuses','Manage form preparation statuses.','note'],['verification-statuses','Verification statuses','Manage Verified, Rejected and other verification statuses.','note'],['email-settings','Email settings','Configure the verified sender and SendGrid API key.','mail']] as [$k,$t,$d,$ic]):?>
   <a class="card nav-card" href="team-settings.php?view=<?=hset($k)?>"><div class="card-head" style="margin-bottom:6px"><h2><?=hset($t)?></h2><?=ui_icon($ic,20)?></div><p class="muted" style="margin:0"><?=hset($d)?></p></a>
  <?php endforeach;?>
 </div>
 <p class="note" style="margin-top:var(--s5)"><strong>Danger zone:</strong> clearing all local student data is kept separate, under <a href="crm-reset.php">CRM data reset</a>.</p>

<?php elseif($view==='employees'):?>
 <section class="card">
  <div class="card-head"><div><h2>Employees</h2><p class="help" style="margin:0">Only non-admin accounts can be switched between teams. Deactivated employees never receive new records.</p></div></div>
  <?=PageSize::selector($perPage,$_GET)?>
  <div class="table-wrap"><table class="table"><thead><tr><th>User</th><th>Username</th><th>Email (ERP match)</th><th>Role</th><th>Active</th><th>Change team</th><th>Password</th></tr></thead><tbody>
  <?php foreach($users as $user):?>
   <tr>
    <td><strong><?=hset($user['name'])?></strong></td>
    <td><?=hset($user['username'])?></td>
    <td><?php if($hasEmail):?>
     <form class="inline-form" method="post" action="team-settings.php?view=employees&amp;page=<?=$page?>&amp;per_page=<?=$perPage?>"><input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="set_email"><input type="hidden" name="user_id" value="<?=(int)$user['id']?>"><input class="input" type="email" name="email" maxlength="254" value="<?=hset($user['email']??'')?>" placeholder="name@imtsinstitute.com" aria-label="Email for <?=hset($user['name'])?>"><button class="btn btn-sm" type="submit">Save</button></form>
    <?php else:?><span class="muted small">Import PHASE50 SQL</span><?php endif;?></td>
    <td><?=hset($roles[$user['role']]??$user['role'])?></td>
    <td><?=((int)$user['is_active'])?'<span class="badge b-green">Active</span>':'<span class="badge b-grey">Inactive</span>'?>
     <?php if((int)$user['id']!==(int)$me['id']):?><?php $isTeamUser=TeamWorkflow::isTeam((string)$user['role']);$openCount=($isTeamUser&&(int)$user['is_active'])?count(AssignmentSettings::openRecordIds($crm,(int)$user['id'],(string)$user['role'])):0;?>
     <details class="act-details"><summary class="btn btn-sm"><?=((int)$user['is_active'])?'Deactivate…':'Activate…'?></summary>
      <form method="post" action="team-settings.php?view=employees&amp;page=<?=$page?>&amp;per_page=<?=$perPage?>" data-confirm="<?=((int)$user['is_active'])?'Deactivate':'Activate'?> <?=hset($user['name'])?>?"><input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="set_active"><input type="hidden" name="user_id" value="<?=(int)$user['id']?>"><input type="hidden" name="active" value="<?=((int)$user['is_active'])?'0':'1'?>">
       <?php if((int)$user['is_active']&&$isTeamUser):?><p class="small"><?=number_format($openCount)?> open record(s) are assigned to this employee. Choose what happens to them:</p><label class="check"><input type="radio" name="policy" value="reassign" checked> Reassign to active employees of the same team (round robin)</label><label class="check"><input type="radio" name="policy" value="keep"> Leave them unchanged</label><?php endif;?>
       <button class="btn btn-sm <?=((int)$user['is_active'])?'btn-danger':'btn-primary'?>" type="submit"><?=((int)$user['is_active'])?'Deactivate':'Activate'?></button>
      </form></details>
     <?php endif;?></td>
    <td><?php if($user['role']!=='admin' && (int)$user['is_active']):?>
     <form class="inline-form" method="post" action="team-settings.php?view=employees" data-confirm="Change this user’s team and remove assignments from the other team?"><input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="set_role"><input type="hidden" name="user_id" value="<?=(int)$user['id']?>"><select class="select" name="role" aria-label="Team for <?=hset($user['name'])?>"><?php foreach($roles as $key=>$text):?><option value="<?=hset($key)?>" <?=$user['role']===$key?'selected':''?>><?=hset($text)?></option><?php endforeach;?></select><button class="btn btn-sm" type="submit">Update</button></form>
    <?php else:?><span class="muted">—</span><?php endif;?></td>
    <td><details class="act-details"><summary class="btn btn-sm">Change password…</summary>
     <form method="post" action="team-settings.php?view=employees" autocomplete="off">
      <input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="change_password"><input type="hidden" name="user_id" value="<?=(int)$user['id']?>"><input type="hidden" name="return_page" value="<?=$page?>"><input type="hidden" name="per_page" value="<?=$perPage?>">
      <div class="field"><label for="np-<?=(int)$user['id']?>">New password</label><input class="input" id="np-<?=(int)$user['id']?>" type="password" name="new_password" minlength="12" maxlength="72" required autocomplete="new-password"><span class="help">At least 12 characters.</span></div>
      <div class="field"><label for="cp-<?=(int)$user['id']?>">Confirm password</label><input class="input" id="cp-<?=(int)$user['id']?>" type="password" name="confirm_password" minlength="12" maxlength="72" required autocomplete="new-password"></div>
      <button class="btn btn-primary btn-sm" type="submit" data-loading>Save password</button>
     </form></details></td>
   </tr>
  <?php endforeach;?>
  </tbody></table></div>
  <nav class="pager" aria-label="Pagination"><span><?=number_format($totalUsers)?> employees · Page <?=$page?></span><span style="display:flex;gap:8px"><?php if($page>1):?><a class="btn btn-sm" href="?<?=hset(http_build_query(array_merge($_GET,['page'=>$page-1])))?>">Previous</a><?php endif;?><?php if($page*$perPage<$totalUsers):?><a class="btn btn-sm" href="?<?=hset(http_build_query(array_merge($_GET,['page'=>$page+1])))?>">Next</a><?php endif;?></span></nav>
 </section>

<?php elseif($view==='create-user'):?>
 <section class="card" id="create-user" style="max-width:640px">
  <h2>Create employee or Administrator login</h2>
  <p class="help">Create separate user accounts so all three teams can update the same assigned student independently. Share the temporary password securely and change it after first login.</p>
  <form method="post" action="team-settings.php?view=<?=hset($view)?>">
   <input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="create_user">
   <div class="field"><label for="cu-name">Employee name</label><input class="input" id="cu-name" name="name" maxlength="150" required></div>
   <div class="field"><label for="cu-user">Username</label><input class="input" id="cu-user" name="username" maxlength="50" minlength="3" required autocomplete="off"><span class="help">3–50 letters, numbers, underscore, dot or hyphen.</span></div>
   <?php if($hasEmail):?><div class="field"><label for="cu-email">Email</label><input class="input" id="cu-email" type="email" name="email" maxlength="254" autocomplete="off"><span class="help">Must match the employee’s ERP email for automatic assignment.</span></div><?php endif;?>
   <div class="field"><label for="cu-pass">Temporary password</label><input class="input" id="cu-pass" type="password" name="password" minlength="12" maxlength="128" required autocomplete="new-password"><span class="help">At least 12 characters.</span></div>
   <div class="field"><label for="cu-role">Account role</label><select class="select" id="cu-role" name="role"><?php foreach($roles+['admin'=>'Administrator'] as $key=>$label):?><option value="<?=hset($key)?>"><?=hset($label)?></option><?php endforeach;?></select></div>
   <button class="btn btn-primary" type="submit" data-loading>Create account</button>
  </form>
 </section>

<?php elseif($view==='assignment'):?>
 <section class="card">
  <h2>Assignment mode per team</h2>
  <p class="help"><strong>Auto:</strong> sync assigns new records to the ERP assignee (matched by email), otherwise round robin among active employees of the team. <strong>Manual:</strong> sync and team handoffs leave the team’s records unassigned; assign them from <a href="bulk-assign.php">Assign (single owner)</a> or <a href="team-bulk-assign.php">Assign by team</a>. Inactive employees never receive records in either mode.</p>
  <div class="table-wrap"><table class="table"><thead><tr><th>Team</th><th>Active employees</th><th>Mode</th><th></th></tr></thead><tbody>
  <?php foreach($roles as $key=>$label):?><tr><td><strong><?=hset($label)?></strong></td><td><?=AssignmentSettings::activeCount($crm,$key)?></td>
   <td colspan="2"><form class="inline-form" method="post" action="team-settings.php?view=assignment"><input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="set_assignment_mode"><input type="hidden" name="team_role" value="<?=hset($key)?>"><select class="select" name="mode" aria-label="Mode for <?=hset($label)?>"><option value="auto" <?=AssignmentSettings::isManual($crm,$key)?'':'selected'?>>Auto</option><option value="manual" <?=AssignmentSettings::isManual($crm,$key)?'selected':''?>>Manual</option></select><button class="btn btn-primary btn-sm" type="submit" data-loading>Save</button></form></td></tr>
  <?php endforeach;?>
  </tbody></table></div>
 </section>

<?php elseif($view==='email-settings'):?>
 <section class="card email-form" style="max-width:640px">
  <h2>SendGrid configuration</h2>
  <p class="help">Use a verified sender address. The API key stays on the server and is never shown after saving; leave the key field empty to keep the current one.</p>
  <form method="post" action="team-settings.php?view=email-settings">
   <input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="save_sendgrid">
   <div class="field"><label for="sg-sender">Sender email</label><input class="input" id="sg-sender" type="email" name="sender_email" maxlength="254" required value="<?=hset($sendgrid['sender_email']??'')?>"></div>
   <div class="field"><label for="sg-name">Sender name</label><input class="input" id="sg-name" name="sender_name" maxlength="120" required value="<?=hset($sendgrid['sender_name']??'IMTS Institute')?>"></div>
   <div class="field"><label for="sg-bcc">BCC email (optional)</label><input class="input" id="sg-bcc" type="email" name="bcc_email" maxlength="254" value="<?=hset($sendgrid['bcc_email']??'')?>" placeholder="Copies of outgoing student emails"></div>
   <div class="field"><label for="sg-reply">Reply-To email (optional)</label><input class="input" id="sg-reply" type="email" name="reply_to_email" maxlength="254" value="<?=hset($sendgrid['reply_to_email']??'')?>" placeholder="Address that receives student replies"></div>
   <div class="field"><label for="sg-key">SendGrid API key</label><input class="input" id="sg-key" type="password" name="api_key" maxlength="512" autocomplete="new-password" placeholder="<?=!empty($sendgrid['api_key_cipher'])?'Saved · enter a new key to replace':'SG.…'?>"><?php if(!empty($sendgrid['api_key_cipher'])):?><span class="help"><span class="badge b-green">Key saved</span></span><?php endif;?></div>
   <button class="btn btn-primary" type="submit" data-loading>Save email settings</button>
  </form>
 </section>

<?php else:?>
 <div class="grid grid-2">
 <?php foreach($roles as $role=>$label):?><?php if(!isset($statuses[$role]))continue;?>
  <section class="card" style="grid-column:1/-1">
   <h2><?=hset($label)?> · statuses</h2>
   <p class="help">Disable a status to hide it from new follow-ups without changing previous history.</p>
   <div class="table-wrap"><table class="table"><thead><tr><th>Status</th><th style="width:110px">Order</th><th style="width:110px">Active</th><th style="width:90px"></th></tr></thead><tbody>
   <?php foreach($statuses[$role] as $s):?>
    <tr><td colspan="4" style="padding:6px 12px">
     <form method="post" action="team-settings.php?view=<?=hset($view)?>" class="status-line"><input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="edit_status"><input type="hidden" name="status_id" value="<?=(int)$s['id']?>">
      <input class="input" name="status_label" required maxlength="180" value="<?=hset($s['status_label'])?>" aria-label="Status label"><input class="input" type="number" name="sort_order" value="<?=(int)$s['sort_order']?>" aria-label="Order" style="width:100px"><label class="check"><input type="checkbox" name="is_active" <?=(int)$s['is_active']?'checked':''?>> Active</label><button class="btn btn-sm" type="submit">Save</button>
     </form></td></tr>
   <?php endforeach;?>
   </tbody></table></div>
   <h3 style="margin:var(--s4) 0 var(--s2)">Add status</h3>
   <form method="post" action="team-settings.php?view=<?=hset($view)?>" class="status-line"><input type="hidden" name="csrf" value="<?=hset(Phase2::csrf())?>"><input type="hidden" name="action" value="add_status"><input type="hidden" name="team_role" value="<?=hset($role)?>">
    <input class="input" name="status_label" maxlength="180" required placeholder="Status label" aria-label="New status label"><input class="input" type="number" name="sort_order" value="100" aria-label="Order" style="width:100px"><button class="btn btn-primary btn-sm" type="submit" data-loading>Add status</button>
   </form>
  </section>
 <?php endforeach;?>
 </div>
<?php endif;?>
<?php ui_end(); ?>
