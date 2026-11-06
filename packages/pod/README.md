# Proof of Delivery (PoD) extension

A Fleetbase extension for proof of delivery, built first for **agricultural input distribution** (NGO, project and government programmes) in English-speaking West Africa, with **Sierra Leone as the default** country.

Design documents (in this repository):
- [`docs/proof-of-delivery/DESIGN.md`](../../docs/proof-of-delivery/DESIGN.md): the generic PoD module
- [`docs/proof-of-delivery/AGRI-INPUTS.md`](../../docs/proof-of-delivery/AGRI-INPUTS.md): the agricultural inputs profile
- [`docs/proof-of-delivery/REPORTING-AUDIT.md`](../../docs/proof-of-delivery/REPORTING-AUDIT.md): reporting and audit rules

## What's built (v0.1.0)

| Area | Design ref | Status |
|---|---|---|
| **Proof guard** on the Fleet-Ops public API (`POST/PATCH /v1/orders/{id}/update-activity`, used by Navigator). Modes: `off` / `log` / `enforce`. | DESIGN §4.3, phase 0 | Done |
| **Country profiles** for Sierra Leone (default), Liberia, Ghana, Nigeria and The Gambia: dial code, time zone, admin level labels, ID types | AGRI §16.1 | Done |
| **Sierra Leone admin areas**: 5 regions, 16 districts, 167 chiefdoms, 1,316 sections, from the official dataset; `pod:seed-admin-areas` command; lookup API | AGRI §16.1 | Done |
| **Programmes** (create, list, show, update) | AGRI §6 | Done (basic) |
| **Farmer List Template** (.xlsx) with drop-downs from the seed list | AGRI §7.1 | Done |
| **Excel/CSV import**: column mapping suggestions, cleaning (names, sex, age, +232 phones, ID types, places), duplicate detection, preview, review decisions, commit | AGRI §7.1, REPORTING §9 | Done |
| **Policy engine**: requirement trees (`all`/`any`), identity levels, lot coverage, entitlement check; policy resolution by scope and priority | DESIGN §5–6.1, AGRI §8 | Done (engine; not yet wired to handovers) |
| **Programme cards**: signed QR codes, offline verification, revocation on reissue | AGRI §7.2 | Done (engine; card PDF printing not yet) |
| **Stock reconciliation** per sku and lot | AGRI §11 | Done (engine) |

Not built yet, in the order of the delivery plan: entitlements, field verification and card printing, transport legs with seals, distribution events and door-to-door handovers, the Navigator app changes, cold chain, audit rules, reporting, and the console screens. The console UI (Ember engine) isn't started; everything above is available through the API.

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

All requests are scoped to the signed-in user's company.

## Privacy

- **ID numbers:** only the ID type, the last 4 characters and a keyed hash (for duplicate checks) are stored. The full number is never saved in beneficiary records. It is masked in every API response, including import previews.
- **Uploaded files:** they're kept, so each record can be traced to its file and row. Limit access to the storage disk, and delete files when the programme's retention period ends.

## Tests

There are two suites. Both only need a vendor directory with a few packages, not a full Fleetbase install. Point `POD_TEST_VENDOR` at it.

**Unit suite** (`server/tests/Unit`): the framework-free domain code in `server/src/Support`. It needs `phpoffice/phpspreadsheet`, `giggsey/libphonenumber-for-php` and `phpunit/phpunit:^10.5`.

```bash
POD_TEST_VENDOR=/path/to/vendor php /path/to/vendor/bin/phpunit
```

**Integration suite** (`server/tests/Integration`): migrations, import service, HTTP routes, seed command and proof guard, on Orchestra Testbench with SQLite. Small stand-ins in `server/tests/Integration/Stubs` replace Fleetbase core and Fleet-Ops. The same vendor directory also needs `orchestra/testbench:^8`.

```bash
POD_TEST_VENDOR=/path/to/vendor php /path/to/vendor/bin/phpunit -c phpunit.integration.xml.dist
```

A vendor directory for both suites can be made in any empty folder:

```bash
composer require giggsey/libphonenumber-for-php:^8.13 phpoffice/phpspreadsheet:^1.28 orchestra/testbench:^8.0 phpunit/phpunit:^10.5
```

## Seed data

`server/data/seed/sierra-leone/` holds the Sierra Leone admin areas. They come from the Government of Sierra Leone's administrative boundaries (COD-AB v02, OCHA/HDX, CC BY-IGO). See its [README](server/data/seed/sierra-leone/README.md) for the source, the licence attribution and the known issues: one spelling fix, and 67 names flagged for local review.
