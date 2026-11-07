# Proof of Delivery (PoD) extension

A Fleetbase extension for proof of delivery, built first for **agricultural input distribution** (NGO, project and government programmes) in English-speaking West Africa, with **Sierra Leone as the default** country.

Design documents (in this repository):
- [`docs/proof-of-delivery/DESIGN.md`](../../docs/proof-of-delivery/DESIGN.md): the generic PoD module
- [`docs/proof-of-delivery/AGRI-INPUTS.md`](../../docs/proof-of-delivery/AGRI-INPUTS.md): the agricultural inputs profile
- [`docs/proof-of-delivery/REPORTING-AUDIT.md`](../../docs/proof-of-delivery/REPORTING-AUDIT.md): reporting and audit rules

## What's built (v0.3.0)

| Area | Design ref | Status |
|---|---|---|
| **Proof guard** on the Fleet-Ops public API (`POST/PATCH /v1/orders/{id}/update-activity`, used by Navigator). Modes: `off` / `log` / `enforce`. | DESIGN §4.3, phase 0 | Done |
| **Country profiles** for Sierra Leone (default), Liberia, Ghana, Nigeria and The Gambia: dial code, time zone, admin level labels, ID types | AGRI §16.1 | Done |
| **Sierra Leone admin areas**: 5 regions, 16 districts, 167 chiefdoms, 1,316 sections, from the official dataset; `pod:seed-admin-areas` command; lookup API | AGRI §16.1 | Done |
| **Programmes** (create, list, show, update) | AGRI §6 | Done (basic) |
| **Farmer List Template** (.xlsx) with drop-downs from the seed list | AGRI §7.1 | Done |
| **Excel/CSV import**: column mapping suggestions, cleaning (names, sex, age, +232 phones, ID types, places), duplicate detection, preview, review decisions, commit | AGRI §7.1, REPORTING §9 | Done |
| **Policy engine**: requirement trees (`all`/`any`), identity levels, lot coverage, entitlement check; policy resolution by scope and priority | DESIGN §5–6.1, AGRI §8 | Done (engine; not yet wired to handovers) |
| **Programme items and entitlement rules**: fixed packages, per acre/hectare, per household member or per animal; conditions on crop, sex, type, group and area; caps per item; restricted items only for trained farmers | AGRI §6, §9, §10.1 | Done |
| **Entitlement generation**: dry run, safe to repeat, never changes collected entitlements, cancels what no longer applies; planning totals by district or chiefdom | AGRI §6, §7.1 step 4 | Done |
| **Field verification**: consent, corrections, rejection with reasons (cancels untouched entitlements) | AGRI §7.1 step 3 | Done (API; the offline field app isn't built yet) |
| **Programme cards**: issued at verification; replacement revokes the old card; re-verification after rejection gets a new card; card check API; append-only card log | AGRI §7.2 | Done |
| **Printable cards**: A4 PDF, 8 ID-size cards per page, QR code + typed code, by area, village or list | AGRI §7.2 | Done (no photos yet; the photo box is left for a printed or pasted photo) |
| **Sites and stock**: central warehouses, district stores, distribution points and vehicles; supplier deliveries; an append-only stock ledger per item and lot that can't go negative | AGRI §3, §4 | Done (own ledger; Pallet isn't connected) |
| **Truck trips**: load (count, lots, seals, waybill, pesticides kept apart) → depart → arrive → receive (good and damaged counts, seal check) → close; shortages, damage and excess become discrepancies that must be explained and resolved; broken seals must be explained; the loader can't receive or close; slow trips flagged; sealed with a hash | AGRI §4, AR-01, AR-02, AR-23 | Done (Fleet-Ops orders can be linked, not created) |
| **Distribution days**: assign farmers by area, village or list; witnessed opening count; handovers by card scan or ID with identity levels, remaining-entitlement checks, expired and recalled lots refused, restricted products for trained farmers only, proof policy (photo, mark or signature, witness for proxy or L1, safety acknowledgement for pesticides); retries can't issue twice; location checks; sealed records | AGRI §7.2, §8, §9 | Done |
| **Door-to-door routes**: handovers at the household (200 m check), failed attempts with reasons, reassignment to a distribution point after the maximum attempts, moved/deceased sent to registry review | AGRI §3.2 | Done |
| **Closing and the day report**: witnessed closing count reconciled per item and lot (refused until every unit is accounted for), leftover stock returned to the site, totals by sex and age band, report as JSON or PDF in the country's local time, sealed with a hash | AGRI §11, REPORTING R-02 | Done |
| **Stock reconciliation** per sku and lot | AGRI §11 | Done (engine) |

Not built yet, in the order of the delivery plan: the Navigator app changes (offline field app), cold chain readings, the audit rules engine and verification sampling, programme reports, role-based permissions, and the console screens. The console UI (Ember engine) isn't started; everything above is available through the API.

## Install (own server)

The extension lives in `packages/pod` of this repository.

1. Link it into the API with the package linker (see [`scripts/README.md`](../../scripts/README.md)), or add a Composer path repository to `api/composer.json`:

   ```json
   "repositories": [{ "type": "path", "url": "../packages/pod" }],
   "require": { "fleetbase/pod-api": "*" }
   ```

2. Set these in `api/.env`, and keep them in your backups (§16.3 of AGRI-INPUTS):

   ```
   POD_GUARD_MODE=log
   POD_DEFAULT_COUNTRY=SL
   POD_CARD_SECRET=<long random secret>
   POD_ID_HASH_KEY=<long random secret>
   ```

   Changing `POD_CARD_SECRET` invalidates every printed card. Changing `POD_ID_HASH_KEY` stops new lists from being compared with existing farmers' IDs.

3. Run the migrations and load the Sierra Leone admin areas:

   ```bash
   php artisan migrate
   php artisan pod:seed-admin-areas SL
   ```

4. Watch the guard log for a while in `log` mode, then set `POD_GUARD_MODE=enforce`.

## API (console, under `/int/v1/pod`)

| Method | Path | Purpose |
|---|---|---|
| GET | `countries` | Country profiles and the default |
| GET | `admin-areas?country=SL&level=2&parent=SL01` | Regions, districts, chiefdoms, sections |
| GET, POST | `programmes` | List, create (country defaults to SL) |
| GET, PUT | `programmes/{id}` | Show, update |
| GET | `programmes/{id}/farmer-list-template` | Download the .xlsx template |
| POST | `programmes/{id}/imports` | Upload a list (`file`) → sheets, headers, suggested mapping |
| GET | `imports/{id}` | The import and its summary |
| POST | `imports/{id}/preview` | `{mapping?, sheet?, header_row?}` → what will happen to each row; nothing is created |
| GET | `imports/{id}/rows?status=review` | Rows by status: `accepted`, `merged`, `exists`, `review`, `rejected` |
| POST | `imports/{id}/rows/{row}/decision` | `{"decision": "import" \| "skip"}` for a likely duplicate |
| POST | `imports/{id}/commit` | Create the beneficiaries (`skip_undecided` to leave review rows for later) |
| GET, PUT | `programmes/{id}/items` | The inputs the programme distributes: `{"items": [{sku, name, category, unit, pack_size, pack_unit, restricted, cold_chain, max_per_beneficiary}]}` |
| GET, PUT | `programmes/{id}/entitlement-rules` | `{"rules": [...]}` (format below) |
| POST | `programmes/{id}/entitlements/generate` | Create or update entitlements; `{"dry_run": true}` to preview |
| GET | `programmes/{id}/entitlements/summary?level=2` | Beneficiaries and quantities per item by district (`2`) or chiefdom (`3`) |
| GET | `programmes/{id}/beneficiaries` | Filter by `verification_status`, `status`, `admin_1`–`admin_4`, `village`, search `q` |
| GET | `beneficiaries/{id}` | With entitlements and card history |
| POST | `beneficiaries/{id}/verify` | `{"method": "community_validation", "consent": true, "updates": {...}}`, issues the card |
| POST | `beneficiaries/{id}/reject` | `{"reason": "not_known" \| "moved" \| "deceased" \| "duplicate" \| "ineligible" \| "other"}` |
| POST | `beneficiaries/{id}/card/reissue` | `{"reason": "lost" \| "damaged" \| "stolen" \| "details_changed"}`; the old card stops working |
| GET | `beneficiaries/{id}/card` | The current card code |
| POST | `programmes/{id}/cards/pdf` | `{"admin_3": "SL010101"}`, `{"village": ...}` or `{"beneficiaries": [ids]}` → PDF (at most 400 cards) |
| POST | `programmes/{id}/cards/check` | `{"code": "FBP1..."}` → valid or the reason (`revoked`, `not_verified`, `bad_signature`...), and open entitlements |
| GET, POST | `sites` | Stores, distribution points, vehicles |
| GET | `sites/{id}` | With stock on hand per item and lot |
| POST | `sites/{id}/stock/receive` | Supplier delivery: `{programme, reference, lines: [{sku, lot, expiry, quantity}]}` |
| GET, POST | `programmes/{id}/legs` | Truck trips: `{origin, destination, transporter_kind, transporter_name, vehicle_plate, waybill_no, expected_duration_min}` |
| GET | `legs/{id}` | With lines and discrepancies |
| POST | `legs/{id}/load` | `{lines: [{sku, lot, quantity}], seals: [...], restricted_segregated}` |
| POST | `legs/{id}/depart`, `legs/{id}/arrive` | |
| POST | `legs/{id}/receive` | `{lines: [{sku, lot, good, damaged, note}], seals: [{number, intact}]}`, by someone other than the loader |
| POST | `legs/{id}/close` | `{seal_explanation}` when the seals weren't intact |
| POST | `legs/{id}/cancel` | Before departure; loaded stock goes back |
| POST | `discrepancies/{id}/resolve` | `{notes, resolution: written_off \| replaced \| recovered \| investigating, reason?, liable_party?}` |
| GET, POST | `programmes/{id}/events` | Distribution days and routes: `{kind: point \| route, site, name, scheduled_date}` |
| GET | `events/{id}` | With stock and totals |
| POST | `events/{id}/assign` | `{admin_3}`, `{village}` or `{beneficiaries: [...]}` |
| POST | `events/{id}/open` | `{stock: [{sku, lot, quantity}], witnesses: [{name, role}]}` |
| GET, POST | `events/{id}/handovers` | A handover (below) |
| POST | `events/{id}/attempts` | Failed door-to-door attempt: `{beneficiary, reason, latitude, longitude}` |
| POST | `events/{id}/reassign` | `{beneficiary, to_event}` |
| POST | `events/{id}/discrepancies` | Damaged or missing stock during the day: `{sku, lot, quantity, reason, notes}` |
| POST | `events/{id}/close` | `{stock: [...], witnesses: [...]}`; refused with the unbalanced lines until every unit is accounted for |
| GET | `events/{id}/report` | JSON, or `?format=pdf` |

All requests are scoped to the signed-in user's company.

### A handover

```json
{
  "client_handover_id": "phone7-000123",
  "card_code": "FBP1.programme_x.beneficiary_y.1.ABC...",
  "identity": {"face_matched": true, "id_last4": "6789"},
  "received_by": "self",
  "lines": [{"sku": "RICE-25", "lot": "R7", "quantity": 1}, {"sku": "NPK-50", "lot": "L1", "quantity": 2}],
  "evidence": [{"type": "photo", "file": "file_uuid"}, {"type": "mark"}],
  "latitude": 7.9806, "longitude": -10.6362
}
```

- **Without a card:** give `beneficiary` and `identity: {photo_taken, witness_confirmed}` instead (identity level L1). A witness signature is then needed in `evidence`.
- **Collected by someone else:** `received_by: proxy` (or `household_member`) with `recipient_name`. A proxy needs a witness signature.
- **Pesticides and veterinary drugs:** these need `{"type": "safety_ack"}`.
- **Refusals:** a refused handover answers 422 with an `error` code for the field app. The codes are `card_revoked`, `already_collected`, `assigned_elsewhere`, `over_entitlement`, `lot_expired`, `lot_recalled`, `not_trained`, `identity_insufficient` and `proof_missing` (with the `missing` requirements).
- **Retries:** sending the same `client_handover_id` again returns the original handover instead of issuing twice.

### Entitlement rules

For each item, the **first** rule that applies decides the quantity, so put specific rules before general ones:

```json
[
  {"sku": "RICE-25", "quantity": 2, "when": {"sex": "female", "crop": ["rice"]}},
  {"sku": "RICE-25", "quantity": 1},
  {"sku": "NPK-50",  "quantity": 1, "per": "farm_size_acres", "max": 4},
  {"sku": "HERB-1L", "quantity": 1},
  {"sku": "DEWORM",  "quantity": 1, "per": "livestock.goats"}
]
```

- `per`: `household_size`, `farm_size_acres`, `farm_size_ha` or `livestock.<species>`. The result is rounded up unless `"round"` says `down` or `nearest`.
- `when` conditions: `crop`, `sex`, `type`, `group` and `admin_1`–`admin_4` (p-codes). A farm size typed without a unit ("2") is read as acres.
- Restricted items (pesticides, veterinary drugs and vaccines, by default) go only to farmers with a restricted-product training date.
- Each item's `max_per_beneficiary` is applied last.
- Only **verified** farmers get entitlements, unless the programme's rules set `"verify_on_first_handover": true`.

## Privacy

- **ID numbers:** only the ID type, the last 4 characters and a keyed hash (for duplicate checks) are stored. The full number is never saved in beneficiary records. It is masked in every API response, including import previews.
- **Uploaded files:** they're kept, so each record can be traced to its file and row. Limit access to the storage disk, and delete files when the programme's retention period ends.

## Tests

There are two suites. Both only need a vendor directory with a few packages, not a full Fleetbase install. Point `POD_TEST_VENDOR` at it.

**Unit suite** (`server/tests/Unit`): the framework-free domain code in `server/src/Support`. It needs `phpoffice/phpspreadsheet`, `giggsey/libphonenumber-for-php`, `milon/barcode`, `dompdf/dompdf`, `vlucas/phpdotenv` and `phpunit/phpunit:^10.5`.

```bash
POD_TEST_VENDOR=/path/to/vendor php /path/to/vendor/bin/phpunit
```

**Integration suite** (`server/tests/Integration`): migrations, import service, HTTP routes, seed command and proof guard, on Orchestra Testbench with SQLite. Small stand-ins in `server/tests/Integration/Stubs` replace Fleetbase core and Fleet-Ops. The same vendor directory also needs `orchestra/testbench:^8`.

```bash
POD_TEST_VENDOR=/path/to/vendor php /path/to/vendor/bin/phpunit -c phpunit.integration.xml.dist
```

A vendor directory for both suites can be made in any empty folder:

```bash
composer require giggsey/libphonenumber-for-php:^8.13 phpoffice/phpspreadsheet:^1.28 milon/barcode:^10.0 dompdf/dompdf:^3.1 vlucas/phpdotenv:^5.6 orchestra/testbench:^8.0 phpunit/phpunit:^10.5
```

## Seed data

`server/data/seed/sierra-leone/` holds the Sierra Leone admin areas. They come from the Government of Sierra Leone's administrative boundaries (COD-AB v02, OCHA/HDX, CC BY-IGO). See its [README](server/data/seed/sierra-leone/README.md) for the source, the licence attribution and the known issues: one spelling fix, and 67 names flagged for local review.
