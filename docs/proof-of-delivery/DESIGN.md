# Proof of Delivery (PoD) Module — Design

Status: **Draft for review**
Target: Fleetbase v0.7.75, Fleet-Ops `0.6.75` (submodule commit `811aa71`)

## 1. Summary

Fleet-Ops already captures basic proof of delivery: a signature, a photo or a QR scan, stored as `Proof` rows against an order or a stop. This design adds a **PoD module** on top of it. The module turns those loose captures into a **verifiable delivery record**. That record:

- says exactly what proof each stop needs (a policy), and enforces it on the server for every client;
- captures richer evidence: recipient name, OTP, ID check, parcel barcodes, several photos, and GPS/time/device metadata;
- records failed and partial attempts, with reasons, not just successes;
- is tamper-evident (hashed, locked once submitted) and works offline in the driver app;
- produces a shareable **ePOD document** (PDF plus link) for customers, billing and disputes.

The module ships as a Fleetbase **extension** (`pod`), with an API package and a console engine. It needs one small upstream change to Fleet-Ops (§4.3), so we don't fork Fleet-Ops.

## 2. What exists today

| Capability | Where | Notes |
|---|---|---|
| `pod_required`, `pod_method` (`scan` / `signature` / `photo`) on orders and waypoints | `fleetops/server/src/Models/Order.php`, `Waypoint.php`; methods from `config('fleetops.pod_methods')` | One method per order or stop. |
| `require_pod` / `pod_method` on Order Config activities | `Support/FleetOps.php`, `Http/Resources/v1/OrderConfig.php` | Hints for the UI. |
| `proofs` table | `server/migrations/2023_04_27_053456_create_proofs_table.php` | `order_uuid`, polymorphic `subject_*`, `file_uuid`, `remarks`, `raw_data`, `data` (JSON). |
| Capture endpoints | `POST /v1/orders/{id}/capture-signature|capture-qr|capture-photo/{subjectId?}`, `GET /v1/orders/{id}/proofs/{subjectId?}` | Used by the Navigator app. The QR scan is checked with `TrackingCode::matches`. |
| Enforcement | `Http/Controllers/Internal/v1/OrderController.php` (console) | Returns 422 when proof is missing, unless `bypass_proof`. |
| Public tracking proof view | `Http/Controllers/Public/TrackingController.php@proof` | The customer sees the signature or photo. |
| Dashboard tile | `Internal/v1/ProofController@recent` | Latest photo proofs. |

### Gaps this module closes

1. **No server-side enforcement on the public API.** `Api/v1/OrderController@updateActivity` (used by Navigator and integrations) accepts a completing activity without proof, even when `pod_required` is set. Only the console path checks.
2. **One method only.** You can't require "signature **and** photo", or "OTP **or** signature".
3. **No structured metadata.** Capture location, time, accuracy, device, recipient name and relationship either aren't stored or end up in untyped `data` JSON.
4. **No location or time checks.** A proof can be captured anywhere, at any time.
5. **No failed-attempt record.** "Recipient not home" or "refused" is just an activity, with no evidence or reason code.
6. **No tamper evidence.** Proof rows and files can be changed or deleted (soft delete only) after delivery.
7. **No ePOD document.** Nothing gives the customer or billing a single signed artefact per delivery.
8. **No per-item proof.** You can't confirm that each parcel or entity on a multi-item stop was scanned.

## 3. Goals and non-goals

**Goals**
- A configurable PoD policy per order type, customer, service area or stop.
- The server enforces the policy for every client (console, Navigator, API, Customer Portal).
- Evidence types: signature, photo (one or more), barcode or QR scan per item, OTP or PIN, recipient name, ID check, "leave at safe place" with a photo, and contactless delivery.
- Every attempt is recorded, successful or not, with a geo/time/device context.
- Records are immutable once submitted, and integrity can be verified.
- Offline capture with later sync.
- An ePOD PDF and a public verification link, plus webhooks and events.
- Privacy controls: retention, redaction and access by permission.

**Non-goals (v1)**
- Biometric checks, or ID document OCR (left as a hook for later).
- Legally qualified e-signatures (eIDAS QES). We produce *advanced evidence*, not a qualified signature.
- Replacing Fleet-Ops' order lifecycle. The module listens to it and guards it.

## 4. Architecture

```
┌──────────────── Clients ─────────────────┐
│ Navigator app │ Console │ API │ Portal   │
└──────┬────────────┬─────────┬────────────┘
       │ capture    │ review  │ query/webhooks
┌──────▼────────────▼─────────▼────────────────────────────┐
│ PoD extension (fleetbase/pod-api + @fleetbase/pod-engine)│
│  PolicyResolver → AttemptService → EvidenceService       │
│  Validator (geo/time/OTP/scan) → Sealer (hash, lock)     │
│  EpodRenderer (dompdf) → Notifier (OTP SMS/email, hooks) │
└──────┬───────────────────────────────────────────────────┘
       │ guard hook + events          │ storage (S3/GCS/local)
┌──────▼──────────────┐       ┌───────▼────────┐
│ Fleet-Ops           │       │ files / proofs │
│ updateActivity()    │       │ (existing)     │
│ Order/Waypoint      │       └────────────────┘
└─────────────────────┘
```

### 4.1 Packaging

Follow the standard extension layout (see `packages/fleetops`, `packages/ledger`):

```
packages/pod/
  extension.json            # name "Proof of Delivery", engine: package.json, api: composer.json
  composer.json             # fleetbase/pod-api, requires fleetbase/core-api, fleetbase/fleetops-api
  package.json              # @fleetbase/pod-engine, keyword "fleetbase-extension", route "pod"
  server/
    config/pod.php
    migrations/
    src/
      Providers/PodServiceProvider.php
      Models/{PodPolicy,DeliveryAttempt,PodEvidence,PodOtp}.php
      Services/{PolicyResolver,AttemptService,EvidenceValidator,Sealer,EpodRenderer}.php
      Guards/RequiresProofOfDelivery.php
      Http/Controllers/{Api,Internal}/v1/...
      Listeners/...
      routes.php
  addon/  app/              # Ember engine: settings, review queue, attempt viewer
```

Link it locally with `node scripts/package-linker.mjs` (see `scripts/README.md`).

### 4.2 Reusing `proofs`

We **don't replace** the `proofs` table. Every piece of evidence still writes a `Proof` row, so existing screens, the tracking page and the dashboard keep working. The module adds typed tables that point at proofs (§5).

### 4.3 Upstream hook in Fleet-Ops (the only core change)

Add a pluggable guard that both `Api\v1\OrderController@updateActivity` and `Internal\v1\OrderController@updateActivity` call before applying an activity:

```php
// fleetops: new contract
interface ActivityProofGuard {
    /** @throws ProofRequiredException (→ HTTP 422 with machine-readable missing requirements) */
    public function check(Order $order, Activity $activity, Waypoint|Order|null $subject, ?Proof $proof, Request $request): void;
}
```

- Fleet-Ops binds a default implementation that reproduces today's internal rule (`require_pod`, or completing with `pod_required`). This also closes gap 1 for the public API.
- The PoD extension rebinds it to `RequiresProofOfDelivery`, which evaluates the full policy.
- `bypass_proof` stays, but needs the `pod bypass` permission and a reason, and is written to the audit log.

Raise this as a pull request to `fleetbase/fleetops`, so the fork stays mergeable with upstream.

## 5. Data model

All tables use the core conventions: `uuid`, `public_id`, `company_uuid`, timestamps, soft deletes where noted.

### `pod_policies`
| column | type | notes |
|---|---|---|
| name | string | e.g. "High-value parcels" |
| scope_type / scope_uuid | morph, nullable | `order_config`, `customer` (contact/vendor), `service_area`, `zone`, or null for the company default |
| priority | int | the highest matching policy wins |
| requirements | json | the rule tree below |
| geofence_radius_m | int, default 150 | the capture must be within this distance of the stop |
| time_window_grace_min | int, nullable | the capture must fall in the stop window ± grace |
| allow_offline | bool | |
| allow_safe_place | bool | |
| max_attempts | int, default 3 | |
| retention_days | int, nullable | overrides the company default |
| active | bool | |

`requirements` is a small AND/OR tree of evidence types with options:

```json
{ "all": [
    { "any": [ {"type": "otp"}, {"type": "signature", "require_name": true} ] },
    { "type": "photo", "min": 1 },
    { "type": "item_scan", "coverage": "all_entities" }
]}
```

The evidence types are `signature`, `photo`, `otp`, `item_scan`, `qr_scan` (the stop or order code, as today), `recipient_name`, `id_check` (type plus last 4 characters, never the full number), `safe_place` (photo plus a location note), and `custom_field`.

### `delivery_attempts`
One row each time a driver tries to complete a stop.

| column | type | notes |
|---|---|---|
| order_uuid, subject_type/subject_uuid | | the order or waypoint (stop) |
| driver_uuid, vehicle_uuid | | |
| policy_uuid, policy_snapshot | uuid, json | the policy is frozen at attempt time |
| attempt_no | int | |
| outcome | enum | `delivered`, `partially_delivered`, `failed`, `refused`, `safe_place`, `returned` |
| failure_reason_code | string, nullable | `not_home`, `wrong_address`, `refused`, `damaged`, `access_denied`, `unsafe`, `other` |
| recipient_name, recipient_relation | string, nullable | `self`, `family`, `neighbour`, `reception`, `other` |
| captured_at (device), received_at (server) | timestamp | |
| location | point | GPS at submission |
| location_accuracy_m | float | |
| distance_from_stop_m | float | computed |
| device_id, app_version, offline | | |
| status | enum | `draft`, `submitted`, `sealed`, `flagged`, `voided` |
| validation | json | the result of each check (pass, warn, fail) |
| seal_hash, sealed_at | char(64), timestamp | §7 |
| epod_file_uuid | uuid, nullable | the generated PDF |

### `pod_evidence`
| column | type | notes |
|---|---|---|
| attempt_uuid | uuid | |
| proof_uuid | uuid | → the existing `proofs` row (and its file) |
| type | enum | as above |
| entity_uuid | uuid, nullable | for `item_scan`, the parcel or entity scanned |
| value | json | e.g. the scanned code, ID type, OTP verification id (never the OTP itself) |
| location, captured_at | | per piece of evidence |
| content_hash | char(64) | SHA-256 of the file bytes or the canonical value |

### `pod_otps`
| column | type | notes |
|---|---|---|
| subject_type/subject_uuid | | |
| code_hash | string | bcrypt or argon2 hash. The OTP is never stored in plain text. |
| channel | enum | `sms`, `email`, `tracking_page` |
| sent_to | string | masked |
| expires_at, attempts, verified_at | | locked after 5 failed tries |

## 6. Flows

### 6.1 Policy resolution
`PolicyResolver::for(Order $order, ?Waypoint $stop)` collects the active policies that match:
1. the stop or order explicitly (`pod_required`/`pod_method` → a synthetic policy, for backward compatibility);
2. the order config;
3. the customer;
4. the service area or zone of the stop;
5. the company default.

The highest priority wins. Requirements from the legacy `pod_method` are merged in with `all`. The result is cached per order and returned to the driver app inside the order payload as `pod_policy`. That way the app renders the right capture steps, even offline.

### 6.2 Successful delivery (driver app)
1. The driver arrives at the stop. The app shows the requirements checklist from `pod_policy`.
2. `POST /v1/pod/attempts` creates a `draft` attempt. The app sends `client_attempt_id` as an idempotency key.
3. For each requirement, the app captures evidence with `POST /v1/pod/attempts/{id}/evidence` (multipart for images, JSON for scans and OTP). Each item creates a `Proof` (using the existing storage paths `uploads/{company}/…`) and a `pod_evidence` row. The server checks it immediately (§6.4) and returns pass, warn or fail.
4. `POST /v1/pod/attempts/{id}/submit` with the outcome `delivered`. The server re-checks the whole requirement tree, seals the attempt (§7), and then calls Fleet-Ops `updateActivity` / `complete()` with the primary proof. The guard sees a sealed, passing attempt and lets it through.
5. Listeners queue the ePOD PDF render and fire `pod.attempt.sealed` and the existing `order.completed` / `waypoint.completed`.

The legacy `capture-*` endpoints keep working. A legacy capture creates an implicit attempt, so older Navigator builds don't break.

### 6.3 Failed or partial delivery
- `submit` with `outcome=failed|refused|…` needs a `failure_reason_code` and at least one photo (configurable). It records the attempt and moves the stop to a configurable activity (for example `ATTEMPT_FAILED`). If `attempt_no < max_attempts`, it schedules a re-delivery. Otherwise it triggers a return-to-sender flow.
- `partially_delivered` needs item scans. Entities that weren't scanned are marked undelivered on the order.

### 6.4 Validation rules
| Check | Rule | On failure |
|---|---|---|
| Geofence | `distance_from_stop_m <= geofence_radius_m + accuracy` | `warn` (flag for review) or `fail`, depending on the policy |
| Time window | `captured_at` falls in the stop window ± grace | `warn` |
| Clock skew | `abs(received_at - captured_at)` is under 10 min when online | `warn` |
| OTP | matches an unexpired, unverified OTP for the subject | `fail` |
| Item scan | the code matches an entity on the stop (`TrackingCode::matches`); coverage met | `fail` |
| Photo | a real image, ≤ 10 MB, EXIF stripped of personal fields, not a duplicate hash on the same order | `fail` on a duplicate |
| Signature | a non-empty stroke count or minimum ink area | `fail` |
| Driver | the submitting user is the assigned driver | `fail` |

Attempts that pass with warnings are sealed as `flagged` and appear in the console review queue.

### 6.5 OTP delivery code
- Generated when the stop becomes `en route` (listening to `WaypointActivityChanged` / `OrderDispatched`), or on demand. It's a 4–6 digit code, sent by SMS (Twilio, already supported) or email, and shown on the customer tracking page once the customer is verified.
- The recipient reads it to the driver. The app submits `{type: "otp", code}`. The server checks it against `code_hash`.
- The code can be resent, with a rate limit, through `POST /v1/pod/otp/{subject}/resend`.

### 6.6 Offline
- Policy and requirements come with the order payload. The app stores evidence and attempts locally, together with `captured_at`, the location and the device id.
- When the app syncs, it sends `client_attempt_id`, so retries are idempotent. The server marks `offline=true`, runs the checks using device time and location, and applies a stricter skew rule (for example a `warn` if the delay is over 24 h).

## 7. Integrity and immutability
- When an attempt is sealed, the server builds a canonical JSON object: the attempt fields, plus a sorted list of `{type, content_hash, captured_at, location}`, plus the order and stop ids. It stores `seal_hash = SHA-256(canonical)` and an HMAC with a per-company key (`pod.seal_key`, kept in the secrets store).
- A sealed attempt and the proofs and files it links to are **read-only**: model guards reject updates and deletes. Corrections are made by **voiding** (with a reason, the `pod void` permission, and an audit entry) and creating a new attempt.
- `GET /v1/pod/attempts/{id}/verify` recomputes the hashes against the stored files and returns `valid` or `tampered`. The ePOD shows a QR code linking to a public verification page.
- Optional: store the files in a bucket with object lock (S3 Object Lock or GCS retention).

## 8. ePOD document
- Rendered with `dompdf` (Fleet-Ops already includes `config/dompdf.php`) by a queued job after sealing.
- Contents: company branding, order and tracking numbers, stop address, driver and vehicle, outcome, recipient, time and a static map of the location, the signature, photo thumbnails, scanned items, the checks that passed, `seal_hash`, and a verification QR code.
- It's stored as a `File` linked to the attempt. It's available at:
  - `GET /v1/pod/attempts/{id}/epod`
  - on the public tracking page (`/tracking/{number}`), from the existing `TrackingController` proof route
  - attached to the "Delivered" email
- Ledger integration: attach the ePOD to the invoice line, and optionally hold invoicing until a stop has a sealed `delivered` attempt.

## 9. API

Public (`/v1`, API key or driver token):

| Method | Path | Purpose |
|---|---|---|
| GET | `/pod/policies/resolve?order={id}&stop={id}` | The resolved requirements |
| POST | `/pod/attempts` | Create a draft (`order`, `subject`, `client_attempt_id`) |
| POST | `/pod/attempts/{id}/evidence` | Add evidence |
| DELETE | `/pod/attempts/{id}/evidence/{eid}` | Only while in `draft` |
| POST | `/pod/attempts/{id}/submit` | Outcome and recipient → validate, seal, advance the order |
| GET | `/pod/attempts?order=…&status=…` | List |
| GET | `/pod/attempts/{id}` / `/epod` / `/verify` | Read, PDF, integrity check |
| POST | `/pod/otp/{subject}/send` / `resend` | OTP |

Internal (`/int/v1`, console): CRUD for `pod-policies`, the review queue (`pod-attempts?status=flagged`), and approve, void and redact actions.

Error contract for the guard (HTTP 422):

```json
{ "error": "proof_required",
  "missing": [ {"type": "otp"}, {"type": "item_scan", "entities": ["entity_x1", "entity_x2"]} ] }
```

## 10. Events and webhooks
Add these to `config/api.php` webhook events (same place as `order.completed`):
- `pod.attempt.created`
- `pod.attempt.sealed`
- `pod.attempt.failed`
- `pod.attempt.flagged`
- `pod.attempt.voided`
- `pod.epod.generated`
- `pod.otp.sent`

The payload is the attempt resource with evidence metadata and signed file URLs. Signed URLs are short-lived and never public by default.

Socket channel `order.{public_id}`: we push `pod.attempt.sealed` so the console and tracking page update live.

## 11. Console UI (Ember engine `pod`)
- **Settings → Proof of Delivery**: a policy list and editor (a visual AND/OR builder with scope pickers, geofence radius, attempts and retention), plus company defaults and OTP channel settings.
- **Order and stop panel** (injected into Fleet-Ops order details through an engine component hook): an attempt timeline with a map pin compared to the stop, an evidence gallery, check badges, the ePOD download, and the verify button.
- **Review queue**: flagged attempts, with approve or void and a required comment.
- **Reports** (registered with the Fleet-Ops report schema): first-attempt delivery rate, failure reasons, average distance from the stop, flagged rate by driver, and OTP adoption.

## 12. Navigator app changes
- Read `pod_policy` from the order payload and render a step-by-step checklist (signature pad, camera with several shots, barcode scanner with per-item ticks, OTP keypad, recipient form, failure reasons).
- Add `captured_at`, the location, accuracy and the device id to every evidence upload.
- Keep an offline queue, using `client_attempt_id` for idempotency.
- Fall back to the legacy `capture-*` endpoints when the server has no PoD extension. Detect this from the absence of `pod_policy`.

## 13. Security and privacy
- **Permissions** (registered with IAM): `pod view`, `pod view-evidence` (images, signatures, personal data), `pod manage-policies`, `pod bypass`, `pod void`, `pod redact`.
- **Personal data**: we store minimal ID data (type plus last 4 characters). EXIF GPS is replaced by the server-verified location. Faces in photos aren't processed.
- **Retention**: a nightly scheduled job (go-crond in the `scheduler` container) redacts evidence files older than `retention_days`. It keeps the hashes and a redaction record, so integrity checks still say what existed.
- **Access**: file URLs are temporary signed URLs. Every evidence access by console users is written to the activity log.
- **Rate limits**: OTP verification is limited to 5 tries per code, and evidence uploads per attempt are throttled.

## 14. Migration and compatibility
- No data migration is needed. The legacy `pod_required`/`pod_method` keep working through a synthetic policy (§6.1).
- An optional backfill command `php artisan pod:backfill-attempts` groups historic `proofs` into `delivery_attempts` with `status=submitted`. Historic attempts aren't sealed, because their integrity can't be shown.
- Feature flag `pod.enforce` (`off` | `log` | `enforce`), the same rollout pattern as socket auth (`SOCKETCLUSTER_AUTH_MODE`). Start with `log`, check the guard decisions, then switch to `enforce`.

## 15. Testing
- **Unit**: the policy tree evaluation, resolver priority, each check, canonical hashing, and OTP hashing and expiry.
- **Feature**: legacy capture still completes orders; an order is rejected with 422 and the `missing` list without proof; bypass needs the permission; a sealed attempt can't be changed; offline idempotent resubmit; multi-stop with per-item coverage.
- **k6**: add evidence upload and submit to `tests/k6/api-release.js`, to watch latency.
- **Navigator e2e**: the happy path, a failed attempt, and an offline sync.

## 16. Delivery plan

| Phase | Scope | Est. |
|---|---|---|
| 0 | Upstream `ActivityProofGuard` PR to Fleet-Ops (enforce existing rules on `/v1`) | 3–4 days |
| 1 | Extension skeleton, tables, PolicyResolver, attempts and evidence API, sealing, `log` mode | 2 weeks |
| 2 | Checks (geo, time, scan coverage), OTP, failed attempts with reasons, console order panel | 2 weeks |
| 3 | ePOD PDF, verification page, webhooks, review queue, reports | 1.5 weeks |
| 4 | Navigator checklist UI, offline queue, `enforce` rollout | 2–3 weeks |
| 5 | Retention and redaction, Ledger hold-until-PoD, backfill | 1 week |

## 17. Open questions
1. Which PoD types matter most for your operation (for example, OTP for e-commerce, item scans for B2B pallets, ID checks for pharmacy)? This decides the order of phases 2–4.
2. When the geofence check fails, should the attempt **block** or only **flag**? Is the default 150 m right for your areas, given GPS accuracy in dense urban or rural places?
3. What are your legal retention requirements for signatures and photos (for example, 90 days, 1 year, 7 years)?
4. Is the "Delivered" ePOD sent to the customer by default, or only on request?
5. Do we contribute the extension upstream, or keep it private to this fork? Both work under AGPL-3.0, but network use requires publishing the source to your users.
