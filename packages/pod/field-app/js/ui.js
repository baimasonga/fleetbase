// The app's small component kit. Text is always set as text, never as HTML, so
// names and other data can't inject markup. Icons are fixed SVG paths drawn
// with createElementNS (no HTML parsing).

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
  append(el, children);
  return el;
}

function append(el, children) {
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
}

// ---------------------------------------------------------------------------
// Icons: one style (24px grid, 2px rounded stroke), drawn as paths.

const ICONS = {
  back: 'M15 18l-6-6 6-6',
  chevron: 'M9 18l6-6-6-6',
  scan: 'M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 12h10',
  keyboard: 'M3 7h18v10H3zM7 11h.01M11 11h.01M15 11h.01M8 14h8',
  check: 'M5 12.5l4.5 4.5L19 7.5',
  alert: 'M12 8v5M12 16.5h.01M10.3 3.9L2.6 17.5A2 2 0 0 0 4.3 20.5h15.4a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z',
  info: 'M12 11v5M12 7.5h.01M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
  cloud: 'M7 18h10a4 4 0 0 0 .7-7.9A6 6 0 0 0 6.2 9.6 4.2 4.2 0 0 0 7 18z',
  cloudOff: 'M3 3l18 18M9.7 6.3A6 6 0 0 1 17.7 10.1 4 4 0 0 1 19.8 17M17 18H7a4.2 4.2 0 0 1-1.6-8.1',
  sync: 'M20 11a8 8 0 0 0-14.6-4.5L4 8M4 4v4h4M4 13a8 8 0 0 0 14.6 4.5L20 16M20 20v-4h-4',
  download: 'M12 4v11M7.5 10.5L12 15l4.5-4.5M5 20h14',
  user: 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4.5 20a7.5 7.5 0 0 1 15 0',
  sack: 'M8 3h8l-1.5 3.5h-5zM9.5 6.5C5.5 9 4 12.5 4 15.5 4 18.5 6.5 21 9.5 21h5c3 0 5.5-2.5 5.5-5.5 0-3-1.5-6.5-5.5-9zM9.5 14.5l2 2 3.5-4',
  camera: 'M4 8h3l2-3h6l2 3h3v11H4zM12 17a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z',
  pen: 'M4 20l4-1 11-11-3-3L5 16zM14 6l3 3',
  open: 'M7 11V8a5 5 0 0 1 9.6-2M5 11h14v10H5zM12 15v2',
  flag: 'M5 21V4M5 4h11l-2 4 2 4H5',
  box: 'M3 7.5L12 3l9 4.5v9L12 21l-9-4.5zM3 7.5L12 12l9-4.5M12 12v9',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
  shield: 'M12 3l8 3v6c0 4.5-3.4 8.2-8 9-4.6-.8-8-4.5-8-9V6zM9 12l2 2 4-4',
  x: 'M6 6l12 12M18 6L6 18',
  minus: 'M5 12h14',
  plus: 'M12 5v14M5 12h14',
  home: 'M4 11l8-7 8 7M6 9.5V20h12V9.5',
  logout: 'M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l4-4-4-4M14 12H4',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
  vee: 'M4 6l8 13 8-13',
  ticket: 'M3 6h18v4a2 2 0 0 0 0 4v4H3v-4a2 2 0 0 0 0-4zM14 6v12',
};

const SVG = 'http://www.w3.org/2000/svg';

export function icon(name, size = 22, label = null) {
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('width', String(size));
  svg.setAttribute('height', String(size));
  svg.setAttribute('class', 'icon');
  if (label) {
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', label);
  } else {
    svg.setAttribute('aria-hidden', 'true');
  }
  const path = document.createElementNS(SVG, 'path');
  path.setAttribute('d', ICONS[name] || ICONS.info);
  svg.append(path);
  return svg;
}

// ---------------------------------------------------------------------------
// Screen frame: the top strip (back, wordmark, signal), a page head (overline
// and a big title) at the top of the content, and the dock with the main action.

let goBack = null;
let shownRoute = null;

/**
 * @param {{title: string, subtitle?: string, back?: string|null, actions?: Node|null, wide?: boolean, head?: boolean}} frame
 *   head: false when the screen draws its own heading (sign-in, receipt).
 * @param  {...any} children
 */
export function screen(frame, ...children) {
  const back = document.getElementById('back');
  document.title = frame.title === 'Invendis' ? frame.title : `${frame.title} · Invendis`;
  back.hidden = !frame.back;
  goBack = frame.back || null;

  const app = document.getElementById('app');
  app.className = `screen${frame.wide ? ' wide' : ''}${frame.actions ? ' has-actions' : ''}`;
  app.replaceChildren();
  if (frame.head !== false) {
    app.append(h('header', { class: 'head' },
      frame.subtitle ? h('p', { class: 'overline' }, frame.subtitle) : null,
      h('h1', {}, frame.title)));
  }
  append(app, children);

  const bar = document.getElementById('actionbar');
  bar.replaceChildren();
  if (frame.actions) bar.append(frame.actions);
  bar.hidden = !frame.actions;

  // A new screen: play the entrance, move focus and scroll to the top. A screen
  // redrawn in place (sync finished, a list refreshed) stays still.
  if (location.hash !== shownRoute) {
    shownRoute = location.hash;
    app.classList.remove('enter');
    void app.offsetWidth;
    app.classList.add('enter');
    app.focus({ preventScroll: true });
    window.scrollTo(0, 0);
  }
}

export function initFrame() {
  document.getElementById('back').addEventListener('click', () => {
    if (goBack) location.hash = goBack;
  });
}

/** The bottom bar: an optional status line, then buttons (the first is the primary). */
export function actionBar(status, ...buttons) {
  return h('div', { class: 'actionbar-inner' },
    status ? h('div', { class: 'actionbar-status', role: 'status', 'aria-live': 'polite' }, status) : null,
    h('div', { class: 'actionbar-buttons' }, buttons));
}

/**
 * What is still missing before the primary action can be used, for the action
 * bar: one item is shown as it is; several fold into a list that stays open
 * (or closed) as the person types.
 */
export function stillNeeded(container, problems) {
  if (problems.length === 1) {
    container.replaceChildren(h('span', { class: 'row todo-one' }, icon('alert', 18), problems[0]));
    return;
  }
  const wasOpen = container.querySelector('details')?.open || false;
  container.replaceChildren(h('details', { open: wasOpen },
    h('summary', {}, h('strong', {}, `${problems.length} things still needed`)),
    h('ul', { class: 'checklist' }, problems.map((p) => h('li', { class: 'todo' }, icon('alert', 16), p)))));
}

export function button(label, { variant = 'secondary', iconName = null, onClick = null, type = 'button', disabled = false, block = false, ariaLabel = null } = {}) {
  return h('button', { type, class: `btn ${variant}${block ? ' block' : ''}`, disabled, 'aria-label': ariaLabel, onclick: onClick },
    iconName ? icon(iconName, 20) : null, h('span', {}, label));
}

/** A status pill: always text with an icon, never colour alone. */
export function pill(text, tone = 'neutral', iconName = null) {
  return h('span', { class: `pill ${tone}` }, iconName ? icon(iconName, 14) : null, text);
}

export function notice(tone, ...children) {
  const icons = { warn: 'alert', bad: 'alert', ok: 'check', info: 'info' };
  return h('div', { class: `notice ${tone}`, role: tone === 'bad' ? 'alert' : 'status' }, icon(icons[tone] || 'info', 20), h('div', { class: 'notice-body' }, children));
}

/**
 * A numbered step that turns into a tick when done.
 * @returns {{el: HTMLElement, setDone: (done: boolean) => void}}
 */
export function step(number, title, ...children) {
  const label = String(number).padStart(2, '0');
  const badge = h('span', { class: 'step-badge', 'aria-hidden': 'true' }, label);
  const state = h('span', { class: 'visually-hidden' }, 'not done');
  const el = h('section', { class: 'card step' }, h('h2', { class: 'step-head' }, badge, h('span', {}, title), state), children);
  return {
    el,
    setDone(done) {
      if (el.classList.contains('done') === done && badge.childNodes.length) return; // unchanged: don't replay the tick
      el.classList.toggle('done', done);
      badge.replaceChildren(done ? icon('check', 24) : label);
      state.textContent = done ? 'done' : 'not done';
    },
  };
}

export function field(label, input, hint = null) {
  const id = input.id || `f${Math.random().toString(36).slice(2, 8)}`;
  input.id = id;
  return h('div', { class: 'field' }, h('label', { for: id }, label), input, hint ? h('p', { class: 'hint' }, hint) : null);
}

/** A tally: one mark per farmer up to 40, a ruled bar beyond that. */
export function progress(done, total) {
  const attrs = { role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(total), 'aria-valuenow': String(done), 'aria-label': `${done} of ${total} served` };
  if (total > 0 && total <= 40) {
    return h('div', { class: 'progress', ...attrs },
      Array.from({ length: total }, (_, i) => h('span', { class: `tick${i < done ? ' on' : ''}` })));
  }
  const pct = total ? Math.round((done / total) * 100) : 0;
  return h('div', { class: 'progress bar', ...attrs }, h('span', { class: 'progress-fill', 'data-pct': String(pct) }));
}

/** Sets progress widths from data attributes (inline styles are blocked by the page's CSP; CSSOM is allowed). */
export function paintProgress(root = document) {
  root.querySelectorAll('.progress-fill[data-pct]').forEach((el) => { el.style.width = `${el.dataset.pct}%`; });
}

export function initials(name) {
  return String(name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
}

let toastTimer = null;
export function toast(message, ms = 3500) {
  const el = document.getElementById('toast');
  el.textContent = message;
  el.hidden = false;
  el.classList.remove('show');
  void el.offsetWidth;
  el.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.hidden = true; }, ms);
}

export function hideToast() {
  clearTimeout(toastTimer);
  document.getElementById('toast').hidden = true;
}

export function number(value) {
  const n = Number(value);
  return Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '');
}

/** "1 bag", "2 bags", "3 kg". */
export function plural(n, word) {
  const count = Number(n);
  if (count === 1 || ['kg', 'g'].includes(word)) return `${number(count)} ${word}`;
  return `${number(count)} ${word.endsWith('s') ? word : word + 's'}`;
}

export function time(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export function clock(iso) {
  if (!iso) return '';
  return new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
}

export const sexLabel = (sex) => (sex === 'female' ? 'Female' : sex === 'male' ? 'Male' : '');
