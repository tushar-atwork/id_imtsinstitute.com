<?php
declare(strict_types=1);
/**
 * Presentation helpers shared by the report pages (Analysis, Work report, Performance, Management reports).
 * They only format data the pages already computed; no queries and no business rules live here.
 * Charts are plain HTML/CSS (no chart library), so they work offline and respect light/dark theme.
 */
require_once __DIR__ . '/layout.php';

/** Summary cards. $items: [[label, value, optionalNote], ...]. Values are escaped. */
function ui_kpis(array $items): string {
    $h = '<section class="stats-row" aria-label="Summary">';
    foreach ($items as $it) {
        $h .= '<div class="card stat"><span class="k">' . ui_e($it[0]) . '</span><span class="v">' . ui_e($it[1]) . '</span>'
            . (isset($it[2]) && $it[2] !== '' ? '<span class="n">' . ui_e($it[2]) . '</span>' : '') . '</div>';
    }
    return $h . '</section>';
}

/** Quick date links, e.g. [[label, href], ...]. */
function ui_presets(array $links): string {
    $h = '<nav class="presets" aria-label="Quick date ranges">';
    foreach ($links as [$label, $href]) $h .= '<a class="btn btn-sm" href="' . ui_e($href) . '">' . ui_e($label) . '</a>';
    return $h . '</nav>';
}

/**
 * Horizontal bar list. $rows: [[label, value, optionalSubLabel], ...]. Bars are scaled to the largest value shown.
 * Each row exposes its value as text, so the chart is readable without colour or sight.
 */
function ui_hbars(array $rows, string $empty = 'Nothing to show for this selection.'): string {
    if (!$rows) return '<p class="muted">' . ui_e($empty) . '</p>';
    $max = 1;
    foreach ($rows as $r) $max = max($max, (float)$r[1]);
    $h = '<div class="hbars" role="list">';
    foreach ($rows as $r) {
        $pct = round(((float)$r[1]) / $max * 100, 2);
        $h .= '<div class="hrow" role="listitem"><div class="hlabel" title="' . ui_e($r[0]) . '">' . ui_e($r[0]) . (!empty($r[2]) ? '<small>' . ui_e($r[2]) . '</small>' : '') . '</div>'
            . '<div class="htrack" aria-hidden="true"><i style="width:' . $pct . '%"></i></div><strong>' . ui_e(number_format((float)$r[1])) . '</strong></div>';
    }
    return $h . '</div>';
}

/**
 * Column chart over a list of x labels. $series: [seriesName => [values aligned to $labels]] (max 2 series, colours by order).
 * $titles (optional) gives each x position a tooltip prefix such as the full date.
 */
function ui_vbars(array $labels, array $series, string $aria, array $titles = []): string {
    $max = 1;
    foreach ($series as $vals) foreach ($vals as $v) $max = max($max, (float)$v);
    $names = array_keys($series);
    $h = '';
    if (count($names) > 1) {
        $h .= '<div class="legend">';
        foreach ($names as $i => $n) $h .= '<span><i class="dot s' . ($i + 1) . '"></i>' . ui_e($n) . '</span>';
        $h .= '</div>';
    }
    $h .= '<div class="vscroll"><div class="vbars" role="img" aria-label="' . ui_e($aria) . '">';
    foreach ($labels as $i => $label) {
        $tip = ($titles[$i] ?? $label) . ': ';
        $parts = [];
        foreach ($names as $n) $parts[] = (int)($series[$n][$i] ?? 0) . ' ' . strtolower($n);
        $h .= '<div class="vgroup" title="' . ui_e($tip . implode(', ', $parts)) . '"><div class="vcols">';
        foreach ($names as $si => $n) {
            $v = (float)($series[$n][$i] ?? 0);
            $h .= '<b class="s' . ($si + 1) . '" style="height:' . ($v > 0 ? max(2, round($v / $max * 100, 2)) : 0) . '%"></b>';
        }
        $h .= '</div><span>' . ui_e($label) . '</span></div>';
    }
    return $h . '</div></div>';
}

/** Page-actions CSV button. */
function ui_csv_button(string $href, string $label = 'Download CSV'): string {
    return '<a class="btn" href="' . ui_e($href) . '">' . ui_icon('note', 16) . ui_e($label) . '</a>';
}
