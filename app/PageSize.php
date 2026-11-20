<?php
/** Pagination settings shared across CRM lists. Only hard-coded numeric limits reach SQL. */
final class PageSize
{
    public const ALLOWED = [10, 20, 50, 100];
    public static function fromRequest(): int
    {
        $size = filter_var($_GET['per_page'] ?? 20, FILTER_VALIDATE_INT);
        return in_array($size, self::ALLOWED, true) ? $size : 20;
    }
    public static function query(array $changes = []): string
    {
        return '?' . http_build_query(array_merge($_GET, $changes));
    }
    public static function control(int $current): string
    {
        $out = '<label class="crm-page-size">Records per page <select name="per_page" onchange="this.form.elements.page.value=1;this.form.submit()">';
        foreach (self::ALLOWED as $size) {
            $out .= '<option value="' . $size . '"' . ($size === $current ? ' selected' : '') . '>' . $size . '</option>';
        }
        return $out . '</select></label>';
    }
    public static function selector(int $current, array $filters = []): string
    {
        $out = '<form method="get" class="crm-page-control">';
        foreach ($filters as $key=>$value) {
            if ($key === 'per_page' || $key === 'page' || $key === 'export') continue;
            $name = htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8');
            if (is_array($value)) {
                // Multi-select filters (key[]=a&key[]=b) must survive a page-size change.
                foreach ($value as $item) {
                    if (is_scalar($item)) $out .= '<input type="hidden" name="' . $name . '[]" value="' . htmlspecialchars((string)$item, ENT_QUOTES, 'UTF-8') . '">';
                }
                continue;
            }
            if (!is_scalar($value)) continue;
            $out .= '<input type="hidden" name="' . $name . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
        }
        return $out . '<input type="hidden" name="page" value="1">' . self::control($current) . '</form>';
    }
}
