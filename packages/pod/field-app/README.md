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
1. **Download** the distribution on every phone that will work at it. The phone now has the farmer list, entitlements, the stock at the site, and everything needed to check cards.
2. Keep the phone charged. Downloaded data and unsent records stay on the phone if the app is closed or the phone restarts.

**Opening the day, with or without signal:**
1. **Open the day** on one phone. Count every item and lot at the distribution point (0 for anything that isn't there); the app shows what the site should hold and refuses a count that is higher. A witness (chief, group leader, extension officer...) writes their name and signs. A second witness is optional.
2. **Other phones** at the same point press **Join: opened on another phone** instead of counting again. Their handovers are kept by the server until the opening phone has synced, then accepted; they are never lost or treated as conflicts for this reason.
3. A day can still be opened from the console instead, before the phones download it.

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

**During the day:** **Damaged or missing stock** records torn bags, spoilt or stolen stock, with a reason and details. It comes out of the stock and counts in the closing reconciliation.

**Closing the day, with or without signal:**
1. **Close the day**, usually on the phone that opened it. Count what's left of every item and lot. The app shows the difference against this phone's records. Handovers made on other phones aren't in this phone's figures: they count when those phones sync.
2. A witness signs. After closing, the phone can't record more handovers for that day.
3. On the server the closing is checked against **all** phones' records once they have synced. If it doesn't balance yet, the home screen says which item and lot differ and by how much. Either it balances once the other phones sync, or the officer records the missing stock (**Damaged or missing stock**, available until the day is closed on the server). The closing is sent again by itself and goes through once it balances. Nothing has to be counted again.

**Back in signal:**
- The app sends everything by itself when signal returns, and every minute while online. **Sync now** forces it.
- Small records go first and photos after.
- The top bar shows **Online / Offline** and how much is waiting to send.
- Before signing out or handing the phone to someone else, check that the home screen says **Everything is sent**.

## What the server does with records sent later

- **Nothing is counted twice.** Every record has a unique id made on the phone. If the signal drops before the phone hears back, it sends again and the server recognises the record.
- **Records keep their capture time.** They're processed in the order they happened. A record that reaches the server more than 12 hours later is flagged `late_sync`, and one dated in the future is flagged `clock_ahead`.
- **Photos and signatures are tamper-checked.** The record contains a SHA-256 fingerprint of each one, and the upload is checked against it. Each file is deleted from the phone only after the server confirms it arrived intact.
- **Openings, closings and discrepancies aren't applied twice either:** each has its own id made on the phone. An offline opening or closing is dated when it happened, not when it synced. On the server, closings are processed after the other records in the same batch, so a discrepancy recorded after a failed closing still counts first.
- **Conflicts are kept, never dropped.** If the server can't accept a record made offline (for example, the same farmer was served on another phone first), it's kept as a **conflict** for a supervisor to review. The officer's home screen says so.

## Reviewing conflicts (supervisors)

**Review conflicts** on the home screen lists the records the server couldn't accept, with signal (they are on the server). Each one shows, side by side, the record as made on the phone (items, lots, who collected, photos and signatures) and what the server already holds for that farmer on that day. The supervisor chooses one:

- **Record as missing stock:** the goods are lost to the programme. They are recorded as missing stock on that day, against the officer. Only while the day is open; once it is closed, the loss belongs in the closing count.
- **Goods taken back:** the extra goods were returned.
- **Reviewed, nothing to record:** checked and explained, for example because the officer already recorded the loss before closing.

Notes are required and kept for the audit. **The person whose record it is can't resolve it**: another supervisor must.

## Design

The app is built for officers outdoors, often in bright sun, with a queue waiting, on low-cost Android phones:

- **One main action per screen**, in a bar at the bottom within thumb reach. When it can't be used yet, the bar says what is still needed.
- **High contrast** on a warm paper background, large type (17 px base), a single deep green kept for the main action, and status always as text with an icon, never colour alone.
- **Numbered steps** on the handover screen (who, items, proof) that tick when done.
- **Short motion** only to show a change of screen or a completed step; none when the phone asks for reduced motion.
- **System fonts and drawn icons:** nothing to download, so it works offline. The design tokens are at the top of `app.css`.

## Limits to know

- **Signal needed for some steps:** signing in, and downloading or refreshing a distribution, need signal. Opening and closing the day don't.
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
| `js/main.js` | Screens: sign-in, home, distribution day, handover, not delivered, receipt, opening and closing counts, damaged or missing stock, conflict review |
| `js/ui.js` | The component kit: screen frame and action bar, buttons, pills, notices, steps, icons |
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

`server/tests/FieldApp/run.sh` runs the full offline flow in headless Chromium against the real server code: two phones, a camera scan, offline saving, sync, a conflict and its review by a supervisor. See `server/tests/FieldApp/README.md`.
