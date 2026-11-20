<?php
declare(strict_types=1);
final class CrmDataReset {
 private const TABLES=['crm_email_send_log','crm_workflow_stages','crm_team_followups','crm_team_assignment_history','crm_team_assignments','crm_followups','crm_assignment_history','crm_student_assignments','crm_record_activity','crm_students'];
 public static function clear(PDO $crm,PDO $erp,array $cfg):int {
  $name=(string)$crm->query('SELECT DATABASE()')->fetchColumn();$source=(string)$erp->query('SELECT DATABASE()')->fetchColumn();
  if(!$name||$name!==($cfg['crm_db']['database']??'')||$source!==($cfg['erp_db']['database']??'')||$name===$source)throw new RuntimeException('Reset blocked: CRM and ERP must use distinct verified database names.');
  $q=$crm->prepare("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=?");$q->execute([$name]);$tables=$q->fetchAll(PDO::FETCH_KEY_PAIR);
  foreach(self::TABLES as $table)if(isset($tables[$table]) && strtoupper((string)$tables[$table])!=='INNODB')throw new RuntimeException('Reset requires transactional InnoDB tables.');
  // Refuse delete triggers and unexpected/cross-schema cascades rather than risk external writes.
  $q=$crm->prepare("SELECT EVENT_OBJECT_TABLE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND EVENT_MANIPULATION='DELETE'");$q->execute([$name]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $table)if(in_array($table,self::TABLES,true))throw new RuntimeException('Reset blocked by a DELETE trigger. Ask the developer to review it.');
  $q=$crm->prepare('SELECT TABLE_SCHEMA,TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IN ('.implode(',',array_fill(0,count(self::TABLES),'?')).')');$q->execute(array_merge([$name],self::TABLES));foreach($q->fetchAll(PDO::FETCH_ASSOC) as $ref)if($ref['TABLE_SCHEMA']!==$name||!in_array($ref['TABLE_NAME'],self::TABLES,true))throw new RuntimeException('Reset blocked by an unexpected related table.');
  if((int)$crm->query("SELECT GET_LOCK(CONCAT('crm-reset-sync-',DATABASE()),0)")->fetchColumn()!==1)throw new RuntimeException('ERP sync or another reset is running. Try again when it finishes.');
  try {
   $crm->beginTransaction();$count=(int)$crm->query('SELECT COUNT(*) FROM crm_students')->fetchColumn();
   foreach(self::TABLES as $table)if(isset($tables[$table]))$crm->exec('DELETE FROM `'.str_replace('`','``',$name).'`.`'.$table.'`');
   if(isset($tables['crm_workflow_rotation']))$crm->exec('UPDATE crm_workflow_rotation SET last_user_id=0');
   $crm->commit();return $count;
  }catch(Throwable $e){if($crm->inTransaction())$crm->rollBack();throw $e;}
  finally{$crm->query("SELECT RELEASE_LOCK(CONCAT('crm-reset-sync-',DATABASE()))");}
 }
}
