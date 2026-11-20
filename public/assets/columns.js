document.addEventListener('DOMContentLoaded', function () {
  const table = document.querySelector('#p26-admissions-table table');
  const panel = document.getElementById('column-settings');
  const list = document.getElementById('column-settings-list');
  const toggle = document.getElementById('column-settings-toggle');
  if (!table || !panel || !list || !toggle) return;
  const headers = Array.from(table.tHead.rows[0].cells);
  const labels = headers.map(h => h.textContent.trim());
  const fixed = labels.slice(0, 5);
  const optional = labels.slice(5);
  const rows = [table.tHead.rows[0], ...Array.from(table.tBodies[0].rows).filter(r => r.cells.length === labels.length)];
  const cells = rows.map(row => Array.from(row.cells));
  const storageKey = 'imts-admissions-columns-v1-user-' + table.dataset.userId;
  let state = {order: optional.slice(), hidden: []};
  try {
    const saved = JSON.parse(localStorage.getItem(storageKey));
    if (saved && Array.isArray(saved.order) && Array.isArray(saved.hidden)) {
      state.order = [...new Set(saved.order.filter(x => optional.includes(x)))];
      state.order.push(...optional.filter(x => !state.order.includes(x)));
      state.hidden = saved.hidden.filter(x => optional.includes(x));
    }
  } catch (_) {}
  function apply() {
    const ordered = fixed.concat(state.order);
    rows.forEach((row, r) => {
      ordered.forEach(label => {
        const i = labels.indexOf(label);
        const cell = cells[r][i];
        cell.classList.toggle('column-hidden', state.hidden.includes(label));
        row.appendChild(cell);
      });
    });
    const empty = table.tBodies[0].querySelector('td.empty');
    if (empty) empty.colSpan = ordered.length - state.hidden.length;
    try { localStorage.setItem(storageKey, JSON.stringify(state)); } catch (_) {}
    window.dispatchEvent(new Event('resize'));
  }
  function render() {
    list.replaceChildren();
    fixed.forEach(label => {
      const item = document.createElement('div'); item.className = 'column-item fixed';
      item.textContent = '🔒 ' + label; list.appendChild(item);
    });
    state.order.forEach((label, index) => {
      const item = document.createElement('div'); item.className = 'column-item';
      const check = document.createElement('input'); check.type = 'checkbox'; check.checked = !state.hidden.includes(label);
      check.setAttribute('aria-label', 'Show ' + label);
      check.addEventListener('change', () => {
        state.hidden = check.checked ? state.hidden.filter(x => x !== label) : [...state.hidden, label];
        apply();
      });
      const name = document.createElement('span'); name.textContent = label;
      item.append(check, name);
      [['↑', -1], ['↓', 1]].forEach(([symbol, delta]) => {
        const button = document.createElement('button'); button.type = 'button'; button.textContent = symbol;
        button.title = (delta < 0 ? 'Move up ' : 'Move down ') + label;
        button.setAttribute('aria-label', button.title);
        button.disabled = index + delta < 0 || index + delta >= state.order.length;
        button.addEventListener('click', () => {
          [state.order[index], state.order[index + delta]] = [state.order[index + delta], state.order[index]];
          apply(); render();
        });
        item.appendChild(button);
      });
      list.appendChild(item);
    });
  }
  toggle.addEventListener('click', () => {
    panel.hidden = !panel.hidden;
    toggle.setAttribute('aria-expanded', String(!panel.hidden));
  });
  document.getElementById('column-reset').addEventListener('click', () => {
    state = {order: optional.slice(), hidden: []}; apply(); render();
  });
  apply(); render();
});
