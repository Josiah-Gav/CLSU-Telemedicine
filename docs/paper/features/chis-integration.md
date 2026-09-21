# Simulated CHIS Integration

> Terminology follows `docs/paper/glossary.md`. Figure and table numbers use the
> `X.n` placeholder pending final manuscript numbering. **No credential value
> appears in this document — only configuration/route names, and one deliberately
> fictional demo token issued by `chis:issue-token` for local use.**

**This document supersedes the "CHIS integration — Not Implemented" section
previously carried in `docs/paper/00-feature-inventory.md`.** That section was
accurate when written: at the time, the only trace of CHIS anywhere in the
repository was a single hardcoded "Not connected" badge with no backend. It no
longer is. This file documents what was subsequently built, on the explicit
instruction of the capstone author, to satisfy Objective 8 ("integration-ready…
through defined data exchange mechanisms and simulated interactions") with a real,
tested implementation rather than a claim.

## 1. Feature Name

Simulated CHIS Integration — a RESTful, Sanctum-authenticated API exchanging data
in both directions with a **simulated** Comprehensive Health Information System
(CHIS), plus the UI wiring that consumes the receive direction inside the
consultation messaging page.

## 2. Purpose, and the Honesty Boundary This Feature Is Built Around

CHIS itself does not exist yet — it is a separate, ongoing capstone project. This
feature cannot integrate with a system that has no API to integrate with. What it
demonstrates instead, honestly, is:

1. That this system **exposes** a real, authenticated, tested REST endpoint a
   future CHIS could call to retrieve a closed encounter (the **send** direction).
2. That this system is **built to consume** an external identity/clinical-context
   API through a single interface (`App\Contracts\ChisClient`), currently bound to
   a fixture-backed implementation (`FakeChisClient`) standing in for CHIS, and
   swappable for a real HTTP client with a one-line change to that binding (the
   **receive** direction).

Every place this is described in the manuscript must use the qualifier
**"simulated"** — never "connected," "synced," or "integrated" unqualified. See
`docs/paper/glossary.md` §14 for the exact wording rule.

## 3. Actors / Roles

| Actor | Involvement |
|---|---|
| CHIS (simulated) | A Sanctum token holder, represented by `App\Models\ChisIntegrationClient` — deliberately **not** a `users` row. Demonstrated in practice by Postman, standing in for a CHIS developer/system calling this API. |
| Physician | Views the receive-direction data (identity, allergies, past injuries/surgeries, etc.) inside the messaging page's patient-info tab, for an active or completed consultation session they are assigned to. |
| Patient | Indirect only — the data displayed concerns them, but they do not access this tab or these endpoints themselves. |

## 4. User Workflow

**Send direction (CHIS pulls a closed encounter from us):**

1. CHIS authenticates with a Sanctum bearer token carrying the
   `chis:read-encounters` ability.
2. `GET /api/v1/consultations/{request_id}/encounter-summary`.
3. `ChisIntegrationController::encounterSummary` loads the consultation request and
   its clinical session and returns diagnosis, assessment, plan, recommendations,
   status, and identifiers as JSON — or 404 if no clinical session exists yet.

**Receive direction (we pull identity/clinical context from CHIS):**

1. A physician opens an active or completed consultation session's messaging page.
2. `ConsultationMessageController::show` reads the patient's `clsu_id` and calls
   `ChisClient::getIdentity()` / `::getMedicalProfile()` — currently
   `FakeChisClient`, reading `chis_mock_patient_records`.
3. The "Patient Info" tab renders blood type, height/weight/BMI, emergency
   contact, known allergies, chronic conditions, current medications, past
   injuries/surgeries, immunization history, and family medical history — or an
   explicit "no CLSU ID on file" / "no matching CHIS record" message.
4. **Nothing from step 3 is written to this application's database.** It is
   fetched fresh on every page load and discarded when the response ends.

This same receive direction is also callable directly, for the Postman defense
demo: `GET /api/v1/patients/{clsu_id}/identity` and
`GET /api/v1/patients/{clsu_id}/medical-profile`.

## 5. Routes

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/api/v1/consultations/{consultation}/encounter-summary` | `api.chis.encounter-summary` | `auth:sanctum`, `ability:chis:read-encounters` |
| GET | `/api/v1/patients/{clsu_id}/identity` | `api.chis.patient-identity` | `auth:sanctum`, `ability:chis:read-patients` |
| GET | `/api/v1/patients/{clsu_id}/medical-profile` | `api.chis.patient-medical-profile` | `auth:sanctum`, `ability:chis:read-patients` |

Defined in `routes/api.php`, registered via `api:` in `bootstrap/app.php`'s
`withRouting()` — this file did not exist before this feature; the application had
no API routes at all until now.

## 6. Controllers

`App\Http\Controllers\Api\ChisIntegrationController::encounterSummary`,
`::identity`, `::medicalProfile`. Depends on `App\Contracts\ChisClient` via
constructor injection for the receive-direction methods only; `encounterSummary`
reads exclusively from this application's own `Consultation`/`ConsultationSession`
models.

## 7. Services / Contracts

**`App\Contracts\ChisClient`** — the interface the rest of the application
depends on. Its docblock states the swap plan explicitly: *"Bound to
App\Services\Chis\FakeChisClient in AppServiceProvider until CHIS exposes a real
API — at that point a RealChisClient implementing this same contract replaces the
binding, and nothing else in the application changes."*

**`App\Services\Chis\FakeChisClient`** — the only implementation that exists. Reads
`users.clsu_id` for identity and `chis_mock_patient_records` for the medical
profile. Returns `null` (never throws) when nothing matches, which both API
consumers and the UI treat as "not found," not an error.

Bound in `AppServiceProvider::register()`:
`$this->app->bind(ChisClient::class, FakeChisClient::class);`

## 8. Models

| Model | Table | Purpose |
|---|---|---|
| `App\Models\ChisIntegrationClient` | `chis_integration_clients` | The Sanctum token principal for an external system integration. Implements `Authenticatable` by hand (`Illuminate\Auth\Authenticatable` trait) rather than extending the framework's `User` base class — it never logs in with a password, only ever resolved from a token. Kept off the `users` table entirely so a machine credential can never appear in the admin user-management list. |
| `App\Models\ChisMockPatientRecord` | `chis_mock_patient_records` | Fixture data standing in for what CHIS would hold. Read only through `FakeChisClient`, never queried directly from a controller. |

`App\Models\User` explicitly does **not** use `HasApiTokens` — its class comment
states why: the CHIS token principal is `ChisIntegrationClient`, not a `User` row,
and nothing else in the application issues API tokens to a CLSU account.

## 9. Database

**`chis_integration_clients`** (`2026_09_21_154843`): `id`, `name`, timestamps.
No foreign keys to `users`.

**`chis_mock_patient_records`** (`2026_09_21_154843`):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | PK |
| `clsu_id` | string | **unique**, no foreign key — deliberately loosely coupled, matching how a real external system would key its own records |
| `blood_type` | string, nullable | |
| `height_cm`, `weight_kg` | unsigned smallint, nullable | |
| `emergency_contact_name`, `_relationship`, `_number` | string, nullable | |
| `known_allergies`, `chronic_conditions`, `current_medications`, `past_injuries_surgeries`, `immunization_history` | JSON, nullable | cast to array |
| `family_medical_history` | text, nullable | |

**`personal_access_tokens`** (Sanctum's own migration, `2026_09_21_154825`) —
polymorphic, so it serves `ChisIntegrationClient` tokens without any schema change
of its own.

No table in this feature is a foreign key target of, or source for, this
application's own medical/clinical tables (`consultations`, `consultation_requests`).
The two domains are deliberately unconnected at the database level, matching the
"No Health Records Management" scope boundary.

## 10. Validation and Authorization

No request body on any of the three endpoints — each takes only route parameters.

Authorization is entirely at the **Sanctum token** layer, not the session/user
layer that gates every other route in this application:

- `auth:sanctum` — rejects an unauthenticated request with **401**.
- `ability:chis:read-encounters` / `ability:chis:read-patients` — rejects a token
  lacking the required ability with **403**, even if the token is otherwise valid.
  Both middleware aliases are registered by hand in `bootstrap/app.php`, since
  Sanctum ships them without auto-aliasing outside Jetstream/Fortify scaffolding.

Tokens are minted only by `php artisan chis:issue-token`, which re-issues (revoking
any previous token for that named client first) and prints the plaintext once to
the console — never stored, logged, or flashed, the same rule
`StaffAccountInvitation` follows for its own one-time token.

## 11. Business Rules

| # | Rule | Enforced by | Enforcement type |
|---|---|---|---|
| CH-1 | An unauthenticated caller may reach none of the three endpoints | `auth:sanctum` middleware | **Framework** |
| CH-2 | A token scoped only to encounters cannot read patient identity/medical data, and vice versa | Per-route `ability:` middleware | **Application (Sanctum abilities)** |
| CH-3 | The encounter-summary endpoint returns only this application's own data — never calls out anywhere | `encounterSummary()` reads `Consultation`/`ConsultationSession` directly | **Application (structural)** |
| CH-4 | The receive direction is served through one interface, never a controller querying `chis_mock_patient_records` directly | `ChisClient` contract + constructor injection | **Application (structural)** |
| CH-5 | No CHIS-sourced data is ever persisted into this application's own tables | `FakeChisClient` returns arrays; `ConsultationMessageController::show` passes them straight to the view, no `save()`/`create()` call anywhere in the path | **Application (structural — see Limitations CH-L2)** |
| CH-6 | A CHIS integration client can never authenticate as, or be mistaken for, a CLSU user account | `ChisIntegrationClient` is a separate table with its own `Authenticatable` implementation, not a `users` row | **Application (structural)** |

## 12. Concurrency

None applies. Every endpoint is a read; nothing here writes to a row another
request could contend for, so this feature adds no locking of its own.

## 13. Status / State

There is no status column or state machine in this feature. `ChisMockPatientRecord`
rows are static fixtures until re-seeded; a CHIS response is either found (200) or
not found (404) — there is no intermediate or pending state.

## 14. Error and Edge-Case Handling

| Case | Response | Test |
|---|---|---|
| No token, encounter-summary | 401 | `ChisEncounterSummaryEndpointTest` — *"rejects an encounter-summary request with no token"* |
| Token missing `chis:read-encounters` | 403 | *"rejects a token that lacks the chis:read-encounters ability"* |
| Consultation request with no clinical session yet | 404 | *"returns 404 for a consultation with no clinical session yet"* |
| Completed session, valid token | 200, full JSON body | *"returns the encounter summary for a completed consultation session"* |
| No token, patient identity/medical-profile | 401 | `ChisPatientLookupEndpointTest` — *"rejects a patient identity request with no token"* |
| Token missing `chis:read-patients` | 403 | *"rejects a token that lacks the chis:read-patients ability"* |
| Unknown `clsu_id`, identity | 404 | *"returns 404 identity for an unknown clsu_id"* |
| Unknown `clsu_id`, medical profile | 404 | *"returns 404 medical profile for an unknown clsu_id"* |
| Known `clsu_id`, record exists but every clinical field is empty | 200, empty arrays (not 404) | *"returns an empty-but-found medical profile when no clinical fields are on file"* — distinguishes "found, nothing reported" from "not found" |
| Patient has no `clsu_id` on file (UI) | Explicit "No CLSU ID on file" banner, no request made | `messaging.blade.php` `@if(!$consultationRequest->patient?->clsu_id)` branch |
| Patient has a `clsu_id` but no matching mock record (UI) | Explicit "No matching CHIS record" banner | `@elseif(!$chisMedicalProfile)` branch |

## 15. UI Implementation

Inside `resources/views/consultations/messaging.blade.php`, the "Patient Info" tab
(`x-show="activeTab === 'patient'"`), previously **every** field hardcoded to
"No Data" with a static "Not connected" badge. Now:

- `ConsultationMessageController::show` fetches `$chisIdentity` and
  `$chisMedicalProfile` once per page load and passes them to the view — never
  fetched client-side, never cached, never written to a session or a table.
- Three mutually exclusive states, each with its own banner: no `clsu_id` on file;
  `clsu_id` present but no matching record; record found. The found state is
  labeled **"Simulated CHIS data"** in the banner text itself, not just in code
  comments — so the distinction is visible on screen, including in the Postman/UI
  defense demo, not only in the source.
- BMI is computed in the controller-adjacent Blade `@php` block from
  `height_cm`/`weight_kg` when both are present, never stored.
- Empty clinical arrays render as **"None reported"**, distinct from **"No Data"**
  (no record at all) — the same found/not-found distinction the API enforces.
- The "CHIS Sync Status" card now reads **"Simulated — found"** / **"No record"**
  instead of a permanent "Not connected," and states plainly *"Fetched live on this
  page load — not stored."*

## 16. Tests

**2 files, 11 cases**, `tests/Feature/Api/`:

| File | Cases | Focus |
|---|---|---|
| `ChisEncounterSummaryEndpointTest.php` | 4 | No token, wrong ability, full happy path, missing clinical session |
| `ChisPatientLookupEndpointTest.php` | 7 | No token, known/unknown identity, known/empty/unknown medical profile, wrong ability |

Shared helper `issueChisToken()` lives in `tests/Pest.php` (not duplicated in
either test file — a top-level function declared in two included Pest files
fatals with "cannot redeclare"), following the same shared-helper convention as
`makeConsultationIntakeAvailable()`.

Existing suites re-run clean: `php artisan test` — 1,284 passed, 9 pre-existing
failures (Cloudinary credentials absent in this environment, unrelated to this
feature, present before this feature was added), 4 skipped.

## 17. Source-Code Evidence

| Claim | Evidence |
|---|---|
| CHIS does not exist; this simulates both directions | `ChisClient` interface docblock |
| Swap point for a real integration is one binding | `AppServiceProvider::register()` comment |
| `User` deliberately has no API tokens | `App\Models\User` class comment, above `use HasFactory, Notifiable;` |
| CHIS token principal is not a `users` row, on purpose | `ChisIntegrationClient` class docblock |
| Mock data table is not this application's medical record | `ChisMockPatientRecord` class docblock |
| Plaintext token never stored/logged | `IssueChisIntegrationToken` class docblock |
| No persistence on the receive path | `ConsultationMessageController::show` comment directly above the `$clsuId`/`$chisIdentity` lines |

## 18. Limitations / Gaps

| # | Limitation |
|---|---|
| CH-L1 | **CHIS does not exist.** Every "receive" response is fixture data seeded by `ChisMockPatientRecordSeeder`, not a real external system. This must never be described in the manuscript as CHIS interoperability, only as a simulation built to a real, swappable contract. |
| CH-L2 | **The no-persistence rule is enforced by omission, not by a database or framework constraint.** Nothing prevents a future change to `ConsultationMessageController` from calling `->save()` on CHIS-sourced data; the guarantee is that no code path currently does, verified by reading the controller, not by a technical barrier. Any future change touching this path must preserve it deliberately. |
| CH-L3 | **`encounterSummary()` uses `Consultation::physician`, the request-level assignment, not the clinical session's `physician_id`.** In a takeover scenario (see `physician-takeover.md`) these can differ; the endpoint reports the originally assigned physician, not necessarily whoever actually completed the session. |
| CH-L4 | **The fixture dataset is small.** `ChisMockPatientRecordSeeder` seeds exactly two records, tied to the two QA test patient accounts. A defense demo or grader testing a third patient's `clsu_id` will correctly get a 404 ("no matching CHIS record"), which is accurate behavior but easy to mistake for a bug if this limitation isn't known going in. |
| CH-L5 | **No rate limiting on the three routes**, unlike the video-consultation routes' `throttle:30,1`. Acceptable for a machine-to-machine demo integration with one issued token, but would need revisiting before any real exposure. |
| CH-L6 | **Sanctum tokens issued by `chis:issue-token` never expire** (no `expires_at` set) and carry both abilities by default. Fine for a controlled defense demo; a real integration would scope and expire tokens per consumer. |
