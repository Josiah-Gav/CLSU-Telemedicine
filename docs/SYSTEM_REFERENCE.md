# Telemed — System Reference (Presentation / Defense Guide)

A single reference for every service, rule, job, queue, scheduled task, polling
loop and security mechanism in the CLSU Telemedicine application, written so it
can be used to answer panel questions. Every number and rule below is taken from
the code; the file that owns it is named so it can be shown if challenged.

> **Accuracy note on wording.** Cloudinary files are **not "hashed"** for
> security — they are protected by **authenticated delivery + short-lived signed
> URLs** (a cryptographic signature, see §9). Hashing is used elsewhere
> (passwords, invitation tokens). Use the right term in the defense: a panel
> member who knows the difference will notice.

---

## 1. System at a glance

| Item | Value |
|---|---|
| Framework | Laravel 12 (PHP), Blade + Tailwind CSS + Alpine.js, bundled with Vite |
| Database | MySQL/MariaDB (dev & prod); tests use in-memory SQLite |
| Roles | `patient`, `nurse`, `physician`, `admin` |
| Real-time strategy | **HTTP polling** (no WebSockets) — see §7 |
| Video | Jitsi as a Service (8x8 JaaS) with RS256 JWTs |
| File storage | Cloudinary (authenticated delivery) → private local disk fallback |
| Queue | `database` driver (`jobs` table) |
| Cache / sessions | `database` driver |
| External system | CHIS (CLSU Comprehensive Health Information System) REST API |
| API | `/api/v1`, Laravel Sanctum bearer tokens, admin-only |

---

## 2. Roles and what each can do

| Role | Can do | Cannot do |
|---|---|---|
| **Patient** | Self-register (CLSU email only), submit a consultation request, cancel while `pending`/`reviewed`, chat/video with the assigned physician, download own prescription/attachments, request a follow-up within 7 days, export own history (CSV/PDF) | See staff identity before assignment, open a second request while one is in flight, start a video room |
| **Nurse** | Triage the queue: approve (set priority High/Normal) or reject with reason; forward or reject patient follow-up requests; view all intake attachments; dashboard + exports | Chat with patients, open messaging/video, see unread counts |
| **Physician** | Open/close consultation intake, manage recurring intake hours and bookable slots, start/schedule/reject reviewed consultations, take over a stale scheduled consultation, chat, video, clinical documentation, prescriptions, complete, create follow-ups, view patient's CHIS profile | Act on another physician's assigned consultation, start a scheduled slot early (>15 min) |
| **Admin** | Create/edit users, invite staff, suspend accounts, analytics dashboard + exports, CHIS sync page (Retry), CHIS audit log, own API tokens | Self-register; is created by another admin |

**How roles are enforced**
- All app routes sit in an `auth` + `verified` middleware group (`routes/web.php`).
- Nurse/physician routes are prefixed `nurses/{nurse}/…` and `physicians/{physician}/…`. Every action calls a private `authorizeNurse()` / `authorizePhysician()` that checks **both** the role **and** that `Auth::id()` equals the user in the URL — the URL parameter alone is never trusted.
- Policies: `ConsultationPolicy` (patient views only own request) and `ConsultationSessionPolicy` (`viewMessaging`, `sendMessage`, `joinVideo`, `startVideo`).
- `EnsureAccountIsActive` middleware logs out a suspended account **on its very next request** (JSON endpoints get `401`, pages redirect to login) — suspension is immediate, not "when the session expires".

---

## 3. The consultation lifecycle (core business rules)

### 3.1 Two tables, one consultation

| Model | Table | PK | Tracks |
|---|---|---|---|
| `Consultation` | `consultation_requests` | `request_id` | the patient's *request*: `request_status`, nurse, physician, symptoms, priority, `type` (`initial`/`follow_up`) |
| `ConsultationSession` | `consultations` | `id` | the clinical *session*: `consultation_status`, assessment/plan/recommendations/diagnosis, prescription, `slot_id`, CHIS sync columns |

Both are updated together, inside one transaction, on every state change.

### 3.2 Request status flow

```
pending ──nurse approve──► reviewed ──physician start──────────► active ──complete──► completed
   │                          │      └─physician schedule─► scheduled ─start─┘
   │                          └──physician reject──► rejected
   ├──nurse reject──► rejected
   └──patient cancel (pending/reviewed only)──► cancelled
```

(`assigned` exists in the DB enum but no code path writes it.)

### 3.3 Rules enforced at submission (`ConsultationController::store`)
Checked in this order:
1. **One active request per patient** — refused if any request is `pending/reviewed/assigned/scheduled/active` (422).
2. **No in-flight follow-up** — refused if the patient has a follow-up `pending`/`forwarded`, or a follow-up consultation still in flight (422).
3. **Intake gate** — `PhysicianAvailabilityService::isServiceAvailable()` must be true, else **503** "Consultations are currently unavailable" (see §4). Placed *before* uploads so a refused request never reaches Cloudinary.
4. Validation: `concern_category` ≤100, `online_reason` ≤1000, notes ≤1000, attachments images only (jpeg/png/jpg/gif) ≤ **10 MB** each.
5. **At least one symptom**, and **every symptom must have an explicit severity 1–4** (Very mild / Mild / Moderate / Severe) — no default.
6. **Symptom onset cannot be in the future.**
7. On success: all active nurses are notified (`CONSULTATION_SUBMITTED`).

### 3.4 Concurrency protection — `ConsultationOwnershipService`
Every transition (`claimByNurse`, `rejectByNurse`, `cancelByPatient`, `startByPhysician`, `scheduleByPhysician`, `rejectReviewedByPhysician`, `takeOverByPhysician`, follow-up forward/reject/cancel/decide) follows the same pattern:

1. `DB::transaction(...)`
2. `SELECT … FOR UPDATE` (`lockForUpdate()`) on the row
3. **Re-check the status under the lock**
4. Write, commit.

**Why:** two nurses clicking "Approve" on the same request at the same moment. Without the lock both read `pending` and both claim it. With the lock, the second waits, re-reads `reviewed`, and gets "already being handled by another nurse". Covered by `tests/Feature/ConsultationConcurrencyTest.php`.

### 3.5 Starting and scheduling rules
- A physician can start a `reviewed`/`assigned`/`scheduled` request; it must not belong to another physician.
- **Scheduled consultations can be started at most 15 minutes before slot start**, and not after the slot has ended (must reschedule).
- A slot whose status is `missed` can be started immediately (it is already past).
- Scheduling: the slot must belong to that physician, be `available`, and be **in the future**. Rescheduling frees the old slot back to `available` and clears its `reminder_sent_at`.

### 3.6 Physician Takeover
- If a scheduled consultation's physician hasn't started it **10 minutes after slot start** (`TAKEOVER_GRACE_MINUTES = 10`), another physician may claim it.
- Exactly **one** takeover per consultation (checked via `taken_over_at` under lock).
- Audit columns: `original_physician_id`, `taken_over_by_physician_id`, `taken_over_at`. The slot's `physician_id` is never rewritten (it records who it was originally booked with).

### 3.7 Completion rules (`ConsultationMessageController::complete`)
- Only the **assigned physician**, only while `active`.
- **Diagnosis, Assessment, Plan and Recommendations must all be filled with real content** — the old placeholder text ("Initial assessment pending.") does not count.
- One transaction: session → `completed` + `completed_at`, request → `completed`, slot → `completed`, and any running video room is closed.
- **After** commit: CHIS sync is queued, the patient gets an in-app notification and a (queued) email.
- Idempotent: completing an already-completed consultation returns success without side effects.

---

## 4. Consultation intake (physician availability) — "Is the clinic open?"

Owner: `App\Services\PhysicianAvailabilityService` (the **only** writer of `physician_availability_sessions`).

**Definition:** "Intake open" = the service is accepting **new** requests. It never touches existing consultations — closing intake does not end an active consultation.

### 4.1 When can a patient submit? (`isServiceAvailable()`)
Both must be true:
1. **`hasOpenIntake()`** — at least one physician who is: role physician, account active, `online_status = online`, `last_seen_at` within **2 minutes**, with an intake session that is `open`, heartbeat within **120 s**, and not past planned end + grace.
2. **`pendingQueueHasCapacity()`** — fewer than **20** `pending` requests in the nurse queue (global, `CONSULTATION_QUEUE_LIMIT`).

Freshness is evaluated **at read time**, so even if the cron janitor never ran, a laptop that closed hours ago does not keep the clinic "open".

### 4.2 Modes and planned end
- Opened inside one of the physician's recurring weekly windows → **Scheduled** mode, planned end = end of that window (touching/overlapping windows are merged into one block).
- Opened outside any window → **Overtime** mode, planned end = start + **120 min** (`max_overtime_minutes`).
- Mode and planned end are fixed when the session opens.
- At planned end the physician is **warned** (banner + notification). If they do nothing, intake **auto-closes 15 minutes later** (`schedule_end_grace_minutes`). "Continue as overtime" closes the current session and opens a new 120-minute overtime one (only allowed at/after the planned end — the cap can't be reset at will).

### 4.3 Configurable values (`config/consultations.php`)

| Key | Default | Env var |
|---|---|---|
| `queue_limit` | 20 | `CONSULTATION_QUEUE_LIMIT` |
| `stale_after_seconds` | 120 (miss 2 heartbeats) | `CONSULTATION_INTAKE_STALE_AFTER` |
| `schedule_end_grace_minutes` | 15 | `CONSULTATION_INTAKE_SCHEDULE_END_GRACE` |
| `max_overtime_minutes` | 120 | `CONSULTATION_INTAKE_MAX_OVERTIME` |

### 4.4 Other intake rules
- `open()` is **idempotent** and locks the physician's `users` row, so 4 tabs/double-clicks still produce exactly one open session.
- The intake heartbeat **can only keep an open session alive**; it can never create or reopen one (opening is always a deliberate CSRF-protected click).
- **Logout closes intake** (`end_reason = logout`).
- End reasons: `manual_close`, `logout`, `stale_expiry`, `schedule_ended`, `overtime_limit_reached`, `continued_as_overtime`.
- Recurring windows: `day_of_week` 0–6, `end_time` after `start_time`, no two windows starting at the same time, no overlap between active windows; windows are half-open `[start, end)`.
- Patients see an anonymized weekly schedule and "next window" hint — **never a physician's name**.

---

## 5. Schedule slots (bookable appointments)

`ScheduleSlot` statuses: `available → booked → completed`, or `booked → missed`.

**Slot generation rules** (`PhysicianController::generateScheduleSlots` / `saveScheduleSlots`):
- Date must be today or later; durations **15, 30, 45 or 60 minutes**.
- Optional break range must be inside working hours; slots overlapping the break are skipped.
- Slots overlapping existing slots are skipped; slots already in the past are skipped.
- `saveScheduleSlots` re-checks past/overlap server-side (a direct POST can't bypass the preview), including overlaps *within the same payload*.

**Missed slots:** `consultations:mark-missed-slots` (every minute) marks a `booked` slot `missed` once its end time passes without the consultation starting, then notifies patient and physician (de-duplicated).

---

## 6. Follow-ups

### 6.1 Patient-initiated
- Allowed only on a **completed** consultation, within **7 days** of completion, reason ≤2000 chars.
- Blocked if the patient already has any follow-up in flight, or one exists for that same consultation.
- Flow: `pending → forwarded` (nurse) `→ approved / rejected` (physician), or `rejected` by nurse, or `cancelled` by patient (while `pending`/`forwarded`).
- Physician approval mode: **immediate** (new consultation goes straight to `active`) or **scheduled** (must pick an available future slot of their own).
- Handled by `ConsultationOwnershipService::decideFollowUpByPhysician` with locks.

### 6.2 Physician-initiated
- From a completed consultation the physician was assigned to; requires decision notes.
- Implemented in `PhysicianController::createFollowUpConsultationFromSource` — **known duplication** of the service's slot-booking/locking logic (be honest about this if asked; it's documented in CLAUDE.md as a maintenance risk).

Either path creates a **new** `Consultation` + `ConsultationSession` pair with `type = follow_up` and `parent_consultation_id` pointing to the original session. Only one active follow-up per source consultation.

---

## 7. Polling — every loop, its interval and its rules

The app uses **short polling over HTTP** (no WebSockets/Pusher). Every poll goes through the same `auth` middleware and policies as a normal page request.

| What | Where (view) | Endpoint | Interval | Rules |
|---|---|---|---|---|
| **Presence heartbeat** | `layouts/app.blade.php` (every authenticated page) | `POST /presence/heartbeat` | **60 s** + once on load | Sets `online_status=online`, `last_seen_at=now`. CSRF-exempt (it can only refresh presence, nothing else). |
| **Intake heartbeat** | same script, piggybacks on the 60 s beat | `POST /physicians/{id}/consultation-intake/heartbeat` | **60 s** | Sent **only if intake is believed open**. CSRF-protected. Server is the authority: once it answers `open:false`, the browser stops sending. Can never open a session. |
| **Chat messages** | `consultations/messaging.blade.php` | `GET /consultation-sessions/{id}/messages` | **3 s** | Skips if a previous fetch is still in flight. Only polls while `active`/`completed`. Response also carries `consultation_status` so the patient sees completion without reload. |
| **Chat presence / typing / video** | same page | `GET /consultation-sessions/{id}/presence` | **4 s** | Returns peer online (seen ≤2 min), typing flag, last seen, and `video.active` **boolean only** — never room name or JWT. |
| **Typing indicator** | same page | `POST …/typing` | on keystroke | Stored in **cache** with an **8-second TTL** — expires by itself if the user stops. |
| **Mark offline** | same page | `POST …/offline` | on `beforeunload` | Best-effort (browsers may drop requests on unload). |
| **Patient dashboard** | `patient/dashboard.blade.php` | `GET /dashboard/active-consultation` + unread counts | **5 s** each | Updates the consultation card/status live. |
| **Physician active consultations unread badges** | `physician/active_consultation.blade.php` | `GET /consultation-sessions/unread-counts` | **6 s** | Only patients and physicians get counts; nurses/admins get an empty list (prevents leaking that sessions exist). |
| **Notification bell** | `layouts/navigation.blade.php` | `GET /notifications/unread-count` | **30 s** | One poller feeds both desktop and mobile badges. A failed request keeps the old count. |
| **Nurse inbox** | `nurse/consultation_inbox.blade.php` | `GET /nurses/{id}/consultation-inbox/refresh` | **30 s** | Paused while a modal is open; reloads page when a new pending request arrives. |
| **Physician inbox** | `physician/consultation_inbox.blade.php` | `GET /physicians/{id}/consultation-inbox/refresh` | **30 s** | Paused while a modal is open; updates list in place. |
| **Admin user search** | admin users page | `GET /admin/users/search` | on keystroke | Returns JSON, table re-renders client-side. |

**Presence rule used everywhere:** a user counts as online only if `online_status = online` **and** `last_seen_at` is within **2 minutes**. `TrackUserPresence` middleware also refreshes presence on *every* authenticated request.

**Why polling (defense answer):** simple, works on shared hosting (Hostinger) with no persistent socket server, reuses existing auth/CSRF/policies. **Trade-offs to admit:** constant request load (e.g. chat = 20 req/min + 15 req/min per open tab), up to 3 s message latency, the messages endpoint returns the *whole* conversation each time (no incremental `since` cursor), and `TrackUserPresence` writes to the DB on every request. WebSockets (Laravel Reverb) would be the scale-up path.

---

## 8. Messaging & attachments rules

| Rule | Value |
|---|---|
| Message length | ≤ 2000 chars; message or attachment required |
| Attachments per message | max **3** |
| Allowed types | jpg, jpeg, png, pdf, doc, docx, mp4 |
| Image/document size | ≤ **10 MB** |
| Video | **MP4 only**, ≤ **50 MB**, max **1 per message** |
| Prescription | pdf/jpg/jpeg/png/doc/docx ≤ 10 MB; assigned physician only; only while active |
| Clinical fields | assessment/plan/recommendations ≤10,000; diagnosis ≤255 |
| Who can chat | the owning patient + the assigned physician, only while `active` (read-only once `completed`). Nurses cannot. |
| Attachment caching | `Cache-Control: private, max-age=3600` (never `public`); prescriptions are **not** cached (they can be replaced) |
| Notifications | each message → `NEW_MESSAGE`; each upload → `NEW_ATTACHMENT` to the other party |

Intake attachment access (`AttachmentController`): nurses — all; patient — own only; physician — requests still in the shared pool (`reviewed/assigned/scheduled`) or assigned to them.

---

## 9. Cloudinary file security (the "hashing" question) — `App\Services\MedicalFileStorage`

All medical files — intake images, chat attachments, prescriptions — go through **one class**.

### 9.1 Upload
1. Upload to Cloudinary with **`type = "authenticated"`** (not the default public `upload`). An authenticated asset's normal URL returns **401** — knowing the URL is not enough to get the file.
2. Upload timeout **10 s** (`CLOUDINARY_UPLOAD_TIMEOUT`) so a hung Cloudinary doesn't block a PHP worker for the SDK's 60 s default.
3. If Cloudinary fails/throws (or returns no `public_id`) → **fallback to the private local disk** `message_attachments` (root `storage/app/medical`). The upload is never lost.

### 9.2 What is stored in the database — a reference, never a URL
```
cloudinary:{resource_type}:{format}:{public_id}   e.g. cloudinary:image:png:telemed_consultations/ab12
{disk-relative path}                              e.g. message-attachments/7/<random40>.jpg
```

### 9.3 Download — authorize first, then sign
1. The controller authorizes (policy / role check).
2. Only then `MedicalFileStorage::response()` either:
   - **Cloudinary:** mints a **signed, expiring download URL** via the SDK's `privateDownloadUrl()`. The signature is computed from `public_id`, `format`, `type` and **`expires_at`** using the Cloudinary **API secret** (which never leaves the server). TTL = **300 s / 5 minutes** (`CLOUDINARY_SIGNED_URL_TTL`). The browser is redirected to it. Change any parameter or wait 5 minutes and Cloudinary rejects it.
   - **Local:** streams the file from the private disk through PHP.

### 9.4 Why this is secure (talking points)
- No permanent public link exists anywhere — not in the DB, not in the HTML.
- A leaked signed URL dies in 5 minutes; a new one requires passing authorization again.
- The signature can't be forged without the API secret.
- The private disk is **outside** `storage/app/public` (unreachable by the `/storage` symlink) and declared `serve => false` (Laravel registers no route to it).
- Delivery type is a **constant**, not config, so a misconfigured deployment cannot silently make files public.

### 9.5 Where hashing *is* actually used
| Thing | Mechanism |
|---|---|
| User passwords | bcrypt via Laravel's `'password' => 'hashed'` cast / `Hash::make` |
| Staff invitation & password-reset tokens | stored **hashed** in the token tables by Laravel's password broker; the plaintext exists only in the emailed link |
| Local fallback filenames | Laravel `store()` → random 40-character name (`hashName()`), so filenames are not guessable — random, not a content hash |
| Jitsi video tokens | **RS256** (RSA + SHA-256) signed JWTs |
| Video room names | 128-bit CSPRNG (`random_bytes(16)` → 32 hex chars) |
| Email verification links | Laravel **signed** URLs (HMAC with `APP_KEY`) |

### 9.6 Honest limitations
- Rows written **before** this change may still hold a public `http(s)` Cloudinary URL — those are still served (redirected) but are not protected. `php artisan attachments:move-to-private` migrates old *local public-disk* files.
- Replaced prescriptions on Cloudinary are **not deleted** (retention decision not made); only private-disk files are deleted.
- CLAUDE.md's Messaging section still says the fallback is the *public* disk — the code now uses the **private** disk; trust the code.

---

## 10. Video consultation (Jitsi / 8x8 JaaS)

- **Only the assigned physician can start** a room; the patient can only **join a running** room (`409` if none). Only while the consultation is `active`.
- Room name: 32 random hex chars — cannot contain patient name/ID.
- Each join mints a fresh **RS256 JWT** valid for **30 minutes**, scoped to exactly **one room**; physician = moderator. Nothing is stored.
- The browser receives only domain, room, JWT, display name — **no API key or private key**.
- Completing the consultation closes the room in the same transaction.
- Start/join throttled to **30 requests/min**.
- The patient learns the room is live through the 4 s presence poll (`video.active` boolean).

---

## 11. Notifications

### 11.1 In-app (`notifications` table) — always through `NotificationService`
- `send()` — one user. `sendToRole()` — every **active** user of a role (bulk insert). `sendUnique()` — skips if the user already has the same type for the same entity id (`consultation_id`, `follow_up_request_id`, `schedule_slot_id`, `session_id`, `request_id`, `message_id`) — prevents duplicates on refresh/re-runs.
- Types are a fixed enum (`App\Enums\NotificationType`), invalid types are silently refused: consultation submitted/reviewed/assigned/scheduled/rescheduled/starting soon/started/completed/missed/cancelled, new message/attachment, follow-up submitted/approved/rejected/scheduled/starting soon, high-priority consultation, physician request, system alert, intake end warning, intake auto-closed.
- High-priority approvals notify all physicians with `HIGH_PRIORITY_CONSULTATION`.

### 11.2 Email (Laravel Notifications)
| Email | Queued? | Trigger |
|---|---|---|
| `ConsultationScheduled` | queued | consultation booked into a slot |
| `ConsultationReminder` | queued | 24 h before slot (cron) |
| `ConsultationCompleted` | queued | completion |
| `FollowUpScheduled` | queued | scheduled follow-up |
| `StaffAccountInvitation` | **synchronous on purpose** | admin invites nurse/physician |

Rule: mail is sent **after** the DB transaction commits, and a mail failure is caught and logged (class name only) so it never rolls back or 500s the action.

---

## 12. Queues and jobs

- Driver: **`QUEUE_CONNECTION=database`** → jobs are rows in the `jobs` table; a worker (`php artisan queue:work`, or `queue:listen` in `composer run dev`) must be running or queued emails and CHIS syncs never execute.
- **Queued items:** `SyncEncounterToChis` job + the four queued emails above.
- **Never queued:** `StaffAccountInvitation` — queuing would serialize the plaintext invitation token into `jobs.payload` (a test asserts it isn't `ShouldQueue`).
- **Payload privacy rule:** jobs carry **ids only**. `SyncEncounterToChis` holds only the session id (+ optional actor id/source string); the clinical payload is rebuilt from the DB at send time, so `jobs.payload` never contains medical data.

### `SyncEncounterToChis` (the main job)
| Setting | Value |
|---|---|
| Tries | 5 |
| Backoff | 60 s, 5 min, 15 min, 60 min |
| Unique | `ShouldBeUnique` per session, lock expires after 2 h |
| Lock rule | DB row lock only while deciding/building the payload — **never held during the HTTP call** |
| Permanent error (4xx except 429) | status `failed` + short code, no retry, needs a human |
| Transient error (timeout, connection, 429, 5xx, unreadable body) | stays `pending`, rethrows → queue retries |
| Tries exhausted | row stays **`pending`** (never auto-failed); the sweeper keeps trying |
| Audit | exactly **one** audit row per real HTTP call |

---

## 13. Scheduled tasks (cron) — `routes/console.php`

All use `withoutOverlapping()`. **They only run if the scheduler is invoked** (`* * * * * php artisan schedule:run` in OS cron, or `php artisan schedule:work` locally).

| Command | Frequency | What it does |
|---|---|---|
| `consultations:mark-missed-slots` | every minute | booked slot past its end without start → `missed`, notify both sides |
| `consultations:expire-intake-sessions` | every minute | 1) close sessions past planned end + 15 min, 2) expire sessions with no heartbeat for 120 s, 3) send end-of-hours warnings (each exactly once) |
| `consultations:send-reminders` | every 15 min | email patient once when the booked slot is within 24 h; `reminder_sent_at` prevents duplicates |
| `chis:sync-pending` | every 15 min | re-queue CHIS syncs `pending` and idle ≥10 min |
| `auth:clear-resets staff_invitations` | daily | delete expired (7-day) staff invitation tokens |

Pattern used by the multi-table jobs: list candidates → for each, transaction + `lockForUpdate` + **re-check** → write → send notifications **after** the loop, outside locks.

Manual commands: `chis:health [--patient=ID]`, `chis:send-skipped [--confirm]` (dry-run without flag), `telemed:api-token`, `attachments:move-to-private`.

---

## 14. CHIS integration

- **Only `ChisClient` talks to CHIS.** Timeout 5 s, connect timeout 2 s. Never retries itself.
- **Identity:** patients matched by `users.clsu_id` (format `YY-NNNN`, `YY-NNNNNN` or `YYYY-NNNN`, unique, write-once for patients).
- **Lawful basis:** RA 10173 (Data Privacy Act) Sec. 13 — *medical treatment*, not consent. Patients get a **privacy notice**, not a consent toggle. Payload carries `"lawful_basis": "medical_treatment"`, `"consent": null`. ⚠ The requirement came **verbally** from CLSU MISO; no written confirmation; DPO review recommended before production. CHIS in testing is a **local simulator** — never claim a live CHIS connection.
- **Encounter push:** on completion → `ChisEncounterSync::queue()` sets `pending` (or `skipped` + `no_clsu_id`) using a conditional update so it can never be queued twice → job sends.
- **Statuses:** `pending`, `synced` (with `chis_reference`), `failed`, `skipped`, NULL.
- **Payload minimization** — sent: CLSU ID, name, physician, symptoms, clinical fields, follow-up info, prescription **file name and type only**. Never: chat, attachments, prescription file/URL, priority, nurse, email, phone, internal user id. Invalid payloads are never truncated — marked `failed: invalid_payload:<reason>`.
- **Physician view of CHIS profile:** lazy-loaded when the Patient tab opens; assigned physician only; first + last name must match (normalized for case/accents/spaces); **fail closed** — if the audit row can't be written, the profile is not shown; response `Cache-Control: no-store`; nothing from CHIS is stored; rendered with `x-text` (no HTML injection). Throttled 30/min.
- **Audit log** (`chis_audit_logs`, admin page `/admin/chis-audit`): records *that* data was sent/viewed (action, outcome, actor, patient, session, CLSU ID snapshot, HTTP status, reason, duration) — never *what*. Append-only at model level (update/delete throw); no foreign keys so rows outlive deleted users. Limitation: raw SQL could still modify it — true immutability needs DB grants.
- **Admin sync page** `/admin/chis-sync`: status counts, Retry for `failed`/`pending` (throttled 30/min).

---

## 15. REST API (Sanctum)

- `/api/v1/encounters` (list), `/encounters/{id}` (show), `POST /encounters/{id}/sync` (run sync now).
- **Bearer tokens only**, created by CLI: `php artisan telemed:api-token {email} --ability=encounters:read --ability=encounters:sync --days=30`. Owner must be an **active admin**; abilities explicit (no `*`); expiry default **30 days**, max **365**; `--list`, `--revoke=<id>`.
- Every request re-checks owner is still an active admin and the token has the ability (else 403).
- Throttle **60 requests/min**.
- Returns **sync status only** — no clinical data, names or CLSU IDs.
- Errors always JSON `{message, code}`: `UNAUTHENTICATED` 401, `FORBIDDEN` 403, `NOT_FOUND` 404, `VALIDATION_FAILED` 422, `RATE_LIMITED` 429, `NOT_ELIGIBLE`/`SYNC_IN_PROGRESS` 409. A transient CHIS failure returns 200 with `pending`, never 500.

---

## 16. Authentication & account security

| Rule | Value / where |
|---|---|
| Patient registration | CLSU emails only: `@clsu.edu.ph` or `@clsu2.edu.ph`; CLSU ID required and unique; Laravel default password rules; throttle **10/min per IP** (campus NAT) |
| Email verification | required (`verified` middleware), signed link, throttle 6/min |
| Login lockout | **5 failed attempts** per email+IP, then locked out (Laravel `RateLimiter`) |
| Inactive account at login | checked **after** the password is correct, so attackers can't probe which accounts are inactive/pending |
| Session | `database` driver, 120-minute lifetime, regenerated on login, invalidated + CSRF token regenerated on logout |
| CSRF | on every POST except `/presence/heartbeat` |
| Forgot/reset password | throttle 6/min per IP + 60 s per email; reset token expires **60 min** |
| Staff accounts (nurse/physician) | never self-register, never given a password by admin: created `inactive`, invited by email, **7-day** single-use activation link (separate `staff_invitations` broker); resend throttled 30/min and invalidates the previous link; changing a pending invitee's email revokes the old link |
| Token handling | plaintext invitation token never stored, logged, flashed (`dontFlash: token`) or queued |
| Suspension | effective on the next request (`EnsureAccountIsActive`) |
| Rate limits elsewhere | resend invitation, CHIS retry, CHIS profile, video start/join: 30/min |

---

## 17. Analytics & exports

- Role dashboards (nurse, physician, admin) via `DashboardAnalyticsService`; date presets `today`, `this_week`, `this_month`, `last_30_days`, `this_year`, `custom` (max **730 days**).
- Metrics are built from canonical model scopes (`completed`, `concluded`, `inFlight`, `forNurse`, `forPhysician`, …) so every chart agrees. Completion rate = completed ÷ concluded.
- Symptom analytics: standardized symptom names; a custom symptom term appears only after **3** reports.
- Exports: CSV and PDF (DomPDF, A4 landscape). PDF capped at **500 rows** (marked truncated). History filters: date `today/last_7_days/last_30_days/all`, status `completed/cancelled/rejected/all`, type `follow_up/general/all`.
- **CSV formula-injection guard:** any cell starting with `= + - @ TAB CR` is prefixed with `'` so Excel won't execute it.
- Exports send `no-store` cache headers; patient export requires role patient explicitly.

---

## 18. Other security headers/settings worth knowing

- `trustProxies('*')` — needed behind ngrok/Cloudflare/load balancer for correct HTTPS URLs (and Jitsi camera access). Safe **only if** the app is reachable solely through that proxy; otherwise clients could spoof `X-Forwarded-For` and bypass per-IP throttles. Deployment decision documented in `docs/HOSTINGER_QA.md`.
- `SESSION_ENCRYPT=false` by default (sessions are in the DB, unencrypted) — a hardening option for production.
- `/consultations/history` and `/consultations/create` are under `auth` only, not `verified` (minor inconsistency).

---

## 19. Likely panel questions — short answers

**Q: How do you prevent two nurses approving the same request?**
Pessimistic locking: transaction + `SELECT … FOR UPDATE`, then re-check status under the lock. The second nurse waits, sees `reviewed`, and is refused. Tested in `ConsultationConcurrencyTest`.

**Q: Is it real-time?**
Near-real-time via polling: chat 3 s, presence 4 s, patient dashboard 5 s, unread badges 6 s, inboxes/notifications 30 s, heartbeat 60 s. No WebSockets — chosen for shared-hosting compatibility; cost is extra requests and up to ~3 s latency.

**Q: How are medical files protected on Cloudinary?**
Uploaded as *authenticated* assets (public URL returns 401). The DB stores a reference, not a URL. After the app authorizes the user, it generates a signed download URL that expires in 5 minutes, signed with the API secret only the server has. If Cloudinary is down, files go to a private disk outside the web root. (It's signing, not hashing.)

**Q: What if Cloudinary is down?**
10-second timeout, then automatic fallback to the private local disk. Download code handles both.

**Q: What if CHIS is down?**
Completion never waits for CHIS — it only queues a job. The job retries 5 times (1 min → 1 h), then stays `pending`; the 15-minute sweeper keeps re-queuing. Permanent errors become `failed` for an admin to Retry.

**Q: What stops patients spamming requests?**
One active request per patient, no request while a follow-up is in flight, the intake gate (a physician must be online with intake open), a global queue cap of 20 pending, plus throttles on auth endpoints.

**Q: What happens if a physician closes their laptop with intake open?**
Heartbeats stop; after 120 s the session no longer counts (read-time check), and the every-minute janitor marks it `expired`. Existing consultations are unaffected.

**Q: What if the physician doesn't show up for a scheduled consultation?**
After slot start + 10 min another physician can take it over (once). After the slot ends, the cron marks it `missed` and notifies both; it can still be started or rescheduled.

**Q: How is patient privacy handled with CHIS?**
Lawful basis = medical treatment (RA 10173 Sec. 13), privacy notice to patients, minimized payload (no chat/files/contact info), ids-only queue payloads, audit log of every access, fail-closed profile view, no caching of CHIS data. Caveat: MISO's requirement is verbal; DPO review pending; CHIS used in testing is a simulator.

**Q: How do staff get accounts?**
Admin creates them inactive; an emailed 7-day single-use link lets them set their own password. Tokens are stored hashed and never logged or queued.

**Q: What are the known weaknesses?**
Polling load and full-conversation refetch; duplicated physician follow-up logic; legacy public Cloudinary URLs; audit log immutability only at app level; unencrypted sessions by default; `trustProxies('*')` depends on deployment; scheduler and queue worker must be running for reminders, missed-slot marking, intake janitor and CHIS sync. Owning these honestly is stronger than claiming none exist.
