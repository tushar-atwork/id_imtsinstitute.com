<?php
require __DIR__.'/../app/bootstrap.php';
require_once __DIR__.'/../app/Phase2.php';
require_once __DIR__.'/../app/TeamWorkflow.php';
require_once __DIR__.'/../app/SendGridService.php';require_once __DIR__.'/../app/ui/layout.php';
$me=Phase2::current($crm);$id=(int)($_GET['id']??$_POST['id']??0);$student=Phase2::student($crm,$id);
if(!TeamWorkflow::canView($crm,$student,$me)){ui_error_page(403,'This record is not assigned to you','You can only email students assigned to you.');}
$email=trim((string)($student['email']??''));if(!filter_var($email,FILTER_VALIDATE_EMAIL)){ui_error_page(400,'No valid email address','This student has no valid email address on record, so an email cannot be sent.','student.php?id='.$id,'Back to student');}
$e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
$emailVariables=[
 'student_name'=>(string)($student['student_name']??''),
 'admission_no'=>(string)($student['admission_no']??''),
 'university'=>(string)($student['university']??''),
 'admission_date'=>(string)($student['admission_date']??''),
 'mobile'=>(string)($student['phone']??''),
 'email'=>(string)($student['email']??''),
 'counsellor_name'=>(string)($student['counsellor_name']??''),
 'session'=>(string)($student['session_name']??''),
 'admission_status'=>(string)($student['admission_status']??''),
 'confirmation_status'=>(string)($student['admission_confirmation_status']??''),
 'approved_amount'=>'₹'.number_format((float)($student['approved_payment_received']??0),2),
];
function renderCrmEmail(string $template,array $variables):string {
 return preg_replace_callback('/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/',static function($match)use($variables){
  if(!array_key_exists($match[1],$variables))throw new RuntimeException('Unknown CRM variable: '.$match[0]);
  return $variables[$match[1]];
 },$template);
}

$error='';$subject=trim((string)($_POST['subject']??''));$body=trim((string)($_POST['body']??''));
try{$configured=(bool)SendGridService::settings($crm);}catch(PDOException $ex){$configured=false;$error='Email settings are not installed. Ask an administrator to import PHASE37_SENDGRID_IMPORT.sql.';}
if($_SERVER['REQUEST_METHOD']==='POST'){
 Phase2::checkCsrf();
 try{
  if(!$configured)throw new RuntimeException('Email settings are not configured.');
  if(mb_strlen($subject)<1||mb_strlen($subject)>200||mb_strlen($body)<1||mb_strlen($body)>10000)throw new RuntimeException('Enter a subject (up to 200 characters) and message (up to 10,000 characters).');
  $q=$crm->prepare("SELECT COUNT(*) FROM crm_email_send_log WHERE user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE) AND status='accepted'");$q->execute([(int)$me['id']]);
  if((int)$q->fetchColumn()>=10)throw new RuntimeException('Email limit reached. Try again after 10 minutes.');
  $renderedSubject=renderCrmEmail($subject,$emailVariables);
  $renderedBody=renderCrmEmail($body,$emailVariables);
  if(trim($renderedSubject)===''||trim($renderedBody)===''||mb_strlen($renderedSubject)>200)throw new RuntimeException('The subject or message is empty after replacing variables, or the subject exceeds 200 characters.');
  $messageId=SendGridService::send($crm,$email,$renderedSubject,$renderedBody);
  $q=$crm->prepare("INSERT INTO crm_email_send_log(student_id,user_id,recipient_email,subject,status,provider_message_id) VALUES(?,?,?,?,'accepted',?)");$q->execute([$id,(int)$me['id'],$email,$renderedSubject,$messageId]);
  require_once __DIR__.'/../app/RecordAudit.php';RecordAudit::log($crm,$id,(int)$me['id'],'email_sent','communication','Email accepted by SendGrid; message ID '.($messageId??'not supplied'));
  $_SESSION['crm_email_flash']='Email accepted by SendGrid for delivery to '.$email.'.';header('Location: send-email.php?id='.$id,true,303);exit;
 }catch(Throwable $ex){$error=$ex->getMessage();}
}
$flash=$_SESSION['crm_email_flash']??'';unset($_SESSION['crm_email_flash']);

$pageUser=['name'=>$me['name'],'role'=>$me['role']];
$isTeamMember=TeamWorkflow::isTeam((string)$me['role']);
ui_start([
 'title'=>'Email '.(string)$student['student_name'],
 'lead'=>'Sent through SendGrid. Variables are replaced with this student’s details before sending.',
 'user'=>$pageUser,'active'=>$isTeamMember?'my-work.php':'index.php',
 'crumbs'=>[[$isTeamMember?'My work':'Records',$isTeamMember?'my-work.php':'index.php'],[(string)$student['student_name'],'student.php?id='.$id],['Send email',null]],
 'toast'=>$flash!==''?['success',$flash]:null,
 'actions'=>'<a class="btn" href="student.php?id='.$id.'">← Back to student</a>',
]);
?>
<?php if($flash):?><div class="alert alert-success" role="status"><?=ui_icon('shield')?><div><?=$e($flash)?></div></div><?php endif;?>
<?php if($error):?><div class="alert alert-error" role="alert"><?=ui_icon('alert')?><div><strong>Email not sent.</strong> <?=$e($error)?></div></div><?php endif;?>
<?php if(!$configured):?><div class="alert alert-warn" role="status"><?=ui_icon('alert')?><div>Email settings are not configured yet, so sending is turned off. Ask an administrator to complete <strong>Team settings → Email settings</strong>.</div></div><?php endif;?>
<div class="grid" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);align-items:start">
 <section class="card email-form">
  <p style="margin-bottom:var(--s4)">To: <strong><?=$e($email)?></strong></p>
  <form method="post">
   <input type="hidden" name="csrf" value="<?=$e(Phase2::csrf())?>"><input type="hidden" name="id" value="<?=$id?>">
   <div class="field"><label for="crm-email-subject">Subject</label><input class="input" id="crm-email-subject" name="subject" maxlength="200" required value="<?=$e($subject)?>"></div>
   <div class="field"><label for="crm-email-body">Message</label><textarea class="textarea" id="crm-email-body" name="body" rows="10" maxlength="10000" required><?=$e($body)?></textarea><span class="help">Up to 10,000 characters. You can send up to 10 emails every 10 minutes.</span></div>
   <div class="email-variable-box">
    <strong>Insert CRM variable</strong>
    <p class="help">Click a variable after placing the cursor in Subject or Message. Blank student fields insert as empty text.</p>
    <div class="chips"><?php foreach($emailVariables as $key=>$value):?><button class="chip" type="button" data-email-variable="<?=$e($key)?>" title="<?=$e($value!==''?$value:'No value in this student record')?>">{{<?=$e($key)?>}}</button><?php endforeach;?></div>
   </div>
   <script type="application/json" id="crm-email-values"><?=json_encode($emailVariables,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)?></script>
   <div class="filter-actions" style="margin-top:var(--s4)"><button class="btn btn-primary" type="submit" data-loading <?=$configured?'':'disabled'?>><?=ui_icon('mail',16)?> Send via SendGrid</button></div>
  </form>
 </section>
 <aside class="card">
  <h2>Preview for this student</h2>
  <p class="help">Updates as you type.</p>
  <div class="field"><span class="label">Subject</span><p id="crm-preview-subject" class="preview-box"></p></div>
  <div class="field"><span class="label">Message</span><pre id="crm-preview-body" class="preview-box preview-pre"></pre></div>
  <p id="crm-preview-warning" role="status" class="field-error"></p>
 </aside>
</div>
<script src="assets/email-variables.js?v=1" defer></script>
<?php ui_end(); ?>
