<?php
final class RecordAudit {
 public static function log(PDO $db,int $student,int $actor,string $event,string $area,string $detail):void {
  // Never log passwords, full student PII, or raw HTTP request bodies.
  try { $q=$db->prepare('INSERT INTO crm_record_activity(student_id,actor_user_id,event_type,area,description,ip_address,user_agent) VALUES(?,?,?,?,?,?,?)');
   $q->execute([$student,$actor,$event,$area,mb_substr($detail,0,1000),substr((string)($_SERVER['REMOTE_ADDR']??''),0,45),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,255)]);
  }catch(PDOException $e){ error_log('CRM audit logging failed: '.$e->getMessage()); }
 }
}
