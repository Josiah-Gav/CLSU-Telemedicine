# Consultation Request Submission

> Terminology follows `docs/paper/glossary.md`. Figure and table numbers use the
> `X.n` placeholder pending final manuscript numbering.

## 1. Feature Name

Consultation Request Submission — the patient's symptom-intake form that creates a
**consultation request** (`consultation_requests`).

## 2. Purpose

To let a patient describe their symptoms and reason for consulting online, attach
supporting images, and submit a request into the nurse-review queue — while
enforcing that a patient holds only one open request at a time and that the service
is currently able to accept a new one.

## 3. Actors / Roles

| Actor | Involvement |
|---|---|
| Patient | The only submitter. `create()` aborts 403 for any other role. |
| Nurse | Not an actor here, but every nurse is notified on successful submission. |

## 4. User Workflow

1. Patient opens `GET /consultations/create` (or `GET /newconsultation`).
2. `ConsultationController::create` performs three things before rendering: a role
   check, a duplicate-request check, and an intake-availability lookup. A patient
   who already has an open request is **redirected to the dashboard** with a status
   message rather than shown the form.
3. The form is a five-step Alpine wizard. Step 1 is the patient profile card, step
   2 symptom selection, step 3 attachments, step 4 review, step 5 submitted.
4. On submit, Alpine serialises the selected symptoms to a hidden
   `symptoms_payload` input and POSTs the form via `fetch()` as `FormData`.
5. `ConsultationController::store` runs its checks **in a deliberate order**:
   duplicate request → intake gate → validation → symptom payload decode → onset
   date check → attachment upload → row creation → nurse notification.
6. On success the endpoint returns HTTP 201 with the created request, and Alpine
   advances to step 5.

## 5. Routes

| Method | URI | Name | Middleware |
|---|---|---|---|
| GET | `/consultations/create` | `consultations.create` | `auth` only |
| POST | `/consultations` | `consultations.store` | `auth` only |
| GET | `/newconsultation` | `newconsultation` | `auth` + `verified` |

`consultations.create` and `consultations.store` sit in an **`auth`-only** group at
the bottom of `routes/web.php`, not the `auth`+`verified` group — see gap CR-5.

## 6. Controllers

`ConsultationController::create` and `::store`. `DashboardController::newconsultation`
renders the same intake page from the dashboard entry point.

## 7. Services

- `PhysicianAvailabilityService::isServiceAvailable()` — the intake gate. Injected
  through the constructor. Documented in `physician-intake.md`.
- `NotificationService::sendToRole('nurse', ...)` — static fan-out on success.

No service owns the creation itself; `store()` calls `Consultation::create()`
directly. Unlike every later workflow transition, submission does **not** go
through `ConsultationOwnershipService`.

## 8. Models

`App\Models\Consultation` (table `consultation_requests`, PK `request_id`).

Two casts matter here:

```php
protected $casts = [
    'symptoms_desc'    => 'array',
    'file_attachments' => 'array',
];
```

Both columns are declared `text`/`longText` in the schema; the array cast
JSON-encodes on write and decodes on read. The manuscript should describe them as
**JSON-encoded text columns**, not as native JSON columns.

## 9. Database

Writes one row to `consultation_requests`. Columns set at submission:

| Column | Value at submission |
|---|---|
| `patient_id` | `auth()->id()` (FK → `users.user_id`) |
| `assigned_physician_id`, `assigned_nurse_id` | explicitly `null` |
| `concern_category` | validated string |
| `symptoms_desc` | the decoded symptom array, JSON-encoded by the cast |
| `online_reason` | validated string |
| `additional_information` | from the `additional_notes` field, or null |
| `file_attachments` | array of URLs, or null when none |
| `request_status` | `'pending'` |
| `submitted_at` | `useCurrent()` — this is the model's `CREATED_AT` |

**Not set at submission:** `priority_level` stays `null`. The migration
`2026_08_29_154539_change_priority_level_default_to_null_...` changed the default
from `'Normal'` to `NULL` precisely so an untriaged request does not look as though
a nurse had already assessed it. `type` defaults to `'initial'`.

## 10. Validation and Authorization

**Authorization.** `create()` aborts 403 unless `role === 'patient'`. **`store()`
performs no role check at all** — it relies on `patient_id = auth()->id()`, so any
authenticated user could create a request owned by themselves. Because
`consultation_requests.patient_id` is just a user FK, a nurse or physician POSTing
here would create a request attributed to their own account.

**Validation** (`store()`):

| Field | Rule |
|---|---|
| `concern_category` | required, string, max 100 |
| `symptoms_payload` | required, string (a JSON blob) |
| `online_reason` | required, string, max 1000 |
| `additional_notes` | nullable, string, max 1000 |
| `attachments.*` | nullable, **`image`**, `mimes:jpeg,png,jpg,gif`, max 10240 KB |

Attachments at submission are therefore **images only** — no PDFs or documents,
unlike message attachments inside a session.

**Post-validation checks** performed manually because they cannot be expressed as
rules:

- The payload must decode to a non-empty array, else 422 *"Please provide at least
  one symptom."*
- Every symptom carrying a `date` is parsed with `Carbon`; an unparseable value
  returns 422, and a future instant returns 422 *"Symptom onset date and time
  cannot be in the future."* The comment states this explicitly: the picker only
  offers past values, but `symptoms_payload` is otherwise unvalidated per entry, so
  this is **the only check that actually enforces it**.

## 11. Business Rules

| # | Rule | Enforced by | Enforcement type |
|---|---|---|---|
| BR-1 | One open consultation request per patient | `store()` existence query across `['pending','reviewed','assigned','scheduled','active']`, excluding requests whose session is no longer `scheduled`/`active` | **Application** — no unique index exists |
| BR-2 | No new request while intake is unavailable | `isServiceAvailable()` → HTTP 503 | **Application** |
| BR-3 | The duplicate check runs *before* the intake gate | Ordering in `store()`, with a comment explaining it is the more accurate of two true answers | **Application** |
| BR-4 | A refused request never reaches Cloudinary, never writes a row, never notifies | The gate is placed before validation and uploads | **Application** |
| BR-5 | At least one symptom is required | Manual decode check | **Application** |
| BR-6 | Symptom onset may not be in the future | Manual Carbon comparison | **Application** |
| BR-7 | `priority_level` stays null until a nurse triages | Not written at creation; enum default changed to NULL | **Database default + application** |
| BR-8 | Upload failure must not fail the request | `try/catch` around the Cloudinary call, falling back to the local `public` disk | **Application** |

**Every rule in this feature is application-enforced.** BR-7 is the only one with
any database participation, and only as a default value. In particular **BR-1 has
no database constraint** — two concurrent submissions from the same patient are
prevented only by the existence check, which is not inside a transaction or a lock.

## 12. Status / State Transitions

Submission creates a request at `request_status = 'pending'`. It creates **no**
consultation session — `consultations` stays empty for this request until a
physician schedules or starts it.

```mermaid
stateDiagram-v2
    [*] --> pending : ConsultationController::store
    pending --> reviewed : nurse claim
    pending --> rejected : nurse reject
    pending --> cancelled : patient cancel
```

*Figure X.3 — The only transitions reachable from a freshly submitted consultation
request. `assigned` is present in the enum but written by nothing and is
deliberately omitted.*

## 13. Error and Edge-Case Handling

| Case | Response | Evidence |
|---|---|---|
| Patient already has an open request | **422** *"You may only have one active consultation request at a time."* | `store()` |
| Service cannot accept requests | **503** *"Consultations are currently unavailable. Please try again later."* | `store()`; the comment notes 503 is chosen over 422 because this is a temporary service condition, and that it is the only 503 `store()` can return |
| Both conditions true | The **duplicate** answer wins | `ConsultationIntakeGateTest`: "reports the duplicate, not the outage, when a patient with an open request submits while unavailable" |
| Empty or malformed symptom payload | 422 | `store()` |
| Unparseable onset date | 422, not a 500 | `ConsultationSymptomOnsetDateTest` |
| Any one of several symptoms dated in the future | Whole request rejected | Same file |
| Cloudinary upload throws | Logged, file stored on the local `public` disk, and `asset('storage/'.$path)` recorded — so the fallback also stores a **URL**, not a bare path | `store()` |
| Any other exception during creation | Logged; 500 *"Server error encountered."* | `store()` |
| Non-patient opens the form | 403 | `create()` |
| Patient with an open request opens the form | Redirect to dashboard with a status message; **availability is not even computed** | `ConsultationIntakeAvailabilityUiTest`: "does not compute intake availability at all for a patient with a duplicate request" |

**Race note.** The page may render as "available" and the submission still be
refused with 503, because the gate is re-evaluated at submit time. This is tested
deliberately: *"still refuses submission with 503 even though the page was rendered
as available."*

## 14. UI Implementation

`resources/views/patient/newconsultation.blade.php` — a single Alpine component
(`x-data` at line 11) driving a five-step wizard.

- `intakeAvailable: @json($intakeAvailable ?? true)` is **server-rendered into the
  component**, so the page never flashes "Available" before correcting itself.
- `canAdvanceToStep(step)` returns false for any `step >= 2` when
  `intakeAvailable` is false, confining the patient to step 1 while intake is
  closed. A test asserts this guard ships in the markup.
- The availability banner is styled conditionally
  (`:class="intakeAvailable ? 'border-brand-green...' : 'border-amber-300...'"`)
  with `x-text` switching between "Consultations Available" and "Consultations
  Currently Unavailable".
- The form posts to `route('consultations.store')` with
  `enctype="multipart/form-data"` and `@submit.prevent`, submitted through
  `fetch()` with a `FormData` built from the form element.
- `<input type="hidden" name="symptoms_payload" :value="JSON.stringify(selectedSymptoms)">`
  is how the symptom array reaches the server.
- On a 503 the component sets `this.intakeAvailable = false`, so the page
  self-corrects without a reload.

The client-side guard is a **convenience, not a control**: the server gate in
`store()` is what actually enforces BR-2, and the tests verify both independently.

## 15. Tests

| File | Cases | Covers |
|---|---|---|
| `tests/Feature/ConsultationIntakeGateTest.php` | 22 | Every refusal condition (no intake, stale session, offline physician, stale presence, inactive account, queue limit), that nothing is written or notified on refusal, that no physician identity leaks, and that the gate does not affect already-accepted requests or physician-initiated follow-ups |
| `tests/Feature/ConsultationIntakeAvailabilityUiTest.php` | 16 | Server-rendered availability on three pages, the client-side step guard, no physician identity leakage, the render-available/submit-503 race, and that active consultations survive an outage |
| `tests/Feature/ConsultationSymptomOnsetDateTest.php` | 7 | Future dates, same-day later times, past dates, optional dates, unparseable input, multi-symptom rejection |
| `tests/Feature/ConsultationAdditionalInformationTest.php` | 4 | `additional_notes` → `additional_information`, null when omitted, the 1000-character limit, and the nurse modal label |
| `tests/Feature/ConsultationPriorityDefaultTest.php` | 1 | `priority_level` is null on a fresh request |

## 16. Source-Code Evidence

| Claim | Evidence |
|---|---|
| Role check on the form only | `ConsultationController::create`, `if ($patient->role !== 'patient') abort(403)`; no equivalent in `store()` |
| Duplicate rule | `Consultation::where('patient_id', auth()->id())->whereIn('request_status', [...])->where(fn …)->exists()` in both `create()` and `store()` |
| Gate ordering and 503 | `if (! $this->availabilityService->isServiceAvailable())` with its four-paragraph comment |
| Images-only attachments | `'attachments.*' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:10240'` |
| Onset enforcement | The `foreach ($symptomsData as $symptom)` block with `$onset->isFuture()` |
| Cloudinary timeout bound | `'timeout' => config('cloudinary.upload_timeout')` with a comment about not holding a PHP worker for the SDK's 60-second default |
| Local fallback stores a URL | `$path = $file->store('consultation-attachments', 'public'); $uploadedFilesUrls[] = asset('storage/' . $path);` |
| Nurse fan-out | `NotificationService::sendToRole('nurse', NotificationType::CONSULTATION_SUBMITTED, ...)` |
| Array casts | `Consultation::$casts` |

## 17. Limitations / Gaps

| # | Limitation |
|---|---|
| CR-1 | **BR-1 is not race-safe.** The one-open-request rule is an `exists()` check with no transaction, no `lockForUpdate()`, and no unique index. Two simultaneous submissions from one patient could both pass. This is the one workflow write in the system that does **not** go through `ConsultationOwnershipService`, and it is correspondingly the one without lock protection. No test covers concurrent submission. |
| CR-2 | **`store()` has no role check.** Only `create()` verifies `role === 'patient'`. A nurse, physician, or admin POSTing directly to `consultations.store` would create a consultation request owned by their own account. |
| CR-3 | **`symptoms_payload` is unvalidated per entry.** Only the onset date is checked. Symptom names, severities, and any other keys are stored verbatim into `symptoms_desc`. The controller comment acknowledges this, referring to `SymptomAnalytics`' docblock (H-4). |
| CR-4 | **The local fallback writes to the public disk.** When Cloudinary fails, the image is stored on `public` and recorded as `asset('storage/...')` — a publicly reachable URL. Message attachments were deliberately migrated to a private disk (`attachments-and-prescriptions.md`), but request attachments were not. |
| CR-5 | **These routes are not `verified`-gated.** `consultations.create` and `consultations.store` sit in an `auth`-only group, unlike `/newconsultation` which is in the `auth`+`verified` group. An unverified patient can reach the form and submit. |
| CR-6 | **Dead code in this controller.** `ConsultationController` imports `App\Models\SymptomLog`, a class that **does not exist** in `app/Models/`. Its `index()` method returns `view('consultations.index')`, a view that does not exist, and no route points to it. Neither breaks anything today — the import is never instantiated and the method is unreachable — but both should be acknowledged rather than explained away. |
| CR-7 | **Submission does not create a consultation session.** Anyone reading the ERD may expect a 1:1 row; the `consultations` row appears only when a physician schedules or starts the request, and never at all for a rejected or cancelled one. |
