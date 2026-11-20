<?php

declare(strict_types=1);
/** Separate team ownership & append-only activity; no mutations to ERP-owned columns. */
require_once __DIR__ . '/WorkflowPipeline.php';
final class TeamWorkflow
{
    public const DOC = 'document_collection';
    public const FORM = 'form_filled';
    public const VER = 'verification';
    public static function roles(): array
    {
        return [self::DOC => 'Documents Team', self::FORM => 'Form Filled Department', self::VER => 'Verification Team'];
    }
    public static function isTeam(string $role): bool
    {
        return array_key_exists($role, self::roles());
    }
    public static function e(mixed $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
    public static function canView(PDO $db, array $student, array $user): bool
    {
        if ($user['role'] === 'admin') return true;
        if (self::isTeam((string)$user['role'])) {
            $q = $db->prepare('SELECT 1 FROM crm_team_assignments WHERE student_id=? AND team_role=? AND assigned_user_id=? LIMIT 1');
            $q->execute([(int)$student['id'], $user['role'], (int)$user['id']]);
            return (bool)$q->fetchColumn();
        }
        return (int)($student['assigned_user_id'] ?? 0) === (int)$user['id'];
    }
    public static function teamUsers(PDO $db, string $role): array
    {
        $q = $db->prepare('SELECT id,name,username FROM crm_users WHERE role=? AND is_active=1 ORDER BY name,id');
        $q->execute([$role]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function assignments(PDO $db, int $sid): array
    {
        $q = $db->prepare('SELECT a.team_role,a.assigned_user_id,u.name FROM crm_team_assignments a LEFT JOIN crm_users u ON u.id=a.assigned_user_id WHERE a.student_id=?');
        $q->execute([$sid]);
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['team_role']] = $r;
        return $out;
    }
    public static function statuses(PDO $db, string $role, bool $activeOnly = true): array
    {
        $q = $db->prepare('SELECT id,status_label,is_active,sort_order FROM crm_team_statuses WHERE team_role=?' . ($activeOnly ? ' AND is_active=1' : '') . ' ORDER BY sort_order,id');
        $q->execute([$role]);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
    public static function requireActiveStudent(array $s): void
    {
        if (!(int)$s['is_active']) throw new RuntimeException('This student is inactive in ERP; existing activity is read-only.');
    }
    public static function assign(PDO $db, int $sid, string $role, ?int $owner, int $by): void
    {
        if (!self::isTeam($role)) throw new RuntimeException('Invalid team.');
        if ($owner !== null) {
            $q = $db->prepare('SELECT 1 FROM crm_users WHERE id=? AND role=? AND is_active=1');
            $q->execute([$owner, $role]);
            if (!$q->fetchColumn()) throw new RuntimeException('Select an active member of the correct team.');
        }
        if ($owner !== null && $role !== self::DOC && ($hold = WorkflowPipeline::documentsHold($db, $sid)) !== null) throw new RuntimeException('Documents status is ' . ucfirst($hold) . '. Change the Documents status before assigning ' . self::roles()[$role] . '.');
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT assigned_user_id FROM crm_team_assignments WHERE student_id=? AND team_role=? FOR UPDATE');
            $q->execute([$sid, $role]);
            $old = $q->fetchColumn();
            $old = $old === false || $old === null ? null : (int)$old;
            if ($old !== $owner) {
                $q = $db->prepare('INSERT INTO crm_team_assignments(student_id,team_role,assigned_user_id,assigned_by,assigned_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE assigned_user_id=VALUES(assigned_user_id),assigned_by=VALUES(assigned_by),assigned_at=NOW()');
                $q->execute([$sid, $role, $owner, $by]);
                $q = $db->prepare('INSERT INTO crm_team_assignment_history(student_id,team_role,previous_user_id,new_user_id,changed_by) VALUES(?,?,?,?,?)');
                $q->execute([$sid, $role, $old, $owner, $by]);
            }
            if ($owner !== null) {
                $q = $db->prepare('INSERT IGNORE INTO crm_workflow_stages(student_id,team_role,entered_at) VALUES(?,?,NOW())');
                $q->execute([$sid, $role]);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
    public static function saveFollowup(PDO $db, int $sid, array $user, array $post): void
    {
        $role = (string)($post['team_role'] ?? '');
        if (!self::isTeam($role)) throw new RuntimeException('Invalid team.');
        if ($user['role'] !== 'admin' && $user['role'] !== $role) throw new RuntimeException('You cannot submit another team’s follow-up.');
        $s = Phase2::student($db, $sid);
        self::requireActiveStudent($s);
        if (!self::canView($db, $s, $user)) throw new RuntimeException('Student is not assigned to your team account.');
        $status = (int)($post['status_id'] ?? 0);
        $q = $db->prepare('SELECT status_label FROM crm_team_statuses WHERE id=? AND team_role=? AND is_active=1');
        $q->execute([$status, $role]);
        $statusLabel = $q->fetchColumn();
        if (!$statusLabel) throw new RuntimeException('Choose an active status for your team.');
        $contact = (string)($post['contact_result'] ?? '');
        if (!in_array($contact, ['Connected', 'Not Connected'], true)) throw new RuntimeException('Select a contact result.');
        $delay = trim((string)($post['delay_reason'] ?? ''));
        $verify = trim((string)($post['remark_for_verification'] ?? ''));
        $notes = trim((string)($post['remarks'] ?? ''));
        foreach ([$delay, $verify, $notes] as $value) if (mb_strlen($value) > 5000) throw new RuntimeException('Each remarks field must be at most 5,000 characters.');
        $due = null;
        $next = trim((string)($post['next_followup_at'] ?? ''));
        if ($next !== '') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $next);
            if (!$d || $d->format('Y-m-d\TH:i') !== $next) throw new RuntimeException('Invalid next follow-up date.');
            $due = $d->format('Y-m-d H:i:s');
        }
        $db->beginTransaction();
        try {
            WorkflowPipeline::lock($db, $sid);
            if (!self::canView($db, $s, $user)) throw new RuntimeException('Your assignment has changed. Reload the student.');
            WorkflowPipeline::requireStage($db, $sid, $role);
            $q = $db->prepare('INSERT INTO crm_team_followups(student_id,team_role,created_by,status_id,contact_result,delay_reason,remark_for_verification,remarks,next_followup_at) VALUES(?,?,?,?,?,?,?,?,?)');
            $q->execute([$sid, $role, (int)$user['id'], $status, $contact, $delay ?: null, $verify ?: null, $notes ?: null, $due]);
            WorkflowPipeline::advance($db, $sid, $role, (string)$statusLabel, (int)$user['id']);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
