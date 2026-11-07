// Field Distribution: offline handovers of agricultural inputs.
// AGRI-INPUTS.md §7.2 (identity), §8 (proof), §11 (opening and closing), §13 (offline); DESIGN.md §6.6.
//
// Design (see app.css): one main action per screen, at the bottom within thumb
// reach; back always top left; progress visible; every action answered.

import * as db from './db.js';
import { api, apiBlob, OfflineError } from './api.js';
import { checkCard, idLast4Matches } from './card.js';
import { buildPolicy, describe, evaluate, identityLevel } from './policy.js';
import { compressPhoto, SignaturePad } from './media.js';
import { scan } from './scanner.js';
import { onSyncChange, status as syncStatus, syncNow, isSyncing } from './sync.js';
import {
  actionBar, button, clock, field, h, hideToast, icon, initFrame, initials, notice, number, paintProgress,
  pill, plural, progress, screen, sexLabel, step, stillNeeded, time, toast,
} from './ui.js';

// A card scanned or typed, kept in memory only (never in the URL or history).
let scanned = null;

const FAILURE_LABELS = {
  farmer_absent: 'Farmer not at home', household_moved: 'Household has moved', farmer_deceased: 'Farmer has died',
  refused: 'Refused the inputs', wrong_village: 'Wrong village', road_inaccessible: 'Road impassable',
  security_issue: 'Unsafe to visit', already_received: 'Says already received', other: 'Other reason',
};

const WITNESS_ROLE_LABELS = {
  chief: 'Chief', section_chief: 'Section chief', group_leader: 'Group leader', extension_officer: 'Extension officer',
  councillor: 'Councillor', community_leader: 'Community leader', other: 'Other',
};

const DISCREPANCY_REASONS = {
  torn_bag: 'Torn bag', damaged: 'Damaged', wet: 'Wet or spoilt', spill_leak: 'Spilled or leaking', expired: 'Expired',
  theft: 'Stolen', count_error: 'Counting error', counterfeit_suspected: 'Looks fake or relabelled', other: 'Other',
};

// ---------------------------------------------------------------------------
// Start-up and routing

function go(route) {
  if (location.hash === route) render();
  else location.hash = route;
}

async function render() {
  hideToastOnNavigate();
  const route = location.hash.replace(/^#/, '') || '/';
  const token = await db.setting('token');
  if (!token && route !== '/login') return go('#/login');

  const parts = route.split('/').filter(Boolean);
  try {
    if (parts[0] === 'login') return renderLogin();
    if (parts[0] === 'event' && parts[1]) return await renderEvent(parts[1]);
    if (parts[0] === 'handover' && parts[1] && parts[2]) return await renderHandover(parts[1], parts[2]);
    if (parts[0] === 'attempt' && parts[1] && parts[2]) return await renderAttempt(parts[1], parts[2]);
    if (parts[0] === 'receipt' && parts[1]) return await renderReceipt(parts[1]);
    if (parts[0] === 'open' && parts[1]) return await renderCount(parts[1], 'open');
    if (parts[0] === 'close' && parts[1]) return await renderCount(parts[1], 'close');
    if (parts[0] === 'discrepancy' && parts[1]) return await renderDiscrepancy(parts[1]);
    if (parts[0] === 'review' && parts[1] && parts[1] !== 'resolved') return await renderConflict(parts[1]);
    if (parts[0] === 'review') return await renderReview(parts[1] || 'open');
    return await renderHome();
  } catch (error) {
    screen({ title: 'Something went wrong', back: '#/' },
      notice('bad', error.message || 'Something went wrong.'),
      button('Home', { variant: 'secondary', iconName: 'home', block: true, onClick: () => go('#/') }));
  }
}

let lastRoute = null;
function hideToastOnNavigate() {
  // A message about the previous screen shouldn't linger over the next one,
  // except right after saving (the toast is shown after navigating there).
  if (lastRoute !== null && lastRoute !== location.hash && !toastSticky) hideToast();
  toastSticky = false;
  lastRoute = location.hash;
}
let toastSticky = false;
function toastAfterNavigate(message, ms) {
  toastSticky = true;
  setTimeout(() => toast(message, ms), 0);
}

async function updateNet() {
  const el = document.getElementById('net');
  const s = await syncStatus();
  const waiting = s.pending + s.mediaPending;
  el.classList.toggle('offline', !navigator.onLine);
  el.classList.toggle('syncing', isSyncing());
  el.textContent = (navigator.onLine ? (isSyncing() ? 'Syncing…' : 'Online') : 'Offline') + (waiting ? ` · ${waiting} to send` : '');
}

initFrame();
window.addEventListener('hashchange', render);
window.addEventListener('online', () => { updateNet(); syncNow(); });
window.addEventListener('offline', () => { updateNet(); if ((location.hash || '#/') === '#/') render(); });
onSyncChange(() => {
  updateNet();
  // The home screen shows sync results; keep it current. (Other screens may have
  // a half-filled form, so they're left alone.)
  const route = location.hash.replace(/^#/, '') || '/';
  if (route === '/' && !isSyncing()) render();
});
setInterval(() => { if (navigator.onLine) syncNow(); }, 60000);

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('sw.js', { scope: './' }).catch(() => {});
}

render();
updateNet();
if (navigator.onLine) syncNow();

// ---------------------------------------------------------------------------
// Sign in

function renderLogin() {
  const error = h('div', { hidden: true });
  const identity = h('input', { type: 'text', autocomplete: 'username', inputmode: 'email', required: true, autocapitalize: 'off', spellcheck: 'false' });
  const password = h('input', { type: 'password', autocomplete: 'current-password', required: true });
  const submit = button('Sign in', { variant: 'primary', type: 'submit', block: true });

  const form = h('form', {
    class: 'card stack',
    onsubmit: async (e) => {
      e.preventDefault();
      error.hidden = true;
      submit.disabled = true;
      submit.classList.add('busy');
      try {
        const data = await api('auth/login', { method: 'POST', json: { identity: identity.value.trim(), password: password.value }, auth: false });
        if (data && data.twoFaSession) {
          throw new Error('This account uses two-step sign-in, which the field app does not support yet. Ask an administrator for a field account.');
        }
        if (!data || !data.token) throw new Error('Sign-in did not work. Try again.');
        await db.setSetting('token', data.token);
        await db.setSetting('identity', identity.value.trim());
        await db.setSetting('auth_expired', false);
        go('#/');
      } catch (err) {
        error.replaceChildren(notice('bad', err instanceof OfflineError ? 'No connection. Sign in once with signal, for example on the depot Wi-Fi.' : err.message));
        error.hidden = false;
      } finally {
        submit.disabled = false;
        submit.classList.remove('busy');
      }
    },
  },
  field('Email or phone', identity),
  field('Password', password),
  error, submit);

  screen({ title: 'Field Distribution' },
    h('div', { class: 'brand' },
      h('span', { class: 'brand-mark' }, icon('sack', 36)),
      h('h1', {}, 'Hand over inputs, with or without signal.'),
      h('p', { class: 'muted' }, 'Sign in with your Fleetbase account. You need signal only to sign in and to download a distribution.')),
    form);
  identity.focus();
}

// ---------------------------------------------------------------------------
// Home: sync status, your distributions, downloads

async function renderHome() {
  const s = await syncStatus();
  const bundles = await db.all('bundles');
  const outbox = await db.all('outbox');
  const user = await db.setting('identity');
  const expired = await db.setting('auth_expired');
  const waiting = s.pending + s.mediaPending;

  // The sync state, said plainly.
  let state;
  if (!navigator.onLine) {
    state = { tone: 'warn', iconName: 'cloudOff', title: waiting ? `${plural(waiting, 'item')} saved on this phone` : 'Offline', text: 'Everything you record is kept on this phone and sent when there is signal.' };
  } else if (waiting) {
    state = { tone: 'info', iconName: 'sync', title: `${plural(s.pending, 'record')} and ${plural(s.mediaPending, 'photo')} to send`, text: s.lastError ? `Last try: ${s.lastError}` : 'Sending by itself while there is signal.' };
  } else {
    state = { tone: 'ok', iconName: 'check', title: 'Everything is sent', text: s.lastSync ? `Last sync ${time(s.lastSync)}` : 'Nothing to send yet.' };
  }

  const syncCard = h('section', { class: 'card' },
    h('div', { class: 'status-card' },
      h('span', { class: `status-icon ${state.tone}` }, icon(state.iconName, 24)),
      h('div', { class: 'grow' }, h('h2', {}, state.title), h('p', { class: 'muted small' }, state.text)),
      navigator.onLine ? button('Sync now', { variant: 'secondary', iconName: 'sync', onClick: async (e) => {
        const b = e.currentTarget;
        b.disabled = true;
        const r = await syncNow();
        toast(r.skipped === 'offline' ? 'No signal. Records are safe on this phone.' : r.error ? `Sync stopped: ${r.error}` : `Sent ${plural(r.sent, 'record')} and ${plural(r.media, 'photo')}.`);
        render();
      } }) : null),
    expired ? notice('bad', 'Your sign-in has expired. Sign in again to send your records. They are safe on this phone.', button('Sign in', { variant: 'secondary', onClick: () => go('#/login') })) : null,
    s.conflicts.length ? notice('bad',
      h('strong', {}, `${plural(s.conflicts.length, 'record')} ${s.conflicts.length === 1 ? 'needs' : 'need'} a supervisor's review`),
      h('ul', {}, s.conflicts.map((c) => h('li', {}, `${c.label || c.payload.client_handover_id}: ${c.result?.message || ''}`)))) : null,
    s.mediaFailed ? notice('warn', `${plural(s.mediaFailed, 'photo')} could not be uploaded. Keep the phone; a supervisor can help.`) : null,
    waitingNotices(outbox));

  const yours = bundles.length
    ? bundles.map((b) => {
      const assigned = b.beneficiaries.length;
      const served = b.beneficiaries.filter(servedLocally).length;
      const pendingHere = outbox.filter((r) => r.event === b.event.id && r.status === 'pending').length;
      return h('article', { class: 'card dist' },
        h('button', { type: 'button', class: 'dist-open', 'aria-label': `${b.event.name}: ${served} of ${assigned} served`, onclick: () => go(`#/event/${b.event.id}`) },
          h('div', { class: 'row between top' },
            h('div', { class: 'grow' }, h('h2', {}, b.event.name), h('p', { class: 'muted small' }, `${b.event.kind === 'route' ? 'Door-to-door route' : b.site.name}${b.event.scheduled_date ? ' · ' + b.event.scheduled_date : ''}`)),
            icon('chevron', 24)),
          h('div', { class: 'row between wrap' }, dayPill(b) || h('span', {}), h('span', { class: 'small' }, h('strong', {}, `${served}`), ` of ${assigned} served`)),
          progress(served, assigned)),
        h('div', { class: 'dist-foot row between' },
          h('span', { class: 'small muted' }, pendingHere ? `${plural(pendingHere, 'record')} to send first` : `Downloaded ${time(b.downloaded_at)}`),
          button('Refresh', { variant: 'ghost', iconName: 'download', disabled: pendingHere > 0 || !navigator.onLine, onClick: () => download(b.event.id) })));
    })
    : [h('div', { class: 'card empty' }, icon('box', 32), h('p', {}, 'Nothing on this phone yet.'), h('p', { class: 'small' }, 'Download a distribution while on the depot Wi-Fi.'))];

  const available = h('div', {});
  if (!navigator.onLine) {
    available.append(h('p', { class: 'muted' }, 'Connect to the depot Wi-Fi to download a distribution.'));
  } else {
    available.append(h('p', { class: 'muted small' }, 'Looking for distributions…'));
    api('pod/field/events').then((data) => {
      const have = new Set(bundles.map((b) => b.event.id));
      const list = (data.events || []).filter((e) => !have.has(e.id));
      available.replaceChildren(list.length
        ? h('ul', { class: 'list' }, list.map((e) => h('li', {},
          h('div', { class: 'list-row' },
            h('span', { class: 'avatar' }, icon(e.kind === 'route' ? 'flag' : 'box', 20)),
            h('div', { class: 'grow' }, h('div', { class: 'row-title' }, e.name), h('div', { class: 'row-meta' }, `${e.programme?.name || ''}${e.scheduled_date ? ' · ' + e.scheduled_date : ''}`)),
            button('Download', { variant: 'secondary', iconName: 'download', onClick: () => download(e.id) })))))
        : h('p', { class: 'muted' }, 'No other open or planned distributions.'));
    }).catch((err) => available.replaceChildren(h('p', { class: 'muted' }, err.message)));
  }

  screen({ title: 'Field Distribution' },
    syncCard,
    h('h2', { class: 'section-title' }, 'Your distributions'), yours,
    h('h2', { class: 'section-title' }, 'Download for the field'), available,
    h('h2', { class: 'section-title' }, 'Account'),
    h('div', { class: 'card row between wrap' },
      h('div', { class: 'row' }, h('span', { class: 'avatar' }, icon('user', 20)), h('span', { class: 'small' }, user || '')),
      h('div', { class: 'row' },
        button('Review conflicts', { variant: 'ghost', iconName: 'shield', onClick: () => go('#/review') }),
        button('Sign out', { variant: 'ghost', iconName: 'logout', onClick: signOut }))));
  paintProgress();
}

async function download(eventId) {
  try {
    toast('Downloading…', 20000);
    const bundle = await api(`pod/events/${encodeURIComponent(eventId)}/bundle`, { timeout: 120000 });
    const previous = await db.get('bundles', eventId);
    bundle.downloaded_at = new Date().toISOString();
    if (previous?.local && bundle.event.status === 'planned') bundle.local = previous.local; // keep a join or opening not yet synced
    await db.put('bundles', bundle.event.id, bundle);
    if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(() => {});
    toast(`Downloaded: ${plural(bundle.beneficiaries.length, 'farmer')}. It works offline now.`);
    render();
  } catch (err) {
    toast(err.message);
  }
}

async function signOut() {
  const s = await syncStatus();
  if (s.pending || s.mediaPending) {
    toast('Send your records first: sync with signal, then sign out.');
    return;
  }
  await db.setSetting('token', null);
  go('#/login');
}

// ---------------------------------------------------------------------------
// The day state on this phone: planned, open, closing (closed here, waiting for
// the server) or closed. Other phones "join" an opening instead of counting again.

function dayState(bundle) {
  const local = bundle.local || {};
  if (bundle.event.status === 'closed') return 'closed';
  if (local.closing) return 'closing';
  if (bundle.event.status === 'open' || local.opened || local.joined) return 'open';
  return 'planned';
}

function dayPill(bundle) {
  const state = dayState(bundle);
  if (state === 'planned') return pill('Not opened yet', 'warn', 'clock');
  if (state === 'closing') return pill('Closed here, waiting to send', 'warn', 'sync');
  if (state === 'closed') return pill('Closed', 'ok', 'check');
  return null;
}

/** Pending records the server answered "not yet" for. */
function waitingNotices(outbox) {
  return outbox.filter((r) => r.status === 'pending' && r.result && ['deferred', 'unbalanced'].includes(r.result.status)).map((r) => {
    if (r.result.status === 'deferred') {
      return notice('info', `${r.label}: waiting for the day to be opened on the server (the opening phone has not synced yet). It will be sent again.`);
    }
    return notice('bad',
      h('strong', {}, `${r.label}: the count does not balance yet.`),
      h('ul', {}, (r.result.lines || []).map((l) => {
        const v = Number(l.variance);
        return h('li', {}, `${l.sku}${l.lot ? ` lot ${l.lot}` : ''}: ${v > 0 ? `${number(v)} fewer counted than recorded` : `${number(-v)} more counted than recorded`}`);
      })),
      h('p', { class: 'small' }, 'If other phones handed out from this stock, it will balance once they sync. Otherwise record what happened as damaged or missing stock; the closing is sent again by itself.'),
      button('Damaged or missing stock', { variant: 'secondary', onClick: () => go(`#/discrepancy/${r.event}`) }));
  });
}

// ---------------------------------------------------------------------------
// One distribution day: progress, farmers, cards

function remainingOf(entitlement) {
  return Math.max(0, Number(entitlement.quantity) - Number(entitlement.collected));
}

function servedLocally(b) {
  return b.entitlements.some((e) => Number(e.collected) > 0);
}

function farmerStatus(b) {
  const remaining = b.entitlements.reduce((sum, e) => sum + remainingOf(e), 0);
  const collected = b.entitlements.some((e) => Number(e.collected) > 0);
  const failed = b.handovers.filter((x) => x.outcome === 'failed').length;
  if (collected && remaining === 0) return pill('Collected', 'ok', 'check');
  if (collected) return pill('Part collected', 'warn');
  if (failed) return pill(`Visited ${failed}×`, 'warn');
  return pill('Not yet', 'neutral');
}

async function loadBundle(eventId) {
  const bundle = await db.get('bundles', eventId);
  if (!bundle) throw new Error('This distribution is not on this phone. Download it first.');
  return bundle;
}

async function renderEvent(eventId) {
  const bundle = await loadBundle(eventId);
  const state = dayState(bundle);
  const served = bundle.beneficiaries.filter(servedLocally).length;
  const assigned = bundle.beneficiaries.length;
  const message = h('div', { hidden: true });

  const showError = (text) => {
    message.replaceChildren(notice('bad', text));
    message.hidden = false;
    message.scrollIntoView({ block: 'nearest' });
  };

  const useCode = async (code) => {
    const result = await checkCard(code, bundle);
    if (!result.valid) return showError(result.message);
    scanned = { eventId, beneficiaryId: result.beneficiary.id, code: code.trim() };
    go(`#/handover/${eventId}/${result.beneficiary.id}`);
  };

  const codeInput = h('input', { type: 'text', placeholder: 'FBP1.…', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false' });
  const typed = h('form', { class: 'card stack', hidden: true, onsubmit: (e) => { e.preventDefault(); useCode(codeInput.value); } },
    field('Code printed under the QR', codeInput, 'Type it exactly as printed, with the dots.'),
    button('Check card', { variant: 'secondary', type: 'submit', block: true }));

  const search = h('input', { type: 'search', placeholder: 'Name, village or ID', 'aria-label': 'Search farmers' });
  const list = h('ul', { class: 'list' });
  const draw = () => {
    const q = search.value.trim().toLowerCase();
    const rows = bundle.beneficiaries
      .filter((b) => !q || [b.name, b.village, b.id, b.farmer_ref].some((v) => (v || '').toLowerCase().includes(q)))
      .sort((a, b) => Number(servedLocally(a)) - Number(servedLocally(b)) || a.name.localeCompare(b.name));
    list.replaceChildren(...(rows.length ? rows.map((b) => farmerRow(bundle, eventId, b, state)) : [h('li', { class: 'empty' }, 'No farmers match.')]));
  };
  search.addEventListener('input', draw);
  draw();

  const hero = h('section', { class: 'card' },
    h('div', { class: 'hero' }, h('span', { class: 'hero-number' }, String(served)), h('span', { class: 'hero-label' }, `of ${assigned} farmers served`)),
    progress(served, assigned),
    h('div', { class: 'row wrap' }, dayPill(bundle), pill(`${assigned - served} to go`, 'neutral')));

  let banner = null;
  let actions = null;
  if (state === 'planned') {
    banner = notice('warn', h('strong', {}, 'The day has not been opened yet.'),
      h('p', {}, 'Open it with a witnessed count of the stock here. If another phone at this distribution point already opened it, join instead. Do not count twice.'));
    actions = actionBar(null,
      button('Open the day', { variant: 'primary', iconName: 'open', onClick: () => go(`#/open/${eventId}`) }),
      button('Join: opened on another phone', { variant: 'secondary', onClick: async () => {
        bundle.local = { ...(bundle.local || {}), joined: true };
        await db.put('bundles', eventId, bundle);
        toastAfterNavigate('Joined. Your handovers will be accepted once the opening phone has synced.', 5000);
        render();
      } }));
  } else if (state === 'closing') {
    banner = notice('warn', h('p', {}, 'The day was closed on this phone. The closing count will be sent with the other records. If the office says it does not balance, record what happened.'),
      button('Damaged or missing stock', { variant: 'secondary', onClick: () => go(`#/discrepancy/${eventId}`) }));
  } else if (state === 'closed') {
    banner = notice('ok', 'This day is closed.');
  } else {
    actions = actionBar(null,
      button('Scan card', { variant: 'primary', iconName: 'scan', onClick: () => scan(useCode).catch((err) => showError(err.message)) }),
      button('Type code', { variant: 'secondary', iconName: 'keyboard', onClick: () => { typed.hidden = !typed.hidden; if (!typed.hidden) codeInput.focus(); } }));
  }

  const endOfDay = state === 'open' ? [
    h('h2', { class: 'section-title' }, 'During and at the end of the day'),
    h('div', { class: 'card stack' },
      button('Damaged or missing stock', { variant: 'secondary', iconName: 'box', block: true, onClick: () => go(`#/discrepancy/${eventId}`) }),
      button('Close the day', { variant: 'danger', iconName: 'flag', block: true, onClick: () => go(`#/close/${eventId}`) }),
      bundle.local?.joined && !bundle.local?.opened ? h('p', { class: 'hint' }, 'This phone joined the day. Usually the phone that opened it does the closing count.') : null),
  ] : [];

  screen({ title: bundle.event.name, subtitle: bundle.event.kind === 'route' ? 'Door-to-door route' : bundle.site.name, back: '#/', actions },
    hero, banner, message, typed,
    h('h2', { class: 'section-title' }, 'Farmers'),
    h('div', { class: 'search' }, icon('search', 20), search),
    list, endOfDay);
  paintProgress();
}

function farmerRow(bundle, eventId, b, state) {
  const done = b.entitlements.length > 0 && b.entitlements.every((e) => remainingOf(e) === 0);
  const meta = [b.village, sexLabel(b.sex), b.farmer_ref].filter(Boolean).join(' · ');
  if (bundle.event.kind === 'route' && state === 'open') {
    return h('li', {},
      h('div', { class: 'list-row' },
        h('span', { class: `avatar${done ? ' ok' : ''}` }, done ? icon('check', 18) : initials(b.name)),
        h('div', { class: 'grow' }, h('div', { class: 'row-title' }, b.name), h('div', { class: 'row-meta' }, meta), farmerStatus(b))),
      h('div', { class: 'row', style: null },
        button('Deliver', { variant: 'secondary', onClick: () => { scanned = null; go(`#/handover/${eventId}/${b.id}`); } }),
        button('Not delivered', { variant: 'ghost', onClick: () => go(`#/attempt/${eventId}/${b.id}`) })));
  }
  return h('li', {},
    h('button', { type: 'button', class: 'list-row', onclick: () => { scanned = null; go(`#/handover/${eventId}/${b.id}`); } },
      h('span', { class: `avatar${done ? ' ok' : ''}` }, done ? icon('check', 18) : initials(b.name)),
      h('span', { class: 'grow' }, h('span', { class: 'row-title' }, b.name), h('br'), h('span', { class: 'row-meta' }, meta)),
      farmerStatus(b)));
}

// ---------------------------------------------------------------------------
// Handover: three steps, then record

async function renderHandover(eventId, beneficiaryId) {
  const bundle = await loadBundle(eventId);
  const farmer = bundle.beneficiaries.find((b) => b.id === beneficiaryId);
  if (!farmer) throw new Error('This farmer is not on this distribution\'s list.');

  const items = Object.fromEntries(bundle.items.map((i) => [i.sku, i]));
  const recalled = new Set((bundle.programme.recalled_lots || []).map((r) => `${r.sku}|${r.lot}`));
  const today = new Date().toISOString().slice(0, 10);
  const card = scanned && scanned.eventId === eventId && scanned.beneficiaryId === beneficiaryId ? scanned : null;
  const back = `#/event/${eventId}`;

  const header = h('section', { class: 'card' },
    h('div', { class: 'row top' },
      h('span', { class: 'avatar' }, initials(farmer.name)),
      h('div', { class: 'grow' },
        h('h1', {}, farmer.name),
        h('p', { class: 'muted' }, [sexLabel(farmer.sex), farmer.birth_year ? `born ${farmer.birth_year}` : '', farmer.village, farmer.farmer_ref].filter(Boolean).join(' · ')),
        card ? pill('Card checked', 'ok', 'check') : pill('No card', 'warn'))));

  const open_ = farmer.entitlements.filter((e) => remainingOf(e) > 0 && ['open', 'partially_collected'].includes(e.status));
  if (!open_.length) {
    screen({ title: farmer.name, back }, header, notice('ok', 'This farmer has collected everything for this distribution.'));
    return;
  }
  if (dayState(bundle) !== 'open') {
    screen({ title: farmer.name, back }, header, notice('warn', dayState(bundle) === 'planned' ? 'The day has not been opened yet, so nothing can be handed over.' : 'The day has been closed on this phone, so nothing more can be handed over.'));
    return;
  }

  const f = { faceMatched: false, idLast4: '', idMatched: false, witnessConfirmed: false, receivedBy: 'self', recipient: '', photos: [], sigType: 'signature', witnessName: '', witnessRole: 'chief', safety: false, lines: {} };

  // --- Step 1: who --------------------------------------------------------------
  const idResult = h('span', {});
  const who = [];
  if (card) {
    who.push(
      h('label', { class: 'check' }, h('input', { type: 'checkbox', onchange: (e) => { f.faceMatched = e.target.checked; update(); } }), h('span', {}, 'I have checked this is the farmer named on the card.')),
      field('Last 4 characters of their ID (optional)', h('input', { type: 'text', maxlength: '4', autocomplete: 'off', autocapitalize: 'characters', oninput: async (e) => {
        f.idLast4 = e.target.value;
        f.idMatched = await idLast4Matches(farmer, f.idLast4);
        idResult.replaceChildren(f.idLast4.length === 4 ? (f.idMatched ? pill('ID matches', 'ok', 'check') : pill('ID does not match', 'bad', 'x')) : '');
        update();
      } }), 'Raises the identity check to the strongest level.'), idResult);
  } else {
    who.push(
      notice('info', 'No card: take a photo of the farmer (step 3) and have a chief, group leader or extension officer confirm who they are and sign.'),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', onchange: (e) => { f.witnessConfirmed = e.target.checked; update(); } }), h('span', {}, 'A witness confirms this is the farmer.')));
  }
  const recipientInput = h('input', { type: 'text', placeholder: 'Full name', oninput: (e) => { f.recipient = e.target.value; update(); } });
  const recipientField = field('Name of the person collecting', recipientInput);
  recipientField.hidden = true;
  const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Collected by' });
  for (const [value, label] of [['self', 'Farmer'], ['household_member', 'Household'], ['proxy', 'Proxy']]) {
    seg.append(h('button', { type: 'button', 'aria-pressed': String(value === 'self'), onclick: (e) => {
      f.receivedBy = value;
      seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === e.currentTarget)));
      recipientField.hidden = value === 'self';
      update();
    } }, label));
  }
  const step1 = step(1, 'Who is collecting', who, h('div', {}, h('span', { class: 'label' }, 'Collected by'), seg), recipientField);

  // --- Step 2: items --------------------------------------------------------------
  const itemRows = [];
  for (const e of open_) {
    const item = items[e.sku] || { name: e.sku, restricted: false };
    const remaining = remainingOf(e);
    const lots = bundle.stock.filter((s) => s.sku === e.sku && Number(s.quantity) > 0);
    const usable = lots.filter((s) => !recalled.has(`${s.sku}|${s.lot}`) && !(s.expiry && s.expiry < today));
    const blocked = item.restricted && !farmer.trained;
    const line = { qty: blocked || !usable.length ? 0 : remaining, lot: usable[0]?.lot ?? null, remaining, unit: e.unit, restricted: !!item.restricted };
    f.lines[e.sku] = line;

    const out = h('output', {}, number(line.qty));
    const stepQty = (delta) => {
      line.qty = Math.min(remaining, Math.max(0, line.qty + delta));
      out.textContent = number(line.qty);
      update();
    };
    const lotSelect = h('select', { 'aria-label': `Lot for ${item.name}`, onchange: (ev) => { line.lot = ev.target.value; update(); } },
      lots.map((s) => {
        const why = recalled.has(`${s.sku}|${s.lot}`) ? ' (recalled: do not use)' : s.expiry && s.expiry < today ? ` (expired ${s.expiry})` : '';
        return h('option', { value: s.lot, disabled: !!why, selected: s.lot === line.lot }, `Lot ${s.lot || '–'} · ${number(s.quantity)} left${why}`);
      }));

    itemRows.push(h('div', { class: 'line-item' },
      h('div', { class: 'row between top' }, h('h3', {}, item.name), h('span', { class: 'small muted' }, `${plural(remaining, e.unit)} to collect`)),
      blocked ? notice('bad', 'Restricted product: this farmer has no recorded safety training, so it cannot be handed over.')
        : !usable.length ? notice('bad', 'None of this item is usable in stock here.')
          : h('div', { class: 'line-controls' },
            h('div', { class: 'stepper' }, h('button', { type: 'button', 'aria-label': `One less: ${item.name}`, onclick: () => stepQty(-1) }, icon('minus', 20)), out, h('button', { type: 'button', 'aria-label': `One more: ${item.name}`, onclick: () => stepQty(1) }, icon('plus', 20))),
            lotSelect)));
  }
  const step2 = step(2, 'Items', itemRows);

  // --- Step 3: proof --------------------------------------------------------------
  const thumbs = h('div', { class: 'thumbs' });
  const photoInput = h('input', { type: 'file', accept: 'image/*', capture: 'environment', hidden: true, onchange: async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    try {
      const photo = await compressPhoto(file);
      photo.url = URL.createObjectURL(photo.blob);
      f.photos.push(photo);
      thumbs.append(h('img', { src: photo.url, alt: `Photo ${f.photos.length}` }));
      update();
    } catch (err) {
      toast(err.message);
    }
  } });

  const sigCanvas = h('canvas', { class: 'pad', 'aria-label': 'Signature or mark' });
  const sigSeg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Signature or mark' });
  for (const [value, label] of [['signature', 'Signs'], ['mark', 'Makes a mark']]) {
    sigSeg.append(h('button', { type: 'button', 'aria-pressed': String(value === 'signature'), onclick: (e) => {
      f.sigType = value;
      sigSeg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === e.currentTarget)));
      update();
    } }, label));
  }

  const witnessCanvas = h('canvas', { class: 'pad', 'aria-label': 'Witness signature' });
  const witnessBox = h('div', { class: 'stack', hidden: true },
    h('h3', {}, 'Witness'),
    field('Witness name', h('input', { type: 'text', oninput: (e) => { f.witnessName = e.target.value; update(); } })),
    field('Role', h('select', { onchange: (e) => { f.witnessRole = e.target.value; } }, (bundle.witness_roles || Object.keys(WITNESS_ROLE_LABELS)).map((r) => h('option', { value: r }, WITNESS_ROLE_LABELS[r] || r)))),
    h('span', { class: 'label' }, 'Witness signs here'), h('div', { class: 'pad-wrap' }, witnessCanvas, h('span', { class: 'pad-hint' }, 'Sign here')),
    button('Clear', { variant: 'ghost', onClick: () => witnessPad.clear() }));

  const safetyBox = h('label', { class: 'check', hidden: true },
    h('input', { type: 'checkbox', onchange: (e) => { f.safety = e.target.checked; update(); } }),
    h('span', {}, 'Safety briefing given: store away from children, food and water; use the right dose; wear the gloves and mask; do not reuse empty containers.'));

  const step3 = step(3, 'Proof',
    button('Take photo of farmer with the inputs', { variant: 'secondary', iconName: 'camera', block: true, onClick: () => photoInput.click() }), photoInput, thumbs,
    h('span', { class: 'label' }, 'The person collecting'), sigSeg,
    h('div', { class: 'pad-wrap' }, sigCanvas, h('span', { class: 'pad-hint' }, 'Sign or mark here')),
    button('Clear', { variant: 'ghost', onClick: () => sigPad.clear() }),
    safetyBox, witnessBox);

  const status = h('div', {});
  const save = button('Record handover', { variant: 'primary', disabled: true });

  screen({ title: farmer.name, subtitle: bundle.event.name, back, actions: actionBar(status, save) },
    header, step1.el, step2.el, step3.el);

  const sigPad = new SignaturePad(sigCanvas);
  const witnessPad = new SignaturePad(witnessCanvas);
  sigPad.onchange = update;
  witnessPad.onchange = update;

  function chosenLines() {
    return Object.entries(f.lines).filter(([, l]) => l.qty > 0 && l.lot !== null).map(([sku, l]) => ({ sku, lot: l.lot, quantity: l.qty, remaining: l.remaining, restricted: l.restricted }));
  }

  function current() {
    const lines = chosenLines();
    const level = identityLevel({ cardValid: !!card, faceMatched: f.faceMatched, idMatched: f.idMatched, photoTaken: f.photos.length > 0, witnessConfirmed: f.witnessConfirmed });
    const restricted = lines.some((l) => l.restricted);
    const proxy = f.receivedBy === 'proxy';
    const policy = buildPolicy(bundle.programme.handover_policy, { proxy, level, restricted });

    const evidence = [];
    if (level) evidence.push({ type: 'beneficiary_id', level, method: card ? 'card_qr' : 'witness' });
    for (const l of lines) {
      evidence.push({ type: 'line_quantities', line: l.sku, quantity: l.quantity });
      if (l.lot) evidence.push({ type: 'lot_scan', line: l.sku, lot: l.lot });
    }
    f.photos.forEach(() => evidence.push({ type: 'photo' }));
    if (!sigPad.isEmpty()) evidence.push({ type: f.sigType });
    if (!witnessPad.isEmpty() && f.witnessName.trim()) evidence.push({ type: 'witness_signature', name: f.witnessName.trim() });
    if (f.safety) evidence.push({ type: 'safety_ack' });

    const result = evaluate(policy, evidence, { lines: lines.map((l) => ({ id: l.sku, entitled: l.remaining })) });
    const whoDone = !!level && (f.receivedBy === 'self' || f.recipient.trim() !== '') && !result.missing.some((m) => m.type === 'witness_signature' && card && !proxy);
    const problems = [];
    if (!level) problems.push(card ? 'Tick that you have checked this is the farmer' : 'Take a photo and have a witness confirm who the farmer is');
    if (f.receivedBy !== 'self' && !f.recipient.trim()) problems.push('Write the name of the person collecting');
    if (!lines.length) problems.push('Choose at least one item to hand over');
    for (const m of result.missing) if (m.type !== 'beneficiary_id') problems.push(describe(m));

    return { lines, level, restricted, proxy, ok: problems.length === 0, problems: [...new Set(problems)], whoDone, proofDone: result.missing.every((m) => ['beneficiary_id', 'line_quantities', 'lot_scan'].includes(m.type)) };
  }

  function update() {
    const c = current();
    witnessBox.hidden = !(c.proxy || c.level === 'L1' || !card);
    safetyBox.hidden = !c.restricted;
    step1.setDone(c.whoDone);
    step2.setDone(c.lines.length > 0);
    step3.setDone(c.proofDone && f.photos.length > 0);
    if (c.ok) status.replaceChildren(h('span', { class: 'row' }, icon('check', 18), h('strong', {}, `Ready to record · identity ${c.level}`)));
    else stillNeeded(status, c.problems);
    save.disabled = !c.ok;
  }
  update();

  save.addEventListener('click', async () => {
    const c = current();
    if (!c.ok) return;
    save.disabled = true;
    save.classList.add('busy');
    try {
      const device = await db.deviceId();
      const clientId = `${device}-${String(await db.next('record_seq')).padStart(6, '0')}`;
      const position = await locate();

      const mediaWrites = [];
      const evidence = [];
      const addMedia = async (type, { blob, sha256 }, extra = {}) => {
        const id = `${device}-m${String(await db.next('media_seq')).padStart(6, '0')}`;
        mediaWrites.push(['media', id, { id, blob, sha256, kind: type, status: 'pending', client_handover_id: clientId }]);
        evidence.push({ type, media: id, sha256, ...extra });
      };
      for (const photo of f.photos) await addMedia('photo', photo);
      if (!sigPad.isEmpty()) await addMedia(f.sigType, await sigPad.toMedia());
      if (!witnessPad.isEmpty() && f.witnessName.trim()) await addMedia('witness_signature', await witnessPad.toMedia(), { name: f.witnessName.trim(), role: f.witnessRole });
      if (f.safety) evidence.push({ type: 'safety_ack' });

      const payload = {
        kind: 'handover', event: eventId, client_handover_id: clientId, beneficiary: farmer.id,
        card_code: card ? card.code : undefined,
        identity: { face_matched: f.faceMatched, id_last4: f.idMatched ? f.idLast4 : undefined, photo_taken: f.photos.length > 0, witness_confirmed: f.witnessConfirmed },
        received_by: f.receivedBy, recipient_name: f.receivedBy === 'self' ? undefined : f.recipient.trim(),
        lines: c.lines.map((l) => ({ sku: l.sku, lot: l.lot, quantity: l.quantity })),
        evidence, latitude: position?.latitude, longitude: position?.longitude, accuracy_m: position?.accuracy,
        captured_at: new Date().toISOString(),
      };

      // Update the phone's copy so the list and stock are right before syncing.
      for (const l of c.lines) {
        const e = farmer.entitlements.find((x) => x.sku === l.sku);
        e.collected = Number(e.collected) + l.quantity;
        e.status = remainingOf(e) <= 0 ? 'collected' : 'partially_collected';
        e.attempts = (e.attempts || 0) + 1;
        const s = bundle.stock.find((x) => x.sku === l.sku && x.lot === l.lot);
        if (s) s.quantity = Number(s.quantity) - l.quantity;
      }
      farmer.handovers.push({ id: clientId, outcome: 'delivered', at: payload.captured_at, local: true });

      await db.putMany([
        ...mediaWrites,
        ['outbox', clientId, { payload, status: 'pending', event: eventId, label: `${farmer.name}, ${time(payload.captured_at)}`, created_at: payload.captured_at }],
        ['bundles', eventId, bundle],
      ]);

      scanned = null;
      f.photos.forEach((p) => URL.revokeObjectURL(p.url));
      syncNow();
      go(`#/receipt/${clientId}`);
    } catch (err) {
      toast(`Not saved: ${err.message}`);
      save.disabled = false;
      save.classList.remove('busy');
    }
  });
}

function locate() {
  return new Promise((resolve) => {
    if (!navigator.geolocation) return resolve(null);
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ latitude: Number(p.coords.latitude.toFixed(7)), longitude: Number(p.coords.longitude.toFixed(7)), accuracy: Math.round(p.coords.accuracy) }),
      () => resolve(null),
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
  });
}

// ---------------------------------------------------------------------------
// Receipt: the moment of success

async function renderReceipt(clientId) {
  const record = await db.get('outbox', clientId);
  if (!record) return go('#/');
  const bundle = await db.get('bundles', record.event);
  const farmer = bundle?.beneficiaries.find((b) => b.id === record.payload.beneficiary);
  const items = Object.fromEntries((bundle?.items || []).map((i) => [i.sku, i]));
  const sent = record.status === 'synced';

  screen({ title: 'Recorded', back: `#/event/${record.event}`, actions: actionBar(null, button('Next farmer', { variant: 'primary', iconName: 'scan', onClick: () => go(`#/event/${record.event}`) })) },
    h('section', { class: 'success' },
      h('div', { class: 'success-mark' }, icon('check', 52)),
      h('h1', {}, 'Handover recorded'),
      h('p', { class: 'muted' }, farmer?.name || record.payload.beneficiary)),
    h('section', { class: 'card' },
      h('ul', { class: 'checklist' }, record.payload.lines.map((l) => {
        const item = items[l.sku];
        return h('li', {}, icon('check', 18), `${item ? plural(l.quantity, item.unit) : number(l.quantity)} · ${item?.name || l.sku}${l.lot ? ` (lot ${l.lot})` : ''}`);
      })),
      h('p', { class: 'small muted' }, `${clock(record.payload.captured_at)} · record ${clientId}`)),
    notice(sent ? 'ok' : 'info', sent ? 'Sent to the office.' : 'Safe on this phone. It will be sent when there is signal.'));
}

// ---------------------------------------------------------------------------
// Door-to-door: not delivered

async function renderAttempt(eventId, beneficiaryId) {
  const bundle = await loadBundle(eventId);
  if (dayState(bundle) !== 'open') throw new Error('The day is not open on this phone.');
  const farmer = bundle.beneficiaries.find((b) => b.id === beneficiaryId);
  if (!farmer) throw new Error('This farmer is not on this route.');

  const reason = h('select', {}, h('option', { value: '' }, 'Choose a reason'), (bundle.failure_reasons || Object.keys(FAILURE_LABELS)).map((r) => h('option', { value: r }, FAILURE_LABELS[r] || r)));
  const photos = [];
  const thumbs = h('div', { class: 'thumbs' });
  const photoInput = h('input', { type: 'file', accept: 'image/*', capture: 'environment', hidden: true, onchange: async (e) => {
    const file = e.target.files[0];
    e.target.value = '';
    if (!file) return;
    const photo = await compressPhoto(file);
    photos.push(photo);
    thumbs.append(h('img', { src: URL.createObjectURL(photo.blob), alt: 'Photo' }));
  } });
  const save = button('Record visit', { variant: 'primary' });

  save.addEventListener('click', async () => {
    if (!reason.value) return toast('Choose a reason.');
    save.disabled = true;
    const device = await db.deviceId();
    const clientId = `${device}-${String(await db.next('record_seq')).padStart(6, '0')}`;
    const position = await locate();
    const writes = [];
    const evidence = [];
    for (const photo of photos) {
      const id = `${device}-m${String(await db.next('media_seq')).padStart(6, '0')}`;
      writes.push(['media', id, { id, blob: photo.blob, sha256: photo.sha256, kind: 'photo', status: 'pending', client_handover_id: clientId }]);
      evidence.push({ type: 'photo', media: id, sha256: photo.sha256 });
    }
    const payload = { kind: 'attempt', event: eventId, client_handover_id: clientId, beneficiary: farmer.id, reason: reason.value, evidence, latitude: position?.latitude, longitude: position?.longitude, accuracy_m: position?.accuracy, captured_at: new Date().toISOString() };
    farmer.entitlements.forEach((e) => { e.attempts = (e.attempts || 0) + 1; });
    farmer.handovers.push({ id: clientId, outcome: 'failed', at: payload.captured_at, local: true });
    await db.putMany([...writes, ['outbox', clientId, { payload, status: 'pending', event: eventId, label: `${farmer.name} (not delivered)`, created_at: payload.captured_at }], ['bundles', eventId, bundle]]);
    syncNow();

    const attempts = Math.max(...farmer.entitlements.map((e) => e.attempts || 0));
    const max = bundle.programme.max_attempts || 2;
    toastAfterNavigate(['farmer_deceased', 'household_moved'].includes(reason.value)
      ? 'Recorded. The office will review this farmer\'s record.'
      : attempts >= max ? `Recorded. Visit ${attempts} of ${max}: the farmer will be moved to a distribution point.` : `Recorded (visit ${attempts} of ${max}).`, 6000);
    go(`#/event/${eventId}`);
  });

  screen({ title: 'Not delivered', subtitle: farmer.name, back: `#/event/${eventId}`, actions: actionBar(null, save) },
    h('section', { class: 'card stack' },
      h('h1', {}, farmer.name),
      h('p', { class: 'muted' }, farmer.village || ''),
      field('Why', reason),
      button('Take a photo (optional)', { variant: 'secondary', iconName: 'camera', block: true, onClick: () => photoInput.click() }), photoInput, thumbs));
}

// ---------------------------------------------------------------------------
// Opening and closing counts

/** A witness: name, role and signature. */
function witnessFields(bundle, label) {
  const name = h('input', { type: 'text', 'aria-label': `${label}: name` });
  const role = h('select', { 'aria-label': `${label}: role` }, (bundle.witness_roles || Object.keys(WITNESS_ROLE_LABELS)).map((r) => h('option', { value: r }, WITNESS_ROLE_LABELS[r] || r)));
  const canvas = h('canvas', { class: 'pad', 'aria-label': `${label}: signature` });
  const clear = button('Clear', { variant: 'ghost' });
  const box = h('div', { class: 'stack' },
    h('h3', {}, label),
    h('div', {}, h('span', { class: 'label' }, 'Name'), name),
    h('div', {}, h('span', { class: 'label' }, 'Role'), role),
    h('span', { class: 'label' }, 'Signs here'), h('div', { class: 'pad-wrap' }, canvas, h('span', { class: 'pad-hint' }, 'Sign here')), clear);
  const field_ = { box, name, role, pad: null };
  field_.attach = (onchange) => {
    field_.pad = new SignaturePad(canvas);
    field_.pad.onchange = onchange;
    name.addEventListener('input', onchange);
    clear.addEventListener('click', () => field_.pad.clear());
  };
  field_.complete = () => name.value.trim() !== '' && field_.pad && !field_.pad.isEmpty();
  field_.empty = () => name.value.trim() === '' && (!field_.pad || field_.pad.isEmpty());
  return field_;
}

async function renderCount(eventId, kind) {
  const bundle = await loadBundle(eventId);
  const state = dayState(bundle);
  if (kind === 'open' && state !== 'planned') throw new Error('This day is already open on this phone.');
  if (kind === 'close' && state !== 'open') throw new Error('Only an open day can be closed.');

  const items = Object.fromEntries(bundle.items.map((i) => [i.sku, i.name]));
  // Opening: what the site holds. Closing: what this phone expects is left.
  const lines = bundle.stock.map((s) => ({ sku: s.sku, lot: s.lot, expected: Number(s.quantity), counted: null }));

  const rows = lines.map((line) => {
    const diff = h('span', {});
    const input = h('input', { type: 'number', inputmode: 'decimal', min: '0', step: 'any', 'aria-label': `Counted: ${items[line.sku] || line.sku} lot ${line.lot}`, oninput: (e) => {
      line.counted = e.target.value === '' ? null : Number(e.target.value);
      const d = line.counted === null ? 0 : line.counted - line.expected;
      diff.replaceChildren(line.counted === null || d === 0 ? '' : pill(kind === 'open' ? (d > 0 ? 'More than the site holds' : `${number(d)} against the site`) : `${d > 0 ? '+' : ''}${number(d)} against this phone's records`, kind === 'open' && d > 0 ? 'bad' : 'warn'));
      update();
    } });
    return h('div', { class: 'line-item' },
      h('div', { class: 'row between top' }, h('h3', {}, items[line.sku] || line.sku), h('span', { class: 'small muted' }, `Lot ${line.lot || '–'}`)),
      h('div', { class: 'count-controls' }, input, h('span', { class: 'muted' }, kind === 'open' ? `site holds ${number(line.expected)}` : `records say ${number(line.expected)}`)),
      diff);
  });

  const w1 = witnessFields(bundle, 'Witness');
  const w2 = witnessFields(bundle, 'Second witness (optional)');
  const status = h('div', {});
  const save = button(kind === 'open' ? 'Record opening' : 'Record closing', { variant: 'primary', disabled: true });

  screen({ title: kind === 'open' ? 'Open the day' : 'Close the day', subtitle: bundle.event.name, back: `#/event/${eventId}`, actions: actionBar(status, save) },
    h('section', { class: 'card' },
      h('h1', {}, kind === 'open' ? 'Opening count' : 'Closing count'),
      h('p', { class: 'muted' }, kind === 'open'
        ? 'Count every item and lot at the distribution point before handing anything over. Enter 0 for anything not here.'
        : 'Count what is left of every item and lot. Handovers recorded on other phones are not in this phone\'s figures: they are counted when those phones sync.'),
      rows),
    h('section', { class: 'card stack' }, w1.box, w2.box));

  w1.attach(update);
  w2.attach(update);

  function problems() {
    const out = [];
    if (lines.some((l) => l.counted === null)) out.push('Enter a count for every line (0 if none)');
    if (lines.some((l) => l.counted !== null && (Number.isNaN(l.counted) || l.counted < 0))) out.push('Counts must be 0 or more');
    if (kind === 'open' && lines.some((l) => l.counted > l.expected)) out.push('A count is more than the site holds: recount, or ask the supervisor to record the delivery first');
    if (kind === 'open' && lines.every((l) => !l.counted)) out.push('Nothing counted');
    if (!w1.complete()) out.push('The witness gives their name and signs');
    if (!w2.empty() && !w2.complete()) out.push('The second witness gives their name and signs, or leave it empty');
    return out;
  }

  function update() {
    const p = problems();
    const off = kind === 'close' ? lines.filter((l) => l.counted !== null && l.counted !== l.expected) : [];
    save.disabled = p.length > 0;
    if (p.length) stillNeeded(status, p);
    else if (off.length) status.replaceChildren(h('span', { class: 'row' }, icon('alert', 18), `${plural(off.length, 'line')} ${off.length === 1 ? 'differs' : 'differ'} from this phone's records. Record damaged or missing stock first, unless other phones handed out from this stock.`));
    else status.replaceChildren(h('span', { class: 'row' }, icon('check', 18), h('strong', {}, 'Ready.')));
  }
  update();

  save.addEventListener('click', async () => {
    if (problems().length) return;
    save.disabled = true;
    save.classList.add('busy');
    try {
      const device = await db.deviceId();
      const clientId = `${device}-${kind}-${String(await db.next('record_seq')).padStart(6, '0')}`;
      const writes = [];
      const witnesses = [];
      for (const w of [w1, w2]) {
        if (!w.complete()) continue;
        const media = await w.pad.toMedia();
        const id = `${device}-m${String(await db.next('media_seq')).padStart(6, '0')}`;
        writes.push(['media', id, { id, blob: media.blob, sha256: media.sha256, kind: 'witness_signature', status: 'pending', client_handover_id: clientId }]);
        witnesses.push({ name: w.name.value.trim(), role: w.role.value, signature: id, sha256: media.sha256 });
      }
      const payload = { kind, event: eventId, client_handover_id: clientId, stock: lines.map((l) => ({ sku: l.sku, lot: l.lot, quantity: l.counted })), witnesses, captured_at: new Date().toISOString() };

      if (kind === 'open') {
        bundle.local = { ...(bundle.local || {}), opened: true, opened_at: payload.captured_at };
        bundle.stock = lines.filter((l) => l.counted > 0).map((l) => ({ sku: l.sku, lot: l.lot, quantity: l.counted, expiry: (bundle.stock.find((s) => s.sku === l.sku && s.lot === l.lot) || {}).expiry || null }));
      } else {
        bundle.local = { ...(bundle.local || {}), closing: true, closed_at: payload.captured_at };
      }

      await db.putMany([...writes, ['outbox', clientId, { payload, status: 'pending', event: eventId, label: `${kind === 'open' ? 'Opening' : 'Closing'} of ${bundle.event.name}`, created_at: payload.captured_at }], ['bundles', eventId, bundle]]);
      syncNow();
      toastAfterNavigate(kind === 'open' ? 'The day is open. You can hand over now.' : 'Closing recorded. It will be sent with the other records.', 5000);
      go(`#/event/${eventId}`);
    } catch (err) {
      toast(`Not saved: ${err.message}`);
      save.disabled = false;
      save.classList.remove('busy');
    }
  });
}

// ---------------------------------------------------------------------------
// Damaged or missing stock

async function renderDiscrepancy(eventId) {
  const bundle = await loadBundle(eventId);
  if (!['open', 'closing'].includes(dayState(bundle))) throw new Error('The day is not open on this phone.');
  const items = Object.fromEntries(bundle.items.map((i) => [i.sku, i.name]));

  const line = h('select', {}, bundle.stock.map((s, i) => h('option', { value: String(i) }, `${items[s.sku] || s.sku} · lot ${s.lot || '–'} · ${number(s.quantity)} left`)));
  const quantity = h('input', { type: 'number', min: '0', step: 'any', inputmode: 'decimal' });
  const reason = h('select', {}, h('option', { value: '' }, 'Choose'), Object.entries(DISCREPANCY_REASONS).map(([v, l]) => h('option', { value: v }, l)));
  const notes = h('textarea', { rows: '3', placeholder: 'What happened, who saw it' });
  const save = button('Record', { variant: 'primary' });

  save.addEventListener('click', async () => {
    const stock = bundle.stock[Number(line.value)];
    const qty = Number(quantity.value);
    if (!stock || !(qty > 0)) return toast('Enter how many.');
    if (qty > Number(stock.quantity)) return toast('That is more than this phone shows is left.');
    if (!reason.value) return toast('Choose what happened.');
    if (!notes.value.trim()) return toast('Explain what happened.');
    save.disabled = true;
    const device = await db.deviceId();
    const clientId = `${device}-d${String(await db.next('record_seq')).padStart(6, '0')}`;
    const payload = { kind: 'discrepancy', event: eventId, client_handover_id: clientId, sku: stock.sku, lot: stock.lot, quantity: qty, reason: reason.value, notes: notes.value.trim(), captured_at: new Date().toISOString() };
    stock.quantity = Number(stock.quantity) - qty;
    await db.putMany([['outbox', clientId, { payload, status: 'pending', event: eventId, label: `${DISCREPANCY_REASONS[reason.value]}: ${number(qty)} ${items[stock.sku] || stock.sku}`, created_at: payload.captured_at }], ['bundles', eventId, bundle]]);
    syncNow();
    toastAfterNavigate('Recorded.');
    go(`#/event/${eventId}`);
  });

  screen({ title: 'Damaged or missing stock', subtitle: bundle.event.name, back: `#/event/${eventId}`, actions: actionBar(null, save) },
    h('section', { class: 'card stack' },
      h('p', { class: 'muted' }, 'Torn, wet, spoilt, stolen or given out by mistake: it comes out of the stock and counts in the closing.'),
      field('Item and lot', line),
      field('How many', quantity),
      field('What happened', reason),
      field('Details', notes)));
}

// ---------------------------------------------------------------------------
// Supervisor: review records the server could not accept

const CONFLICT_HEADLINES = {
  already_collected: 'Farmer served twice',
  assigned_elsewhere: 'Farmer belongs to another distribution',
  card_revoked: 'A replaced card was used',
  already_opened: 'Day opened twice',
  already_closed: 'Closing after the day was closed',
  over_entitlement: 'More than the entitlement',
  not_trained: 'Restricted product without training',
  lot_expired: 'Expired lot handed over',
  lot_recalled: 'Recalled lot handed over',
  identity_insufficient: 'Identity not confirmed',
  proof_missing: 'Proof missing',
  event_not_found: 'Distribution not found',
};

const KIND_LABELS = { handover: 'Handover', attempt: 'Door-to-door visit', open: 'Opening count', close: 'Closing count', discrepancy: 'Missing stock' };

const RESOLUTIONS = {
  written_off: ['Record as missing stock', 'The goods are lost to the programme. They are recorded as missing stock on this day, against the officer.'],
  recovered: ['Goods taken back', 'The extra goods were returned. Nothing is recorded as missing.'],
  reviewed: ['Reviewed, nothing to record', 'You have checked what happened and the stock needs no change (for example, the loss was already recorded at closing).'],
};

async function renderReview(status) {
  if (!navigator.onLine) throw new Error('Reviewing conflicts needs signal: they are on the server.');
  const tabs = h('div', { class: 'seg', role: 'group', 'aria-label': 'Show' },
    ['open', 'resolved'].map((s) => h('button', { type: 'button', 'aria-pressed': String(s === status), onclick: () => go(s === 'open' ? '#/review' : `#/review/${s}`) }, s === 'open' ? 'To review' : 'Resolved')));

  const data = await api(`pod/conflicts?status=${status}`);
  const conflicts = data.conflicts || [];

  screen({ title: 'Review', subtitle: 'Records the server could not accept', back: '#/', wide: true },
    tabs,
    conflicts.length
      ? h('ul', { class: 'list' }, conflicts.map((c) => h('li', {},
        h('button', { type: 'button', class: 'list-row', onclick: () => go(`#/review/${c.id}`) },
          h('span', { class: `avatar${c.status === 'resolved' ? ' ok' : ''}` }, icon(c.status === 'resolved' ? 'check' : 'alert', 20)),
          h('span', { class: 'grow col' },
            h('span', { class: 'row-title' }, CONFLICT_HEADLINES[c.error] || KIND_LABELS[c.kind] || 'Record not accepted'),
            h('span', { class: 'row-meta' }, [c.farmer?.name, c.event?.name, time(c.captured_at)].filter(Boolean).join(' · ')),
            h('span', { class: 'row-pill' }, c.status === 'resolved' ? pill(RESOLUTIONS[c.resolution]?.[0] || 'Resolved', 'ok', 'check') : pill(KIND_LABELS[c.kind] || c.kind, 'warn'))),
          icon('chevron', 20)))))
      : h('div', { class: 'card empty' }, icon('shield', 36), h('h2', {}, status === 'open' ? 'Nothing to review' : 'Nothing resolved yet'), h('p', { class: 'small' }, status === 'open' ? 'Records that the server could not accept will appear here.' : '')));
}

async function renderConflict(id) {
  if (!navigator.onLine) throw new Error('Reviewing conflicts needs signal: they are on the server.');
  const c = (await api(`pod/conflicts/${encodeURIComponent(id)}`)).conflict;
  const linesList = (lines) => h('ul', { class: 'checklist' }, (lines || []).map((l) => h('li', {}, icon('box', 16), `${number(l.quantity)} × ${l.name || l.sku}${l.lot ? ` (lot ${l.lot})` : ''}`)));

  // The record from the phone.
  const photos = h('div', { class: 'photo-grid' });
  for (const e of c.evidence || []) {
    if (!e.media) continue;
    const img = h('img', { alt: `${e.type.replace(/_/g, ' ')}${e.name ? ': ' + e.name : ''}` });
    photos.append(h('figure', {}, img, h('figcaption', {}, `${e.type.replace(/_/g, ' ')}${e.name ? ' · ' + e.name : ''}${e.uploaded ? '' : ' (not uploaded yet)'}`)));
    if (e.uploaded) apiBlob(`pod/media/${encodeURIComponent(e.media)}`).then((blob) => { img.src = URL.createObjectURL(blob); }).catch(() => {});
  }

  const phoneRecord = h('section', { class: 'card' },
    h('h3', {}, icon('pen', 18), 'Recorded on the phone'),
    h('dl', { class: 'kv' },
      h('dt', {}, 'When'), h('dd', {}, time(c.captured_at)),
      h('dt', {}, 'Record'), h('dd', { class: 'mono' }, c.record),
      c.received_by ? [h('dt', {}, 'Collected by'), h('dd', {}, c.received_by === 'self' ? 'The farmer' : `${c.received_by.replace('_', ' ')}: ${c.recipient || ''}`)] : null),
    linesList(c.lines),
    photos.childElementCount ? photos : null);

  const existing = c.existing || {};
  const serverRecord = h('section', { class: 'card' },
    h('h3', {}, icon('cloud', 18), 'Already on the server'),
    existing.handovers?.length
      ? existing.handovers.map((x) => h('div', { class: 'stack' }, h('p', { class: 'small' }, `${x.outcome === 'failed' ? 'Visit' : 'Handover'} at ${time(x.captured_at)} · ${x.record || x.id}`), linesList(x.lines)))
      : existing.opening_stock ? [h('p', { class: 'small' }, 'Opening count'), linesList(existing.opening_stock), existing.closing_stock ? [h('p', { class: 'small' }, 'Closing count'), linesList(existing.closing_stock)] : null]
        : h('p', { class: 'muted small' }, 'Nothing for this farmer on this day.'));

  const top = h('section', { class: 'card' },
    h('div', { class: 'row between top wrap' },
      h('div', { class: 'grow' },
        h('h1', {}, CONFLICT_HEADLINES[c.error] || 'Record not accepted'),
        h('p', { class: 'muted' }, [c.farmer?.name, c.farmer?.village, c.event?.name].filter(Boolean).join(' · '))),
      c.status === 'resolved' ? pill('Resolved', 'ok', 'check') : pill(KIND_LABELS[c.kind] || c.kind, 'warn')),
    notice(c.status === 'resolved' ? 'ok' : 'warn', c.message));

  if (c.status === 'resolved') {
    screen({ title: 'Conflict', back: '#/review', wide: true }, top,
      h('section', { class: 'card' }, h('h3', {}, RESOLUTIONS[c.resolution]?.[0] || c.resolution), h('p', {}, c.resolution_notes), h('p', { class: 'small muted' }, `Resolved ${time(c.resolved_at)}`)),
      h('div', { class: 'compare' }, phoneRecord, serverRecord));
    return;
  }

  let choice = c.options[0] || null;
  const notes = h('textarea', { rows: '3', placeholder: 'What happened and what was done' });
  const options = h('fieldset', { class: 'card' }, h('legend', { class: 'visually-hidden' }, 'Decision'), h('h2', {}, 'Your decision'),
    c.options.map((o) => h('label', { class: 'option' },
      h('input', { type: 'radio', name: 'resolution', value: o, checked: o === choice, onchange: () => { choice = o; update(); } }),
      h('span', {}, h('strong', {}, RESOLUTIONS[o][0]), h('span', { class: 'small muted' }, RESOLUTIONS[o][1])))),
    field('Notes', notes, 'Required. Kept with the record for the audit.'));
  const error = h('div', {});
  const resolve = button('Resolve', { variant: 'primary', disabled: true });
  const update = () => { resolve.disabled = !choice || notes.value.trim().length < 10; };
  notes.addEventListener('input', update);
  update();

  resolve.addEventListener('click', async () => {
    resolve.disabled = true;
    resolve.classList.add('busy');
    try {
      await api(`pod/conflicts/${encodeURIComponent(id)}/resolve`, { method: 'POST', json: { resolution: choice, notes: notes.value.trim() } });
      toastAfterNavigate('Resolved.');
      go('#/review');
    } catch (err) {
      error.replaceChildren(notice('bad', err.message));
      resolve.classList.remove('busy');
      update();
    }
  });

  screen({ title: 'Conflict', subtitle: c.event?.name, back: '#/review', wide: true, actions: actionBar(null, resolve) },
    top, h('div', { class: 'compare' }, phoneRecord, serverRecord), options, error);
}
