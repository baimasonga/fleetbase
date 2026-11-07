# Proof of Delivery: Reporting and Audit Framework (Agricultural Inputs)

Status: **Draft for review**
Applies to: [`AGRI-INPUTS.md`](AGRI-INPUTS.md), for programmes run by NGOs, projects and governments in English-speaking West Africa (Sierra Leone by default).

Funders don't require specific report formats. The reports here are for programme management, government partners and audit, and can be shared with funders as they are.

This document defines:
- what is reported, and how each figure is calculated (§3–4);
- the **audit rules** the system checks automatically (§5);
- the **audit methods** used to verify that distribution really happened as recorded (§6);
- how findings are handled until they are closed (§7);
- the audit trail and retention (§8).

All thresholds are **defaults**. A programme can override them in `pod_programmes.rules.audit`. Every override is logged.

## 1. Principles

1. **Traceable numbers.** Every reported figure can be drilled down to the sealed handover, leg or event records behind it. Reports never use figures typed in by hand.
2. **Separation of duties.** Nobody approves, voids or audits their own work (§2).
3. **Automated first, then sampled.** The system checks 100% of records against the rules. People verify a statistically chosen sample in the field.
4. **Risk-based.** Records and locations with flags are sampled more heavily.
5. **Independence.** Verification (call-backs, post-distribution monitoring, spot checks) is done by staff who did **not** take part in the distribution being checked.
6. **Every finding is closed.** Each flag becomes a case with an owner, a deadline (SLA) and a recorded outcome.

## 2. Roles and separation of duties

| Role | Can | Cannot |
|---|---|---|
| **Field officer** | Open and close events or routes, record handovers, raise discrepancies | Void records; resolve cases about their own work; edit beneficiaries |
| **Storekeeper** | Load and receive legs, count stock, record seals | Close a leg they loaded; do farmer handovers |
| **Transporter** (staff or hired) | View their own legs, share location, sign | Close legs, open events, do handovers |
| **Witness** (chief, group leader, extension officer) | Sign event open and close, L1 identity and proxy handovers | Use the console |
| **Cold-chain officer** | Release or discard quarantined cold-chain lots | Release a lot they handled in the same leg |
| **Programme manager** | Approve overrides (AR-13, bypass), void with a reason, reassign entitlements, approve beneficiary changes | Audit; close cases about their own approvals |
| **M&E officer** | Run sampling, call-backs and PDM; produce reports | Change distribution records |
| **Internal auditor** | Read everything, including evidence; run integrity checks; create findings; close cases | Change distribution records |
| **Third-party monitor (TPM)** (independent organisation) | Field visits and call-backs on its **own blind sample**; record PDM and observation results; see summaries of its findings | See any beneficiary outside its sample; see programme-staff PDM results before submitting its own; change any distribution record |
| **Government / partner viewer** | See aggregated reports and audit pack summaries | See farmer-level personal data (unless granted in writing) |

The system enforces these as IAM roles and policies. It also refuses any action where the actor is the same user who created the record under review (for example a void, a case resolution or a release).

## 3. Indicators

Each indicator has an ID, a formula and a default target. All indicators can be broken down by:
- **programme / funder**, **season**, **admin areas from the country profile** (Sierra Leone default: region → district → chiefdom → section → village);
- **delivery model** (distribution point or door-to-door), **input** (sku and category), **transporter**;
- **sex**, **age band** (15–24, 25–35 (youth), 36–59, 60+), **disability** (where recorded).

### 3.1 Reach and fulfilment

| ID | Indicator | Formula | Default target |
|---|---|---|---|
| I-01 | Farmers reached | Count of distinct beneficiaries with at least one sealed `delivered`/`partial` handover | Plan |
| I-02 | Coverage | I-01 ÷ registered active beneficiaries | ≥ 95% |
| I-03 | Entitlement fulfilment | Σ quantity handed over ÷ Σ quantity entitled (per sku) | ≥ 98% |
| I-04 | Full fulfilment rate | Beneficiaries with every entitlement `collected` ÷ beneficiaries with entitlements | ≥ 95% |
| I-05 | Women reached | Female share of I-01 | Programme-set (e.g. ≥ 40%) |
| I-06 | Youth reached | Share of I-01 aged 15–35 | Programme-set |

### 3.2 Timeliness

| ID | Indicator | Formula | Target |
|---|---|---|---|
| I-07 | On-time delivery | Quantity handed over **before** the planting window start **for the beneficiary's district or zone** ÷ total handed over | ≥ 90% |
| I-08 | Late delivery | Quantity handed over **after** the planting window end for the district or zone ÷ total | ≤ 2% |
| I-09 | Leg transit time | Median (received − loaded) per leg type and transporter | Within expected |

### 3.3 Stock integrity

| ID | Indicator | Formula | Target |
|---|---|---|---|
| I-10 | Loss rate | Σ discrepancy quantity (short, damaged, expired, theft, excursion) ÷ Σ quantity issued from the central warehouse | ≤ 0.5% |
| I-11 | Loss rate by leg | I-10 split by leg type, transporter and store | ≤ 0.5% each |
| I-12 | Seal integrity | Legs with `seal_status = intact` ÷ legs with seals | 100% |
| I-13 | Reconciled events | Events or routes closed with zero unexplained variance ÷ events or routes closed | 100% |
| I-14 | End-of-season balance | Central issues − (Σ handovers + Σ returns + Σ discrepancies), per lot | 0 |

### 3.4 Proof quality and verification

| ID | Indicator | Formula | Target |
|---|---|---|---|
| I-15 | PoD completeness | Sealed handovers with every required piece of evidence ÷ handovers | 100% |
| I-16 | Identity strength | Share of handovers at L3 / L2 / L1 | L1 ≤ 10% |
| I-17 | Proxy rate | Proxy handovers ÷ handovers | ≤ 10% |
| I-18 | Flag rate | Handovers with at least one flag ÷ handovers | ≤ 5% |
| I-19 | Call-back confirmation | Call-backs confirming receipt of the correct quantity ÷ call-backs completed | ≥ 95% |
| I-20 | PDM confirmation | PDM visits confirming receipt ÷ PDM visits completed | ≥ 95% |
| I-21 | Duplicate rate | Confirmed duplicate handovers ÷ handovers | 0% |
| I-22 | Sync latency | Median (server received − trusted capture time) for metadata | ≤ 2 h |

### 3.5 Safety and cold chain

| ID | Indicator | Formula | Target |
|---|---|---|---|
| I-23 | Pesticide compliance | Restricted-product handovers to trained recipients with `safety_ack` (and PPE, where entitled) ÷ restricted-product handovers | 100% |
| I-24 | Cold-chain compliance | Cold-chain custody points with readings in range ÷ cold-chain custody points | 100% |
| I-25 | Cold-chain losses | Doses discarded for excursion or VVM ÷ doses issued | ≤ 1% |
| I-26 | Dose reconciliation (optional) | (administered + wasted + returned) ÷ doses received by vets and CAHWs | 100% |

### 3.6 Accountability to farmers

| ID | Indicator | Formula | Target |
|---|---|---|---|
| I-27 | Complaints received | Count, by type | — |
| I-28 | Complaint resolution | Complaints closed within 14 days ÷ complaints | ≥ 90% |
| I-29 | Substantiated complaints | Complaints found valid ÷ complaints closed | Monitored |

### 3.7 Door-to-door

| ID | Indicator | Formula | Target |
|---|---|---|---|
| I-30 | First-attempt success | Households served on the first attempt ÷ households attempted | ≥ 85% |
| I-31 | Reassignment rate | Entitlements moved from door-to-door to a distribution point ÷ door-to-door entitlements | ≤ 10% |

### 3.8 Beneficiary list quality

| ID | Indicator | Formula | Target |
|---|---|---|---|
| I-32 | Verified beneficiaries | `verified` ÷ beneficiaries on the season's list, by source (Excel, paper, field) | 100% before distribution |
| I-33 | List rejection rate | `rejected` (not known, moved, deceased, duplicate) ÷ beneficiaries checked, by source and district | Monitored (a high rate means a poor source list) |
| I-34 | Field additions | Beneficiaries added in the field ÷ beneficiaries on the season's list | ≤ 5% |
| I-35 | Paper entry accuracy | Fields matching in the double-entry check ÷ fields checked | ≥ 98% |
| I-36 | Independent agreement | TPM confirmation rate − internal M&E confirmation rate (I-20 by visitor type) | Within ±5 percentage points |

## 4. Report catalogue

All reports are in English. They export as **PDF** (signed, with seal hashes), **XLSX** and **CSV**. Each one shows the generation time, the filters used and a dataset hash, so an exported report can be matched to the system later.

| ID | Report | Audience | Frequency | Content |
|---|---|---|---|---|
| R-01 | **Operations dashboard** | Programme manager, field leads | Live | Today's events and routes, handovers, sync status per device, open flags, stock in transit, cold-chain alerts |
| R-02 | **Event / Route ePOD** | Programme file, auditors | Per event or route | The handover list, totals by sex and age band, opening, closing and reconciliation, discrepancies, cold-chain readings, witnesses, seal hash |
| R-03 | **Transport leg report** (waybill reconciliation) | Logistics, finance | Per leg; weekly summary | Loaded against received per line and lot, seals, times, GPS track (if any), discrepancies, liable party |
| R-04 | **Weekly progress report** | Management, funder | Weekly | I-01–I-09 against plan, by district; top issues; open cases by severity |
| R-05 | **Stock, loss and transporter performance** | Logistics, audit | Monthly | I-10–I-14, Pallet stock per store and lot, losses by reason, transporter scorecard (losses, delays, seal breaches) |
| R-06 | **Cold-chain report** | Veterinary lead, audit | Weekly + on excursion | Readings per custody point, excursions, quarantines and outcomes, I-24–I-26 |
| R-07 | **Pesticide distribution register** | Regulator, safety lead | Monthly; on recall | Every restricted-product handover: recipient, training date, product, lot, quantity, PPE, safety ack. **Recall view** per lot. |
| R-08 | **Verification report** | M&E, audit | Monthly | Sample design, call-back and PDM results (I-19, I-20), findings, comparison against the recorded data |
| R-09 | **Complaints report** | Management, funder | Monthly | I-27–I-29, by type and location, with resolution status |
| R-10 | **End-of-season programme report** | Programme management, government partners (shareable with funders) | End of season | All indicators against targets with breakdowns, map of coverage, end-of-season balance (I-14), beneficiary list quality (I-32–I-35), audit summary including third-party monitoring results (I-36), lessons. One standard layout; no funder-specific templates. |
| R-11 | **Audit pack** | Internal/external auditors | On demand | R-02 for every event, R-03 for every leg, all findings and cases, the integrity check result (§6.7), access log extract, data dictionary |
| R-12 | **Beneficiary onboarding report** | Programme manager, M&E | During onboarding; weekly | Import results per file and paper list (accepted, merged, rejected, fix-up queue), verification progress by village, field additions awaiting approval, I-32–I-35 |
| R-13 | **Third-party monitoring report** | Programme management, auditors | Per TPM round | The TPM's sample, completion, findings and confirmation rate against internal M&E (I-36), with differences by district. The TPM submits it through its own role; programme staff can't edit it. |

Reports are built on the Fleet-Ops report schema, so the console's report builder can produce extra ad-hoc reports from the same data.

## 5. Audit rules (automated)

The **rules engine** runs:
- on every sync, for record-level rules;
- when an event, route or leg closes;
- nightly, for cross-record rules (duplicates, impossible travel, rates).

Every hit creates a **flag** and, at Medium severity or above, a **case** (§7).

Severity and what follows:
- **Critical**: blocks the related action or freezes the beneficiary or lot. The programme manager and the internal auditor are notified at once. SLA 24 h.
- **High**: opens a case for the programme manager. SLA 72 h.
- **Medium**: opens a case for the M&E officer or field lead. SLA 7 days.
- **Low**: flag only, shown on reports.

| ID | Rule | Threshold (default) | Severity | Automatic action |
|---|---|---|---|---|
| AR-01 | Count variance on a leg, event or route | Unexplained variance > 0 units | High | Block close until a discrepancy with evidence explains it |
| AR-02 | Seal mismatch, broken or missing | Any | High | Hold the received stock for a full recount; set the liable party to the transporter |
| AR-03 | Duplicate handover for the same entitlement | Any | Critical | Freeze the beneficiary's entitlements; void the later record once confirmed |
| AR-04 | Handover outside the geofence | > 500 m (point) / > 200 m (household) + GPS accuracy | Medium | Flag |
| AR-05 | Unusual handover rate per officer | > 60 handovers/hour (Medium), > 90/hour (High) | Medium / High | Flag the event for verification oversampling |
| AR-06 | Time anomaly | Trusted-time skew > 5 min, or handover outside the event's open–close window | Medium | Flag |
| AR-07 | High proxy rate | > 15% of the handovers at an event or route | Medium | Oversample the event's proxies for call-back |
| AR-08 | High L1 identity rate | > 20% of the handovers at an event or route | Medium | Oversample |
| AR-09 | Duplicate or near-duplicate photos | The same content hash, or a perceptual-hash distance below the threshold, across different beneficiaries | High | Flag both handovers |
| AR-10 | Impossible travel | The same officer or device records handovers at two places too far apart for the time between them (> 80 km/h implied) | High | Flag |
| AR-11 | Late sync | Metadata > 12 h after capture (Low); media > 24 h (Medium) | Low / Medium | Flag; notify the officer's lead |
| AR-12 | High void rate | Voids > 2% of an officer's handovers in a season | Medium | Review case |
| AR-13 | Restricted product override | A pesticide or veterinary drug handed to an untrained recipient, or above the cap | High | Needs programme manager approval beforehand; reported in R-07 |
| AR-14 | Cold-chain excursion | > 8 °C for > 60 min in total, or any reading < 2 °C | Critical | Block handovers of the lot; quarantine; cold-chain officer decides |
| AR-15 | VVM at or past the discard point | Stage 3 or 4 | Critical | Block; discard workflow |
| AR-16 | Verification failure | A call-back or PDM visit reports "not received" or a different quantity; or an event's confirmation rate < 90% | High | Investigation case; if the event rate is low, verify the whole event |
| AR-17 | Complaint overdue | Not resolved within 14 days | Medium | Escalate to the programme manager |
| AR-18 | Proof bypass used | Any `bypass_proof` | High | Review case |
| AR-19 | Expired, recalled or revoked lot attempted | Any | High | Block the line (already blocked on the phone); flag |
| AR-20 | Over-fulfilment | `collected_quantity` > entitled quantity | Critical | Freeze; investigate (this should be impossible) |
| AR-21 | Conflict of interest | The officer, storekeeper or witness is also a beneficiary of the handover or event (matched on registry ID, phone or name + village) | Critical | Block the handover; the programme manager reassigns |
| AR-22 | Integrity check failure | A seal-hash or device-signature check fails, or there's a gap in the hash chain | Critical | Freeze the records; auditor investigates |
| AR-23 | Transport anomaly | Transit time > 2 × expected; or, with GPS, route deviation > 10 km or a stop > 60 min outside known places | Medium | Flag; check against AR-01 and AR-02 results |
| AR-24 | Beneficiary data change during distribution | Phone, photo, ID or household members changed less than 7 days before or during their event | Medium | Flag; needs second approval |
| AR-25 | Unusually uniform records | > 30% of an event's handovers have identical timestamps to the minute, or the same thumbprint image hash | High | Flag the event for full verification |
| AR-26 | Independent results disagree | The TPM confirmation rate for a district is more than 5 percentage points below the internal M&E rate, or the TPM finds "not received" where the internal sample confirmed receipt | High | Open a case for the internal auditor (not the programme manager); extend the TPM sample in that district |
| AR-27 | Unverified or field-added beneficiary | Entitlement generated or handover made for a beneficiary who isn't `verified`, or a field addition without programme manager approval | Medium | Block entitlement generation until approved; oversample if handed over |
| AR-28 | Transporter track gap or mismatch | Gap > 60 min in the transporter's phone track during a leg, or the phone and vehicle tracker more than 2 km apart for > 15 min | Medium | Flag the leg; check against AR-01 and AR-02 results |

Rule thresholds and severities live in `config/pod-agri-audit.php` and can be overridden per programme. Each rule has unit tests with positive and negative cases.

## 6. Audit methods

### 6.1 Continuous automated monitoring
The rules engine (§5) checks **100%** of records. The flag rate (I-18) and open cases are on R-01 and R-04.

### 6.2 Remote verification (call-backs)
- **Who is called:** handovers whose beneficiary has a phone. Phones are often shared in a household, so the caller first asks for the beneficiary by name and confirms they're the person on the line (programme card number and village), or records who answered.
- **Sample size:** 5% of the season's handovers, with **at least 10 per event or route**. Double for flagged events and for L1, proxy and household-member handovers.
- **Selection:** random, drawn by the system (seeded, recorded, so it can be reproduced). The M&E officer can't choose who to call.
- **Caller:** M&E staff who weren't at the event, using a script in English with a local-language interpreter where needed. Results are entered in the console.
- **Script:**
  1. Confirm identity using the programme card number and name.
  2. "Did you receive inputs on {date} at {place}?"
  3. "Which items and how many?"
  4. "Did anyone ask you for payment or a favour?"
  5. "Were the inputs in good condition?"
- **Outcomes:** `confirmed`, `quantity_mismatch`, `not_received`, `payment_requested`, `unreachable` (try 3 times, then replace with the next person in the sample).
- Any result other than `confirmed` or `unreachable` triggers AR-16.

### 6.3 Post-distribution monitoring (PDM) household visits
- **What:** an in-person visit to a sample of farmers, 2–6 weeks after distribution, using the same offline app in **PDM mode**.
- **Who visits.** Three groups, each with its **own sample**, so results can be compared:

  | Visitor | Sample | Purpose |
  |---|---|---|
  | **Internal M&E** | The main sample (size below) | Routine monitoring |
  | **Third-party monitor (TPM)** | An **independent blind sample**, at least 30% of the M&E sample size, and at least 30 per district | Independent check of both the distribution and internal M&E |
  | **Internal auditors** | **Targeted:** events and villages with Critical/High flags, substantiated complaints, or AR-26 hits | Investigation |

- **Blind sampling for the TPM:**
  - the system draws the TPM sample with its own seed when the TPM requests it;
  - the list is only visible to TPM accounts;
  - programme staff see **only the sample size**, not who is in it, until the TPM marks the round complete;
  - this stops staff from "preparing" the farmers who will be visited.
- **TPM data access:** for each sampled beneficiary only, a TPM sees the name, village, household location, programme card number, photo thumbnail, entitlement and a recorded handover summary. Their accounts are time-limited to the monitoring round. Every view is logged (§8).
- **Comparison:** R-13 and I-36 compare the TPM results with internal M&E. A significant gap raises AR-26, which goes to the internal auditor, not the programme manager, to keep it independent.
- **Sample design:**
  - stratified by **district × delivery model**;
  - sample size per programme from the standard formula for proportions:

    `n₀ = z² · p(1 − p) / e²` with z = 1.96 (95% confidence), p = 0.5, e = 0.05 → **n₀ ≈ 385**

    with finite population correction: `n = n₀ / (1 + (n₀ − 1) / N)`. For example, N = 10,000 beneficiaries gives n ≈ 370.
  - split across strata in proportion to their size, with **at least 30 per stratum**;
  - **risk weighting:** events with Critical or High flags are oversampled ×2.
- **Questionnaire** (configurable, English):
  - receipt and quantity, checked against the record;
  - condition and quality of the inputs;
  - **use** (planted or applied? when? area);
  - any **sale or transfer** of inputs;
  - payment or favours requested;
  - pesticide safety practice (storage, PPE use, container disposal);
  - for livestock inputs, animals vaccinated or treated;
  - satisfaction and complaints.
- **Evidence:** GPS of the visit, a photo of the inputs or field (with consent), and a matching programme card scan.
- **Results:** I-20 and R-08. Mismatches trigger AR-16.

### 6.4 Physical stock audits
- **Unannounced spot counts** at central and district stores and active distribution points: at least **monthly per store**, plus during 10% of events.
- The auditor counts per sku and lot, using the app's count mode, against the Pallet stock and the open events.
- A variance opens a case (same severity as AR-01).

### 6.5 Event observation
For **10%** of events (risk-weighted), an independent observer attends and completes an observation checklist in the app:
- were identity checks done?
- were quantities correct?
- was the safety briefing given?
- was the queue orderly, and was anyone asked for payment?
- were cold-chain readings taken?

### 6.6 End-of-season reconciliation audit
Per sku and lot, the auditor confirms:

```
Received at central warehouse
  = Σ issued on legs + central closing stock
Σ issued on legs
  = Σ received on legs + Σ leg discrepancies
Σ received at points/routes
  = Σ handovers + Σ event/route discrepancies + Σ returns + carry-over
```

Every non-zero line must be explained by a closed case. The result is I-14 and part of R-10.

### 6.7 Data integrity check
Nightly, and on demand for an audit:
- recompute the seal hashes for a random **1%** of sealed records, plus **all** flagged records;
- verify device signatures and hash chains;
- check that the audit log (§8) has no gaps.

Failures trigger AR-22.

### 6.8 Cold-chain audit
- Download the full logger history wherever loggers are used, and compare it with the readings recorded at custody points.
- Check every quarantine decision for a documented outcome.

### 6.9 External audit support
R-11 (the audit pack) gives an external auditor everything for desk review. Auditors can have a time-limited **Internal auditor** read-only account.

## 7. Flags, cases and findings

```
flag ──(severity ≥ Medium)──▶ case ──▶ investigation ──▶ outcome ──▶ closed
                                 └─ SLA breach ──▶ escalate to programme manager, then the auditor
```

- **`pod_cases`:**
  - `rule_id`, `severity`, the linked records, `owner_uuid`, `due_at`, `status` (`open`, `investigating`, `pending_approval`, `closed`);
  - `outcome`, one of:
    - `cleared` (false positive)
    - `corrected` (data error fixed by void and re-record)
    - `recovered` (stock recovered)
    - `written_off`
    - `disciplinary`
    - `referred` (to police or the funder)
  - `notes`, evidence attachments.
- A case can't be closed by the person whose work it concerns (§2).
- Closing a `written_off` case above a value threshold (programme-set) needs the programme manager **and** the auditor.
- **`pod_audit_findings`**, for results of the methods in §6:
  - `method`, `finding`, `severity`, `recommendation`;
  - `corrective_action`, `action_owner`, `action_due`, `action_status`.

  These are tracked to completion and reported in R-08 and R-10.
- Repeat findings for the same officer, store, transporter or village are counted and shown on the dashboards.

## 8. Audit trail and retention
- **Immutable audit log** for every create, update, void, approval, override, case action, export and evidence view. Each entry records who, what, when, from which device or IP, and the before/after values. Entries are hash-chained daily, and the daily hash is stored separately (for example in object-lock storage), so gaps or edits can be detected.
- **Access logging** for personal data: every view of a farmer's photo, ID or phone number is logged and reported in R-11.
- **Retention:**
  - distribution records, evidence, cases and findings: **7 years** after the end of the programme;
  - farmer photos and thumbprint images are redacted after the retention period, keeping hashes and aggregates;
  - set per programme where the funder or law requires otherwise.
- **Exports:** every exported report records the dataset hash and the user. Re-exporting the same filters later must give the same hash, unless records changed through the audited void process.

## 9. Data quality checks (on import and on sync)
- **Beneficiary import** (Excel and paper sources; see `AGRI-INPUTS.md` §7.1):
  - column mapping saved per source format, and the normalisation rules applied (names, sex, age, E.164 phone, place matching);
  - paper pages double-entered on a 10% sample, re-entered in full if > 2% of fields disagree (I-35);
  - required fields present;
  - duplicates by ID + name + village and by phone;
  - valid age range;
  - household location inside the district boundary.
- **Stock import:** the sku exists, the lot and expiry are valid, and the product isn't on the banned list.
- **Handover sync:** quantities are whole units (or valid decimals for kg or litres), the lot belongs to the event's stock, and the beneficiary is assigned to the event or route.
- Rejected rows go to a fix-up queue with a reason. They are never silently dropped.

## 10. Implementation notes
- **Rules engine:** each AR-xx is a small class implementing `AuditRule` (`appliesTo()`, `evaluate()`, `severity()`). They run in queued jobs on sync, on close and nightly through the `scheduler` container.
- **Sampling:**
  - `pod:sample-callbacks {programme}` and `pod:sample-pdm {programme}` draw samples using a recorded seed;
  - the sample lists are themselves audited records.
- **Reports:** the indicator queries are defined once (I-xx → SQL view or report-schema definition) and reused by dashboards, PDFs and exports, so numbers always match between reports.
- **Permissions:** the IAM roles in §2 ship as default policies with the extension. The TPM role is scoped to its blind sample by a row-level policy, not just hidden in the UI.
