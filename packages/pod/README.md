# Proof of Delivery (PoD) extension

A Fleetbase extension for proof of delivery, built first for **agricultural input distribution** (NGO, project and government programmes) in English-speaking West Africa, with **Sierra Leone as the default** country.

Design documents (in this repository):
- [`docs/proof-of-delivery/DESIGN.md`](../../docs/proof-of-delivery/DESIGN.md): the generic PoD module
- [`docs/proof-of-delivery/AGRI-INPUTS.md`](../../docs/proof-of-delivery/AGRI-INPUTS.md): the agricultural inputs profile
- [`docs/proof-of-delivery/REPORTING-AUDIT.md`](../../docs/proof-of-delivery/REPORTING-AUDIT.md): reporting and audit rules

## What's built (v0.2.0)

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
| **Stock reconciliation** per sku and lot | AGRI §11 | Done (engine) |

Not built yet, in the order of the delivery plan: transport legs with seals, distribution events and door-to-door handovers, the Navigator app changes, cold chain, audit rules, reporting, and the console screens. The console UI (Ember engine) isn't started; everything above is available through the API.

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

All requests are scoped to the signed-in user's company.

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
