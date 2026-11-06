# Proof of Delivery: Agricultural Inputs Profile

Status: **Draft for review, v3** (beneficiary onboarding, transporters, West Africa, third-party monitoring)
Builds on:
- [`DESIGN.md`](DESIGN.md), the generic PoD module;
- [`REPORTING-AUDIT.md`](REPORTING-AUDIT.md), the reporting framework and audit rules for this profile.

This document only describes what changes for agricultural inputs. Anything not mentioned here works as in `DESIGN.md`, including offline-first sync for 3G with dead zones and depot Wi-Fi (§6.6).

## 1. Confirmed operating model

| Topic | Decision |
|---|---|
| Operators | **NGOs, donor-funded projects and government programmes.** One installation can run several programmes, each with its own funder. |
| Delivery models | **Distribution points** (events) **and door-to-door** delivery to households |
| Payment | **None.** Inputs are free to farmers, so there is no co-payment. |
| Farmer identification | **Some** farmers have national ID, a voter's card, a passport or another ID. **Some have none.** Every farmer gets a programme card (§7). |
| Transport | **Programme staff** or **hired truckers** (third-party transporters) |
| Inputs | Seed, fertiliser, **pesticides** (restricted), tools, **livestock inputs**. A **few** of these need refrigeration (vaccines and some veterinary drugs). |
| Reporting and audit | Defined in [`REPORTING-AUDIT.md`](REPORTING-AUDIT.md) |
| Language | **English** for the app, the console, SMS and reports. The farmer-facing screens use icons first, for low literacy. |
| Connectivity | Mostly 3G with frequent dead zones; depot Wi-Fi available |
| Region | **West Africa** (§16) |
| Beneficiary lists | Exist today in **Excel and on paper**, in different formats. They're imported and verified (§7.1). |
| Hired truckers | **Install the Navigator app** (Transporter role). Vehicle GPS trackers are **optional**, an extra data source. |
| Funder reporting | **No funder-required formats.** Reports are for the programme, government partners and audit. |
| Field verification | Done by **M&E staff, internal auditors and independent third-party monitors (TPMs)** |

## 2. What changes compared with commercial delivery

| Area | Commercial (DESIGN.md) | Agricultural inputs |
|---|---|---|
| Recipient | Whoever is at the address | A **specific registered farmer**, a registered household member, or a registered proxy |
| Unit of proof | One delivery per stop | Distribution point: **one stop, many handovers**. Door-to-door: one handover per household. |
| What is proven | "Parcel delivered" | **Quantity per input line** (bags, kg, litres, doses), lot/batch, condition, and temperature for cold-chain items |
| Main identity proof | Signature or OTP | **Programme card QR**, ID check where available, photo, mark or thumbprint, witness |
| Main risks | Lost or disputed parcels | **Double collection, ghost beneficiaries, diversion in transit, theft by transporters, broken cold chain, unsafe pesticide handling** |
| Accountability | Customer and billing | Programme, government and independent audit: reconciliation to the bag or dose (see `REPORTING-AUDIT.md`) |

## 3. Delivery models

### 3.1 Distribution points (events)
A distribution point is a ward or village centre, cooperative store, school or similar. Stock arrives on a transport leg (§4). An officer opens an **event**, hands over to many farmers, then reconciles and closes it (§11). Farmers come to the point.

### 3.2 Door-to-door
- Each household is a **waypoint** on a Fleet-Ops route. Routes are planned with the Fleet-Ops orchestrator and route optimisation. One vehicle carries the stock for every household on the route.
- The vehicle works like a **mobile distribution point**:
  - it opens with a counted load;
  - each household stop is one handover;
  - it closes with a reconciliation when it returns.
- **Who can receive:**
  - the registered farmer;
  - a **registered household member** (recorded at registration, with photo and ID where available);
  - a registered proxy, which needs a witness.
- **Agricultural reasons for a failed delivery** (these replace the commercial list in `DESIGN.md` §6.3):
  - `farmer_absent`
  - `household_moved`
  - `farmer_deceased`
  - `refused`
  - `wrong_village`
  - `road_inaccessible`
  - `security_issue`
  - `already_received`
  - `other`
- **After a failed attempt:**
  - up to `max_attempts` (default 2);
  - then the entitlement is **moved to the nearest distribution point**, and the farmer is notified by SMS if they have a phone. An officer can also notify them in person.
  - `farmer_deceased` and `household_moved` open a registry case and don't trigger a re-attempt.
- **Location check:** the household's GPS location from registration is the stop location. The geofence radius defaults to **200 m**, against 500 m for distribution points. A household with no recorded location takes the location of the first successful handover, and that is flagged for review.

## 4. Chain of custody and transporters

Every transport leg is a counted, two-party handover:

```
Central warehouse ─▶ District store ─▶ Distribution point / door-to-door vehicle ─▶ Farmer
      │ load                │ receive & load              │ open event / route
      └─ transporter: programme staff OR hired trucker ─────┘
```

### 4.1 Rules for every leg
- **Loading** (at the issuing store), recorded by the **storekeeper**:
  - a count per input line and lot;
  - photos of the loaded vehicle;
  - the vehicle plate photo;
  - the **truck seal numbers** (numbered tamper seals on doors or tarpaulins);
  - the waybill number;
  - the transporter's signature and ID.
- **Receiving** (at the destination), recorded by the **receiving storekeeper or officer**:
  - a seal check (photo, and the number matched against loading);
  - a count;
  - condition per line, including spills and leaks for pesticides;
  - the receiver's signature;
  - the transporter's counter-signature.
- **Who can close a leg:** only the receiving **programme staff member**. A transporter can never close a leg, open an event or do a farmer handover.
- **Discrepancies:** a shortage or damage at receiving creates a discrepancy (`REPORTING-AUDIT.md` audit rules AR-01 and AR-02). It's attributed to the transporter while it is investigated.

### 4.2 Programme staff transporters
They use the Navigator app as normal drivers, with full GPS tracking.

### 4.3 Hired truckers
- The transport company is a Fleet-Ops **Vendor**. Each trucker is a **Driver** with a limited **Transporter** role: they can see their legs, share location and sign. Their truck is a **Vehicle**.
- **The trucker uses Navigator** (required for hired truckers, as for staff). This gives:
  - live GPS while the leg is in transit;
  - a geofence check on arrival;
  - their signature on the device at loading and receiving.

  The app links to the trucker's phone number, and the Transporter role only sees that trucker's own legs.
- **Vehicle GPS trackers (optional).** When a truck has one, connect it through Fleet-Ops telematics as a **second, independent track**. The phone track is then compared with the vehicle track. If they're more than 2 km apart for more than 15 minutes, the phone may have left the truck (AR-28).
- **If the phone dies or has no signal**, the app keeps a local track and backfills it (`DESIGN.md` §6.6.10). The leg can still be received on the strength of the seals, counts and waybill. Any gap of more than 60 minutes in the track is flagged under AR-28.
- **Liability.** The contracted transporter is responsible for any shortage between loading and receiving. Transporter performance (losses, delays, seal breaches) is reported per vendor (`REPORTING-AUDIT.md` R-05).
- Paying transporters is out of scope for v1. Their contracts can be handled in Ledger later.

## 5. Mapping to Fleetbase

| Agricultural concept | Fleetbase object | Notes |
|---|---|---|
| Transport leg | Fleet-Ops **Order** + waypoints | Order Config flow: `LOADED → IN_TRANSIT → ARRIVED → RECEIVED` or `RECEIVED_WITH_DISCREPANCY`. Seals and waybill go in `order.meta`. |
| Door-to-door route | Fleet-Ops **Order**, one **waypoint per household** | Route optimised with the orchestrator |
| Input line | Fleet-Ops **Entity** | `sku`, `barcode`, `weight`, plus `meta.quantity`, `meta.unit`, `meta.lot`, `meta.expiry`, `meta.category`, `meta.cold_chain`, `meta.restricted` |
| Transport company / trucker / truck | **Vendor** / **Driver** (Transporter role) / **Vehicle** | |
| Stores, stock, lots | **Pallet** | Legs deduct and add Pallet stock per lot |
| Temperature data from a fridge or logger | Fleet-Ops **telematics / sensors** | When a connected logger exists; otherwise entered manually (§10) |
| Programme, beneficiary, entitlement, event, handover | New PoD-Agri tables (§6) | |

## 6. Data model additions

Every table has `uuid`, `public_id`, `company_uuid` and timestamps.

**`pod_programmes`**:
- `name`, `operator_type` (`ngo`, `project`, `government`), `funder`, `season`, `country`
- `planting_windows` (JSON per district or agro-ecological zone: start and end; §16)
- `rules` (JSON: delivery models, proxy and household-member rules, witness rules, pesticide and veterinary rules, audit thresholds overriding the defaults in `REPORTING-AUDIT.md`)

**`pod_beneficiaries`**:
- `programme_uuid`, `farmer_ref`, `type` (`farmer` or `animal_health_worker`), `name`
- `sex`, `birth_year`, `disability` (optional, for disaggregation)
- `district`, `chiefdom`/`ward`, `village`, `household_location` (point, nullable)
- `group_uuid`, `phone` (nullable), `photo_file_uuid`
- `card_code` (signed QR)
- `ids` (JSON list of `{type, last4}`, where `type` is `national_id`, `voter_card`, `passport`, `drivers_licence`, `birth_certificate` or `other`; can be empty)
- `household_members` (JSON: name, relation, photo, id last4)
- `proxy` (JSON, nullable)
- `trained_restricted_products` (date or null)
- `livestock` (JSON: counts per species, for livestock entitlements)
- `consent_at`, `status` (`active`, `suspended`, `deceased`, `moved`, `duplicate`)
- `source` (JSON: `excel` file + sheet + row, `paper` list id + page + line, or `field_registration`), `source_file_uuid` (the original Excel file or paper scan)
- `verification_status` (`unverified`, `verified`, `rejected`), `verified_at`, `verified_by`, `verification_method` (`field_visit`, `community_validation`, `first_handover`)

**`pod_entitlements`**:
- `beneficiary_uuid`, `programme_uuid`, `sku`, `quantity`, `unit`
- `delivery_model` (`distribution_point` or `door_to_door`), `distribution_event_uuid` or `route_order_uuid`
- `status` (`open`, `partially_collected`, `collected`, `reassigned`, `expired`, `cancelled`), `collected_quantity`

**`pod_distribution_events`** (also used for door-to-door vehicle runs, with `kind = 'route'`):
- `kind` (`point` or `route`), `programme_uuid`, `place_uuid`, `order_uuid`
- `scheduled_date`, `opened_at`, `closed_at`
- `opening_stock`, `closing_stock` (JSON per sku and lot)
- `officer_uuid`, `witnesses` (JSON), `reconciliation` (JSON), `status`

**`pod_handovers`**:
- `event_uuid`, `beneficiary_uuid`, `waypoint_uuid` (door-to-door)
- `received_by` (`self`, `household_member`, `proxy`)
- `identity_level` (`L3`, `L2`, `L1`; §7), `identity_methods`
- `lines` (JSON: sku, lot, quantity, condition, and `temperature_c`/`vvm_stage` for cold-chain lines)
- `safety_ack` (bool), `outcome` (`delivered`, `partial`, `failed`), `failure_reason_code`
- `device_id`, `client_handover_id`, `trusted_captured_at`, `location`
- `status`, `seal_hash`
- Evidence goes in `pod_evidence` (`DESIGN.md` §5).

**`pod_transport_legs`** (1:1 with a Fleet-Ops order):
- `transporter_kind` (`staff` or `hired`), `vendor_uuid`, `waybill_no`
- `seals_loaded` (JSON), `seals_received` (JSON), `seal_status` (`intact`, `mismatch`, `broken`, `missing`)
- `expected_duration_min`, `loaded_count`, `received_count` (JSON)

**`pod_cold_chain_readings`**:
- `subject` (a leg, event or handover), `source` (`logger`, `thermometer`, `telematics`)
- `temp_c`, `min_c`, `max_c` (since the last reading), `excursion_minutes`
- `vvm_stage` (1–4), `photo_file_uuid`, `recorded_at`

**`pod_discrepancies`**: as in v1, plus:
- the reasons `seal_broken`, `spill_leak`, `temperature_excursion`, `vvm_discard`;
- a `liable_party` (`transporter`, `store`, `officer`, `unknown`).

**`pod_cases`** and **`pod_audit_findings`**: see `REPORTING-AUDIT.md` §7.

## 7. Beneficiary onboarding and identification

### 7.1 Bringing in the existing lists (Excel and paper)
Today's lists come in different formats and quality. Onboarding has four steps, and every record keeps a link to where it came from (`source`).

**Step 1: Excel import** (console → Programme → Import beneficiaries)
- Upload `.xlsx`, `.xls` or `.csv`, then pick the sheet and header row.
- **Column mapping wizard:** match the file's columns to beneficiary fields. Saved mappings can be reused for the next file in the same format.
- **Normalisation:**
  - **names:** trim, collapse spaces, title case;
  - **sex:** `M`/`F`/`Male`/`Female`/`1`/`2` → standard values;
  - **age:** year of birth, or age in years → `birth_year`;
  - **phone:** converted to the international format (E.164) using the country code;
  - **places:** district, chiefdom/ward and village names matched against the programme's place list, with suggestions for misspellings.
- **Validation and de-duplication** (`REPORTING-AUDIT.md` §9):
  - **exact duplicates** are merged automatically;
  - **likely duplicates** (similar name in the same village, same phone, same ID last 4) go to a review queue.
- **Preview:** the import result is shown before anything is saved. Rejected rows go to a fix-up queue with reasons; nothing is dropped silently.
- The original file is stored, and each beneficiary keeps its file, sheet and row as `source`.

**Step 2: Digitising paper lists**
- **Scan or photograph** each page of the paper list in the app or console. The images are stored as the `source` evidence for those records.
- **Enter the data** through a quick-entry console form that shows the page image side by side.
- **Double-entry check** for quality: a second clerk re-enters a random 10% of each page. If more than 2% of fields disagree, the whole page is entered again.
- Records from paper start `unverified`, like every imported record.

**Step 3: Field verification and card issue** (before the first distribution)
Lists from Excel and paper are often out of date, so every beneficiary must be **verified once** before they are issued a programme card. Two ways:
- **Community validation meeting** (recommended, quickest). An officer meets the village with the chief or group leader, using the app in **registration mode**, which works offline. For each farmer on the list, the officer:
  1. confirms the farmer is present and known to the community;
  2. takes the farmer's photo;
  3. records any ID (type + last 4), phone, household members and household GPS;
  4. records consent;
  5. prints or hands out the programme card.

  Farmers not found are marked `rejected` (reason: not known, moved, deceased) after a second visit. People who are eligible but missing from the list can be **added in the field**, but they start `unverified` and need programme manager approval (AR-27).
- **Verify on first handover** (fallback, for programmes that can't hold validation meetings). The first handover does the verification steps above, at identity level L1 or L2, and is sampled at double the normal rate.

**Step 4: Approval**
- The programme manager approves the verified list for the season. Entitlements are only generated for `verified` beneficiaries, unless the programme allows verify-on-first-handover.

### 7.2 Identity levels at handover

**Everyone gets a programme card at verification**, a QR code signed by the server, so every farmer has at least one machine-checkable credential. Each handover is given an **identity level**:

| Level | Required | Typical case |
|---|---|---|
| **L3: strong** | Programme card QR + a government ID with matching last 4 digits (national ID, voter card, passport, etc.) + face match against the registration photo | Farmers with ID |
| **L2: standard** | Programme card QR + face match against the registration photo | Farmers without ID |
| **L1: community-verified** | No card (lost or not issued) + photo + **witness confirmation** by a registered chief, group leader or extension officer | Lost cards, late registration |

Rules (thresholds can be set per programme):
- L1 handovers are **always** flagged, and are sampled at **double** the normal rate for verification (`REPORTING-AUDIT.md` §6).
- Lost cards are reissued **only** through the console by programme staff, after verification. The old card code is revoked, and the revocation list ships in the route bundle.
- Household members and proxies need L2 or better **for themselves**, plus the farmer's programme card. A proxy also needs a witness signature.
- Biometric *matching* is out of scope. A thumbprint **image** can serve as the farmer's mark.

## 8. Evidence at farmer handover (default policy)

```json
{ "all": [
    { "type": "beneficiary_id", "min_level": "L1" },
    { "type": "line_quantities", "match": "entitlement" },
    { "type": "lot_scan", "coverage": "all_lines" },
    { "type": "photo", "min": 1, "hint": "recipient with inputs" },
    { "any": [ {"type": "signature"}, {"type": "mark"}, {"type": "thumbprint_image"} ] }
]}
```

Conditional rules:
- **Proxy:** add `witness_signature`.
- **Restricted pesticide or veterinary drug line:** add `safety_ack` and `trained_check` (§9).
- **Cold-chain line:** add `cold_chain_reading` (§10).
- **L1 identity:** add `witness_signature`.

Over-issue is blocked on the phone and checked again on the server. Partial collection is allowed. An expired, recalled or **revoked** lot blocks that line.

## 9. Pesticides

| Control | Rule |
|---|---|
| Restricted list | Each pesticide sku has `restricted = true` and a hazard class. Unregistered or banned products are rejected when stock is imported. |
| Eligible recipients | Only farmers with `trained_restricted_products` recorded within the programme's validity period (default 3 years). Overriding this needs programme manager approval and raises AR-13. |
| Quantity cap | A maximum quantity per farmer per season per product, set in the programme rules |
| PPE | When PPE (gloves, mask) is part of the entitlement, it **must** be handed over in the same handover as the pesticide |
| Safety briefing | `safety_ack`: the officer confirms the briefing covered storage away from children, food and water, dosage, and disposal of empty containers. The farmer gives their mark. |
| Transport | At loading, pesticides must be **in a separate compartment or clearly separated from seed, feed, food and veterinary products**. The storekeeper confirms this with a checkbox and photo. Leaks and spills are checked at loading and receiving. |
| Labels | The receiver checks the label is intact and legible in English. Unlabelled or decanted containers are rejected as a `counterfeit_suspected` discrepancy. |
| Traceability | Lot numbers are recorded for every handover. A recall report lists every recipient and village per lot (`REPORTING-AUDIT.md` R-07). |
| Empty containers | Optional return-collection tracking at the next event, for programmes that run a take-back scheme |

## 10. Livestock inputs and cold chain

### 10.1 Categories

| Category | Examples | Recipient | Extra proof |
|---|---|---|---|
| General | Feed, mineral licks, equipment | Farmer | Standard |
| Veterinary drugs | Dewormers, antibiotics, acaricides | **Animal health worker** (vet or community animal health worker, CAHW), or a farmer when the programme allows | Restricted rules as for pesticides; quantity tied to the livestock count |
| Cold chain (few items) | Vaccines, some drugs | **Vet or CAHW only** | Cold-chain proof (§10.2) |

Entitlements for veterinary drugs and vaccines can be calculated from `pod_beneficiaries.livestock` counts (for example 1 dose per animal, plus a wastage allowance).

### 10.2 Cold-chain proof (2–8 °C unless the product says otherwise)
At **every** custody point (loading, receiving, event open, handover to the vet or CAHW):
- **Temperature reading:**
  - a current reading, plus the min and max since the last reading;
  - taken from an electronic logger, a thermometer in the cold box, or Fleet-Ops telematics if the fridge or vehicle has a connected sensor.
- **Vaccine vial monitor (VVM) stage**, where the product has one: select the stage and take a photo.
- **Cold box condition:** ice packs or coolant present and conditioned.

Rules:
- An **excursion** is above 8 °C for more than 60 minutes in total, or **any** reading below 2 °C (freezing).
- On an excursion the app **blocks** the handover, **quarantines** the lot at that location, and raises AR-14 (Critical). A cold-chain officer decides whether to release or discard.
- **VVM at or past the discard point** blocks and raises AR-15.

### 10.3 Optional administration log
The vet or CAHW can later record doses **administered**, **wasted** and **returned** per village. This allows dose reconciliation (`REPORTING-AUDIT.md` R-06). It's optional, because administration happens after delivery.

## 11. Event and route reconciliation
For each sku and lot:

```
opening_stock = Σ handed_over + closing_stock + Σ discrepancies
```

- Tolerance: default **0** units (AR-01). The event or route can't close while a variance is unexplained.
- The officer **and** at least one witness sign the closing summary.
- The summary becomes the **Event ePOD**: the handover list, totals by sex and age band, reconciliation, cold-chain readings, witnesses and seal hash.
- Closing stock goes back on a counted return leg (§4), or carries over with a count.

## 12. Fraud and leakage controls
The detailed rules, thresholds and actions are in `REPORTING-AUDIT.md` §5. The main ones:
- **Double collection:** AR-03 and AR-20.
- **Ghost beneficiaries:** AR-09 and AR-16, plus identity levels and verification sampling.
- **Diversion in transit:** AR-01, AR-02, AR-23 and AR-28.
- **Officer misconduct:** AR-05, AR-07, AR-08, AR-12, AR-21, plus segregation of duties.

## 13. Offline behaviour
As in v1, for both distribution points and door-to-door routes:
- The **event or route manifest** (beneficiaries, photo thumbnails, entitlements, card keys, the revocation list, recall and restricted lists, household locations) downloads on depot Wi-Fi.
- A full event or route runs offline.
- Several devices at one event are reconciled on sync: the first sealed handover wins.
- Photos are about 150 KB each.
- Cold-chain readings are tiny, and they sync in the metadata phase with priority.

## 14. Usability (English)
- The app, the console, SMS receipts and all reports are in **English**. The console's translation system is left in place, so another language can be added later without code changes.
- Farmer-facing steps are **icon-first**. Every action has an icon and a short English label, and quantities are shown as pictures of bags or doses next to the number.
- **Target:** one handover in under 60 seconds (scan card → confirm face → confirm quantities → photo → mark).
- **SMS receipt** (if the farmer has a phone), for example: `MoA Input Prog: You received 1x Seed Rice 25kg, 2x NPK 50kg on 12 Mar at Kpandebu DP. Ref H-7F3K. Problem? SMS HELP H-7F3K to 1234.`

## 15. Privacy and consent
As in v1, plus:
- ID documents are stored as **type + last 4 digits only**.
- Household members' consent is recorded by the head of household at registration.
- Default retention is **7 years** (a common donor and government requirement). It can be set per programme (`REPORTING-AUDIT.md` §8).

## 16. West Africa considerations

| Topic | Design decision |
|---|---|
| **Data protection** | The ECOWAS Supplementary Act on Personal Data Protection (A/SA.1/01/10, 2010) applies regionally, and most countries also have a national law and a data protection authority. Before go-live, confirm the national requirements of each country of operation: registration with the authority, consent wording, cross-border transfer rules. Fleetbase can be **self-hosted in the country or region**, which meets any requirement to keep data local. |
| **IDs** | The ID types are configurable per country (national ID number, voter's card, passport, driver's licence, birth certificate, other). Only the type and last 4 digits are stored. |
| **Phones** | Numbers are stored in E.164 format with the country code. Many farmers **share phones** within the household, so the call-back script (`REPORTING-AUDIT.md` §6.2) confirms which person is speaking. |
| **SMS / USSD** | The SMS provider is pluggable: Twilio, or a **local aggregator** with a direct connection to the national mobile networks, which usually gives better delivery and cost. USSD complaint menus need an aggregator agreement for each country. |
| **Planting windows** | Planting windows differ between agro-ecological zones (for example Sahel against coastal or forest zones). `planting_window_start` and `planting_window_end` are set **per district or zone** in the programme rules, not once per programme. Timeliness (I-07, I-08) uses the window for the beneficiary's district. |
| **Rainy season access** | Roads may close during the rainy season. `road_inaccessible` failures automatically suggest moving the farmer to the nearest distribution point. Route planning can mark roads or villages as seasonal. |
| **Power** | Devices may not be charged for days. The app has a battery-saver mode: GPS sampling reduced to 1 point per 2 minutes between stops, screen dimmed, and media uploads only when the battery is above 30%. Field teams should carry power banks or solar chargers. |
| **Units** | Standard bag sizes (e.g. 25 kg and 50 kg) are set per sku. Dates are shown as DD/MM/YYYY. |
| **Language** | English, as decided. The translation system stays in place, so French or Portuguese can be added later for francophone or lusophone programmes without code changes. |
| **Security** | `security_issue` is a failure reason. A programme manager can suspend all distributions in an area, which blocks routes and events there until lifted. |

## 17. Revised delivery plan

| Phase | Scope | Est. |
|---|---|---|
| 0 | Fleet-Ops proof guard (DESIGN.md) | 3–4 days |
| 1 | PoD core with counted quantities and lots; transport legs with seals, waybills and the Transporter role (Navigator for staff and hired truckers, optional vehicle trackers); discrepancies | 3 weeks |
| 2 | Programmes; **Excel import with column mapping and de-duplication; paper list digitisation with double entry; field registration and verification mode**; entitlements; programme cards (printable PDF) and revocation | 3.5 weeks |
| 3 | Distribution events and door-to-door routes: open, handover, failed attempts and reassignment, reconcile, close; Event ePOD | 3 weeks |
| 4 | Navigator: offline event and route mode, card scanning, identity levels, icon-first English UI | 3–4 weeks |
| 5 | Pesticide and veterinary rules; cold-chain readings and blocking; optional dose log | 2 weeks |
| 6 | Audit rules engine, cases and findings, verification sampling, post-distribution monitoring (PDM) module, **third-party monitor accounts and blind samples** (`REPORTING-AUDIT.md`) | 3.5 weeks |
| 7 | Report catalogue, dashboards, audit pack export | 2 weeks |

**Total:** about 19–23 weeks with one team.
- Onboarding (phase 2) can start in parallel with phase 1, so list cleaning and field verification are under way before the first distribution.
- A one-district pilot can run after phase 5. Audit and reporting are finished during the pilot.

## 18. Remaining questions
1. **Which West African countries first?** This sets the ID types, phone country codes, SMS aggregator and data protection registration.
2. **Scale:** number of farmers, districts and distribution events per season. This affects PDM sample sizes and server sizing.
3. **Hosting:** run it on your own server in the country, a regional cloud, or Fleetbase Cloud? This depends on government data-residency expectations.
4. **Paper lists:** roughly how many pages or farmers are on paper, so data-entry staffing can be planned.

Resolved in v3:
- beneficiary lists (Excel and paper → §7.1);
- truckers use the app, and vehicle GPS is optional (§4.3);
- no funder report formats;
- region is West Africa (§16);
- M&E, auditors and third parties all do visits (`REPORTING-AUDIT.md` §6.3).
