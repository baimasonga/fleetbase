// Sends what was recorded offline whenever there is signal (DESIGN.md §6.6.3):
// first the records (small), then the photos and signatures (large).
//
// A record stays "pending" until the server has answered for it. If the answer
// is lost (signal drops mid-request), the record is simply sent again: the
// server recognises its id and answers "replayed" without recording it twice.

import { all, get, put, setSetting, setting } from './db.js';
import { api, ApiError, OfflineError } from './api.js';

const BATCH = 50;
const MAX_MEDIA_TRIES = 6;

let running = null;
const listeners = new Set();

export function onSyncChange(fn) {
  listeners.add(fn);
  return () => listeners.delete(fn);
}

function changed() {
  listeners.forEach((fn) => fn());
}

/** Run one sync now (or join the one already running). */
export function syncNow() {
  if (!running) {
    running = run().finally(() => {
      running = null;
      changed();
    });
  }
  return running;
}

export function isSyncing() {
  return running !== null;
}

async function run() {
  if (!navigator.onLine) return { skipped: 'offline' };
  if (!(await setting('token'))) return { skipped: 'signed_out' };

  const summary = { sent: 0, accepted: 0, replayed: 0, conflicts: 0, media: 0 };
  try {
    await sendRecords(summary);
    await sendMedia(summary);
    await setSetting('last_sync', new Date().toISOString());
    await setSetting('last_sync_error', null);
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      await setSetting('auth_expired', true);
    }
    await setSetting('last_sync_error', error instanceof OfflineError ? 'No connection' : error.message);
    summary.error = error.message;
  }
  return summary;
}

async function sendRecords(summary) {
  const pending = (await all('outbox'))
    .filter((record) => record.status === 'pending')
    .sort((a, b) => a.payload.captured_at.localeCompare(b.payload.captured_at));

  for (let i = 0; i < pending.length; i += BATCH) {
    const chunk = pending.slice(i, i + BATCH);
    const response = await api('pod/field/sync', { method: 'POST', json: { items: chunk.map((r) => r.payload) }, timeout: 60000 });
    if (response.server_time) await setSetting('clock_offset_ms', Date.parse(response.server_time) - Date.now());

    for (const result of response.results || []) {
      const record = chunk.find((r) => r.payload.client_handover_id === result.client_handover_id);
      if (!record) continue;
      record.result = result;
      record.synced_at = new Date().toISOString();
      // deferred: the day isn't open on the server yet; unbalanced: a closing count that
      // doesn't balance yet. Both stay pending and are sent again.
      if (result.status === 'deferred' || result.status === 'unbalanced') {
        record.status = 'pending';
      } else {
        record.status = result.status === 'accepted' || result.status === 'replayed' ? 'synced' : 'conflict';
      }
      if (record.status === 'synced' && ['open', 'close'].includes(record.payload.kind)) {
        await markDay(record.payload.event, record.payload.kind === 'open' ? 'open' : 'closed');
      }
      summary.sent++;
      if (result.status === 'accepted') summary.accepted++;
      if (result.status === 'replayed') summary.replayed++;
      if (result.status === 'deferred' || result.status === 'unbalanced') summary.waiting = (summary.waiting || 0) + 1;
      if (record.status === 'conflict') summary.conflicts++;
      await put('outbox', record.payload.client_handover_id, record);
    }
    changed();
  }
}

async function markDay(eventId, status) {
  const bundle = await get('bundles', eventId);
  if (bundle) {
    bundle.event.status = status;
    await put('bundles', eventId, bundle);
  }
}

async function sendMedia(summary) {
  const pending = (await all('media')).filter((m) => m.status === 'pending');

  for (const media of pending) {
    const form = new FormData();
    form.append('file', media.blob, `${media.id}.${media.blob.type === 'image/png' ? 'png' : 'jpg'}`);
    form.append('client_media_id', media.id);
    form.append('sha256', media.sha256);
    form.append('kind', media.kind);

    try {
      await api('pod/field/media', { method: 'POST', form, timeout: 120000 });
      // Uploaded and checked against its hash: the phone's copy can go, to save space.
      media.status = 'uploaded';
      media.blob = null;
      summary.media++;
    } catch (error) {
      if (error instanceof OfflineError || (error instanceof ApiError && (error.status === 401 || error.status >= 500))) throw error;
      media.tries = (media.tries || 0) + 1;
      media.last_error = error.message;
      if (error instanceof ApiError && error.status === 409) media.status = 'conflict';
      else if (media.tries >= MAX_MEDIA_TRIES) media.status = 'failed';
    }
    await put('media', media.id, media);
    changed();
  }
}

/** Counts for the status line. */
export async function status() {
  const outbox = await all('outbox');
  const media = await all('media');
  return {
    pending: outbox.filter((r) => r.status === 'pending').length,
    conflicts: outbox.filter((r) => r.status === 'conflict'),
    mediaPending: media.filter((m) => m.status === 'pending').length,
    mediaFailed: media.filter((m) => m.status === 'failed' || m.status === 'conflict').length,
    lastSync: await setting('last_sync'),
    lastError: await setting('last_sync_error'),
  };
}
