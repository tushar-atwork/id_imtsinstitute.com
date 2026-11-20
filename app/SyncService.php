<?php
require_once __DIR__.'/TeamWorkflow.php';
require_once __DIR__.'/AssignmentSettings.php';
final class SyncService
{
    /** Lower-cased CRM employee email => ['id'=>CRM user id, 'role'=>team role]. Active team users only. */
    private array $emailTargets = [];
    /** Active CRM user id => role, for the legacy erp_owner_map fallback. */
    private array $activeRoles = [];

    public function __construct(
        private PDO $erp,
        private PDO $crm,
        private array $cfg
    ) {}

    public function run(string $trigger = 'manual'): array
    {
        $lock=$this->crm->query("SELECT GET_LOCK(CONCAT('crm-reset-sync-',DATABASE()),0)")->fetchColumn();
        if((int)$lock!==1)return ['ok'=>false,'error'=>'CRM reset or another sync is running. Try again shortly.'];
        try {
        $started = microtime(true);
        $logId = $this->startLog($trigger);

        $stats = [
            'eligible_read' => 0,
            'inserted' => 0,
            'updated' => 0,
            'deactivated' => 0,
            'errors' => 0,
            'owners_changed' => 0,
        ];

        try {
            $this->loadOwnerTargets();
            $stats['owners_changed'] += self::releaseInactiveOwners($this->crm);
            $eligibleIds = [];
            $lastId = 0;
            $batchSize = max(100, (int)($this->cfg['sync']['batch_size'] ?? 1000));

            do {
                $rows = $this->fetchEligible($lastId, $batchSize);
                if (!$rows) {
                    break;
                }
                $assigneeEmails = $this->erpUserEmails(array_column($rows, 'erp_assignee_id'));

                $this->crm->beginTransaction();
                try {
                    foreach ($rows as $row) {
                        $lastId = max($lastId, (int)$row['erp_student_id']);
                        $eligibleIds[] = (int)$row['erp_student_id'];
                        $stats['eligible_read']++;

                        $exists = $this->exists((int)$row['erp_student_id']);
                        if (!$exists && trim((string)($row['admission_no'] ?? '')) !== '') {
                            $byNo = $this->crm->prepare('SELECT id FROM crm_students WHERE admission_no = ? LIMIT 2');
                            $byNo->execute([trim((string)$row['admission_no'])]);
                            $matches = $byNo->fetchAll(PDO::FETCH_COLUMN);
                            if (count($matches) > 1) {
                                throw new RuntimeException('Duplicate CRM admission number: manual reconciliation required before syncing.');
                            }
                            $exists = count($matches) === 1;
                        }
                        $this->upsert($row);
                        $ownerQ=$this->crm->prepare('SELECT id FROM crm_students WHERE erp_student_id=?');$ownerQ->execute([(int)$row['erp_student_id']]);
                        $crmStudentId=(int)$ownerQ->fetchColumn();
                        WorkflowPipeline::lock($this->crm,$crmStudentId);
                        // When owner mapping is configured, ERP's assignee decides the owner (see mapOwner).
                        // Round robin only fills an empty Documents owner for records with no ERP assignee.
                        $assignee = (int)($row['erp_assignee_id'] ?? 0);
                        if ($assignee > 0 && $this->mappingEnabled()) {
                            $stats['owners_changed'] += $this->mapOwner($crmStudentId, $assignee, $assigneeEmails);
                        } else {
                            WorkflowPipeline::syncStudent($this->crm,$crmStudentId);
                        }
                        $stats[$exists ? 'updated' : 'inserted']++;
                    }
                    $this->crm->commit();
                } catch (Throwable $e) {
                    if ($this->crm->inTransaction()) {
                        $this->crm->rollBack();
                    }
                    throw $e;
                }
            } while (count($rows) === $batchSize);

            $this->crm->exec("UPDATE crm_students s SET s.is_active=1,s.ineligible_at=NULL WHERE s.is_active=0 AND (s.admission_status_id=3 OR LOWER(TRIM(s.admission_status))='approved' OR EXISTS (SELECT 1 FROM crm_workflow_stages ws WHERE ws.student_id=s.id AND ws.team_role='verification' AND LOWER(TRIM(ws.outcome)) IN ('verified','approved')))");

            // Deactivate records that no longer meet Phase 1 eligibility.
            // Uses a temporary table to avoid a massive NOT IN list.
            $this->crm->exec("DROP TEMPORARY TABLE IF EXISTS tmp_eligible_erp_students");
            $this->crm->exec(
                "CREATE TEMPORARY TABLE tmp_eligible_erp_students (
                    erp_student_id BIGINT UNSIGNED PRIMARY KEY
                ) ENGINE=MEMORY"
            );

            if ($eligibleIds) {
                $ins = $this->crm->prepare(
                    "INSERT IGNORE INTO tmp_eligible_erp_students (erp_student_id) VALUES (?)"
                );
                foreach ($eligibleIds as $id) {
                    $ins->execute([$id]);
                }
            }

            $stmt = $this->crm->prepare(
                "UPDATE crm_students s
                 LEFT JOIN tmp_eligible_erp_students t
                   ON t.erp_student_id = s.erp_student_id
                 SET s.is_active = 0,
                     s.ineligible_at = NOW(),
                     s.updated_at = NOW()
                 WHERE s.is_active = 1
                   AND t.erp_student_id IS NULL
                   AND COALESCE(s.admission_status_id,0)<>3
                   AND LOWER(TRIM(COALESCE(s.admission_status,'')))<>'approved'
                   AND NOT EXISTS (SELECT 1 FROM crm_workflow_stages ws WHERE ws.student_id=s.id AND ws.team_role='verification' AND LOWER(TRIM(ws.outcome)) IN ('verified','approved'))"
            );
            $stmt->execute();
            $stats['deactivated'] = $stmt->rowCount();

            $stats['owners_changed'] += $this->reconcileActiveWithErp();

            // Phase 20: capture the first ERP Approved follow-up timestamp, including
            // students no longer eligible for the active CRM list. Never overwrite it.
            $this->captureErpApprovalDates();

            $duration = round(microtime(true) - $started, 3);
            $this->finishLog($logId, 'success', $stats, null, $duration);
            return ['ok' => true, 'log_id' => $logId, 'duration' => $duration] + $stats;
        } catch (Throwable $e) {
            $stats['errors']++;
            $duration = round(microtime(true) - $started, 3);
            $this->finishLog($logId, 'failed', $stats, $e->getMessage(), $duration);
            return ['ok' => false, 'log_id' => $logId, 'duration' => $duration, 'error' => $e->getMessage()] + $stats;
        }
        }finally{$this->crm->query("SELECT RELEASE_LOCK(CONCAT('crm-reset-sync-',DATABASE()))");}
    }

    /** Optional legacy fallback: ERP user id => CRM user id, used only when no email matches. */
    private function erpOwnerMap(): array
    {
        return array_map('intval', $this->cfg['sync']['erp_owner_map'] ?? []);
    }

    /** Loads active CRM team employees by email. Missing email column (migration not run) means no matches. */
    private function loadOwnerTargets(): void
    {
        $this->activeRoles = $this->crm->query('SELECT id,role FROM crm_users WHERE is_active=1')->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->emailTargets = [];
        try {
            // Phase 54: inactive employees are loaded too (flagged), so an ERP assignee who is inactive in the CRM
            // is handed to an active member of the same team instead of the record being released.
            $rows = $this->crm->query("SELECT id,role,is_active,LOWER(TRIM(email)) AS email FROM crm_users WHERE email IS NOT NULL AND TRIM(email)<>'' AND role IN ('document_collection','form_filled','verification')")->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('CRM sync: crm_users.email unavailable; import PHASE50 SQL to enable email mapping.');
            return;
        }
        foreach ($rows as $r) {
            $this->emailTargets[(string)$r['email']] = ['id' => (int)$r['id'], 'role' => (string)$r['role'], 'active' => (int)$r['is_active'] === 1];
        }
    }

    private function erpEmailColumn(): string
    {
        $col = (string)($this->cfg['sync']['erp_user_email_column'] ?? 'email');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $col)) {
            throw new RuntimeException('Invalid sync.erp_user_email_column in config/local.php.');
        }
        return $col;
    }

    /** ERP user id => lower-cased email for the given ERP user ids (read-only ERP query). */
    private function erpUserEmails(array $erpUserIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $erpUserIds), static fn(int $v): bool => $v > 0)));
        if (!$ids || !$this->emailTargets) {
            return [];
        }
        $col = $this->erpEmailColumn();
        $in = implode(',', array_fill(0, count($ids), '?'));
        try {
            $q = $this->erp->prepare("SELECT id,LOWER(TRIM(`$col`)) FROM imts_erp_user WHERE id IN ($in)");
            $q->execute($ids);
        } catch (PDOException $e) {
            throw new RuntimeException("Could not read ERP user email column `$col` in imts_erp_user. Set sync.erp_user_email_column in config/local.php.", 0, $e);
        }
        $out = [];
        foreach ($q->fetchAll(PDO::FETCH_NUM) as [$id, $email]) {
            $out[(int)$id] = (string)$email;
        }
        return $out;
    }

    /** The CRM team employee responsible for an ERP assignee: email match first, then the legacy id map. */
    private function resolveTarget(int $erpUserId, array $erpEmails): ?array
    {
        if ($erpUserId <= 0) {
            return null;
        }
        $email = $erpEmails[$erpUserId] ?? '';
        if ($email !== '' && isset($this->emailTargets[$email])) {
            return $this->emailTargets[$email];
        }
        $legacy = $this->erpOwnerMap()[$erpUserId] ?? null;
        $role = $legacy !== null ? ($this->activeRoles[$legacy] ?? null) : null;
        if ($role !== null && TeamWorkflow::isTeam((string)$role)) {
            return ['id' => $legacy, 'role' => (string)$role];
        }
        return null;
    }

    /**
     * Makes every active CRM student match ERP's latest follow row: the status always,
     * and the owner whenever the ERP assignee matches a CRM employee (no round robin here).
     * Covers students outside the eligible list too, such as those now Approved in ERP.
     * Returns the number of owner assignments changed.
     */
    private function reconcileActiveWithErp(): int
    {
        $mapping = $this->mappingEnabled();
        $changed = 0;
        $last = 0;
        do {
            $q = $this->crm->prepare('SELECT id,erp_student_id,admission_status_id FROM crm_students WHERE is_active=1 AND id>? ORDER BY id LIMIT 300');
            $q->execute([$last]);
            $batch = $q->fetchAll(PDO::FETCH_ASSOC);
            if (!$batch) {
                break;
            }
            $last = (int)end($batch)['id'];
            $ids = array_map('intval', array_column($batch, 'erp_student_id'));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $erpQ = $this->erp->prepare(
                "SELECT sf.student_id,sf.follow_status,sf.assigned_to_user_id
                 FROM imts_erp_student_assigned_follow_pdc sf
                 INNER JOIN (
                     SELECT MAX(id) id FROM imts_erp_student_assigned_follow_pdc
                     WHERE student_id IN ($in) GROUP BY student_id
                 ) latest ON latest.id = sf.id"
            );
            $erpQ->execute($ids);
            $latest = [];
            foreach ($erpQ->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $latest[(int)$row['student_id']] = $row;
            }
            $assigneeEmails = $mapping ? $this->erpUserEmails(array_column($latest, 'assigned_to_user_id')) : [];

            $this->crm->beginTransaction();
            try {
                foreach ($batch as $student) {
                    $row = $latest[(int)$student['erp_student_id']] ?? null;
                    if (!$row) {
                        continue;
                    }
                    $status = (int)$row['follow_status'];
                    if ($status !== (int)$student['admission_status_id']) {
                        $label = [1 => 'Incomplete', 2 => 'Pending', 3 => 'Approved', 4 => 'Rejected'][$status] ?? "Status $status";
                        $upd = $this->crm->prepare('UPDATE crm_students SET admission_status_id=?,admission_status=?,updated_at=NOW() WHERE id=?');
                        $upd->execute([$status, $label, (int)$student['id']]);
                    }
                    if ($mapping && (int)$row['assigned_to_user_id'] > 0) {
                        $changed += $this->mapOwner((int)$student['id'], (int)$row['assigned_to_user_id'], $assigneeEmails);
                    }
                }
                $this->crm->commit();
            } catch (Throwable $e) {
                if ($this->crm->inTransaction()) {
                    $this->crm->rollBack();
                }
                throw $e;
            }
        } while (count($batch) === 300);
        return $changed;
    }

    /**
     * Phase 53: no record may stay with an inactive employee, or with an employee who is no longer
     * in that team. Their assignments are cleared (history kept); the rest of the sync then gives the
     * record to ERP's matching employee, or to round robin among ACTIVE employees only.
     * Pass $userId to release only that employee (used when an admin deactivates someone).
     * Returns the number of assignments cleared.
     */
    public static function releaseInactiveOwners(PDO $crm, ?int $userId = null, ?int $actorId = null): int
    {
        // Phase 54: employees an admin deactivated with "leave records unchanged" keep their records.
        $inactive = AssignmentSettings::hasKeepColumn($crm) ? '(u.is_active<>1 AND u.keep_records_when_inactive=0)' : 'u.is_active<>1';
        $where = "a.assigned_user_id IS NOT NULL AND ($inactive OR u.role<>a.team_role)";
        $args = [];
        if ($userId !== null) { $where .= ' AND a.assigned_user_id=?'; $args[] = $userId; }
        $own = !$crm->inTransaction();
        if ($own) $crm->beginTransaction();
        try {
            $q = $crm->prepare("SELECT a.student_id,a.team_role,a.assigned_user_id FROM crm_team_assignments a JOIN crm_users u ON u.id=a.assigned_user_id WHERE $where FOR UPDATE");
            $q->execute($args);
            $rows = $q->fetchAll(PDO::FETCH_ASSOC);
            $upd = $crm->prepare('UPDATE crm_team_assignments SET assigned_user_id=NULL,assigned_by=NULL,assigned_at=NOW() WHERE student_id=? AND team_role=? AND assigned_user_id=?');
            $his = $crm->prepare('INSERT INTO crm_team_assignment_history(student_id,team_role,previous_user_id,new_user_id,changed_by) VALUES(?,?,?,NULL,?)');
            foreach ($rows as $r) {
                $upd->execute([(int)$r['student_id'], $r['team_role'], (int)$r['assigned_user_id']]);
                // History requires a non-null actor; an automatic release is recorded against the previous owner.
                $his->execute([(int)$r['student_id'], $r['team_role'], (int)$r['assigned_user_id'], $actorId ?? (int)$r['assigned_user_id']]);
            }
            if ($own) $crm->commit();
            return count($rows);
        } catch (Throwable $e) {
            if ($own && $crm->inTransaction()) $crm->rollBack();
            throw $e;
        }
    }

    /** True when ERP assignees can be mapped to CRM employees (any employee email or the legacy id map). */
    private function mappingEnabled(): bool
    {
        return $this->emailTargets || $this->erpOwnerMap();
    }

    /**
     * Keeps the CRM Documents owner equal to ERP's assignee, so per-employee counts match ERP:
     * - assignee is a CRM Documents employee: that employee owns the record in Documents;
     * - assignee is a Form Filled / Verification employee: owner set in that department, and the
     *   open Documents assignment is released (ERP no longer has it with a Documents employee);
     * - assignee is not a CRM employee: the open Documents assignment is released (Unassigned).
     * Releasing skips only owners an admin assigned by hand (forwarded records are released too).
     */
    private function mapOwner(int $studentId, int $erpUserId, array $erpEmails): int
    {
        $changed = 0;
        $target = $this->resolveTarget($erpUserId, $erpEmails);
        if ($target) {
            $changed += $this->applyOwner($studentId, $target);
            if ($target['role'] === TeamWorkflow::DOC) {
                return $changed;
            }
        }
        return $changed + $this->releaseDocOwner($studentId);
    }

    /** Gives the record to the next ACTIVE member of the team unless it already has one. Returns 1 when changed. */
    private function fallbackOwner(int $studentId, string $role): int
    {
        $q = $this->crm->prepare('SELECT a.assigned_user_id FROM crm_team_assignments a JOIN crm_users u ON u.id=a.assigned_user_id WHERE a.student_id=? AND a.team_role=? AND u.is_active=1 AND u.role=a.team_role');
        $q->execute([$studentId, $role]);
        if ($q->fetchColumn()) {
            return 0;
        }
        $q = $this->crm->prepare('SELECT assigned_user_id FROM crm_team_assignments WHERE student_id=? AND team_role=? FOR UPDATE');
        $q->execute([$studentId, $role]);
        $current = $q->fetchColumn();
        $uid = AssignmentSettings::nextUser($this->crm, $role);
        if ($uid === null) {
            return 0; // nobody active in this team: leave unassigned (admin sees a warning)
        }
        $q = $this->crm->prepare('INSERT INTO crm_team_assignments(student_id,team_role,assigned_user_id,assigned_by,assigned_at) VALUES(?,?,?,NULL,NOW()) ON DUPLICATE KEY UPDATE assigned_user_id=VALUES(assigned_user_id),assigned_by=NULL,assigned_at=NOW()');
        $q->execute([$studentId, $role, $uid]);
        $q = $this->crm->prepare('INSERT INTO crm_team_assignment_history(student_id,team_role,previous_user_id,new_user_id,changed_by) VALUES(?,?,?,?,?)');
        $q->execute([$studentId, $role, $current ?: null, $uid, $uid]);
        WorkflowPipeline::requireStage($this->crm, $studentId, $role);
        return 1;
    }

    /** Clears an automatic Documents assignment. Returns 1 when an owner was removed. */
    private function releaseDocOwner(int $studentId): int
    {
        // Phase 54: in Manual mode the sync leaves Documents ownership alone.
        if (AssignmentSettings::isManual($this->crm, TeamWorkflow::DOC)) {
            return 0;
        }
        $q = $this->crm->prepare('SELECT assigned_user_id,assigned_by FROM crm_team_assignments WHERE student_id=? AND team_role=? FOR UPDATE');
        $q->execute([$studentId, TeamWorkflow::DOC]);
        $a = $q->fetch(PDO::FETCH_ASSOC);
        if (!$a || $a['assigned_user_id'] === null || $a['assigned_by'] !== null) {
            return 0;
        }
        // Forwarded records are released too: the owner must match ERP. The Documents stage keeps
        // completed_by/outcome, so the student's timeline still shows who forwarded it.
        $previous = (int)$a['assigned_user_id'];
        $q = $this->crm->prepare('UPDATE crm_team_assignments SET assigned_user_id=NULL,assigned_by=NULL,assigned_at=NOW() WHERE student_id=? AND team_role=?');
        $q->execute([$studentId, TeamWorkflow::DOC]);
        // History requires a non-null actor; an automatic release is recorded against the previous owner.
        $q = $this->crm->prepare('INSERT INTO crm_team_assignment_history(student_id,team_role,previous_user_id,new_user_id,changed_by) VALUES(?,?,?,NULL,?)');
        $q->execute([$studentId, TeamWorkflow::DOC, $previous, $previous]);
        return 1;
    }

    /**
     * Assigns the student to the matched CRM employee in that employee's own department.
     * Other departments' owners are left untouched. Form Filled / Verification are never
     * assigned while the Documents status is Pending or Rejected.
     */
    private function applyOwner(int $studentId, array $target): int
    {
        $role = (string)$target['role'];
        $want = (int)$target['id'];
        if ($role !== TeamWorkflow::DOC && WorkflowPipeline::documentsHold($this->crm, $studentId) !== null) {
            return 0;
        }
        // Phase 54: Manual teams get no automatic owner from the sync.
        if (AssignmentSettings::isManual($this->crm, $role)) {
            return 0;
        }
        // Phase 54: ERP assignee is inactive in the CRM -> an active member of the same team (round robin),
        // keeping any active owner the record already has.
        if (($target['active'] ?? true) === false) {
            return $this->fallbackOwner($studentId, $role);
        }
        $q = $this->crm->prepare('SELECT assigned_user_id FROM crm_team_assignments WHERE student_id=? AND team_role=? FOR UPDATE');
        $q->execute([$studentId, $role]);
        $current = $q->fetchColumn();
        $current = $current === false || $current === null ? null : (int)$current;
        if ($current === $want) {
            return 0;
        }
        $q = $this->crm->prepare('INSERT INTO crm_team_assignments(student_id,team_role,assigned_user_id,assigned_by,assigned_at) VALUES(?,?,?,NULL,NOW()) ON DUPLICATE KEY UPDATE assigned_user_id=VALUES(assigned_user_id),assigned_by=NULL,assigned_at=NOW()');
        $q->execute([$studentId, $role, $want]);
        // History requires a non-null actor; automatic changes use the receiving employee.
        $q = $this->crm->prepare('INSERT INTO crm_team_assignment_history(student_id,team_role,previous_user_id,new_user_id,changed_by) VALUES(?,?,?,?,?)');
        $q->execute([$studentId, $role, $current, $want, $want]);
        WorkflowPipeline::requireStage($this->crm, $studentId, $role);
        return 1;
    }

    private function captureErpApprovalDates(): void
    {
        // Uses the ERP's historical follow-up timestamp, not the date of this sync.
        // Chunk by CRM student ID to keep memory and query sizes bounded.
        $last=0;
        do {
            $q=$this->crm->prepare('SELECT id,erp_student_id FROM crm_students WHERE id>? AND erp_verified_at IS NULL ORDER BY id LIMIT 300');
            $q->execute([$last]);$batch=$q->fetchAll(PDO::FETCH_ASSOC);
            if(!$batch)break;
            $last=(int)end($batch)['id'];
            $ids=array_map('intval',array_column($batch,'erp_student_id'));
            $in=implode(',',array_fill(0,count($ids),'?'));
            $erpQ=$this->erp->prepare("SELECT student_id,MIN(follow_created_at) verified_at FROM imts_erp_student_assigned_follow_pdc WHERE follow_status=3 AND student_id IN ($in) AND follow_created_at IS NOT NULL GROUP BY student_id");
            $erpQ->execute($ids);$dates=$erpQ->fetchAll(PDO::FETCH_KEY_PAIR);
            $upd=$this->crm->prepare('UPDATE crm_students SET erp_verified_at=? WHERE id=? AND erp_verified_at IS NULL');
            foreach($batch as $s){$stamp=$dates[(int)$s['erp_student_id']]??null;if($stamp)$upd->execute([$stamp,(int)$s['id']]);}
        }while(count($batch)===300);
    }

    private function fetchEligible(int $lastId, int $limit): array
    {
        $statuses = array_map('intval', $this->cfg['sync']['eligible_follow_statuses'] ?? [1,2,4]);
        $statusSql = implode(',', $statuses);
        $paymentStatus = (int)($this->cfg['sync']['approved_payment_status'] ?? 2);
        $minimum = (float)($this->cfg['sync']['minimum_approved_payment'] ?? 4800);

        // Counsellor is matched exactly like the supplied ERP admission listing:
        // IFNULL(lead.assigned_to_user_id, student.created_by).
        //
        // Counsellor Form Assigned on ERP Date:
        // we retain student.created_at as the admission/form creation timestamp.
        // The latest admission assignment timestamp is also stored separately.
        $sql = "
            SELECT
                s.id AS erp_student_id,
                s.uid AS erp_student_uid,
                s.admission_no,
                s.name,
                s.admission_date,
                s.created_at AS counsellor_form_assigned_erp_date,
                s.phone,
                s.email,

                sf.follow_status AS admission_status_id,
                sf.assigned_to_user_id AS erp_assignee_id,
                CASE sf.follow_status
                    WHEN 1 THEN 'Incomplete'
                    WHEN 2 THEN 'Pending'
                    WHEN 3 THEN 'Approved'
                    WHEN 4 THEN 'Rejected'
                    ELSE CONCAT('Status ', COALESCE(sf.follow_status, 0))
                END AS admission_status,

                acs.name AS admission_confirmation_status,
                uv.name AS university,
                ses.name AS session_name,

                cn.id AS counsellor_erp_user_id,
                cn.full_name AS counsellor_name,

                (
                    SELECT MAX(sa.assigned_at)
                    FROM imts_erp_student_assigned sa
                    WHERE sa.student_id = s.id
                ) AS latest_erp_assignment_date,

                pay.approved_payment_received,

                GREATEST(
                    COALESCE(UNIX_TIMESTAMP(s.updated_at), 0),
                    COALESCE(UNIX_TIMESTAMP(s.created_at), 0),
                    COALESCE(UNIX_TIMESTAMP(sf.follow_created_at), 0),
                    COALESCE(UNIX_TIMESTAMP(sf.assigned_at), 0),
                    COALESCE(UNIX_TIMESTAMP(pay.last_payment_update), 0)
                ) AS erp_change_stamp

            FROM imts_erp_student s

            LEFT JOIN imts_erp_student_assigned_follow_pdc sf
                ON sf.id = (
                    SELECT MAX(sf2.id)
                    FROM imts_erp_student_assigned_follow_pdc sf2
                    WHERE sf2.student_id = s.id
                )

            LEFT JOIN imts_erp_admission_confirmation_status acs
                ON acs.id = s.admission_confirmation_status_id
               AND acs.deleted_at IS NULL

            LEFT JOIN imts_erp_university uv
                ON uv.id = s.university_id
               AND uv.deleted_at IS NULL

            LEFT JOIN imts_erp_session ses
                ON ses.id = s.session_id
               AND ses.deleted_at IS NULL

            LEFT JOIN imts_erp_lead l
                ON l.id = s.lead_id

            LEFT JOIN imts_erp_user cn
                ON cn.id = IFNULL(l.assigned_to_user_id, s.created_by)

            INNER JOIN (
                SELECT
                    sp.student_id,
                    SUM(sp.pay) AS approved_payment_received,
                    MAX(COALESCE(sp.approved_at, sp.updated_at, sp.created_at)) AS last_payment_update
                FROM imts_erp_student_payment sp
                WHERE sp.status = {$paymentStatus}
                  AND sp.deleted_at IS NULL
                GROUP BY sp.student_id
                HAVING SUM(sp.pay) >= {$minimum}
            ) pay
                ON pay.student_id = s.id

            WHERE s.deleted_at IS NULL
              AND s.id > :last_id
              AND sf.follow_status IN ({$statusSql})

            ORDER BY s.id ASC
            LIMIT {$limit}
        ";

        $stmt = $this->erp->prepare($sql);
        $stmt->execute(['last_id' => $lastId]);
        return $stmt->fetchAll();
    }

    private function exists(int $erpStudentId): bool
    {
        $stmt = $this->crm->prepare("SELECT 1 FROM crm_students WHERE erp_student_id = ? LIMIT 1");
        $stmt->execute([$erpStudentId]);
        return (bool)$stmt->fetchColumn();
    }

    private function upsert(array $r): void
    {
        // Reconcile a changed ERP row identifier by admission number WITHOUT changing crm_students.id.
        // Both teams' assignments, follow-ups, and audit history reference that stable CRM ID.
        $admissionNo = trim((string)($r['admission_no'] ?? ''));
        if ($admissionNo !== '') {
            $sameErp = $this->crm->prepare('SELECT id FROM crm_students WHERE erp_student_id = ? LIMIT 1');
            $sameErp->execute([(int)$r['erp_student_id']]);
            if (!$sameErp->fetchColumn()) {
                $sameAdmission = $this->crm->prepare('SELECT id,erp_student_id FROM crm_students WHERE admission_no = ? LIMIT 2 FOR UPDATE');
                $sameAdmission->execute([$admissionNo]);
                $matches = $sameAdmission->fetchAll(PDO::FETCH_ASSOC);
                if (count($matches) > 1) {
                    throw new RuntimeException('Duplicate admission number in CRM; manual reconciliation required before sync.');
                }
                if (count($matches) === 1 && (int)$matches[0]['erp_student_id'] !== (int)$r['erp_student_id']) {
                    $rekey = $this->crm->prepare('UPDATE crm_students SET erp_student_id=? WHERE id=?');
                    $rekey->execute([(int)$r['erp_student_id'], (int)$matches[0]['id']]);
                }
            }
        }
        // Only ERP mirror columns are upserted below. Do not update crm_students.id or
        // touch crm_student_assignments, crm_team_assignments, crm_followups,
        // crm_team_followups or either assignment history table.
        $sql = "
            INSERT INTO crm_students (
                erp_student_id,
                erp_student_uid,
                admission_no,
                student_name,
                admission_date,
                counsellor_name,
                counsellor_erp_user_id,
                counsellor_form_assigned_erp_date,
                latest_erp_assignment_date,
                admission_status_id,
                admission_status,
                admission_confirmation_status,
                university,
                session_name,
                phone,
                email,
                approved_payment_received,
                erp_change_stamp,
                is_active,
                first_synced_at,
                last_synced_at,
                created_at,
                updated_at
            ) VALUES (
                :erp_student_id,
                :erp_student_uid,
                :admission_no,
                :student_name,
                :admission_date,
                :counsellor_name,
                :counsellor_erp_user_id,
                :counsellor_form_assigned_erp_date,
                :latest_erp_assignment_date,
                :admission_status_id,
                :admission_status,
                :admission_confirmation_status,
                :university,
                :session_name,
                :phone,
                :email,
                :approved_payment_received,
                :erp_change_stamp,
                1,
                NOW(),
                NOW(),
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                erp_student_uid = VALUES(erp_student_uid),
                admission_no = VALUES(admission_no),
                student_name = VALUES(student_name),
                admission_date = VALUES(admission_date),
                counsellor_name = VALUES(counsellor_name),
                counsellor_erp_user_id = VALUES(counsellor_erp_user_id),
                counsellor_form_assigned_erp_date = VALUES(counsellor_form_assigned_erp_date),
                latest_erp_assignment_date = VALUES(latest_erp_assignment_date),
                admission_status_id = VALUES(admission_status_id),
                admission_status = VALUES(admission_status),
                admission_confirmation_status = VALUES(admission_confirmation_status),
                university = VALUES(university),
                session_name = VALUES(session_name),
                phone = VALUES(phone),
                email = VALUES(email),
                approved_payment_received = VALUES(approved_payment_received),
                erp_change_stamp = VALUES(erp_change_stamp),
                is_active = 1,
                ineligible_at = NULL,
                last_synced_at = NOW(),
                updated_at = NOW()
        ";

        $stmt = $this->crm->prepare($sql);
        $stmt->execute([
            'erp_student_id' => $r['erp_student_id'],
            'erp_student_uid' => $r['erp_student_uid'],
            'admission_no' => $r['admission_no'],
            'student_name' => $r['name'],
            'admission_date' => $r['admission_date'],
            'counsellor_name' => $r['counsellor_name'],
            'counsellor_erp_user_id' => $r['counsellor_erp_user_id'],
            'counsellor_form_assigned_erp_date' => $r['counsellor_form_assigned_erp_date'],
            'latest_erp_assignment_date' => $r['latest_erp_assignment_date'],
            'admission_status_id' => $r['admission_status_id'],
            'admission_status' => $r['admission_status'],
            'admission_confirmation_status' => $r['admission_confirmation_status'],
            'university' => $r['university'],
            'session_name' => $r['session_name'],
            'phone' => $r['phone'],
            'email' => $r['email'],
            'approved_payment_received' => $r['approved_payment_received'],
            'erp_change_stamp' => $r['erp_change_stamp'],
        ]);
    }

    private function startLog(string $trigger): int
    {
        $stmt = $this->crm->prepare(
            "INSERT INTO crm_sync_logs (trigger_type, status, started_at, created_at)
             VALUES (?, 'running', NOW(), NOW())"
        );
        $stmt->execute([$trigger]);
        return (int)$this->crm->lastInsertId();
    }

    private function finishLog(int $id, string $status, array $stats, ?string $error, float $duration): void
    {
        $stmt = $this->crm->prepare(
            "UPDATE crm_sync_logs
             SET status = ?,
                 eligible_read = ?,
                 inserted_count = ?,
                 updated_count = ?,
                 deactivated_count = ?,
                 error_count = ?,
                 error_message = ?,
                 duration_seconds = ?,
                 finished_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $status,
            $stats['eligible_read'],
            $stats['inserted'],
            $stats['updated'],
            $stats['deactivated'],
            $stats['errors'],
            $error,
            $duration,
            $id,
        ]);
    }
}
