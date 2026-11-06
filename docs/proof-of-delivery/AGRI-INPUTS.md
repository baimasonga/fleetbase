# Proof of Delivery: Agricultural Inputs Profile

Status: **Draft for review, v2** (updated with the confirmed operating model)
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

## 2. What changes compared with commercial delivery

| Area | Commercial (DESIGN.md) | Agricultural inputs |
|---|---|---|
| Recipient | Whoever is at the address | A **specific registered farmer**, a registered household member, or a registered proxy |
| Unit of proof | One delivery per stop | Distribution point: **one stop, many handovers**. Door-to-door: one handover per household. |
| What is proven | "Parcel delivered" | **Quantity per input line** (bags, kg, litres, doses), lot/batch, condition, and temperature for cold-chain items |
| Main identity proof | Signature or OTP | **Programme card QR**, ID check where available, photo, mark or thumbprint, witness |
| Main risks | Lost or disputed parcels | **Double collection, ghost beneficiaries, diversion in transit, theft by transporters, broken cold chain, unsafe pesticide handling** |
| Accountability | Customer and billing | Funder or government audit: reconciliation to the bag or dose (see `REPORTING-AUDIT.md`) |

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
- **If the trucker installs Navigator:** live GPS, plus a geofence check on arrival.
- **If they don't:** the leg relies on the loading and receiving records, the seals and the waybill. Expected travel time is checked (audit rule AR-23). Optional: a GPS tracker clipped to the load, connected through Fleet-Ops telematics.
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
- `name`, `operator_type` (`ngo`, `project`, `government`), `funder`, `season`
- `planting_window_start`, `planting_window_end`
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

## 7. Identifying farmers (with or without an ID document)

**Everyone gets a programme card at registration**, a QR code signed by the server, so every farmer has at least one machine-checkable credential. Each handover is given an **identity level**:

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
- **Diversion in transit:** AR-01, AR-02 and AR-23.
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

## 16. Revised delivery plan

| Phase | Scope | Est. |
|---|---|---|
| 0 | Fleet-Ops proof guard (DESIGN.md) | 3–4 days |
| 1 | PoD core with counted quantities and lots; transport legs with seals, waybills and the Transporter role; discrepancies | 3 weeks |
| 2 | Programmes; beneficiary and household import (CSV/XLSX); entitlements; programme cards (printable PDF) and revocation | 2 weeks |
| 3 | Distribution events and door-to-door routes: open, handover, failed attempts and reassignment, reconcile, close; Event ePOD | 3 weeks |
| 4 | Navigator: offline event and route mode, card scanning, identity levels, icon-first English UI | 3–4 weeks |
| 5 | Pesticide and veterinary rules; cold-chain readings and blocking; optional dose log | 2 weeks |
| 6 | Audit rules engine, cases and findings, verification sampling, post-distribution monitoring (PDM) module (`REPORTING-AUDIT.md`) | 3 weeks |
| 7 | Report catalogue, dashboards, audit pack export | 2 weeks |

**Total:** about 18–21 weeks with one team. A one-district pilot can run after phase 5, with audit and reporting finished during the pilot.

## 17. Remaining questions
1. Is there an existing beneficiary list or registry, and in what format? Or does registration happen in the app?
2. Scale: number of farmers, districts and distribution events per season. This affects sample sizes and server sizing.
3. Will hired truckers install Navigator, or should we plan for load-mounted GPS trackers?
4. Do specific funders require their own report templates (for example a USAID, FAO or World Bank format)? The R-10 report can be mapped to them.
5. Which country's data-protection law applies, and does the government need data stored in the country (self-hosting location)?
6. Who carries out post-distribution monitoring: an M&E team in the programme, or an independent third party?
