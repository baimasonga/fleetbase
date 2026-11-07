// Talks to the Fleetbase API on the same server the app was loaded from.

import { setting } from './db.js';

export class ApiError extends Error {
  constructor(status, data) {
    super((data && data.errors && data.errors[0]) || (data && data.message) || `The server answered ${status}.`);
    this.status = status;
    this.data = data || {};
  }
}

export class OfflineError extends Error {
  constructor() {
    super('No connection to the server.');
  }
}

/**
 * @param {string} path  under /int/v1/, e.g. "pod/field/events"
 * @param {{method?: string, json?: any, form?: FormData, timeout?: number, auth?: boolean, token?: string}} options
 *   token: use this token instead of the saved one (to check a sign-in before keeping it)
 */
export async function api(path, { method = 'GET', json, form, timeout = 25000, auth = true, token: useToken = null } = {}) {
  const headers = { Accept: 'application/json' };
  if (auth) {
    const token = useToken || await setting('token');
    if (token) headers.Authorization = `Bearer ${token}`;
  }
  let body;
  if (json !== undefined) {
    headers['Content-Type'] = 'application/json';
    body = JSON.stringify(json);
  } else if (form) {
    body = form;
  }

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  let response;
  try {
    response = await fetch(`/int/v1/${path}`, { method, headers, body, signal: controller.signal, credentials: 'omit' });
  } catch (error) {
    throw new OfflineError();
  } finally {
    clearTimeout(timer);
  }

  let data = null;
  try {
    data = await response.json();
  } catch (error) {
    data = null;
  }
  if (!response.ok) throw new ApiError(response.status, data);
  return data;
}

/** A file (a photo or signature) as a Blob, with the sign-in token. */
export async function apiBlob(path, { timeout = 30000 } = {}) {
  const token = await setting('token');
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeout);
  try {
    const response = await fetch(`/int/v1/${path}`, { headers: token ? { Authorization: `Bearer ${token}` } : {}, signal: controller.signal, credentials: 'omit' });
    if (!response.ok) throw new ApiError(response.status, null);
    return await response.blob();
  } catch (error) {
    if (error instanceof ApiError) throw error;
    throw new OfflineError();
  } finally {
    clearTimeout(timer);
  }
}
