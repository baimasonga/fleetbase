# Proof of Delivery: Agricultural Inputs Profile

Status: **Draft for review, v5** (own servers, pilot size, Sierra Leone seed list)
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
| Countries | **English-speaking West Africa**: Sierra Leone, Liberia, Ghana, Nigeria, The Gambia. **Sierra Leone is the default** country profile (§16.1). |
| Scale | Set by each **programme's beneficiary capacity**. There is no fixed limit; sizing guidance is in §16.4. |
| Hosting | **Own servers** (option A in §16.3), self-hosted. **Not Fleetbase Cloud.** |
| First deployment size | **Pilot tier**, up to 20,000 beneficiaries per season (§16.4) |
| Beneficiary lists | **Mostly Excel** (soft copies, in different layouts); a few on paper. They're imported and verified (§7.1). |
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
- `planting_windows` (JSON per district or agro-ecological zone: start and end; §16.2)
- `rules` (JSON: delivery models, proxy and household-member rules, witness rules, pesticide and veterinary rules, audit thresholds overriding the defaults in `REPORTING-AUDIT.md`)

**`pod_beneficiaries`**:
- `programme_uuid`, `farmer_ref`, `type` (`farmer` or `animal_health_worker`), `name`
- `sex`, `birth_year`, `disability` (optional, for disaggregation)
- `admin_1`, `admin_2`, `admin_3`, `admin_4`, `village`, `household_location` (point, nullable). The labels come from the country profile (§16.1); for Sierra Leone: region, district, chiefdom, section.
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

### 7.1 Bringing in the existing lists (mostly Excel)
Most lists are **Excel soft copies** in different layouts. Excel import (step 1) is the main path, and paper digitisation (step 2) is for the few lists still on paper. Every record keeps a link to where it came from (`source`).

**Standard template (going forward).** The console offers a downloadable **Farmer List Template (.xlsx)** for each country profile. It has:
- fixed columns;
- drop-down lists for sex, ID type and admin areas (for Sierra Leone: region → district → chiefdom);
- a phone number column checked for the country format (+232 for Sierra Leone);
- an instructions sheet.

Partners who use the template need no column mapping, and far fewer rows are rejected. Existing files in other layouts are still imported through the mapping wizard.

**Step 1: Excel import** (console → Programme → Import beneficiaries)
- Upload `.xlsx`, `.xls` or `.csv`, then pick the sheet and header row.
- **Column mapping wizard:** match the file's columns to beneficiary fields. Saved mappings can be reused for the next file in the same format.
- **Normalisation:**
  - **names:** trim, collapse spaces, title case;
  - **sex:** `M`/`F`/`Male`/`Female`/`1`/`2` → standard values;
  - **age:** year of birth, or age in years → `birth_year`;
  - **phone:** converted to the international format (E.164) using the country code;
  - **places:** admin-area and village names (for Sierra Leone: region, district, chiefdom, section) matched against the country profile's list (§16.1), with suggestions for misspellings.
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

## 16. Countries and deployment

### 16.1 Country profiles (Sierra Leone is the default)
Country-specific settings live in a **country profile**. A new programme takes **Sierra Leone** unless another country is chosen. A profile can be edited, so details can be corrected locally without code changes.

| Setting | **Sierra Leone (default)** | Liberia | Ghana | Nigeria | The Gambia |
|---|---|---|---|---|---|
| Phone country code | **+232** | +231 | +233 | +234 | +220 |
| Time zone | **GMT (UTC+0)** | GMT | GMT | WAT (UTC+1) | GMT |
| Admin levels (labels) | **Region → District → Chiefdom → Section → Village** | County → District → Clan → Town | Region → District → Area council → Community | State → LGA → Ward → Community | Region → District → Village |
| ID types offered | **National ID (NIN), Voter ID card, Passport, Driver's licence, Other** | National ID, Voter card, Passport, Other | Ghana Card, Voter ID, Passport, Other | NIN, Voter's card (PVC), Passport, Other | National ID, Voter card, Passport, Other |
| SMS provider | **A local aggregator connected to the Sierra Leone networks (e.g. Orange, Africell, Qcell)**, or Twilio | Local aggregator or Twilio | Local aggregator or Twilio | Local aggregator or Twilio | Local aggregator or Twilio |

The lists and labels above are **starting values**. Admin area names are loaded from an official list per country (CSV import).

**Sierra Leone seed list (decided):** [`packages/pod/server/data/seed/sierra-leone/admin-areas.csv`](../../packages/pod/server/data/seed/sierra-leone/admin-areas.csv) holds 5 regions, 16 districts, 167 chiefdoms and 1,316 sections. It comes from the Government of Sierra Leone's administrative boundaries dataset (COD-AB v02), published by OCHA on HDX under CC BY-IGO. It's keyed by official p-code.
- "Fabala" is corrected to Falaba.
- 67 names that may have been cut off in the source are flagged for local confirmation during the pilot.
- Villages are added under their section from beneficiary lists and field registration.

See the [seed data README](../../packages/pod/server/data/seed/sierra-leone/README.md) for the source, columns and known issues.

Common to all profiles:
- English;
- dates as DD/MM/YYYY;
- standard bag sizes per sku (e.g. 25 kg and 50 kg).

### 16.2 Regional considerations

| Topic | Design decision |
|---|---|
| **Data protection** | The ECOWAS Supplementary Act on Personal Data Protection (A/SA.1/01/10, 2010) applies regionally. National laws include, for example, Ghana's Data Protection Act 2012 (Act 843) and Nigeria's Data Protection Act 2023. **For Sierra Leone, Liberia and The Gambia, confirm the current national law and any registration requirement with legal counsel before go-live.** The consent wording at registration is set per country profile. Self-hosting (§16.3) lets you keep data in the country. |
| **Phones** | Phones are often shared in the household; see the call-back script in `REPORTING-AUDIT.md` §6.2. |
| **SMS / USSD** | The SMS provider is pluggable per country profile. USSD complaint menus need an aggregator agreement in each country. |
| **Planting windows** | Set **per district or zone** in the programme rules. Timeliness (I-07, I-08) uses the beneficiary's own district. |
| **Rainy season access** | `road_inaccessible` failures suggest moving the farmer to the nearest distribution point. Villages or roads can be marked as seasonal. |
| **Power** | Battery-saver mode (GPS every 2 minutes between stops, dimmed screen, media upload only above 30% battery). Field teams carry power banks or solar chargers. |
| **Language** | English. The translation system stays in place for later additions. |
| **Security** | A programme manager can suspend distributions in an area. |

### 16.3 Hosting (self-hosted, not Fleetbase Cloud)
The system runs entirely on infrastructure you control. **Decision: option A (own servers)**, at the pilot size. Option B stays documented for later growth.

| | **A. Own servers** (office or local data centre) | **B. Cloud provider of your choice** (AWS, Azure, GCP, or a local/regional provider) |
|---|---|---|
| Install | Docker Compose from this repository (`scripts/docker-install.sh`) | Docker Compose on a VM for smaller deployments; Kubernetes with the Helm chart in `infra/helm/` for larger ones |
| Database | MySQL 8 on the server (the bundled container or a separate instance) | Managed MySQL 8 (e.g. RDS, Cloud SQL, Azure Database for MySQL) |
| File storage (photos, signatures, ePODs) | MinIO (S3-compatible) on the server, or local disk for small pilots | The provider's object storage (S3, GCS, Azure Blob through an S3-compatible gateway) |
| Data location | In the country | The provider's region. Major cloud providers have few or no regions in West Africa, so check what's available and whether national law allows data outside the country. If it doesn't, use option A or a local provider. |
| Power and network | **UPS and generator**, plus two internet links (two ISPs), because the phones sync to this server | Handled by the provider |

Both options need:
- **TLS** (Caddy, whose config is in the repository, or another reverse proxy);
- the production hardening from the adoption notes (a database password, no public database port, socket origins set, socket auth on, pinned image versions);
- **backups:**
  - nightly database dumps and file storage replication to a **second location** (another server, another cloud account or region);
  - kept 30 days rolling plus monthly copies for the retention period (`REPORTING-AUDIT.md` §8);
  - a restore test every quarter;
- **monitoring:** uptime checks, disk space, queue length, and sync failure rates from the PoD module;
- **object-lock or write-once (WORM) storage** for evidence and the daily audit-log hashes (`DESIGN.md` §7), where the storage supports it (MinIO and S3 both do).

**Pilot hardware on own servers (option A, decided)**

The minimum for the pilot tier is 4 vCPU and 8 GB RAM. On your own servers, hardware takes a long time to upgrade, so the recommendation adds headroom:

| Item | Recommendation |
|---|---|
| Main server | 1 × server with 8 cores, 32 GB RAM, **2 × 1 TB SSD in RAID 1** (mirrored), Ubuntu Server LTS. Runs the whole Docker Compose stack: application, queue, scheduler, socket, MySQL, Redis, MinIO, Caddy and OSRM. |
| Map routing (OSRM) | On the main server, with the Sierra Leone map extract (small, about 1–2 GB RAM). Add neighbouring countries later. |
| Backup target | A second machine or NAS (network storage) with at least 2 TB, **in a different building or site**. It receives the nightly database dumps and MinIO replication. Add a monthly encrypted copy to off-site storage (a removable drive kept in a safe, or a cloud bucket if national law allows). |
| Power | An online UPS for at least 2 hours, plus a generator or solar with battery. The server shuts down safely when the UPS is low. |
| Internet | **Two ISPs** (e.g. fibre plus a 4G/5G router as failover), and a **static public IP** or a DNS name with dynamic DNS for the API address the phones use |
| Network security | A firewall that only allows ports 443 (HTTPS) and the socket port in from the internet. Admin access only through a VPN. |
| Spare | A spare SSD, and a documented rebuild procedure (restore to a fresh server from backup), tested once before go-live |
| Estimated storage use | About 10 GB per pilot season (§16.4). 1 TB covers the 7-year retention with plenty of room. |

**No dependency on Fleetbase Cloud:**
- The PoD extension is installed from your own packages (`packages/pod`), not from the Fleetbase extension registry.
- The deployment's `REGISTRY_HOST` and `REGISTRY_PREINSTALLED_EXTENSIONS` settings (see `docker-compose.yml`) point at the public Fleetbase registry by default. Phase 8 confirms the install works with those disabled or pointing at your own registry, so nothing depends on Fleetbase services.
- The driver app (Navigator, open source) is **built and signed by you**, pointing to your API address, and distributed through Google Play (private or unlisted) or as an APK through device management (MDM). Field phones are expected to be **Android**.
- Map routing: use your own OSRM server with the West Africa map extract, instead of the public `router.project-osrm.org` demo server. Google Maps is optional.

### 16.4 Sizing guide (by programme capacity)
Scale depends on each programme's beneficiary capacity, so size the deployment by the **largest season you expect across all active programmes**:

| Tier | Beneficiaries per season (all programmes) | Hosting | Server (application + queue + socket) | Database | Storage per season* |
|---|---|---|---|---|---|
| **Pilot (chosen)** | Up to 20,000 | A or B, single VM with Docker Compose. **Decided: A (own server), see hardware in §16.3.** | 4 vCPU, 8 GB RAM minimum | Same VM | ~10 GB |
| Standard | 20,000–150,000 | A or B, Docker Compose on 2 VMs (application, database) | 8 vCPU, 16 GB RAM | 4 vCPU, 16 GB RAM, SSD | ~60 GB |
| Large | 150,000–1,000,000 | B (or a well-equipped data centre), Kubernetes with Helm, 2+ application replicas | 3 × 8 vCPU, 16 GB RAM | Managed MySQL, 8 vCPU, 32 GB RAM, a read replica for reports | ~400 GB |

\* Approximate: one 150 KB photo per handover, signatures, ePOD PDFs and registration photos, for about 2 handovers per beneficiary per season. Storage grows each season for the retention period (7 years by default).

The peak load comes from **end-of-day syncs** when teams return to depots. Test with the k6 load test (`tests/k6/`) plus a PoD sync scenario sized to the tier before go-live.

## 17. Revised delivery plan

| Phase | Scope | Est. |
|---|---|---|
| 0 | Fleet-Ops proof guard (DESIGN.md) | 3–4 days |
| 1 | PoD core with counted quantities and lots; transport legs with seals, waybills and the Transporter role (Navigator for staff and hired truckers, optional vehicle trackers); discrepancies | 3 weeks |
| 2 | Programmes; **country profiles (Sierra Leone default) and admin-area import**; **Excel import with template, column mapping and de-duplication** (main path); paper list digitisation with double entry (minor); field registration and verification mode; entitlements; programme cards (printable PDF) and revocation | 3.5 weeks |
| 3 | Distribution events and door-to-door routes: open, handover, failed attempts and reassignment, reconcile, close; Event ePOD | 3 weeks |
| 4 | Navigator: offline event and route mode, card scanning, identity levels, icon-first English UI | 3–4 weeks |
| 5 | Pesticide and veterinary rules; cold-chain readings and blocking; optional dose log | 2 weeks |
| 6 | Audit rules engine, cases and findings, verification sampling, post-distribution monitoring (PDM) module, **third-party monitor accounts and blind samples** (`REPORTING-AUDIT.md`) | 3.5 weeks |
| 7 | Report catalogue, dashboards, audit pack export | 2 weeks |
| 8 | Own-server production setup: server, UPS and ISP installation; Docker Compose stack with MinIO and OSRM (Sierra Leone extract); backups to the second site and a restore test; monitoring; own Navigator build and distribution; load test at pilot tier | 1.5 weeks (can overlap with phases 6–7; hardware should be ordered early) |

**Total:** about 20–24 weeks with one team (19–23 if phase 8 overlaps).
- Onboarding (phase 2) can start in parallel with phase 1, so list cleaning and field verification are under way before the first distribution.
- A one-district pilot can run after phase 5. Audit and reporting are finished during the pilot.

## 18. Remaining questions
1. **Sierra Leone data protection:** confirm the current legal requirements with counsel (§16.2).
2. **Seed list review:** during the pilot, a local person confirms the 67 flagged names and checks for chiefdom or section changes since 2017 (seed data README).
3. **Server location and ISPs:** where the server and the backup site will be, and which two internet providers, so hardware and connections can be ordered early.

Resolved in v5:
- own servers (§16.3), pilot tier (§16.4);
- Sierra Leone seed list from the official dataset (§16.1).

Resolved in v4:
- English-speaking West Africa, with Sierra Leone as the default (§16.1);
- scale is set by programme capacity (§16.4);
- self-hosted, not Fleetbase Cloud;
- farmer lists are mostly Excel (§7.1).

Resolved in v3:
- truckers use the app, and vehicle GPS is optional (§4.3);
- no funder report formats;
- M&E, auditors and third parties all do visits (`REPORTING-AUDIT.md` §6.3).
