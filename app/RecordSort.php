<?php
declare(strict_types=1);
/**
 * Phase 54: newest/oldest sorting by ERP sync time for record lists.
 * Uses the existing crm_students.first_synced_at (set once when a record is first synced from ERP);
 * created_at is the fallback for any row where it is empty. last_synced_at is refreshed on every re-sync.
 */
final class RecordSort {
    public const NEWEST = 'newest';
    public const OLDEST = 'oldest';
    public const DUE = 'due';

    /** Selected sort from ?sort=. Default (and any invalid value) = newest first. */
    public static function current(array $extra = []): string {
        $v = $_GET['sort'] ?? '';
        return is_string($v) && in_array($v, array_merge([self::NEWEST, self::OLDEST], $extra), true) ? $v : self::NEWEST;
    }
    public static function syncedExpr(string $alias = 's'): string {
        return 'COALESCE(' . $alias . '.first_synced_at,' . $alias . '.created_at)';
    }
    /** SQL ORDER BY body (without the ORDER BY keyword). Alias is a fixed code literal, never user input. */
    public static function orderBy(string $sort, string $alias = 's'): string {
        $e = self::syncedExpr($alias);
        return match ($sort) {
            self::OLDEST => "$e ASC,$alias.id ASC",
            'adm_new' => "($alias.admission_date IS NULL),$alias.admission_date DESC,$alias.id DESC",
            'adm_old' => "($alias.admission_date IS NULL),$alias.admission_date ASC,$alias.id ASC",
            'name' => "$alias.student_name ASC,$alias.id ASC",
            'amount' => "$alias.approved_payment_received DESC,$alias.id DESC",
            default => "$e DESC,$alias.id DESC",
        };
    }
    /** Extra sort options usable on any record list (keys are validated by current()). */
    public static function more(): array {
        return ['adm_new' => 'Admission date: newest', 'adm_old' => 'Admission date: oldest', 'name' => 'Student name A–Z', 'amount' => 'Approved amount: high to low'];
    }
    /** SQL boolean: synced within the last 24 hours. */
    public static function isNewExpr(string $alias = 's'): string {
        return '(' . self::syncedExpr($alias) . ' >= (NOW() - INTERVAL 24 HOUR))';
    }
    /** Dropdown to place inside an existing GET filter form; changing it re-submits the form (page resets to 1). */
    public static function select(string $current, array $extra = []): string {
        $labels = [self::NEWEST => 'Newest first', self::OLDEST => 'Oldest first'] + $extra;
        $h = '<label>Sort by<select class="select" name="sort" onchange="this.form.submit()">';
        foreach ($labels as $value => $text) {
            $h .= '<option value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"' . ($value === $current ? ' selected' : '') . '>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        return $h . '</select></label>';
    }
    /** Date/time cell text for a synced timestamp. */
    public static function stamp(?string $dt): string {
        $t = $dt ? strtotime($dt) : false;
        return $t ? date('d M Y, h:i A', $t) : '—';
    }
    /** Small "New" badge for records synced in the last 24 hours. */
    public static function badge(mixed $isNew): string {
        return (int)$isNew === 1 ? ' <span class="crm-new-badge" title="Synced in the last 24 hours" style="display:inline-block;margin-left:4px;padding:1px 7px;border-radius:9px;background:#e6f6ec;color:#17713c;font-size:11px;font-weight:700;vertical-align:middle">New</span>' : '';
    }
}
