// Checks a farmer's card with no network, exactly as the server does
// (server/src/Support/Cards/ProgrammeCard.php):
//
//   FBP1.<programme>.<beneficiary>.<issue>.<signature>
//   signature = base32(first 16 bytes of HMAC-SHA256(programme key, "FBP1.<programme>.<beneficiary>.<issue>"))

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

export function base32(bytes) {
  let bits = '';
  for (const byte of bytes) bits += byte.toString(2).padStart(8, '0');
  let out = '';
  for (let i = 0; i < bits.length; i += 5) out += ALPHABET[parseInt(bits.slice(i, i + 5).padEnd(5, '0'), 2)];
  return out;
}

function fromBase64(text) {
  return Uint8Array.from(atob(text), (c) => c.charCodeAt(0));
}

const keys = new Map();
async function hmacKey(keyBase64) {
  if (!keys.has(keyBase64)) {
    keys.set(keyBase64, crypto.subtle.importKey('raw', fromBase64(keyBase64), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']));
  }
  return keys.get(keyBase64);
}

/**
 * @param {string} code    what the camera read or the officer typed
 * @param {object} bundle  the downloaded bundle (card_key, programme, beneficiaries)
 * @returns {Promise<{valid: boolean, reason?: string, message?: string, beneficiary?: object}>}
 */
export async function checkCard(code, bundle) {
  const parts = String(code || '').trim().split('.');
  if (parts.length !== 5 || parts[0].toUpperCase() !== 'FBP1' || !/^\d+$/.test(parts[3]) || Number(parts[3]) < 1) {
    return { valid: false, reason: 'malformed', message: 'This is not a programme card code.' };
  }
  const [, programmeId, beneficiaryId, issueText, signature] = parts;
  const issue = Number(issueText);
  const body = `FBP1.${programmeId}.${beneficiaryId}.${issue}`;

  const mac = new Uint8Array(await crypto.subtle.sign('HMAC', await hmacKey(bundle.card_key), new TextEncoder().encode(body)));
  if (base32(mac.slice(0, 16)) !== signature.toUpperCase()) {
    return { valid: false, reason: 'bad_signature', message: 'This card is not genuine, or the code was mistyped.' };
  }
  if (programmeId !== bundle.programme.id) {
    return { valid: false, reason: 'wrong_programme', message: 'This card is for another programme.' };
  }

  const beneficiary = bundle.beneficiaries.find((b) => b.id === beneficiaryId);
  if (!beneficiary) {
    return { valid: false, reason: 'not_here', message: 'This farmer is not on the list for this distribution.' };
  }
  if (!beneficiary.verified || !beneficiary.active) {
    return { valid: false, reason: 'not_verified', message: 'This farmer is not verified or not active.' };
  }
  if (issue !== beneficiary.card_issue) {
    return { valid: false, reason: 'revoked', message: 'This card has been replaced. Ask for the farmer\'s new card.', beneficiary };
  }
  return { valid: true, beneficiary };
}

/** The last 4 of an ID, checked against the hash in the bundle. */
export async function idLast4Matches(beneficiary, last4) {
  const clean = String(last4 || '').replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  if (clean.length !== 4 || !beneficiary.id_last4_hash) return false;
  return (await sha256Hex(new TextEncoder().encode(`${beneficiary.id}|${clean}`))) === beneficiary.id_last4_hash;
}

export async function sha256Hex(data) {
  const buffer = data instanceof Blob ? await data.arrayBuffer() : data;
  return [...new Uint8Array(await crypto.subtle.digest('SHA-256', buffer))].map((b) => b.toString(16).padStart(2, '0')).join('');
}
