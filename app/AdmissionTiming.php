<?php
/** Phase 20: read-only, batched per-student admission timing. */
final class AdmissionTiming {
 public static function forStudents(PDO $db,array $students):array {
  if(!$students)return [];
  $ids=array_values(array_unique(array_map(static fn($s)=>(int)$s['id'],$students)));
  $place=implode(',',array_fill(0,count($ids),'?'));
  $q=$db->prepare("SELECT f.student_id,f.created_at,f.id,st.status_label FROM crm_team_followups f JOIN crm_team_statuses st ON st.id=f.status_id WHERE f.team_role='document_collection' AND f.student_id IN ($place) ORDER BY f.student_id,f.created_at,f.id");
  $q->execute($ids);$events=[];
  foreach($q->fetchAll(PDO::FETCH_ASSOC) as $e){$sid=(int)$e['student_id'];$label=mb_strtolower(trim((string)$e['status_label']));$complete=(bool)preg_match('/(all\s+(completed|complete)\s+doc|all\s+doc.*complet|documents?\s+complet)/iu',$label);$prior=$events[$sid]??['ever'=>false];$events[$sid]=['complete'=>$complete,'ever'=>$complete||$prior['ever'],'at'=>$complete?$e['created_at']:null];}
  $out=[];foreach($students as $s){$sid=(int)$s['id'];$ad=$s['admission_date']??null;$assigned=$s['counsellor_form_assigned_erp_date']??null;$doc=$events[$sid]['at']??null;$reopened=isset($events[$sid])&&$events[$sid]['ever']&&!$events[$sid]['complete'];$verified=$s['erp_verified_at']??null;
   $out[$sid]=['admission_date'=>$ad,'assigned_date'=>$assigned,'docs_completed_at'=>$doc,'docs_reopened'=>$reopened,'verification_date'=>$verified,
   'send_delay_days'=>self::days($ad,$assigned),'documents_delay_days'=>$reopened?null:self::days($assigned,$doc),'approval_days'=>self::days($ad,$verified),'verification_processing_days'=>$reopened?null:self::days($doc,$verified),'pending_days'=>$verified?null:self::days($ad,date('Y-m-d'))];
  }return $out;
 }
 public static function days(?string $a,?string $b):?int {if(!$a||!$b)return null;try{$x=new DateTimeImmutable($a);$y=new DateTimeImmutable($b);return (int)$x->setTime(0,0)->diff($y->setTime(0,0))->format('%r%a');}catch(Throwable $e){return null;}}
 public static function fmt($n):string{return $n===null?'Pending':($n<0?'Check dates':(string)$n.' days');}
 public static function date($s):string{return $s?htmlspecialchars(date('d M Y',strtotime($s)),ENT_QUOTES,'UTF-8'):'Pending';}
}
