<?php
declare(strict_types=1);
final class WorkflowPipeline {
 /** Documents statuses that keep a record on hold in Documents. Compared lower-cased and trimmed. */
 /**
  * Single source of truth for the outcomes (lower-cased Documents/Form/Verification status labels) that close a stage.
  * advance() below uses closingOutcomes(); the My Work page uses COMPLETE_OUTCOMES, so they cannot drift apart.
  * COMPLETE = the stage finished successfully; HANDOFF = it closed with open items (docs pending, or Verification rejected).
  */
 public const COMPLETE_OUTCOMES=['document_collection'=>['send with all completed docx'],'form_filled'=>['form filled'],'verification'=>['verified']];
 public const HANDOFF_OUTCOMES=['document_collection'=>['send with docs pending by students','send with docs pending by imts','send with docks pending by imts'],'form_filled'=>[],'verification'=>['rejected']];
 public static function closingOutcomes(string $role):array {return array_merge(self::COMPLETE_OUTCOMES[$role]??[],self::HANDOFF_OUTCOMES[$role]??[]);}
 public const DOC_PENDING=['pending'];
 public const DOC_REJECTED=['rejected','reject'];
 /** 'pending', 'rejected' or null, from the latest Documents follow-up. */
 public static function documentsHold(PDO $db,int $sid):?string {
  $q=$db->prepare("SELECT LOWER(TRIM(st.status_label)) FROM crm_team_followups f JOIN crm_team_statuses st ON st.id=f.status_id WHERE f.student_id=? AND f.team_role='document_collection' ORDER BY f.id DESC LIMIT 1");$q->execute([$sid]);$label=(string)$q->fetchColumn();
  if(in_array($label,self::DOC_PENDING,true))return 'pending';
  if(in_array($label,self::DOC_REJECTED,true))return 'rejected';
  return null;
 }
 /** SQL for the latest Documents status of student alias s (lower-cased). */
 public static function docStatusSql():string {return self::teamStatusSql(TeamWorkflow::DOC);}
 /** SQL condition: Documents finished and handed the record to Form Filled. */
 public static function docForwardedSql():string {return self::teamForwardedSql(TeamWorkflow::DOC);}
 /** SQL condition for a Documents list view: active, pending, rejected or forwarded. */
 public static function docViewSql(string $view):?string {return self::teamViewSql(TeamWorkflow::DOC,$view);}

 /** Phase 52: Pending / Rejected status labels per team (lower-cased, trimmed). */
 public static function pendingLabels(string $role):array {return $role===TeamWorkflow::FORM?['pending','form pending']:self::DOC_PENDING;}
 public static function rejectedLabels(string $role):array {return self::DOC_REJECTED;}
 private static function teamAlias(string $role):string {
  return match($role){TeamWorkflow::DOC=>'d',TeamWorkflow::FORM=>'fm',TeamWorkflow::VER=>'vr',default=>throw new InvalidArgumentException('Unknown team')};
 }
 /** SQL for the latest status of a team on student alias s (lower-cased). */
 public static function teamStatusSql(string $role):string {
  $a=self::teamAlias($role);
  return "(SELECT LOWER(TRIM({$a}st.status_label)) FROM crm_team_followups {$a}tf JOIN crm_team_statuses {$a}st ON {$a}st.id={$a}tf.status_id WHERE {$a}tf.student_id=s.id AND {$a}tf.team_role='$role' ORDER BY {$a}tf.id DESC LIMIT 1)";
 }
 /** SQL condition: the team finished its stage (Documents/Form Filled forwarded, Verification completed). */
 public static function teamForwardedSql(string $role):string {
  $a=self::teamAlias($role);
  return "EXISTS(SELECT 1 FROM crm_workflow_stages {$a}ws WHERE {$a}ws.student_id=s.id AND {$a}ws.team_role='$role' AND {$a}ws.completed_at IS NOT NULL)";
 }
 /** SQL condition for a team list view: active, pending, rejected or forwarded ('all' = null). */
 public static function teamViewSql(string $role,string $view):?string {
  $status=self::teamStatusSql($role);$list=fn(array $v)=>"'".implode("','",$v)."'";$rejected=$list(self::rejectedLabels($role));
  $forwarded=self::teamForwardedSql($role);
  return match($view){
   'active'=>"(NOT $forwarded AND ($status IS NULL OR $status NOT IN ($rejected)))",
   'pending'=>"($status IN (".$list(self::pendingLabels($role)).'))',
   'rejected'=>"($status IN ($rejected))",
   // Verification "Rejected" also closes the stage; keep it only in the Rejected list.
   'forwarded'=>$role===TeamWorkflow::VER?"($forwarded AND ($status IS NULL OR $status NOT IN ($rejected)))":$forwarded,
   default=>null,
  };
 }
 /** Tabs shown to a team employee on the Admissions list. */
 public static function teamViews(string $role):array {
  return match($role){
   TeamWorkflow::DOC=>['active'=>'Active documents','pending'=>'Pending','rejected'=>'Rejected','forwarded'=>'Forwarded (completed)','all'=>'All my records'],
   TeamWorkflow::FORM=>['active'=>'Active forms','pending'=>'Pending','rejected'=>'Rejected','forwarded'=>'Forwarded (form filled)','all'=>'All my records'],
   TeamWorkflow::VER=>['active'=>'Active verification','pending'=>'Pending','rejected'=>'Rejected','forwarded'=>'Verified (completed)','all'=>'All my records'],
   default=>[],
  };
 }
 public static function lock(PDO $db,int $sid):void {
  $q=$db->prepare('SELECT id FROM crm_students WHERE id=? FOR UPDATE');$q->execute([$sid]);
  $q=$db->prepare("INSERT IGNORE INTO crm_workflow_stages(student_id,team_role,entered_at) VALUES(?,'document_collection',NOW())");$q->execute([$sid]);
 }
 public static function owner(PDO $db,int $sid,string $role,?int $by):void {
  // Sync/handoffs never replace an existing responsible person, even if inactive or moved.
  $q=$db->prepare('SELECT assigned_user_id FROM crm_team_assignments WHERE student_id=? AND team_role=?');$q->execute([$sid,$role]);if($q->fetchColumn())return;
  // Pending or Rejected in Documents: never hand the record to a later department.
  if($role!==TeamWorkflow::DOC && self::documentsHold($db,$sid)!==null)return;
  // Phase 54: a team in Manual mode never gets automatic owners (sync or forwarding); admin assigns by hand.
  require_once __DIR__.'/AssignmentSettings.php';
  if(AssignmentSettings::isManual($db,$role))return;
  $q=$db->prepare('SELECT last_user_id FROM crm_workflow_rotation WHERE team_role=? FOR UPDATE');$q->execute([$role]);$last=(int)$q->fetchColumn();
  $q=$db->prepare('SELECT id FROM crm_users WHERE role=? AND is_active=1 ORDER BY (id>?) DESC,id LIMIT 1');$q->execute([$role,$last]);$uid=$q->fetchColumn();if(!$uid)return;
  $q=$db->prepare('SELECT assigned_user_id FROM crm_team_assignments WHERE student_id=? AND team_role=?');$q->execute([$sid,$role]);$old=$q->fetchColumn();
  $q=$db->prepare('INSERT INTO crm_team_assignments(student_id,team_role,assigned_user_id,assigned_by,assigned_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE assigned_user_id=VALUES(assigned_user_id),assigned_by=VALUES(assigned_by),assigned_at=NOW()');$q->execute([$sid,$role,$uid,$by]);
  // History requires a non-null actor; automatic actions use the receiving employee.
  $q=$db->prepare('INSERT INTO crm_team_assignment_history(student_id,team_role,previous_user_id,new_user_id,changed_by) VALUES(?,?,?,?,?)');$q->execute([$sid,$role,$old?:null,$uid,$by??$uid]);
  $q=$db->prepare('UPDATE crm_workflow_rotation SET last_user_id=? WHERE team_role=?');$q->execute([$uid,$role]);
 }
 public static function syncStudent(PDO $db,int $sid):void {
  self::lock($db,$sid);
  // ERP intake assigns Documents only. Later departments are assigned by advance().
  self::owner($db,$sid,TeamWorkflow::DOC,null);

 }
 public static function requireStage(PDO $db,int $sid,string $role):void {
  // Independent assigned teams can begin work before the formal handoff.
  $q=$db->prepare('INSERT IGNORE INTO crm_workflow_stages(student_id,team_role,entered_at) VALUES(?,?,NOW())');$q->execute([$sid,$role]);
 }
 public static function advance(PDO $db,int $sid,string $role,string $label,int $by):void {
  $label=strtolower(trim($label));
  $done=in_array($label,self::closingOutcomes($role),true);
  if(!$done)return;
  // Documents hands on to Form Filled, Form Filled to Verification; Verification is the last stage.
  $next=[TeamWorkflow::DOC=>TeamWorkflow::FORM,TeamWorkflow::FORM=>TeamWorkflow::VER][$role]??null;
  if($next){self::owner($db,$sid,$next,$by);}

  $q=$db->prepare('UPDATE crm_workflow_stages SET completed_at=NOW(),completed_by=?,outcome=? WHERE student_id=? AND team_role=? AND completed_at IS NULL');$q->execute([$by,$label,$sid,$role]);
  if($next){$q=$db->prepare('INSERT IGNORE INTO crm_workflow_stages(student_id,team_role,entered_at) VALUES(?,?,NOW())');$q->execute([$sid,$next]);}
 }
 public static function renderTimeline(PDO $db,array $s):void {
  $q=$db->prepare('SELECT t.*,u.name AS finished_by,a.assigned_at,owner.name AS owner FROM crm_workflow_stages t LEFT JOIN crm_users u ON u.id=t.completed_by LEFT JOIN crm_team_assignments a ON a.student_id=t.student_id AND a.team_role=t.team_role LEFT JOIN crm_users owner ON owner.id=a.assigned_user_id WHERE t.student_id=?');$q->execute([$s['id']]);$rows=[];foreach($q->fetchAll(PDO::FETCH_ASSOC) as $r)$rows[$r['team_role']]=$r;
  $e=fn($v)=>htmlspecialchars((string)($v??'—'),ENT_QUOTES,'UTF-8');$duration=function($start,$end){if(!$start)return '—';$seconds=strtotime($end??date('Y-m-d H:i:s'))-strtotime($start);if($seconds<0)return 'Invalid date order';return floor($seconds/86400).'d '.floor(($seconds%86400)/3600).'h '.floor(($seconds%3600)/60).'m';};
  echo '<section class="panel"><h2>Department timeline</h2><p>Admission: '.$e($s['admission_date']).'. Stage time includes waiting and processing; ongoing time runs until now. Assignment changes are recorded in history.</p>';
  $doc=$rows[TeamWorkflow::DOC]??null;echo '<p>Admission → Documents intake: '.$e($duration($s['admission_date'],$doc['entered_at']??null)).'</p><div class="table-wrap"><table><thead><tr><th>Department</th><th>Assigned employee</th><th>Entered</th><th>Finished</th><th>Finished by</th><th>Outcome</th><th>Elapsed</th></tr></thead><tbody>';
  $longest=null;$max=-1;
  foreach(TeamWorkflow::roles() as $role=>$label){$r=$rows[$role]??[];if(!empty($r['entered_at'])){$seconds=strtotime($r['completed_at']??date('Y-m-d H:i:s'))-strtotime($r['entered_at']);if($seconds>$max){$max=$seconds;$longest=$label;}}
   echo '<tr><td>'.$e($label).'</td><td>'.$e($r['owner']??null).'</td><td>'.$e($r['entered_at']??'Not reached').'</td><td>'.$e($r['completed_at']??(isset($r['entered_at'])?'In progress':'Not reached')).'</td><td>'.$e($r['finished_by']??null).'</td><td>'.$e($r['outcome']??null).'</td><td>'.$e($duration($r['entered_at']??null,$r['completed_at']??null)).'</td></tr>';
  }
  echo '</tbody></table></div>';
  foreach([[TeamWorkflow::DOC,TeamWorkflow::FORM],[TeamWorkflow::FORM,TeamWorkflow::VER]] as [$previous,$next]){
   $finished=$rows[$previous]['completed_at']??null;$started=$rows[$next]['entered_at']??null;
   if($finished && $started){$gap=strtotime($started)-strtotime($finished);echo '<p>'.$e(TeamWorkflow::roles()[$previous]).' → '.$e(TeamWorkflow::roles()[$next]).': '.($gap<0?'Concurrent work began before handoff (overlap '.$e($duration($started,$finished)).')':'Handoff gap '.$e($duration($finished,$started))).'.</p>';}
  }
  echo '<p>Longest elapsed stage: '.$e($longest).'. Total since admission: '.$e($duration($s['admission_date'],$rows[TeamWorkflow::VER]['completed_at']??null)).'.</p></section>';
 }
}
