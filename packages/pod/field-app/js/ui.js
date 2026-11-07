// Small DOM helpers. Text is always set as text, never as HTML, so names and
// other data can't inject markup.

export function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === false || value === null || value === undefined) continue;
    if (key.startsWith('on') && typeof value === 'function') el.addEventListener(key.slice(2), value);
    else if (key === 'class') el.className = value;
    else if (key === 'text') el.textContent = value;
    else if (value === true) el.setAttribute(key, '');
    else el.setAttribute(key, String(value));
  }
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return el;
}

export function mount(...children) {
  const app = document.getElementById('app');
  app.replaceChildren(...children.flat(Infinity).filter((c) => c !== null && c !== undefined && c !== false));
  app.focus({ preventScroll: true });
  window.scrollTo(0, 0);
}

let toastTimer = null;
export function toast(message, ms = 3500) {
  const el = document.getElementById('toast');
  el.textContent = message;
  el.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.hidden = true; }, ms);
}

export function setTitle(text) {
  document.getElementById('title').textContent = text;
  document.title = text === 'Field Distribution' ? text : `${text} · Field Distribution`;
}

export function number(value) {
  const n = Number(value);
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

export function time(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return d.toLocaleString('en-GB', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
}

export const sexLabel = (sex) => (sex === 'female' ? 'Female' : sex === 'male' ? 'Male' : '');

/** "1 bag", "2 bags", "3 kg". */
export function plural(n, word) {
  const count = Number(n);
  if (count === 1 || ['kg', 'g'].includes(word)) return `${number(count)} ${word}`;
  return `${number(count)} ${word.endsWith('s') ? word : word + 's'}`;
}
