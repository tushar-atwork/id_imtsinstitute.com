<?php
declare(strict_types=1);
/**
 * Shared presentation pieces for the two assignment pages (select records -> choose employee -> review & confirm).
 * Presentation only: the pages keep their own POST handlers, field names and permission checks.
 */
require_once __DIR__ . '/layout.php';

/** Three-step guide shown above the table; assets/js/assign.js moves the highlight as the admin progresses. */
function ui_assign_steps(): string {
    $h = '<ol class="steps3" id="assign-steps" aria-label="Assignment steps">';
    foreach (['Select records', 'Choose employee', 'Review & confirm'] as $i => $label) {
        $h .= '<li data-step="' . ($i + 1) . '"' . ($i === 0 ? ' class="is-active" aria-current="step"' : '') . '><b>' . ($i + 1) . '</b> ' . ui_e($label) . '</li>';
    }
    return $h . '</ol>';
}

/**
 * Floating bar (inside the POST form). $ownerSelect is the page's existing <select name="owner"> markup, unchanged.
 * Without JavaScript the bar is simply a normal, always-visible block and the form posts directly.
 */
function ui_assign_bar(string $ownerSelectHtml, string $buttonLabel, int $max): string {
    return '<div class="bulk-bar assign-bar" id="bulk-bar" role="region" aria-label="Selected records" data-max="' . $max . '">'
        . '<span><strong id="sel-count">0</strong> selected</span>'
        . '<label class="assign-bar-owner"><span class="sr-only">Assign to</span>' . $ownerSelectHtml . '</label>'
        . '<button class="btn btn-primary" type="submit" id="bulk-review">' . ui_e($buttonLabel) . '</button>'
        . '<button class="btn btn-ghost" type="button" id="sel-clear" style="color:inherit">Clear</button></div>';
}

/** Preview/confirm dialog filled by assign.js. $note is optional trusted-plain text shown in the preview. */
function ui_assign_dialog(string $note = ''): string {
    return '<dialog class="modal" id="assign-dialog" aria-labelledby="assign-title"><div class="modal-body"><h2 id="assign-title">Review before assigning</h2>'
        . '<p id="assign-summary"></p><div id="assign-list" class="assign-preview"></div>'
        . ($note !== '' ? '<p class="help" style="margin-top:12px">' . ui_e($note) . '</p>' : '')
        . '</div><div class="modal-foot"><button class="btn" type="button" id="assign-cancel">Go back</button><button class="btn btn-primary" type="button" id="assign-confirm" data-loading>Confirm assignment</button></div></dialog>';
}
