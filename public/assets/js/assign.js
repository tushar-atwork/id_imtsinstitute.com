/* Assignment pages: row selection, floating action bar, and a "what will change" preview before the real POST.
   Progressive enhancement: without this file the form still posts normally. It never changes field names or values. */
(function () {
  'use strict';
  var form = document.querySelector('form[data-assign]');
  if (!form) return;
  var boxes = Array.prototype.slice.call(form.querySelectorAll('input.js-sel'));
  var all = form.querySelector('#sel-all');
  var bar = document.getElementById('bulk-bar');
  var count = document.getElementById('sel-count');
  var owner = form.querySelector('select[name="owner"]');
  var steps = document.getElementById('assign-steps');
  var dlg = document.getElementById('assign-dialog');
  var max = bar ? parseInt(bar.getAttribute('data-max'), 10) || 0 : 0;
  var confirmed = false;

  function selected() { return boxes.filter(function (b) { return b.checked; }); }
  function setStep(n) {
    if (!steps) return;
    Array.prototype.forEach.call(steps.children, function (li) {
      var on = parseInt(li.getAttribute('data-step'), 10) === n;
      li.classList.toggle('is-active', on); li.classList.toggle('is-done', parseInt(li.getAttribute('data-step'), 10) < n);
      if (on) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
    });
  }
  function refresh() {
    var sel = selected();
    boxes.forEach(function (b) { var tr = b.closest('tr'); if (tr) tr.classList.toggle('is-selected', b.checked); });
    if (count) count.textContent = String(sel.length);
    if (bar) bar.hidden = sel.length === 0;
    if (all) { all.checked = sel.length > 0 && sel.length === boxes.length; all.indeterminate = sel.length > 0 && sel.length < boxes.length; }
    setStep(sel.length === 0 ? 1 : 2);
  }
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); refresh(); });
  var clear = document.getElementById('sel-clear');
  if (clear) clear.addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; }); refresh(); });
  refresh();

  function ownerName() {
    if (!owner) return '';
    var o = owner.options[owner.selectedIndex];
    return o && o.value !== '' ? (o.getAttribute('data-name') || o.textContent.trim()) : '';
  }
  function el(tag, text, cls) { var e = document.createElement(tag); if (text != null) e.textContent = text; if (cls) e.className = cls; return e; }

  function preview() {
    var sel = selected(), target = ownerName(), unassign = owner && owner.value === '';
    var unassigned = 0, change = 0, same = 0;
    sel.forEach(function (b) {
      var cur = b.getAttribute('data-owner') || '';
      if (cur === '') unassigned++; else if (!unassign && cur === target) same++; else change++;
    });
    var summary = document.getElementById('assign-summary'), list = document.getElementById('assign-list');
    summary.textContent = '';
    summary.appendChild(el('strong', String(sel.length)));
    summary.appendChild(document.createTextNode(unassign ? ' record(s) will be set to Unassigned.' : ' record(s) will be assigned to '));
    if (!unassign) summary.appendChild(el('strong', target));
    if (!unassign) summary.appendChild(document.createTextNode('.'));
    list.textContent = '';
    var facts = el('div', null, 'assign-facts');
    [[unassigned, 'currently unassigned'], [change, unassign ? 'will lose their current owner' : 'move from another owner'], [same, 'already with this owner (no change)']].forEach(function (p) {
      if (p[0] > 0) facts.appendChild(el('span', p[0] + ' ' + p[1], 'badge ' + (p[1].indexOf('no change') > -1 ? 'b-grey' : 'b-blue')));
    });
    list.appendChild(facts);
    var table = el('table', null, 'table'), tb = el('tbody');
    sel.slice(0, 8).forEach(function (b) {
      var tr = el('tr');
      tr.appendChild(el('td', b.getAttribute('data-name') || ''));
      tr.appendChild(el('td', b.getAttribute('data-adm') || ''));
      var cur = b.getAttribute('data-owner') || 'Unassigned';
      tr.appendChild(el('td', cur + ' → ' + (unassign ? 'Unassigned' : target)));
      tb.appendChild(tr);
    });
    table.appendChild(tb); list.appendChild(el('div', null, 'table-wrap')).appendChild(table);
    if (sel.length > 8) list.appendChild(el('p', '+ ' + (sel.length - 8) + ' more selected', 'help'));
  }

  form.addEventListener('submit', function (e) {
    if (confirmed) return;
    var sel = selected();
    if (sel.length === 0) { e.preventDefault(); if (window.crmToast) window.crmToast('Select at least one record first.', 'error'); return; }
    if (max && sel.length > max) { e.preventDefault(); if (window.crmToast) window.crmToast('Select at most ' + max + ' records.', 'error'); return; }
    if (!dlg || typeof dlg.showModal !== 'function') return;   // no <dialog>: post directly
    e.preventDefault(); preview(); setStep(3); dlg.showModal();
  });
  var cancel = document.getElementById('assign-cancel');
  if (cancel) cancel.addEventListener('click', function () { dlg.close(); setStep(selected().length ? 2 : 1); });
  var ok = document.getElementById('assign-confirm');
  if (ok) ok.addEventListener('click', function () {
    confirmed = true; ok.classList.add('is-loading'); form.submit();
  });
  if (dlg) dlg.addEventListener('close', function () { if (!confirmed) setStep(selected().length ? 2 : 1); });
})();
