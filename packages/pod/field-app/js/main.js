// Field Distribution: offline handovers of agricultural inputs.
// AGRI-INPUTS.md §7.2 (identity), §8 (proof), §13 (offline); DESIGN.md §6.6.

import * as db from './db.js';
import { api, ApiError, OfflineError } from './api.js';
import { checkCard, idLast4Matches } from './card.js';
import { buildPolicy, describe, evaluate, identityLevel } from './policy.js';
import { compressPhoto, SignaturePad } from './media.js';
import { scan } from './scanner.js';
import { onSyncChange, status as syncStatus, syncNow, isSyncing } from './sync.js';
import { h, mount, number, plural, setTitle, sexLabel, time, toast } from './ui.js';

// A card scanned or typed, kept in memory only (never in the URL or history).
let scanned = null;

const FAILURE_LABELS = {
  farmer_absent: 'Farmer not at home',
  household_moved: 'Household has moved',
  farmer_deceased: 'Farmer has died',
  refused: 'Refused the inputs',
  wrong_village: 'Wrong village',
  road_inaccessible: 'Road impassable',
  security_issue: 'Unsafe to visit',
  already_received: 'Says already received',
  other: 'Other reason',
};

const WITNESS_ROLE_LABELS = {
  chief: 'Chief', section_chief: 'Section chief', group_leader: 'Group leader', extension_officer: 'Extension officer',
  councillor: 'Councillor', community_leader: 'Community leader', other: 'Other',
};

// ---------------------------------------------------------------------------
// Start-up and routing

function go(route) {
  if (location.hash === route) render();
  else location.hash = route;
}

async function render() {
  const route = location.hash.replace(/^#/, '') || '/';
  const token = await db.setting('token');
  if (!token && route !== '/login') return go('#/login');

  const parts = route.split('/').filter(Boolean);
  try {
    if (parts[0] === 'login') return renderLogin();
    if (parts[0] === 'event' && parts[1]) return renderEvent(parts[1]);
    if (parts[0] === 'handover' && parts[1] && parts[2]) return renderHandover(parts[1], parts[2]);
    if (parts[0] === 'attempt' && parts[1] && parts[2]) return renderAttempt(parts[1], parts[2]);
    if (parts[0] === 'receipt' && parts[1]) return renderReceipt(parts[1]);
    if (parts[0] === 'open' && parts[1]) return renderCount(parts[1], 'open');
    if (parts[0] === 'close' && parts[1]) return renderCount(parts[1], 'close');
    if (parts[0] === 'discrepancy' && parts[1]) return renderDiscrepancy(parts[1]);
    return renderHome();
  } catch (error) {
    mount(h('div', { class: 'notice bad' }, error.message || 'Something went wrong.'), h('button', { onclick: () => go('#/') }, 'Home'));
  }
}

async function updateNet() {
  const el = document.getElementById('net');
  const s = await syncStatus();
  const waiting = s.pending + s.mediaPending;
  el.classList.toggle('offline', !navigator.onLine);
  el.textContent = (navigator.onLine ? (isSyncing() ? 'Syncing…' : 'Online') : 'Offline') + (waiting ? ` · ${waiting} to send` : '');
}

window.addEventListener('hashchange', render);
window.addEventListener('online', () => { updateNet(); syncNow(); });
window.addEventListener('offline', updateNet);
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
  setTitle('Field Distribution');
  const error = h('div', { class: 'notice bad', hidden: true });
  const identity = h('input', { type: 'text', id: 'identity', autocomplete: 'username', inputmode: 'email', required: true });
  const password = h('input', { type: 'password', id: 'password', autocomplete: 'current-password', required: true });
  const submit = h('button', { type: 'submit', class: 'primary block' }, 'Sign in');

  const form = h('form', {
    class: 'card stack',
    onsubmit: async (e) => {
      e.preventDefault();
      error.hidden = true;
      submit.disabled = true;
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
        error.textContent = err instanceof OfflineError ? 'No connection. Sign in once with signal (for example on depot Wi-Fi).' : err.message;
        error.hidden = false;
      } finally {
        submit.disabled = false;
      }
    },
  },
  h('h1', {}, 'Sign in'),
  h('p', { class: 'muted' }, 'Use your Fleetbase account. You only need signal to sign in and to download a distribution.'),
  h('label', { for: 'identity' }, 'Email or phone'), identity,
  h('label', { for: 'password' }, 'Password'), password,
  error, submit);

  mount(form);
  identity.focus();
}

// ---------------------------------------------------------------------------
// Home: sync status, distributions on this phone, downloads

async function renderHome() {
  setTitle('Field Distribution');
  const s = await syncStatus();
  const bundles = await db.all('bundles');
  const outbox = await db.all('outbox');
  const user = await db.setting('identity');
  const expired = await db.setting('auth_expired');

  const syncCard = h('section', { class: 'card' },
    h('div', { class: 'row between' },
      h('strong', {}, s.pending || s.mediaPending ? `${plural(s.pending, 'record')} and ${plural(s.mediaPending, 'photo')} to send` : 'Everything is sent'),
      h('button', { type: 'button', onclick: async (e) => { e.target.disabled = true; const r = await syncNow(); toast(r.skipped === 'offline' ? 'No signal. Records are safe on this phone.' : r.error ? `Sync stopped: ${r.error}` : `Sent ${r.sent} records and ${r.media} photos.`); render(); } }, 'Sync now')),
    h('p', { class: 'small muted' }, s.lastSync ? `Last sync ${time(s.lastSync)}` : 'Not synced yet', s.lastError ? ` · ${s.lastError}` : ''),
    expired ? h('div', { class: 'notice bad' }, 'Your sign-in has expired. Sign in again to send your records (they are safe on this phone).', h('div', {}, h('button', { type: 'button', onclick: () => go('#/login') }, 'Sign in'))) : null,
    s.conflicts.length ? h('div', { class: 'notice bad' },
      h('strong', {}, `${plural(s.conflicts.length, 'record')} ${s.conflicts.length === 1 ? 'needs' : 'need'} a supervisor's review`),
      h('ul', {}, s.conflicts.map((c) => h('li', {}, `${c.label || c.payload.client_handover_id}: ${c.result?.message || ''}`)))) : null,
    s.mediaFailed ? h('div', { class: 'notice' }, `${plural(s.mediaFailed, 'photo')} could not be uploaded. Keep the phone; a supervisor can help.`) : null,
    waitingNotices(outbox),
  );

  const onPhone = h('section', {},
    h('h2', {}, 'On this phone'),
    bundles.length ? h('ul', { class: 'list card' }, bundles.map((b) => {
      const assigned = b.beneficiaries.length;
      const served = b.beneficiaries.filter(servedLocally).length;
      const pendingHere = outbox.filter((r) => r.event === b.event.id && r.status === 'pending').length;
      return h('li', {},
        h('div', { class: 'row between wrap item-row' },
          h('div', { class: 'grow' },
            h('strong', {}, b.event.name),
            h('div', { class: 'small muted' }, `${b.event.kind === 'route' ? 'Door-to-door' : b.site.name} · ${served}/${assigned} served · downloaded ${time(b.downloaded_at)}`),
            dayChip(b)),
          h('div', { class: 'row' },
            h('button', { type: 'button', class: 'primary', onclick: () => go(`#/event/${b.event.id}`) }, 'Open'),
            h('button', { type: 'button', disabled: pendingHere > 0 || !navigator.onLine, title: pendingHere ? 'Send this distribution\'s records first' : '', onclick: () => download(b.event.id) }, 'Refresh'))));
    })) : h('p', { class: 'muted' }, 'Nothing downloaded yet.'));

  const available = h('section', {}, h('h2', {}, 'Download for the field'));
  if (!navigator.onLine) {
    available.append(h('p', { class: 'muted' }, 'Connect to the depot Wi-Fi to download a distribution.'));
  } else {
    available.append(h('p', { class: 'muted small' }, 'Loading…'));
    api('pod/field/events').then((data) => {
      const have = new Set(bundles.map((b) => b.event.id));
      const list = (data.events || []).filter((e) => !have.has(e.id));
      available.lastChild.replaceWith(list.length ? h('ul', { class: 'list card' }, list.map((e) => h('li', {},
        h('div', { class: 'row between wrap item-row' },
          h('div', { class: 'grow' }, h('strong', {}, e.name), h('div', { class: 'small muted' }, `${e.programme?.name || ''} · ${e.kind === 'route' ? 'door-to-door' : e.site?.name || ''}${e.scheduled_date ? ' · ' + e.scheduled_date : ''}`)),
          h('button', { type: 'button', class: 'primary', onclick: () => download(e.id) }, 'Download'))))) : h('p', { class: 'muted' }, 'No other open or planned distributions.'));
    }).catch((err) => {
      available.lastChild.replaceWith(h('p', { class: 'muted' }, err.message));
    });
  }

  const footer = h('p', { class: 'small muted' }, `Signed in as ${user || ''}. `,
    h('button', { type: 'button', class: 'danger', onclick: signOut }, 'Sign out'));

  mount(syncCard, onPhone, available, footer);
}

async function download(eventId) {
  try {
    toast('Downloading…');
    const bundle = await api(`pod/events/${encodeURIComponent(eventId)}/bundle`, { timeout: 120000 });
    bundle.downloaded_at = new Date().toISOString();
    await db.put('bundles', bundle.event.id, bundle);
    if (navigator.storage && navigator.storage.persist) navigator.storage.persist().catch(() => {});
    toast(`Downloaded: ${bundle.beneficiaries.length} farmers. It works offline now.`);
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
// One distribution: find farmers, scan cards

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
  if (collected && remaining === 0) return h('span', { class: 'chip ok' }, 'Collected');
  if (collected) return h('span', { class: 'chip warn' }, 'Part collected');
  if (failed) return h('span', { class: 'chip warn' }, `Visited ${failed}×`);
  return h('span', { class: 'chip' }, 'Not yet');
}

async function loadBundle(eventId) {
  const bundle = await db.get('bundles', eventId);
  if (!bundle) throw new Error('This distribution is not on this phone. Download it first.');
  return bundle;
}

async function renderEvent(eventId) {
  const bundle = await loadBundle(eventId);
  setTitle(bundle.event.name);

  const served = bundle.beneficiaries.filter(servedLocally).length;
  const assigned = bundle.beneficiaries.length;
  const message = h('div', { class: 'notice bad', hidden: true });

  const useCode = async (code) => {
    const result = await checkCard(code, bundle);
    if (!result.valid) {
      message.textContent = result.message;
      message.hidden = false;
      return;
    }
    scanned = { eventId, beneficiaryId: result.beneficiary.id, code: code.trim() };
    go(`#/handover/${eventId}/${result.beneficiary.id}`);
  };

  const codeInput = h('input', { type: 'text', id: 'code', placeholder: 'FBP1.…', autocomplete: 'off', autocapitalize: 'characters', spellcheck: 'false' });
  const typed = h('form', { class: 'card', hidden: true, onsubmit: (e) => { e.preventDefault(); useCode(codeInput.value); } },
    h('label', { for: 'code' }, 'Code printed under the QR'), codeInput,
    h('button', { type: 'submit', class: 'primary block mt' }, 'Check card'));

  const search = h('input', { type: 'search', placeholder: 'Search by name, village or ID', 'aria-label': 'Search farmers' });
  const list = h('ul', { class: 'list card' });
  const draw = () => {
    const q = search.value.trim().toLowerCase();
    const rows = bundle.beneficiaries
      .filter((b) => !q || [b.name, b.village, b.id, b.farmer_ref].some((v) => (v || '').toLowerCase().includes(q)))
      .sort((a, b) => Number(servedLocally(a)) - Number(servedLocally(b)) || a.name.localeCompare(b.name));
    list.replaceChildren(...(rows.length ? rows.map((b) => h('li', {},
      bundle.event.kind === 'route'
        ? h('div', { class: 'row between wrap route-row' },
          h('div', { class: 'grow' }, h('strong', {}, b.name), h('div', { class: 'small muted' }, [b.village, sexLabel(b.sex)].filter(Boolean).join(' · ')), farmerStatus(b)),
          h('div', { class: 'row' },
            h('button', { type: 'button', class: 'primary', onclick: () => { scanned = null; go(`#/handover/${eventId}/${b.id}`); } }, 'Deliver'),
            h('button', { type: 'button', onclick: () => go(`#/attempt/${eventId}/${b.id}`) }, 'Not delivered')))
        : h('button', { type: 'button', class: 'item', onclick: () => { scanned = null; go(`#/handover/${eventId}/${b.id}`); } },
          h('span', { class: 'grow' }, h('strong', {}, b.name), h('br'), h('span', { class: 'small muted' }, [b.village, sexLabel(b.sex), b.farmer_ref].filter(Boolean).join(' · '))),
          farmerStatus(b)))) : [h('li', { class: 'pad muted' }, 'No farmers match.')]));
  };
  search.addEventListener('input', draw);
  draw();

  mount(
    h('div', { class: 'row' }, h('button', { type: 'button', onclick: () => go('#/') }, '‹ Home')),
    dayActions(bundle, eventId),
    h('div', { class: 'stats' },
      h('div', { class: 'stat' }, h('b', {}, String(served)), 'served'),
      h('div', { class: 'stat' }, h('b', {}, String(assigned - served)), 'to go'),
      h('div', { class: 'stat' }, h('b', {}, String(assigned)), 'on list')),
    h('div', { class: 'row mt' },
      h('button', { type: 'button', class: 'primary grow', onclick: () => scan(useCode).catch((err) => { message.textContent = err.message; message.hidden = false; }) }, 'Scan card'),
      h('button', { type: 'button', onclick: () => { typed.hidden = !typed.hidden; if (!typed.hidden) codeInput.focus(); } }, 'Type code')),
    message, typed,
    h('h2', {}, 'Farmers'), search, list,
  );
}

// ---------------------------------------------------------------------------
// Handover

async function renderHandover(eventId, beneficiaryId) {
  const bundle = await loadBundle(eventId);
  const farmer = bundle.beneficiaries.find((b) => b.id === beneficiaryId);
  if (!farmer) throw new Error('This farmer is not on this distribution\'s list.');
  setTitle(farmer.name);

  const items = Object.fromEntries(bundle.items.map((i) => [i.sku, i]));
  const recalled = new Set((bundle.programme.recalled_lots || []).map((r) => `${r.sku}|${r.lot}`));
  const today = new Date().toISOString().slice(0, 10);
  const card = scanned && scanned.eventId === eventId && scanned.beneficiaryId === beneficiaryId ? scanned : null;
  const open = dayState(bundle) === 'open';

  const f = {
    faceMatched: false, idLast4: '', idMatched: false, witnessConfirmed: false,
    receivedBy: 'self', recipient: '', photos: [], sigType: 'signature', witnessName: '', witnessRole: 'chief', safety: false,
    lines: {},
  };

  const back = h('div', { class: 'row' }, h('button', { type: 'button', onclick: () => go(`#/event/${eventId}`) }, '‹ List'));
  const header = h('section', { class: 'card' },
    h('div', { class: 'row between' }, h('strong', { class: 'big' }, farmer.name), card ? h('span', { class: 'chip ok' }, 'Card checked') : h('span', { class: 'chip warn' }, 'No card')),
    h('div', { class: 'muted' }, [sexLabel(farmer.sex), farmer.birth_year ? `born ${farmer.birth_year}` : '', farmer.village, farmer.farmer_ref].filter(Boolean).join(' · ')),
    h('div', { class: 'mono muted' }, farmer.id));

  const open_ = farmer.entitlements.filter((e) => remainingOf(e) > 0 && ['open', 'partially_collected'].includes(e.status));
  if (!open_.length) {
    mount(back, header, h('div', { class: 'notice ok' }, 'This farmer has collected everything for this distribution.'));
    return;
  }
  if (!open) {
    mount(back, header, h('div', { class: 'notice' }, dayState(bundle) === 'planned' ? 'The day has not been opened yet, so nothing can be handed over.' : 'The day has been closed on this phone, so nothing more can be handed over.'));
    return;
  }

  // --- Who is collecting -------------------------------------------------------
  const identity = h('section', { class: 'card' }, h('h2', { class: 'first' }, 'Who is the farmer?'));
  const idResult = h('span', { class: 'small' });
  if (card) {
    identity.append(
      h('label', { class: 'check' }, h('input', { type: 'checkbox', onchange: (e) => { f.faceMatched = e.target.checked; update(); } }), h('span', {}, 'I have checked this is the farmer named on the card.')),
      h('label', { for: 'last4' }, 'Last 4 characters of their ID (optional)'),
      h('div', { class: 'row' },
        h('input', { type: 'text', id: 'last4', maxlength: '4', inputmode: 'text', autocomplete: 'off', class: 'short', oninput: async (e) => {
          f.idLast4 = e.target.value;
          f.idMatched = await idLast4Matches(farmer, f.idLast4);
          idResult.textContent = f.idLast4.length === 4 ? (f.idMatched ? '✓ matches' : '✗ does not match') : '';
          idResult.className = f.idMatched ? 'chip ok' : (f.idLast4.length === 4 ? 'chip bad' : 'small');
          update();
        } }), idResult));
  } else {
    identity.append(
      h('p', { class: 'muted small' }, 'No card: take a photo of the farmer (below) and have a chief, group leader or extension officer confirm who they are and sign.'),
      h('label', { class: 'check' }, h('input', { type: 'checkbox', onchange: (e) => { f.witnessConfirmed = e.target.checked; update(); } }), h('span', {}, 'A witness confirms this is the farmer.')));
  }

  const recipientInput = h('input', { type: 'text', id: 'recipient', placeholder: 'Full name', hidden: true, oninput: (e) => { f.recipient = e.target.value; update(); } });
  const seg = h('div', { class: 'seg', role: 'group', 'aria-label': 'Collected by' });
  for (const [value, label] of [['self', 'Farmer'], ['household_member', 'Household'], ['proxy', 'Proxy']]) {
    seg.append(h('button', { type: 'button', 'aria-pressed': String(value === 'self'), onclick: (e) => {
      f.receivedBy = value;
      seg.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === e.currentTarget)));
      recipientInput.hidden = value === 'self';
      update();
    } }, label));
  }
  identity.append(h('label', {}, 'Collected by'), seg, recipientInput);

  // --- Items ---------------------------------------------------------------------
  const itemsSection = h('section', { class: 'card' }, h('h2', { class: 'first' }, 'Items'));
  for (const e of open_) {
    const item = items[e.sku] || { name: e.sku, restricted: false };
    const remaining = remainingOf(e);
    const lots = bundle.stock.filter((s) => s.sku === e.sku && Number(s.quantity) > 0);
    const usable = lots.filter((s) => !recalled.has(`${s.sku}|${s.lot}`) && !(s.expiry && s.expiry < today));
    const blocked = item.restricted && !farmer.trained;

    const line = { qty: blocked || !usable.length ? 0 : remaining, lot: usable[0]?.lot ?? null, remaining, unit: e.unit, restricted: !!item.restricted };
    f.lines[e.sku] = line;

    const out = h('output', {}, number(line.qty));
    const step = (delta) => {
      line.qty = Math.min(remaining, Math.max(0, line.qty + delta));
      out.textContent = number(line.qty);
      update();
    };
    const lotSelect = h('select', { 'aria-label': `Lot for ${item.name}`, onchange: (ev) => { line.lot = ev.target.value; update(); } },
      lots.map((s) => {
        const why = recalled.has(`${s.sku}|${s.lot}`) ? ' (recalled: do not use)' : s.expiry && s.expiry < today ? ` (expired ${s.expiry})` : '';
        return h('option', { value: s.lot, disabled: !!why, selected: s.lot === line.lot }, `${s.lot || 'no lot'} · ${number(s.quantity)} left${why}`);
      }));

    itemsSection.append(h('div', { class: 'line' },
      h('div', { class: 'row between' }, h('strong', {}, item.name), h('span', { class: 'small muted' }, `${plural(remaining, e.unit)} to collect`)),
      blocked ? h('div', { class: 'notice bad' }, 'Restricted product: this farmer has no recorded safety training, so it cannot be handed over.')
        : !usable.length ? h('div', { class: 'notice bad' }, 'None of this item is usable in stock here.')
          : h('div', { class: 'row wrap mt-small' },
            h('div', { class: 'stepper' }, h('button', { type: 'button', 'aria-label': 'Less', onclick: () => step(-1) }, '−'), out, h('button', { type: 'button', 'aria-label': 'More', onclick: () => step(1) }, '+')),
            h('div', { class: 'grow' }, lotSelect))));
  }

  // --- Proof -----------------------------------------------------------------------
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
  const witnessBox = h('div', { hidden: true },
    h('h2', {}, 'Witness'),
    h('label', { for: 'wname' }, 'Witness name'),
    h('input', { type: 'text', id: 'wname', oninput: (e) => { f.witnessName = e.target.value; update(); } }),
    h('label', { for: 'wrole' }, 'Role'),
    h('select', { id: 'wrole', onchange: (e) => { f.witnessRole = e.target.value; } }, (bundle.witness_roles || Object.keys(WITNESS_ROLE_LABELS)).map((r) => h('option', { value: r }, WITNESS_ROLE_LABELS[r] || r))),
    h('label', {}, 'Witness signs here'), witnessCanvas,
    h('button', { type: 'button', onclick: () => witnessPad.clear() }, 'Clear'));

  const safetyBox = h('label', { class: 'check', hidden: true },
    h('input', { type: 'checkbox', onchange: (e) => { f.safety = e.target.checked; update(); } }),
    h('span', {}, 'Safety briefing given: store away from children, food and water; use the right dose; wear the gloves and mask; do not reuse empty containers.'));

  const proof = h('section', { class: 'card' },
    h('h2', { class: 'first' }, 'Proof'),
    h('button', { type: 'button', class: 'block', onclick: () => photoInput.click() }, 'Take photo of farmer with the inputs'), photoInput, thumbs,
    h('label', {}, 'The person collecting'), sigSeg, sigCanvas,
    h('button', { type: 'button', onclick: () => sigPad.clear() }, 'Clear'),
    safetyBox, witnessBox);

  const missingBox = h('div', { class: 'notice', role: 'status' });
  const save = h('button', { type: 'button', class: 'primary block', disabled: true }, 'Record handover');

  mount(back, header, identity, itemsSection, proof, h('div', { class: 'footer-actions' }, missingBox, save));

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
    const problems = [];
    if (!lines.length) problems.push('Choose at least one item to hand over');
    if (!level) problems.push(card ? 'Tick that you have checked this is the farmer' : 'Take a photo and have a witness confirm who the farmer is');
    if (f.receivedBy !== 'self' && !f.recipient.trim()) problems.push('Write the name of the person collecting');
    for (const m of result.missing) if (m.type !== 'beneficiary_id') problems.push(describe(m));

    return { lines, level, restricted, proxy, ok: problems.length === 0, problems: [...new Set(problems)] };
  }

  function update() {
    const c = current();
    witnessBox.hidden = !(c.proxy || c.level === 'L1' || !card);
    safetyBox.hidden = !c.restricted;
    missingBox.className = c.ok ? 'notice ok' : 'notice';
    missingBox.replaceChildren(c.ok ? `Ready: identity ${c.level}.` : h('span', {}, h('strong', {}, 'Still needed: '), c.problems.join('; ') + '.'));
    save.disabled = !c.ok;
  }
  update();

  save.addEventListener('click', async () => {
    const c = current();
    if (!c.ok) return;
    save.disabled = true;
    save.textContent = 'Saving…';
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
        kind: 'handover',
        event: eventId,
        client_handover_id: clientId,
        beneficiary: farmer.id,
        card_code: card ? card.code : undefined,
        identity: {
          face_matched: f.faceMatched,
          id_last4: f.idMatched ? f.idLast4 : undefined,
          photo_taken: f.photos.length > 0,
          witness_confirmed: f.witnessConfirmed,
        },
        received_by: f.receivedBy,
        recipient_name: f.receivedBy === 'self' ? undefined : f.recipient.trim(),
        lines: c.lines.map((l) => ({ sku: l.sku, lot: l.lot, quantity: l.quantity })),
        evidence,
        latitude: position?.latitude,
        longitude: position?.longitude,
        accuracy_m: position?.accuracy,
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
      save.textContent = 'Record handover';
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
// Door-to-door: not delivered

async function renderAttempt(eventId, beneficiaryId) {
  const bundle = await loadBundle(eventId);
  if (dayState(bundle) !== 'open') throw new Error('The day is not open on this phone.');
  const farmer = bundle.beneficiaries.find((b) => b.id === beneficiaryId);
  if (!farmer) throw new Error('This farmer is not on this route.');
  setTitle(farmer.name);

  const reason = h('select', { id: 'reason' }, h('option', { value: '' }, 'Choose a reason'), (bundle.failure_reasons || Object.keys(FAILURE_LABELS)).map((r) => h('option', { value: r }, FAILURE_LABELS[r] || r)));
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
  const save = h('button', { type: 'button', class: 'primary block' }, 'Record visit');

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
    const payload = {
      kind: 'attempt', event: eventId, client_handover_id: clientId, beneficiary: farmer.id, reason: reason.value, evidence,
      latitude: position?.latitude, longitude: position?.longitude, accuracy_m: position?.accuracy, captured_at: new Date().toISOString(),
    };
    farmer.entitlements.forEach((e) => { e.attempts = (e.attempts || 0) + 1; });
    farmer.handovers.push({ id: clientId, outcome: 'failed', at: payload.captured_at, local: true });
    await db.putMany([...writes, ['outbox', clientId, { payload, status: 'pending', event: eventId, label: `${farmer.name} (not delivered)`, created_at: payload.captured_at }], ['bundles', eventId, bundle]]);
    syncNow();

    const attempts = Math.max(...farmer.entitlements.map((e) => e.attempts || 0));
    const max = bundle.programme.max_attempts || 2;
    toast(['farmer_deceased', 'household_moved'].includes(reason.value)
      ? 'Recorded. The office will review this farmer\'s record.'
      : attempts >= max ? `Recorded. This was visit ${attempts} of ${max}: the farmer will be moved to a distribution point.` : `Recorded (visit ${attempts} of ${max}).`, 6000);
    go(`#/event/${eventId}`);
  });

  mount(
    h('div', { class: 'row' }, h('button', { type: 'button', onclick: () => go(`#/event/${eventId}`) }, '‹ Route')),
    h('section', { class: 'card stack' },
      h('h1', {}, 'Not delivered'),
      h('p', { class: 'muted' }, `${farmer.name} · ${farmer.village || ''}`),
      h('label', { for: 'reason' }, 'Why'), reason,
      h('button', { type: 'button', onclick: () => photoInput.click() }, 'Take a photo (optional)'), photoInput, thumbs,
      save));
}

// ---------------------------------------------------------------------------
// Receipt

async function renderReceipt(clientId) {
  const record = await db.get('outbox', clientId);
  if (!record) return go('#/');
  const bundle = await db.get('bundles', record.event);
  const farmer = bundle?.beneficiaries.find((b) => b.id === record.payload.beneficiary);
  const items = Object.fromEntries((bundle?.items || []).map((i) => [i.sku, i.name]));
  setTitle('Recorded');

  mount(
    h('section', { class: 'card stack' },
      h('div', { class: 'notice ok' }, h('strong', {}, 'Handover recorded.'), ' ', record.status === 'synced' ? 'Sent to the office.' : 'It is safe on this phone and will be sent when there is signal.'),
      h('p', {}, h('strong', {}, farmer?.name || record.payload.beneficiary)),
      h('ul', {}, record.payload.lines.map((l) => h('li', {}, `${number(l.quantity)} × ${items[l.sku] || l.sku}${l.lot ? ` (lot ${l.lot})` : ''}`))),
      h('p', { class: 'small muted' }, `${time(record.payload.captured_at)} · record ${clientId}`)),
    h('button', { type: 'button', class: 'primary block', onclick: () => go(`#/event/${record.event}`) }, 'Next farmer'));
}

// ---------------------------------------------------------------------------
// Opening and closing the day (AGRI-INPUTS.md §11), offline
//
// The phone's copy of a day is planned, open, closing (closed on this phone,
// waiting for the server) or closed. Other phones at the same distribution
// point "join" the opening instead of counting again; their handovers wait on
// the server until the opening phone has synced.

function dayState(bundle) {
  const local = bundle.local || {};
  if (bundle.event.status === 'closed') return 'closed';
  if (local.closing) return 'closing';
  if (bundle.event.status === 'open' || local.opened || local.joined) return 'open';
  return 'planned';
}

function dayChip(bundle) {
  const state = dayState(bundle);
  if (state === 'planned') return h('span', { class: 'chip warn' }, 'Not opened yet');
  if (state === 'closing') return h('span', { class: 'chip warn' }, 'Closed here, waiting to send');
  if (state === 'closed') return h('span', { class: 'chip ok' }, 'Closed');
  return null;
}

function dayActions(bundle, eventId) {
  const state = dayState(bundle);
  if (state === 'planned') {
    return h('section', { class: 'card stack' },
      h('strong', {}, 'The day has not been opened yet.'),
      h('p', { class: 'small muted' }, 'Open it with a witnessed count of the stock here. If another phone at this distribution point has already opened it, join instead: do not count twice.'),
      h('button', { type: 'button', class: 'primary block', onclick: () => go(`#/open/${eventId}`) }, 'Open the day'),
      h('button', { type: 'button', class: 'block', onclick: async () => {
        bundle.local = { ...(bundle.local || {}), joined: true };
        await db.put('bundles', eventId, bundle);
        toast('Joined. Your handovers will be accepted once the opening phone has synced.', 5000);
        render();
      } }, 'Join: opened on another phone'));
  }
  if (state === 'closing') {
    return h('div', { class: 'notice' },
      'The day was closed on this phone. The closing count will be sent with the other records. If the office says it does not balance, record what happened:',
      h('div', { class: 'mt' }, h('button', { type: 'button', onclick: () => go(`#/discrepancy/${eventId}`) }, 'Damaged or missing stock')));
  }
  if (state === 'closed') {
    return h('div', { class: 'notice ok' }, 'This day is closed.');
  }
  return h('div', {},
    h('div', { class: 'row wrap mt' },
      h('button', { type: 'button', class: 'grow', onclick: () => go(`#/discrepancy/${eventId}`) }, 'Damaged or missing stock'),
      h('button', { type: 'button', class: 'grow danger', onclick: () => go(`#/close/${eventId}`) }, 'Close the day')),
    bundle.local?.joined && !bundle.local?.opened ? h('p', { class: 'small muted' }, 'This phone joined the day. Usually the phone that opened it does the closing count.') : null);
}

/** Pending records the server answered "not yet" for: the day isn't open there yet, or a closing doesn't balance. */
function waitingNotices(outbox) {
  const waiting = outbox.filter((r) => r.status === 'pending' && r.result && ['deferred', 'unbalanced'].includes(r.result.status));
  if (!waiting.length) return null;

  return waiting.map((r) => {
    if (r.result.status === 'deferred') {
      return h('div', { class: 'notice' }, `${r.label}: waiting for the day to be opened on the server (the opening phone has not synced yet). It will be sent again.`);
    }
    const lines = (r.result.lines || []).map((l) => {
      const v = Number(l.variance);
      return h('li', {}, `${l.sku}${l.lot ? ` lot ${l.lot}` : ''}: ${v > 0 ? `${number(v)} fewer counted than recorded` : `${number(-v)} more counted than recorded`}`);
    });
    return h('div', { class: 'notice bad' },
      h('strong', {}, `${r.label}: the count does not balance yet.`),
      h('ul', {}, lines),
      h('p', { class: 'small' }, 'If other phones handed out from this stock, it will balance once they sync. Otherwise record what happened as damaged or missing stock; the closing is sent again by itself.'),
      h('button', { type: 'button', onclick: () => go(`#/discrepancy/${r.event}`) }, 'Damaged or missing stock'));
  });
}

/** A witness: name, role and signature. */
function witnessFields(bundle, label) {
  const name = h('input', { type: 'text', 'aria-label': `${label}: name` });
  const role = h('select', { 'aria-label': `${label}: role` }, (bundle.witness_roles || Object.keys(WITNESS_ROLE_LABELS)).map((r) => h('option', { value: r }, WITNESS_ROLE_LABELS[r] || r)));
  const canvas = h('canvas', { class: 'pad', 'aria-label': `${label}: signature` });
  const box = h('div', { class: 'stack' }, h('strong', {}, label), h('label', {}, 'Name'), name, h('label', {}, 'Role'), role, h('label', {}, 'Signs here'), canvas);
  const field = { box, name, role, pad: null };
  field.attach = (onchange) => {
    field.pad = new SignaturePad(canvas);
    field.pad.onchange = onchange;
    name.addEventListener('input', onchange);
    box.append(h('button', { type: 'button', onclick: () => field.pad.clear() }, 'Clear'));
  };
  field.complete = () => name.value.trim() !== '' && field.pad && !field.pad.isEmpty();
  field.empty = () => name.value.trim() === '' && (!field.pad || field.pad.isEmpty());
  return field;
}

/**
 * The opening count (stock at the distribution point now) or the closing count
 * (what's left), each witnessed and signed.
 */
async function renderCount(eventId, kind) {
  const bundle = await loadBundle(eventId);
  const state = dayState(bundle);
  if (kind === 'open' && state !== 'planned') throw new Error('This day is already open on this phone.');
  if (kind === 'close' && state !== 'open') throw new Error('Only an open day can be closed.');
  setTitle(kind === 'open' ? 'Open the day' : 'Close the day');

  const items = Object.fromEntries(bundle.items.map((i) => [i.sku, i.name]));
  // Opening: the stock the site holds. Closing: what this phone expects is left.
  const lines = bundle.stock.map((s) => ({ sku: s.sku, lot: s.lot, expected: Number(s.quantity), counted: null }));

  const rows = lines.map((line) => {
    const diff = h('span', { class: 'small' });
    const input = h('input', { type: 'number', inputmode: 'decimal', min: '0', step: 'any', 'aria-label': `Counted: ${items[line.sku] || line.sku} lot ${line.lot}`, oninput: (e) => {
      line.counted = e.target.value === '' ? null : Number(e.target.value);
      const d = line.counted === null ? 0 : line.counted - line.expected;
      diff.textContent = line.counted === null || d === 0 ? '' : kind === 'open' ? (d > 0 ? 'More than the site holds' : '') : `${d > 0 ? '+' : ''}${number(d)} against this phone's records`;
      diff.className = d === 0 ? 'small' : 'chip warn';
      update();
    } });
    return h('div', { class: 'line' },
      h('div', { class: 'row between' }, h('strong', {}, items[line.sku] || line.sku), h('span', { class: 'small muted' }, `lot ${line.lot || '–'}`)),
      h('div', { class: 'row wrap mt-small' },
        h('div', { class: 'grow' }, input),
        h('span', { class: 'small muted' }, kind === 'open' ? `site holds ${number(line.expected)}` : `records say ${number(line.expected)}`)),
      diff);
  });

  const w1 = witnessFields(bundle, 'Witness');
  const w2 = witnessFields(bundle, 'Second witness (optional)');
  const status = h('div', { class: 'notice', role: 'status' });
  const save = h('button', { type: 'button', class: 'primary block', disabled: true }, kind === 'open' ? 'Record opening' : 'Record closing');

  const intro = kind === 'open'
    ? 'Count every item and lot at the distribution point before handing anything over. Enter 0 for anything not here.'
    : 'Count what is left of every item and lot. Handovers recorded on other phones are not in this phone\'s figures: they are counted when those phones sync.';

  mount(
    h('div', { class: 'row' }, h('button', { type: 'button', onclick: () => go(`#/event/${eventId}`) }, '‹ Back')),
    h('section', { class: 'card' }, h('h1', {}, kind === 'open' ? 'Opening count' : 'Closing count'), h('p', { class: 'muted small' }, intro), rows),
    h('section', { class: 'card stack' }, w1.box, w2.box),
    h('div', { class: 'footer-actions' }, status, save));

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
    status.className = p.length ? 'notice' : off.length ? 'notice bad' : 'notice ok';
    status.replaceChildren(p.length
      ? h('span', {}, h('strong', {}, 'Still needed: '), p.join('; ') + '.')
      : off.length ? `${plural(off.length, 'line')} ${off.length === 1 ? 'differs' : 'differ'} from this phone's records. Record damaged or missing stock first, unless other phones handed out from this stock.`
        : 'Ready.');
    save.disabled = p.length > 0;
  }
  update();

  save.addEventListener('click', async () => {
    if (problems().length) return;
    save.disabled = true;
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
      const payload = {
        kind, event: eventId, client_handover_id: clientId,
        stock: lines.map((l) => ({ sku: l.sku, lot: l.lot, quantity: l.counted })),
        witnesses, captured_at: new Date().toISOString(),
      };

      if (kind === 'open') {
        bundle.local = { ...(bundle.local || {}), opened: true, opened_at: payload.captured_at };
        // From now on the phone works from the counted stock.
        bundle.stock = lines.filter((l) => l.counted > 0).map((l) => ({ sku: l.sku, lot: l.lot, quantity: l.counted, expiry: (bundle.stock.find((s) => s.sku === l.sku && s.lot === l.lot) || {}).expiry || null }));
      } else {
        bundle.local = { ...(bundle.local || {}), closing: true, closed_at: payload.captured_at };
      }

      await db.putMany([...writes, ['outbox', clientId, { payload, status: 'pending', event: eventId, label: `${kind === 'open' ? 'Opening' : 'Closing'} of ${bundle.event.name}`, created_at: payload.captured_at }], ['bundles', eventId, bundle]]);
      syncNow();
      toast(kind === 'open' ? 'The day is open. You can hand over now.' : 'Closing recorded. It will be sent with the other records.', 5000);
      go(`#/event/${eventId}`);
    } catch (err) {
      toast(`Not saved: ${err.message}`);
      save.disabled = false;
    }
  });
}

const DISCREPANCY_REASONS = {
  torn_bag: 'Torn bag', damaged: 'Damaged', wet: 'Wet or spoilt', spill_leak: 'Spilled or leaking', expired: 'Expired',
  theft: 'Stolen', count_error: 'Counting error', counterfeit_suspected: 'Looks fake or relabelled', other: 'Other',
};

/** Stock damaged, spoilt or missing during the day. */
async function renderDiscrepancy(eventId) {
  const bundle = await loadBundle(eventId);
  if (dayState(bundle) !== 'open' && dayState(bundle) !== 'closing') throw new Error('The day is not open on this phone.');
  setTitle('Damaged or missing stock');
  const items = Object.fromEntries(bundle.items.map((i) => [i.sku, i.name]));

  const line = h('select', { id: 'line' }, bundle.stock.map((s, i) => h('option', { value: String(i) }, `${items[s.sku] || s.sku} · lot ${s.lot || '–'} · ${number(s.quantity)} left`)));
  const quantity = h('input', { type: 'number', id: 'qty', min: '0', step: 'any', inputmode: 'decimal' });
  const reason = h('select', { id: 'why' }, h('option', { value: '' }, 'Choose'), Object.entries(DISCREPANCY_REASONS).map(([v, l]) => h('option', { value: v }, l)));
  const notes = h('textarea', { id: 'notes', rows: '3', placeholder: 'What happened, who saw it' });
  const save = h('button', { type: 'button', class: 'primary block' }, 'Record');

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
    toast('Recorded.');
    go(`#/event/${eventId}`);
  });

  mount(
    h('div', { class: 'row' }, h('button', { type: 'button', onclick: () => go(`#/event/${eventId}`) }, '‹ Back')),
    h('section', { class: 'card stack' },
      h('h1', {}, 'Damaged or missing stock'),
      h('label', { for: 'line' }, 'Item and lot'), line,
      h('label', { for: 'qty' }, 'How many'), quantity,
      h('label', { for: 'why' }, 'What happened'), reason,
      h('label', { for: 'notes' }, 'Details'), notes,
      save));
}
