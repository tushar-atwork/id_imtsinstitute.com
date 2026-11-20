document.addEventListener('DOMContentLoaded', () => {
  const subject = document.getElementById('crm-email-subject');
  const body = document.getElementById('crm-email-body');
  const valuesNode = document.getElementById('crm-email-values');
  if (!subject || !body || !valuesNode) return;
  let values;
  try { values = JSON.parse(valuesNode.textContent); } catch (_) { return; }
  let target = body;
  [subject, body].forEach(input => input.addEventListener('focus', () => { target = input; }));
  const render = source => source.replace(/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/g, (match, key) => Object.prototype.hasOwnProperty.call(values, key) ? values[key] : match);
  function preview() {
    document.getElementById('crm-preview-subject').textContent = render(subject.value) || '(enter a subject)';
    document.getElementById('crm-preview-body').textContent = render(body.value) || '(enter a message)';
    const unknown = [...subject.value.matchAll(/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/g), ...body.value.matchAll(/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/g)].map(m => m[1]).filter(key => !Object.prototype.hasOwnProperty.call(values, key));
    document.getElementById('crm-preview-warning').textContent = unknown.length ? 'Unknown variable: ' + [...new Set(unknown)].join(', ') : '';
  }
  [subject, body].forEach(input => input.addEventListener('input', preview));
  document.querySelectorAll('[data-email-variable]').forEach(button => button.addEventListener('click', () => {
    const token = '{{' + button.dataset.emailVariable + '}}';
    const start = target.selectionStart ?? target.value.length;
    const end = target.selectionEnd ?? start;
    target.setRangeText(token, start, end, 'end');
    target.focus(); preview();
  }));
  preview();
});
