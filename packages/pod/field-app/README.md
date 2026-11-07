# Field Distribution app

An offline web app for field officers to record handovers of agricultural inputs to farmers, with proof of delivery. It runs in Chrome on Android phones and needs no app store. It works with no signal and sends records when the phone gets signal again.

Design: `docs/proof-of-delivery/DESIGN.md` §6.6 (offline) and `AGRI-INPUTS.md` §7.2 (identity), §8 (proof) and §13 (offline).

## Opening it on a phone

The app is served by your Fleetbase server at **`https://<your-server>/pod-field/`**.

1. On the phone, open that address in **Chrome**, while on the depot Wi-Fi.
2. Chrome menu (⋮) → **Add to Home screen**. The app then opens like any other app, including with no signal.
3. Sign in with the officer's Fleetbase account.

The server must use **HTTPS**. Phones only allow the camera, and the app's offline storage and checks, on secure addresses. Your Caddy set-up already does this.

## A distribution day

**At the depot, with Wi-Fi:**
1. **Download** the distribution. The supervisor must have opened it in the console with the opening stock count. The phone now has the farmer list, entitlements, stock and lots, and everything needed to check cards.
2. Keep the phone charged. Downloaded data and unsent records stay on the phone if the app is closed or the phone restarts.

**At the distribution point, with or without signal:**
1. **Scan card**, which uses the back camera, or **Type code** with the code printed under the QR.
   - The card is checked on the phone. A replaced (old) card, a forged code, a farmer from another list, or one not verified is refused with the reason.
2. **Who is the farmer:**
   - **With a card:** tick that you've checked this is the person on the card. If the farmer gives the last 4 characters of their ID and they match, the identity level rises from L2 to L3.
   - **Without a card:** take a photo of the farmer, and a witness (chief, group leader or extension officer) confirms who they are and signs (L1).
3. **Collected by:** the farmer, a household member or a proxy (with their name). A proxy needs a witness signature.
4. **Items:**
   - The quantity starts at what is left to collect; lower it for a partial collection.
   - Choose the lot. Expired and recalled lots are shown but can't be chosen.
   - Pesticides and veterinary drugs are only offered to farmers with recorded safety training.
5. **Proof:**
   - a photo of the farmer with the inputs;
   - the farmer signs or makes a mark on the screen;
   - for pesticides, tick that the safety briefing was given.

   The app lists anything still missing and only allows saving when everything required is there.
6. **Record handover.** The receipt shows what was recorded. The record and its photos are saved on the phone first, then sent.

**Door-to-door routes:** each farmer has **Deliver** and **Not delivered**. "Not delivered" records the reason, such as not at home, moved or road impassable. After the maximum number of visits (2 by default) the farmer is moved to a distribution point by the office. Moved and deceased farmers go to the office for a records check instead.

**Back in signal:**
- The app sends everything by itself when signal returns, and every minute while online. **Sync now** forces it.
- Small records go first and photos after.
- The top bar shows **Online / Offline** and how much is waiting to send.
- Before signing out or handing the phone to someone else, check that the home screen says **Everything is sent**.

## What the server does with records sent later

- **Nothing is counted twice.** Every record has a unique id made on the phone. If the signal drops before the phone hears back, it sends again and the server recognises the record.
- **Records keep their capture time.** They're processed in the order they happened. A record that reaches the server more than 12 hours later is flagged `late_sync`, and one dated in the future is flagged `clock_ahead`.
- **Photos and signatures are tamper-checked.** The record contains a SHA-256 fingerprint of each one, and the upload is checked against it. Each file is deleted from the phone only after the server confirms it arrived intact.
- **Conflicts are kept, never dropped.** If the server can't accept a record made offline (for example, the same farmer was served on another phone first), it's kept as a **conflict** for a supervisor to review: `GET /int/v1/pod/events/{id}/conflicts`. The goods have already been given, so the supervisor records what happened, usually as a discrepancy, before the day can be closed.

## Limits to know

- **Signal needed for some steps:** signing in, downloading or refreshing a distribution, and opening and closing a distribution day (done by the supervisor in the console) all need signal.
- **Records live only on the phone until they are sent.** If a phone is lost, broken or reset before it syncs, its unsent records are lost. Sync whenever there is signal.
- **Don't clear Chrome's data:** doing so deletes unsent records. The app asks Chrome to keep its storage.
- **No two-step sign-in:** field officers need an account without it for now.
- **No farmer photos on cards yet.** "I have checked this is the farmer" relies on the officer and the card, until registration photos are added.
- **Android with Chrome only so far.** iPhones may work in Safari, but haven't been tested.

## Files

| File | What it does |
|---|---|
| `index.html`, `app.css`, `manifest.webmanifest`, `icons/` | The app shell |
| `sw.js` | Service worker: keeps the app usable offline |
| `js/main.js` | Screens: sign-in, home, distribution list, handover, not delivered, receipt |
| `js/db.js` | Storage on the phone (IndexedDB) |
| `js/api.js` | Calls to the Fleetbase API |
| `js/card.js` | Offline card checks (the same HMAC check as the server) and the ID last-4 check |
| `js/policy.js` | The proof rules (the same as the server's) |
| `js/media.js` | Photo compression (about 150 KB) and the signature pad |
| `js/scanner.js` | Camera QR reading |
| `js/sync.js` | Sending records and photos |
| `vendor/jsQR.js` | QR decoder, jsQR 1.4.0, Apache-2.0 (see `vendor/README.txt`) |

No build step: the files are served as they are.

## Tests

`server/tests/FieldApp/run.sh` runs the full offline flow in headless Chromium against the real server code: two phones, a camera scan, offline saving, sync and a conflict. See `server/tests/FieldApp/README.md`.
