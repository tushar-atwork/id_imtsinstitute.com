<?php
declare(strict_types=1);
/**
 * Phase 54: per-team assignment mode (auto/manual) and employee active/inactive handling.
 * Everything here degrades to the old behaviour (Auto, no special handling) when the PHASE54 migration
 * has not been imported yet, and never throws because of a missing table or column.
 */
require_once __DIR__.'/TeamWorkflow.php';
final class AssignmentSettings {
    public const AUTO = 'auto';
    public const MANUAL = 'manual';
    /** @var array<string,string> */
    private static array $modes = [];
    private static ?bool $hasKeep = null;

    /** 'auto' (default) or 'manual' for a team. */
    public static function mode(PDO $db, string $role): string {
        if (isset(self::$modes[$role])) return self::$modes[$role];
        $mode = self::AUTO;
        try {
            $q = $db->prepare('SELECT assignment_mode FROM crm_team_settings WHERE team_role=?');
            $q->execute([$role]);
            if ($q->fetchColumn() === self::MANUAL) $mode = self::MANUAL;
        } catch (Throwable $e) { /* migration not imported: keep Auto */ }
        return self::$modes[$role] = $mode;
    }
    public static function isManual(PDO $db, string $role): bool { return self::mode($db, $role) === self::MANUAL; }
    public static function setMode(PDO $db, string $role, string $mode, int $by): void {
        if (!TeamWorkflow::isTeam($role)) throw new RuntimeException('Invalid team.');
        if (!in_array($mode, [self::AUTO, self::MANUAL], true)) throw new RuntimeException('Invalid assignment mode.');
        try {
            $q = $db->prepare('INSERT INTO crm_team_settings(team_role,assignment_mode,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE assignment_mode=VALUES(assignment_mode),updated_by=VALUES(updated_by)');
            $q->execute([$role, $mode, $by]);
        } catch (PDOException $e) {
            throw new RuntimeException('Import database/PHASE54_SORT_AND_ASSIGNMENT_MODE.sql first.', 0, $e);
        }
        self::$modes[$role] = $mode;
    }
    public static function hasKeepColumn(PDO $db): bool {
        if (self::$hasKeep !== null) return self::$hasKeep;
        try { self::$hasKeep = (bool)$db->query("SHOW COLUMNS FROM crm_users LIKE 'keep_records_when_inactive'")->fetch(); }
        catch (Throwable $e) { self::$hasKeep = false; }
        return self::$hasKeep;
    }
    public static function activeCount(PDO $db, string $role): int {
        $q = $db->prepare('SELECT COUNT(*) FROM crm_users WHERE role=? AND is_active=1');
        $q->execute([$role]);
        return (int)$q->fetchColumn();
    }
    /** Teams that auto-assign but have nobody active to receive records: team_role => label. */
    public static function warnings(PDO $db): array {
        $out = [];
        foreach (TeamWorkflow::roles() as $role => $label) {
            if (!self::isManual($db, $role) && self::activeCount($db, $role) === 0) $out[$role] = $label;
        }
        return $out;
    }
    public static function warningHtml(PDO $db): string {
        $w = self::warnings($db);
        if (!$w) return '';
        return '<div class="alert error alert-error" role="alert"><strong>No active employees:</strong> '
            . htmlspecialchars(implode(', ', $w), ENT_QUOTES, 'UTF-8')
            . '. New records for ' . (count($w) > 1 ? 'these teams are' : 'this team is') . ' left unassigned until an employee is activated (Team settings &gt; Employees) or you assign them manually.</div>';
    }
    /** Round robin among ACTIVE members of a team; advances the shared rotation pointer. Null when nobody is active. */
    public static function nextUser(PDO $db, string $role): ?int {
        $q = $db->prepare('SELECT last_user_id FROM crm_workflow_rotation WHERE team_role=? FOR UPDATE');
        $q->execute([$role]);
        $last = (int)$q->fetchColumn();
        $q = $db->prepare('SELECT id FROM crm_users WHERE role=? AND is_active=1 ORDER BY (id>?) DESC,id LIMIT 1');
        $q->execute([$role, $last]);
        $uid = $q->fetchColumn();
        if (!$uid) return null;
        $q = $db->prepare('INSERT INTO crm_workflow_rotation(team_role,last_user_id) VALUES(?,?) ON DUPLICATE KEY UPDATE last_user_id=VALUES(last_user_id)');
        $q->execute([$role, (int)$uid]);
        return (int)$uid;
    }
    /** Open (not yet completed/forwarded) records of an active student that a team member currently owns. */
    public static function openRecordIds(PDO $db, int $userId, string $role, bool $lock = false): array {
        if (!TeamWorkflow::isTeam($role)) return [];
        $sql = 'SELECT a.student_id FROM crm_team_assignments a JOIN crm_students s ON s.id=a.student_id WHERE a.team_role=? AND a.assigned_user_id=? AND s.is_active=1 AND NOT ' . WorkflowPipeline::teamForwardedSql($role) . ($lock ? ' FOR UPDATE' : '');
        $q = $db->prepare($sql);
        $q->execute([$role, $userId]);
        return array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    }
    /**
     * Admin toggles an employee. Deactivating a team member needs a policy for their open records:
     * 'reassign' = round robin to active members of the same team (history logged),
     * 'keep'     = leave them with the inactive employee (sync will not release them).
     * Returns a human readable result message.
     */
    public static function setActive(PDO $db, int $userId, bool $active, string $policy, int $actorId): string {
        if ($userId === $actorId) throw new RuntimeException('You cannot deactivate your own account.');
        $db->beginTransaction();
        try {
            $q = $db->prepare('SELECT id,name,role,is_active FROM crm_users WHERE id=? FOR UPDATE');
            $q->execute([$userId]);
            $u = $q->fetch(PDO::FETCH_ASSOC);
            if (!$u) throw new RuntimeException('Employee not found.');
            $keepSql = self::hasKeepColumn($db) ? ',keep_records_when_inactive=?' : '';
            if ($active) {
                $q = $db->prepare('UPDATE crm_users SET is_active=1' . $keepSql . ',updated_at=NOW() WHERE id=?');
                $q->execute($keepSql ? [0, $userId] : [$userId]);
                $db->commit();
                return $u['name'] . ' is active again and can receive new records.';
            }
            if (!in_array($policy, ['reassign', 'keep'], true) && TeamWorkflow::isTeam((string)$u['role'])) throw new RuntimeException('Choose what to do with this employee’s open records.');
            // Deactivate first so the round robin below can never pick this employee.
            $q = $db->prepare('UPDATE crm_users SET is_active=0,updated_at=NOW() WHERE id=?');
            $q->execute([$userId]);
            $moved = 0; $left = 0;
            $role = (string)$u['role'];
            if (TeamWorkflow::isTeam($role)) {
                $ids = self::openRecordIds($db, $userId, $role, true);
                if ($policy === 'reassign') {
                    $upd = $db->prepare('UPDATE crm_team_assignments SET assigned_user_id=?,assigned_by=?,assigned_at=NOW() WHERE student_id=? AND team_role=? AND assigned_user_id=?');
                    $his = $db->prepare('INSERT INTO crm_team_assignment_history(student_id,team_role,previous_user_id,new_user_id,changed_by) VALUES(?,?,?,?,?)');
                    foreach ($ids as $sid) {
                        $to = self::nextUser($db, $role);
                        if ($to === null) { $left = count($ids) - $moved; break; }
                        $upd->execute([$to, $actorId, $sid, $role, $userId]);
                        $his->execute([$sid, $role, $userId, $to, $actorId]);
                        $moved++;
                    }
                }
                // Anything not moved stays with this employee; stop the sync from releasing it.
                if ($keepSql) {
                    $q = $db->prepare('UPDATE crm_users SET keep_records_when_inactive=? WHERE id=?');
                    $q->execute([($policy === 'keep' || $left > 0) ? 1 : 0, $userId]);
                }
            }
            $db->commit();
            $msg = $u['name'] . ' is now inactive and will not receive new records.';
            if ($policy === 'reassign' && TeamWorkflow::isTeam($role)) {
                $msg .= " $moved open record(s) reassigned to active members of the same team.";
                if ($left > 0) $msg .= " $left record(s) were left unchanged because the team has no other active employee.";
            } elseif (TeamWorkflow::isTeam($role)) {
                $msg .= ' Their open records were left unchanged.';
            }
            return $msg;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
