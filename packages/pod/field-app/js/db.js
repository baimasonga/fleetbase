// Everything the app keeps on the phone, in IndexedDB. Nothing here is lost when
// the app is closed, the phone restarts or the battery dies.
//
//   settings  key -> value          token, user, device id, counters, clock offset
//   bundles   event id -> bundle    downloaded distribution data
//   outbox    client id -> record   handovers and attempts waiting to sync (and their results)
//   media     media id -> {blob}    photos and signatures waiting to upload

const NAME = 'pod-field';
const VERSION = 1;
let opening = null;

function open() {
  if (!opening) {
    opening = new Promise((resolve, reject) => {
      const request = indexedDB.open(NAME, VERSION);
      request.onupgradeneeded = () => {
        const db = request.result;
        for (const store of ['settings', 'bundles', 'outbox', 'media']) {
          if (!db.objectStoreNames.contains(store)) db.createObjectStore(store);
        }
      };
      request.onsuccess = () => resolve(request.result);
      request.onerror = () => reject(request.error);
    });
  }
  return opening;
}

function run(store, mode, fn) {
  return open().then((db) => new Promise((resolve, reject) => {
    const tx = db.transaction(store, mode);
    const result = fn(tx.objectStore(store));
    tx.oncomplete = () => resolve(result && 'result' in result ? result.result : undefined);
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error || new Error('The save was cancelled. Is the phone out of storage?'));
  }));
}

export const get = (store, key) => run(store, 'readonly', (s) => s.get(key));
export const put = (store, key, value) => run(store, 'readwrite', (s) => s.put(value, key));
export const del = (store, key) => run(store, 'readwrite', (s) => s.delete(key));
export const all = (store) => run(store, 'readonly', (s) => s.getAll());
export const keys = (store) => run(store, 'readonly', (s) => s.getAllKeys());

/**
 * Write several records in one transaction: all are saved, or none.
 * @param {Array<[string, IDBValidKey, any]>} writes [store, key, value]
 */
export function putMany(writes) {
  const stores = [...new Set(writes.map((w) => w[0]))];
  return open().then((db) => new Promise((resolve, reject) => {
    const tx = db.transaction(stores, 'readwrite');
    for (const [store, key, value] of writes) tx.objectStore(store).put(value, key);
    tx.oncomplete = () => resolve();
    tx.onerror = () => reject(tx.error);
    tx.onabort = () => reject(tx.error || new Error('The save was cancelled. Is the phone out of storage?'));
  }));
}

export const setting = (key) => get('settings', key);
export const setSetting = (key, value) => put('settings', key, value);

/** The next number in a counter, saved before it is used, so ids never repeat. */
export async function next(counter) {
  const value = ((await setting(counter)) || 0) + 1;
  await setSetting(counter, value);
  return value;
}

/** This phone's id, made once. Part of every record id, so phones never clash. */
export async function deviceId() {
  let id = await setting('device_id');
  if (!id) {
    const bytes = crypto.getRandomValues(new Uint8Array(4));
    id = 'd' + [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    await setSetting('device_id', id);
  }
  return id;
}
