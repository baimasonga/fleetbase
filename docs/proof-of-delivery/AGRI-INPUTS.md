# Proof of Delivery: Agricultural Inputs Profile

Status: **Draft for review**
Builds on: [`DESIGN.md`](DESIGN.md), the generic PoD module. This document only describes what changes for agricultural inputs. Anything not mentioned here works as in `DESIGN.md`, including the offline-first sync for 3G with dead zones and depot Wi-Fi (§6.6).

## 1. Context and assumptions

The PoD module is used to distribute **agricultural inputs** (seed, fertiliser, agrochemicals, small tools, and possibly livestock inputs) to **farmers**, not to sell commercial goods. The assumptions below need confirming (§14):

- **Programme-based.** Distribution runs as a programme: a government subsidy, an NGO or donor project, or a cooperative scheme. It is organised by **season**, and each farmer has a fixed **entitlement** (for example 1 × 25 kg seed + 2 × 50 kg NPK).
- **Registered recipients.** Recipients are **registered beneficiaries**, from a farmer registry or a programme list. Many have no smartphone, some have no phone at all, and literacy varies.
- **Several handover legs.** Inputs move central warehouse → district store → **distribution point** (a ward or village centre, cooperative store or agro-dealer) → farmer. Sometimes they go directly to a farmer group.
- **Rural locations.** Distribution points are rural and often informal (a school, a market, a chief's compound). Connectivity is 3G with frequent dead zones, and depot Wi-Fi is available.
- **Possible co-payment.** A farmer may pay a small part of the price in cash or mobile money.

## 2. What changes compared with commercial delivery

| Area | Commercial (DESIGN.md) | Agricultural inputs |
|---|---|---|
| Recipient | Whoever is at the address | A **specific registered farmer** (or a registered proxy) |
| Unit of proof | One delivery per stop | **One stop, many handovers.** A distribution event serves tens to hundreds of farmers. |
| What is proven | "Parcel delivered" | **Quantity per input line** (bags or kg), lot/batch, and condition |
| Main identity proof | Signature or OTP | **Farmer ID card QR**, photo with the inputs, mark or thumbprint, and a witness |
| Main risks | Lost or disputed parcels | **Double collection, ghost beneficiaries, diversion or resale, short-weighting, expired or counterfeit agrochemicals** |
| Accountability | Customer and billing | **Programme, donor or government audit.** Reconciliation down to the bag. |
| Reporting | Delivery KPIs | Farmers reached (by sex, age, district), quantity against plan, timeliness against the planting window |

## 3. Chain of custody

Every leg is a handover with counted quantities and two parties signing. The farmer handover is the last link.

```
Central warehouse ──leg 1──▶ District store ──leg 2──▶ Distribution point ──handovers──▶ Farmers
   (dispatch note)          (goods received note)     (event opened, stock counted)      (individual PoD)
                                                       └── event closed + reconciliation ──▶ returns leg
```

- **Legs 1 and 2** are ordinary Fleet-Ops orders. At arrival, PoD requires:
  - the receiving storekeeper's signature and name;
  - a **count per input line** (expected against received);
  - lot/batch scans;
  - photos of the stock and of any damage.

  A shortfall or damage creates a **discrepancy record** (§5). The leg can't close with an unexplained difference above tolerance (default 0%).
- **Distribution point:**
  - **Opening** the event needs an opening stock count.
  - **Closing** it needs a closing count and a reconciliation (§7).
  - Leftover stock goes back on a **returns leg**, which needs its own PoD.

## 4. Mapping to Fleetbase

| Agricultural concept | Fleetbase object | Notes |
|---|---|---|
| Shipment leg (warehouse → store → distribution point) | Fleet-Ops **Order**, with waypoints | Custom **Order Config** flow: `LOADED → IN_TRANSIT → ARRIVED → RECEIVED` (counted) or `RECEIVED_WITH_DISCREPANCY` |
| Input line (e.g. 40 × NPK 50 kg, lot L123) | Fleet-Ops **Entity** | Uses `sku`, `barcode` and `weight`, plus `meta.quantity`, `meta.unit`, `meta.lot`, `meta.expiry` |
| Stock and lots in stores | **Pallet** (warehouse extension) | Stock levels, lots, expiry and cycle counts. Legs deduct from and add to Pallet stock. |
| Distribution event | New: `pod_distribution_events` | Linked to the order or waypoint that delivered the stock |
| Farmer | New: `pod_beneficiaries` | Optional link to a Fleet-Ops **Contact** if the farmer has a phone or portal access |
| Entitlement | New: `pod_entitlements` | Per farmer, season and input |
| Farmer handover | New: `pod_handovers` | Uses the evidence, sealing and offline machinery from `DESIGN.md` (each handover is a kind of delivery attempt) |
| Co-payment | **Ledger** | Cash receipt or mobile money reference, linked to the handover |

## 5. Data model additions

Every table has `uuid`, `public_id`, `company_uuid` and timestamps.

**`pod_programmes`**:
- `name`, `funder`, `season` (e.g. "2027 Main Season")
- `planting_window_start`, `planting_window_end`
- `rules` (JSON: proxy allowed, co-payment amount, witness required, restricted-product rules)

**`pod_beneficiaries`**:
- `programme_uuid`, `farmer_ref` (registry id), `name`
- `sex`, `birth_year`, `district`, `chiefdom`/`ward`, `village`
- `group_uuid` (cooperative or farmer group)
- `phone` (nullable), `id_type`, `id_last4`, `photo_file_uuid`
- `card_code` (a signed QR, §6.1)
- `trained_restricted_products` (bool plus date)
- `proxy_name`, `proxy_id_last4` (registered in advance)
- `consent_at`, `status` (`active`, `suspended`, `deceased`, `duplicate`)

**`pod_entitlements`**:
- `beneficiary_uuid`, `programme_uuid`, `sku`, `quantity`, `unit`
- `distribution_event_uuid` (the assigned point)
- `status` (`open`, `partially_collected`, `collected`, `expired`)
- `collected_quantity`

**`pod_distribution_events`**:
- `programme_uuid`, `place_uuid` (the distribution point)
- `order_uuid` (the inbound stock leg)
- `scheduled_date`, `opened_at`, `closed_at`
- `opening_stock` (JSON per sku/lot), `closing_stock` (JSON)
- `officer_uuid` (the user running the event)
- `witnesses` (JSON: name, role and signature evidence for chief, extension officer, group leader)
- `reconciliation` (JSON), `status`

**`pod_handovers`** (one per farmer per collection; extends the delivery-attempt concept):
- `event_uuid`, `beneficiary_uuid`
- `collected_by` (`self` or `proxy`)
- `lines` (JSON: sku, lot, quantity, condition)
- `identity_method` (`card_qr`, `id_check`, `photo_match`, `group_leader_confirm`)
- `copayment` (amount, method, reference)
- `device_id`, `client_handover_id`, `trusted_captured_at`, `location`
- `status` (`draft`, `submitted`, `sealed`, `flagged`, `voided`)
- `seal_hash`
- Evidence rows reuse `pod_evidence` (§5 of `DESIGN.md`).

**`pod_discrepancies`**:
- `subject` (an order leg or an event)
- `sku`, `lot`, `expected`, `actual`
- `reason` (`short_delivered`, `damaged`, `expired`, `wet`, `torn_bag`, `counterfeit_suspected`, `theft`, `other`)
- evidence photos
- `resolution` (`written_off`, `replaced`, `investigating`, `recovered`), `resolved_by`

## 6. Proof at farmer handover

### 6.1 Identifying the farmer (offline-capable, phone not required)
Methods, in order of preference (the programme policy decides which are allowed):

1. **Farmer ID card QR.** A card issued at registration carries a QR signed by the server: `beneficiary_id + programme + HMAC`. The app checks the signature offline and shows the farmer's photo from the downloaded manifest, so the officer can compare faces. This is the primary method because it needs no phone, no network and no literacy.
2. **ID number check.** The farmer gives their national or voter ID, and the app checks the last 4 digits against the manifest.
3. **Group leader confirmation.** For a farmer without a card, a registered group leader confirms the farmer's identity. The leader's identity is checked too, and the handover is flagged for spot-checking.
4. **Proxy collection.** Only a proxy **registered in advance** can collect. The app checks the proxy's ID, photographs them, and needs a witness co-sign. The farmer gets an SMS (if they have a phone) saying the proxy collected for them.

Biometric fingerprint *matching* stays out of scope. It needs heavy infrastructure and raises data-protection risk. A **thumbprint image** can be captured as a mark of acknowledgement, without matching.

### 6.2 Evidence for each handover (default policy)
```json
{ "all": [
    { "type": "beneficiary_id", "methods": ["card_qr", "id_check", "group_leader_confirm"] },
    { "type": "line_quantities", "match": "entitlement" },
    { "type": "lot_scan", "coverage": "all_lines" },
    { "type": "photo", "min": 1, "hint": "farmer with inputs" },
    { "any": [ {"type": "signature"}, {"type": "mark"}, {"type": "thumbprint_image"} ] }
]}
```
Extra rules:
- **Proxy collection:** add `{"type": "witness_signature"}`.
- **Restricted agrochemicals:** add `{"type": "safety_ack"}` (§8).
- **Co-payment:** add `{"type": "copayment_receipt"}`.

The generic geofence check uses a larger radius for informal distribution points (default 500 m), set per `place`.

### 6.3 Quantities
- The app pre-fills each input line from the entitlement. The officer confirms the count of bags or units and scans the lot barcode.
- **Partial collection** is allowed, for example when stock runs out. The entitlement becomes `partially_collected`, and the balance can be collected at a later event.
- **Over-issue is blocked.** The app checks entitlements on the phone, and the server checks them again on sync.
- **Lot and expiry:** scanning an expired or recalled lot blocks the line. The recall list comes in the route bundle.

### 6.4 Receipt for the farmer
- If the farmer has a phone, they get an SMS in their language: what they received, the date, the place and a reference number. The SMS is queued if there is no signal.
- Optionally, a printed slip from a Bluetooth thermal printer.
- The reference number lets the farmer raise a complaint later (§10).

## 7. Event reconciliation
When the event closes, the app (and the server again on sync) checks:

```
opening_stock = Σ handed_over + closing_stock + Σ discrepancies(damaged/expired/…)
```
This is checked per sku and lot.

- Any variance above the programme tolerance (default **0 bags**) blocks the event from closing until a discrepancy with evidence explains it.
- The officer and at least one witness (chief, extension officer or group leader) sign the closing summary. The summary shows totals: farmers served, by sex, and quantities per input.
- The closing summary becomes an **Event ePOD**, a PDF with the handover list, totals, reconciliation, witnesses and seal hash. It's the main audit document for the programme or donor.
- Closing stock goes back on a returns leg (§3) or is carried to the next event, with a recorded count.

## 8. Agrochemical safety and traceability
- Restricted products (pesticides, herbicides) are flagged on the sku. They are issued only to farmers with `trained_restricted_products = true`, unless the programme overrides this.
- The handover needs a **safety acknowledgement**: the officer confirms the safety briefing was given and PPE handed out (if PPE is part of the entitlement). Evidence is a checkbox plus the farmer's mark.
- **Lot traceability:** every handover records lot numbers. If a lot is recalled, a report lists every farmer and village that received it, and an SMS alert can go to the farmers who have phones.

## 9. Controls against fraud and leakage

| Risk | Control |
|---|---|
| **Double collection** (same farmer, two events or two devices) | One entitlement per farmer per input per season. The manifest is split by event, so a farmer is only on their assigned event. Several devices at one event share a list, and duplicates are caught when the devices sync (most of the time within minutes on 3G). The server flags any duplicate handover and freezes the entitlement for review. |
| **Ghost beneficiaries** | Photo of the farmer with the inputs; card QR with a face check; random **call-back checks** (SMS or voice) to a sample (default 5%) of farmers with phones; registry de-duplication by name, ID and village. |
| **Diversion or resale before the event** | Custody chain with counts at each leg; GPS and trusted time on every handover; reconciliation (§7); alerts when one officer serves an unusual number of farmers per hour (default above 60 an hour). |
| **Officer collusion** | Witness co-sign at open and close; `pod void` and `pod bypass` permissions held by programme staff, not field officers; independent spot audits sampled from sealed handovers. |
| **Short-weighting** | Counts in bags plus the sku pack weight; optional scale readings for loose distribution. |
| **Counterfeit or expired product** | Lot scan against the expected lot from the inbound leg; expiry and recall check on the phone. |

## 10. Feedback and complaints
- Farmers can report "not received", "less than entitled" or "poor quality" by SMS short code, USSD, or through the extension officer.
- A complaint links to the entitlement and handover. If the handover is sealed, its evidence (photo, mark, witness) is shown to the reviewer.
- Complaints are tracked to resolution and shown in programme reports. Donors usually require a complaints mechanism.

## 11. Changes to the offline design for distribution events
Distribution points are often in dead zones for the whole event, so:

- **Event manifest in the route bundle** (downloaded on depot Wi-Fi). It holds the beneficiaries assigned to the event, their entitlements, their **photo thumbnails** (about 10–15 KB each, so roughly 7.5 MB for 500 farmers), card-signing keys, the recall list and restricted-product rules.
- **The whole event can run offline.** Open, every handover, reconciliation and close all work with no network. Handovers sync in the background whenever signal appears, metadata first, as in `DESIGN.md` §6.6.3.
- **Several devices at one event.** Each device takes a range of beneficiaries, or all devices use the full list. The server resolves conflicts on sync: the first sealed handover wins and later duplicates are voided and flagged. Phone-to-phone sync (Bluetooth or Wi-Fi Direct) is deferred.
- **Photo budget.** One photo per handover at about 150 KB (smaller than parcel photos, because 300 handovers × 300 KB would be about 90 MB on 3G). Full-quality photos upload on depot Wi-Fi.
- **Co-payment by mobile money** needs a network. If there's no signal, record the farmer's transaction reference and verify it against the mobile money provider when the phone syncs. Unverified references are flagged.

## 12. Usability for officers and farmers
- The app runs in local languages, with icon-first screens and big buttons, and one handover in **under 60 seconds** (scan card → confirm photo → confirm quantities → photo → mark).
- The farmer only does one thing: give a mark, signature or thumbprint on screen.
- SMS receipts and complaint instructions are in the farmer's language.
- A queue mode for busy events: scan the next card while the previous handover saves in the background.

## 13. Programme reporting
These are added to the reports in `DESIGN.md` §11:
- farmers reached against target, by programme, season, district, ward, sex and age band;
- quantities distributed against plan per input, by lot;
- **timeliness**: the share distributed before the planting window opens;
- discrepancies and losses by leg, reason and location;
- duplicate or ghost flags, call-back results and complaints;
- **audit pack export** per event and per programme: CSV/XLSX plus the Event ePODs and seal hashes, for government or donor auditors;
- **access levels:** donor or ministry viewers see **aggregated** figures only. Seeing farmer-level personal data needs `pod view-evidence`.

## 14. Privacy and consent
- Farmer data is personal data, and photos and ID numbers are sensitive. Consent is recorded at registration (`consent_at`).
- Only the last 4 digits of ID numbers are stored, never full ID scans by default. Thumbprints are kept as images only and never turned into templates.
- Retention follows the programme's audit requirement. Donors commonly require 5–7 years for distribution records. After that, photos are redacted and hashes and aggregates are kept.
- Apply the national data-protection law where the programme operates. Confirm data-sharing terms with funders before giving them farmer-level access.

## 15. Revised delivery plan

| Phase | Scope | Est. |
|---|---|---|
| 0 | Fleet-Ops proof guard (as in DESIGN.md) | 3–4 days |
| 1 | Generic PoD core (DESIGN.md phase 1) with counted quantities and lots on legs; discrepancies | 2.5 weeks |
| 2 | Programmes, beneficiary import (CSV/XLSX from the registry), entitlements, signed farmer cards (printable PDF) | 2 weeks |
| 3 | Distribution events: open, handover, reconcile, close; Event ePOD; witnesses | 2.5 weeks |
| 4 | Navigator: offline event mode, card scanning, manifest bundle, queue mode, local languages | 3–4 weeks |
| 5 | Fraud controls (duplicates, call-back sampling, rate alerts), recalls, safety rules | 1.5 weeks |
| 6 | Programme reports, audit pack export, complaints (SMS/USSD) | 2 weeks |
| 7 | Co-payment (cash and mobile money via Ledger) — only if needed | 1–1.5 weeks |

**Total:** about 15–18 weeks, or 14–16 without co-payment. A pilot of one district and one season can start after phase 4.

## 16. Open questions
1. **Who runs the distribution?** Government subsidy, NGO or donor project, cooperative, or agro-dealers (e-vouchers)? This affects roles, reporting and whether there's co-payment.
2. **Is there an existing farmer registry?** What format is it in, and which ID do farmers have (national ID, voter card, programme card, none)?
3. **Distribution model:** mostly distribution-point events, door-to-door, or collection from agro-dealers?
4. **Which inputs?** Are restricted agrochemicals included? Are there livestock inputs that need a cold chain?
5. **Do farmers co-pay**, and if so, in cash or mobile money (which provider)?
6. **Reporting and audit requirements** of the funder or ministry: indicators, retention period and export format.
7. **Languages** for the app and SMS, and the share of farmers with a phone.
