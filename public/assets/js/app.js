/* IMTS CRM shared UI behaviour. Vanilla JS, progressive enhancement: pages still work if this file fails to load. */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var store = {
    get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
  };

  /* ---- Toasts: window.crmToast(message, 'success'|'error'|'info') ---- */
  function toast(msg, type) {
    var box = $('#toasts'); if (!box) return;
    var t = document.createElement('div');
    t.className = 'toast t-' + (type || 'info'); t.textContent = msg;
    box.appendChild(t);
    setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(function () { t.remove(); }, 320); }, type === 'error' ? 7000 : 4000);
  }
  window.crmToast = toast;

  /* ---- Sidebar: collapse (desktop, remembered) and off-canvas (mobile) ---- */
  var app = $('#app');
  if (app) {
    if (store.get('crm-nav') === '1') app.classList.add('is-collapsed');
    $$('[data-collapse]').forEach(function (b) {
      b.addEventListener('click', function () {
        var on = app.classList.toggle('is-collapsed'); store.set('crm-nav', on ? '1' : '0');
      });
    });
    $$('[data-nav-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        var open = app.classList.toggle('nav-open'); b.setAttribute('aria-expanded', open ? 'true' : 'false');
      });
    });
    $$('[data-nav-close]').forEach(function (b) { b.addEventListener('click', function () { app.classList.remove('nav-open'); }); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') app.classList.remove('nav-open'); });
  }

  /* ---- Sidebar groups (<details>): the group holding the current page is open; others remember your choice.
         While the sidebar is collapsed to icons every group is opened so no icon is hidden. ---- */
  (function () {
    var groups = $$('.nav-group[data-group]'); if (!groups.length) return;
    var key = 'crm-groups', busy = false;
    function saved() { try { return JSON.parse(store.get(key) || '[]'); } catch (e) { return []; } }
    function persist() {
      if (app && app.classList.contains('is-collapsed')) return;
      store.set(key, JSON.stringify(groups.filter(function (g) { return g.open; }).map(function (g) { return g.getAttribute('data-group'); })));
    }
    function restore() {
      busy = true;
      var open = saved(), collapsed = app && app.classList.contains('is-collapsed');
      groups.forEach(function (g) { g.open = collapsed || g.hasAttribute('data-current') || open.indexOf(g.getAttribute('data-group')) > -1; });
      busy = false;
    }
    groups.forEach(function (g) { g.addEventListener('toggle', function () { if (!busy) persist(); }); });
    restore();
    $$('[data-collapse]').forEach(function (b) { b.addEventListener('click', function () { setTimeout(restore, 0); }); });
  })();

  /* ---- Dropdowns ---- */
  function closeMenus(except) {
    $$('[data-dropdown]').forEach(function (b) {
      var m = b.nextElementSibling; if (!m || m === except) return;
      m.hidden = true; b.setAttribute('aria-expanded', 'false');
    });
  }
  $$('[data-dropdown]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.stopPropagation();
      var m = b.nextElementSibling; var open = m.hidden;
      closeMenus(open ? m : null); m.hidden = !open; b.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });
  document.addEventListener('click', function () { closeMenus(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenus(null); });

  /* ---- Theme (light default, remembered) ---- */
  $$('[data-theme-toggle]').forEach(function (b) {
    b.addEventListener('click', function () {
      var dark = document.documentElement.getAttribute('data-theme') === 'dark';
      var next = dark ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next); store.set('crm-theme', next);
    });
  });

  /* ---- Show / hide password ---- */
  $$('[data-toggle-password]').forEach(function (b) {
    b.addEventListener('click', function () {
      var input = document.getElementById(b.getAttribute('data-toggle-password')); if (!input) return;
      var show = input.type === 'password'; input.type = show ? 'text' : 'password';
      b.setAttribute('aria-pressed', show ? 'true' : 'false');
      b.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });
  });
  $$('input[data-caps-hint]').forEach(function (input) {
    var hint = document.getElementById(input.getAttribute('data-caps-hint')); if (!hint) return;
    var check = function (e) { if (e.getModifierState) hint.hidden = !e.getModifierState('CapsLock'); };
    input.addEventListener('keydown', check); input.addEventListener('keyup', check);
    input.addEventListener('blur', function () { hint.hidden = true; });
  });

  /* ---- Confirmation dialog: <form data-confirm="Message" [data-confirm-type="RESET"]> or <a/button data-confirm> ---- */
  var dlg = $('#confirm-dialog');
  function ask(message, word) {
    return new Promise(function (resolve) {
      if (!dlg || typeof dlg.showModal !== 'function') { resolve(window.confirm(message)); return; }
      var typeBox = $('#confirm-type'), input = $('#confirm-input'), ok = $('#confirm-ok');
      $('#confirm-msg').textContent = message;
      typeBox.hidden = !word; input.value = ''; ok.disabled = !!word;
      if (word) { $('#confirm-word').textContent = word; input.oninput = function () { ok.disabled = input.value.trim() !== word; }; }
      // Resolve from the button clicks (reliable) and from close (covers Esc); only the first one counts.
      var settled = false, cancelBtn = dlg.querySelector('.modal-foot button[value=cancel]');
      function finish(yes) { if (settled) return; settled = true; ok.onclick = null; if (cancelBtn) cancelBtn.onclick = null; dlg.onclose = null; resolve(yes); }
      ok.onclick = function () { finish(true); };
      if (cancelBtn) cancelBtn.onclick = function () { finish(false); };
      dlg.onclose = function () { finish(dlg.returnValue === 'ok'); };
      dlg.returnValue = 'cancel'; dlg.showModal(); (word ? input : ok).focus();
    });
  }
  window.crmConfirm = ask;
  document.addEventListener('submit', function (e) {
    var f = e.target; if (!(f instanceof HTMLFormElement)) return;
    var msg = f.getAttribute('data-confirm'); if (!msg || f.dataset.confirmed === '1') return;
    e.preventDefault();
    var submitter = e.submitter;
    ask(msg, f.getAttribute('data-confirm-type')).then(function (yes) {
      if (!yes) return;
      f.dataset.confirmed = '1';
      if (f.requestSubmit && submitter) f.requestSubmit(submitter); else f.submit();
    });
  }, true);
  document.addEventListener('click', function (e) {
    var el = e.target.closest ? e.target.closest('a[data-confirm],button[data-confirm]:not([type=submit])') : null;
    if (!el) return;
    e.preventDefault();
    ask(el.getAttribute('data-confirm'), el.getAttribute('data-confirm-type')).then(function (yes) {
      if (yes && el.href) window.location.href = el.href;
    });
  });

  /* ---- Loading state on submit (does not disable the button, so its name/value still post) ---- */
  document.addEventListener('submit', function (e) {
    var f = e.target; if (!(f instanceof HTMLFormElement) || e.defaultPrevented) return;
    var b = e.submitter || $('button[type=submit][data-loading]', f);
    if (b && b.hasAttribute('data-loading')) setTimeout(function () { b.classList.add('is-loading'); b.setAttribute('aria-busy', 'true'); }, 0);
  });
  window.addEventListener('pageshow', function () { $$('.is-loading').forEach(function (b) { b.classList.remove('is-loading'); b.removeAttribute('aria-busy'); }); });

  /* ---- Generic tabs: <div data-tabs><button data-tab="a">..</button> ... <div data-panel="a">..</div></div> ---- */
  $$('[data-tabs]').forEach(function (root) {
    var tabs = $$('[data-tab]', root), panels = $$('[data-panel]', root);
    if (!tabs.length) return;
    function show(name) {
      tabs.forEach(function (t) { var on = t.getAttribute('data-tab') === name; t.setAttribute('aria-selected', on ? 'true' : 'false'); t.classList.toggle('is-active', on); t.setAttribute('role', 'tab'); });
      panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.getAttribute('data-tab')); }); });
    var h = (location.hash || '').replace('#', ''), pick = tabs[0].getAttribute('data-tab');
    if (h) panels.forEach(function (p) { if (p.id === h || p.querySelector('[id="' + h + '"]')) pick = p.getAttribute('data-panel'); });
    show(pick);
  });

  /* ---- Deep link: ?section=name scrolls to [data-section="name"] (used after saving on the record page) ---- */
  (function () {
    var m = /[?&]section=([a-z_-]+)/.exec(location.search); if (!m) return;
    var el = $('[data-section="' + m[1] + '"]'); if (el && el.scrollIntoView) el.scrollIntoView({ block: 'start' });
  })();

  /* ---- "Update status" jump: focus the first field of the target form ---- */
  $$('[data-focus-form]').forEach(function (a) {
    a.addEventListener('click', function () {
      var t = document.querySelector(a.getAttribute('href')); if (!t) return;
      var f = t.querySelector('select,textarea,input:not([type=hidden])'); if (f) setTimeout(function () { f.focus({ preventScroll: true }); }, 250);
    });
  });

  /* ---- Server-rendered flash message -> toast ---- */
  var flash = $('#ui-flash'); if (flash) toast(flash.getAttribute('data-msg'), flash.getAttribute('data-type'));
})();
