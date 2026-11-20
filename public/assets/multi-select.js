/* Phase 50: checkbox dropdown for <select multiple data-multi>. The native select stays in the
   form (hidden) and is kept in sync, so submitted values are identical with or without JS. */
(function () {
  'use strict';
  if (window.crmMultiSelect) return;
  window.crmMultiSelect = true;
  var open = null;

  function close(widget) {
    if (!widget) return;
    widget.panel.hidden = true;
    widget.toggle.setAttribute('aria-expanded', 'false');
    if (open === widget) open = null;
  }

  function summary(select) {
    var picked = Array.prototype.filter.call(select.options, function (o) { return o.selected; });
    if (!picked.length) return select.getAttribute('data-placeholder') || 'All';
    if (picked.length === 1) return picked[0].text;
    return picked.length + ' selected';
  }

  function enhance(select) {
    if (select.dataset.msReady) return;
    select.dataset.msReady = '1';

    var wrap = document.createElement('div');
    wrap.className = 'ms';
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'ms-toggle';
    toggle.setAttribute('aria-haspopup', 'listbox');
    toggle.setAttribute('aria-expanded', 'false');
    var panel = document.createElement('div');
    panel.className = 'ms-panel';
    panel.hidden = true;

    var search = null;
    if (select.options.length > 8) {
      search = document.createElement('input');
      search.type = 'search';
      search.className = 'ms-search';
      search.placeholder = 'Search…';
      search.setAttribute('aria-label', 'Search options');
      panel.appendChild(search);
    }
    var actions = document.createElement('div');
    actions.className = 'ms-actions';
    var all = document.createElement('button');
    all.type = 'button';
    all.textContent = 'Select shown';
    var none = document.createElement('button');
    none.type = 'button';
    none.textContent = 'Clear';
    actions.appendChild(all);
    actions.appendChild(none);
    panel.appendChild(actions);

    var list = document.createElement('div');
    list.className = 'ms-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-multiselectable', 'true');
    var boxes = [];
    Array.prototype.forEach.call(select.options, function (opt) {
      var row = document.createElement('label');
      var box = document.createElement('input');
      box.type = 'checkbox';
      box.checked = opt.selected;
      box.addEventListener('change', function () { opt.selected = box.checked; refresh(); });
      row.appendChild(box);
      row.appendChild(document.createTextNode(opt.text));
      list.appendChild(row);
      boxes.push({ box: box, row: row, opt: opt, text: opt.text.toLowerCase() });
    });
    if (!boxes.length) {
      var empty = document.createElement('div');
      empty.className = 'ms-empty';
      empty.textContent = 'No options';
      list.appendChild(empty);
    }
    panel.appendChild(list);

    function refresh() {
      toggle.textContent = summary(select);
      toggle.classList.toggle('has-value', select.selectedOptions.length > 0);
      toggle.title = Array.prototype.map.call(select.selectedOptions, function (o) { return o.text; }).join(', ');
    }
    function setAll(state, visibleOnly) {
      boxes.forEach(function (b) {
        if (visibleOnly && b.row.hidden) return;
        b.box.checked = state;
        b.opt.selected = state;
      });
      refresh();
    }
    all.addEventListener('click', function () { setAll(true, true); });
    none.addEventListener('click', function () { setAll(false, false); });
    if (search) {
      search.addEventListener('input', function () {
        var term = search.value.trim().toLowerCase();
        boxes.forEach(function (b) { b.row.hidden = term !== '' && b.text.indexOf(term) === -1; });
      });
    }

    var widget = { panel: panel, toggle: toggle };
    toggle.addEventListener('click', function () {
      if (!panel.hidden) { close(widget); return; }
      close(open);
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      open = widget;
      if (search) search.focus();
    });
    wrap.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { close(widget); toggle.focus(); }
    });

    // Checkbox rows are labels, and labels must not nest: turn a wrapping <label> into a <div>.
    var host = select.parentNode;
    if (host.tagName === 'LABEL') {
      var field = document.createElement('div');
      field.className = (host.className ? host.className + ' ' : '') + 'ms-field';
      var caption = document.createElement('span');
      caption.id = 'ms-caption-' + Math.random().toString(36).slice(2);
      while (host.firstChild && host.firstChild !== select) caption.appendChild(host.firstChild);
      field.appendChild(caption);
      host.parentNode.replaceChild(field, host);
      field.appendChild(select);
      toggle.setAttribute('aria-labelledby', caption.id + ' ' + (toggle.id = caption.id + '-t'));
      list.setAttribute('aria-labelledby', caption.id);
    }
    select.parentNode.insertBefore(wrap, select);
    wrap.appendChild(toggle);
    wrap.appendChild(panel);
    wrap.appendChild(select);
    select.hidden = true;
    select.style.display = 'none';
    widget.wrap = wrap;
    refresh();
  }

  document.addEventListener('click', function (ev) {
    if (open && !open.wrap.contains(ev.target)) close(open);
  });

  function init() {
    document.querySelectorAll('select[multiple][data-multi]').forEach(enhance);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
