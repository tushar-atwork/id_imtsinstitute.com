<?php
declare(strict_types=1);
final class Phase2 {
 public static function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
 public static function csrf(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
 public static function checkCsrf(): void { if (!is_string($_POST['csrf']??null) || !hash_equals(self::csrf(),$_POST['csrf'])) { http_response_code(419); exit('Session expired: please reload and try again.'); } }
 public static function current(PDO $db): array { Auth::requireLogin(); $s=$db->prepare('SELECT id,name,role,is_active FROM crm_users WHERE id=? LIMIT 1');$s->execute([(int)Auth::user()['id']]);$u=$s->fetch(); if(!$u || !(int)$u['is_active']) {Auth::logout();header('Location: login.php');exit;}try {$db->exec('SET @crm_actor_id='.(int)$u['id']);}catch(Throwable $ignore){} return $u; }
 public static function student(PDO $db,int $id): array { $q=$db->prepare('SELECT s.*,a.assigned_user_id,u.name AS local_owner FROM crm_students s LEFT JOIN crm_student_assignments a ON a.student_id=s.id LEFT JOIN crm_users u ON u.id=a.assigned_user_id WHERE s.id=?');$q->execute([$id]);$s=$q->fetch();if(!$s){http_response_code(404);exit('Student not found');}return $s; }
 public static function allowed(array $student,array $user):bool {return $user['role']==='admin' || (int)$student['assigned_user_id']===(int)$user['id'];}
 public static function requireAllowed(array $student,array $user):void {if(!self::allowed($student,$user)){http_response_code(403);exit('This student is not assigned to you.');}}
 public static function link(int $id):string {return 'student.php?id='.$id;}
}
