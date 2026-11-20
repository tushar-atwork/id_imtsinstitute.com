<?php
final class ContactActions {
 public static function phone(mixed $value):string {
  $number=preg_replace('/\D+/', '', (string)$value);
  if(strlen($number)===10) $number='91'.$number;
  elseif(strlen($number)===11 && $number[0]==='0') $number='91'.substr($number,1);
  return preg_match('/^[1-9][0-9]{9,14}$/D',$number)?$number:'';
 }
 public static function html(array $student):string {
  $e=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
  $phone=self::phone($student['phone']??'');$email=trim((string)($student['email']??''));$id=(int)$student['id'];$out='';
  if($phone!=='') {
   $out.='<a class="contact-icon" href="https://wa.me/'.$phone.'" target="_blank" rel="noopener noreferrer" title="WhatsApp" aria-label="WhatsApp '.$e($student['student_name']??'student').'">&#x2706; WhatsApp</a>';
   $out.='<a class="contact-icon" href="tel:+'.$phone.'" title="Call" aria-label="Call '.$e($student['student_name']??'student').'">&#x260E; Call</a>';
  }
  if(filter_var($email,FILTER_VALIDATE_EMAIL)) $out.='<a class="contact-icon" href="send-email.php?id='.$id.'" title="Send email through SendGrid" aria-label="Email '.$e($student['student_name']??'student').'">&#x2709; Email</a>';
  return $out;
 }
}
