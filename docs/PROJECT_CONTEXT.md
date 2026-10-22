# CLSU Telemedicine — Project Context for an AI Assistant

> **How to use this file.** Paste this whole file at the start of a new chat (or
> upload it to a Claude Project as knowledge, together with `CLAUDE.md`). Then
> paste the *actual source* of the files you want changed — the assistant
> cannot see the repository, so this document tells it *where things are and
> what rules they follow*, while the pasted files tell it *what the code
> currently says*. Never ask it to edit a file you have not pasted.
>
> Snapshot taken from branch `main` at commit `38aa2ad` (CHIS phase 8 merged).
> If the code has moved on since, trust the code over this document.

---

## 1. What the system is

A **Laravel 12** telemedicine web app for the **CLSU Infirmary** (Central Luzon
State University). It runs the full lifecycle of a patient consultation:

1. Patient submits a consultation request (symptoms, onset, severity, attachments).
2. Nurse triages it (sets priority High/Normal, or rejects with a reason).
3. Physician reviews it, books it onto one of their schedule slots, starts it.
4. Physician and patient chat (HTTP-polled), optionally join a **Jitsi (JaaS)** video call.
5. Physician documents diagnosis / assessment / plan / recommendations, uploads a prescription, completes it.
6. Completed encounter is pushed to **CHIS** (CLSU's Comprehensive Health Information System) via a queued job.
7. Follow-ups: patient asks (nurse forwards → physician decides) or physician creates one directly.

Roles: `patient`, `nurse`, `physician`, `admin`.

### Stack

| Layer | Tech |
|---|---|
| Backend | PHP ^8.2, Laravel 12, Sanctum 4 (API tokens), dompdf (PDF exports), sweet-alert |
| Frontend | Server-rendered **Blade + Tailwind CSS + Alpine.js**, bundled with **Vite 7** (Node ≥ 20.19). **No Bootstrap, no SPA framework.** |
| DB | **MySQL/MariaDB** in dev (XAMPP, db `telemed`) and production. Tests use in-memory **SQLite**. |
| Queue / cache / session | `database` driver for all three |
| Files | Cloudinary (authenticated delivery) with private local disk fallback |
| Video | Jitsi as a Service (8x8.vc) with RS256-signed JWTs |
| Mail | SMTP (Mailtrap dev) or custom `sendgrid` HTTPS transport (Railway blocks SMTP) |
| Tests | **Pest 3** (`tests/Feature` uses `RefreshDatabase`) |
| Lint | Laravel Pint |

### Deployments

- **Local**: XAMPP (Apache + PHP + MySQL). `composer run dev` runs server + queue listener + log tail + vite.
- **Hostinger**: operated by CLSU MISO for their QA (`docs/HOSTINGER_QA.md`). Conventional docroot `public/`.
- **Railway**: dev team's client/TAM env (`docs/RAILWAY_DEPLOYMENT.md`, `Dockerfile`, `railway.json`, health check `/up`). Three services from one image: **web** (Apache+mod_php), **scheduler** (`php artisan schedule:work`), **worker** (`php artisan queue:work` — required, CHIS sync is queued) + MySQL.
- The two deployments are independent; never sync their databases.

---

## 2. Commands you will need

```bash
composer install && npm install
composer run dev                 # serve + queue:listen + pail + vite
npm run build                    # production assets
php artisan migrate
composer test                    # SAFE way to run tests (clears config cache first)
vendor/bin/pest tests/Feature/FollowUpRequestTest.php
vendor/bin/pest --filter="name of test"
vendor/bin/pint --dirty          # format changed PHP files
php artisan tinker
```

Artisan commands defined by this project:

| Command | Schedule | Purpose |
|---|---|---|
| `consultations:mark-missed-slots` | every minute | booked slot whose end passed without start → `missed`, notifies |
| `consultations:expire-intake-sessions` | every minute | stale/past-planned-end physician intake sessions → closed/expired |
| `consultations:send-reminders` | every 15 min | 24-hour reminder email; `schedule_slots.reminder_sent_at` de-dupes |
| `chis:sync-pending` | every 15 min | re-queue CHIS syncs `pending` 10+ min |
| `auth:clear-resets staff_invitations` | daily | flush expired staff invitation tokens (broker name is REQUIRED) |
| `chis:health [--patient=<clsu_id>]` | manual | check CHIS reachability/token |
| `chis:send-skipped [--confirm]` | manual | one-off: send sessions skipped under old consent rule (dry run without `--confirm`) |
| `telemed:api-token {email} --ability=... [--days=30]` / `--list` / `--revoke=<id>` | manual | Sanctum tokens for active admins |
| `attachments:move-to-private` | manual | migrate legacy public-disk attachments to the private disk |

Scheduled commands only run if something calls `schedule:run` (cron) or `schedule:work`.

Seeders: `AdminUserSeeder` (uses `ADMIN_EMAIL`/`ADMIN_PASSWORD` env, one-time), `ChisDemoSeeder`, `QaTestUsersSeeder`, `DatabaseSeeder`.

---

## 3. DANGER — gotchas that have caused real damage

1. **Stale config cache wipes the dev DB.** If `bootstrap/cache/config.php` exists (from `config:cache`/`optimize`), tests ignore `phpunit.xml`'s SQLite settings and `RefreshDatabase` runs `migrate:fresh` against **real MySQL `telemed`**. This already happened once. Always use `composer test`, or `php artisan config:clear` first.
2. **Stale route cache hides new routes** (`bootstrap/cache/routes-*.php`). Run `php artisan route:clear` after adding routes.
3. **SQLite ≠ MySQL.** The enum-altering migrations (`alter_consultations_status_enum`, `alter_status_enum_on_schedule_slots_table`) `return` early on SQLite. A status value tests accept may be **rejected by MySQL** in dev/prod. Any new enum value needs a MySQL `ALTER ... ENUM` migration.
4. `.env.example` says `DB_CONNECTION=sqlite`, but real dev uses MySQL; `database/database.sqlite` is a leftover.

---

## 4. Data model (most important section)

### Two tables for one consultation — keep both in sync

| Model | Table | PK | Role |
|---|---|---|---|
| `Consultation` | `consultation_requests` | `request_id` | the patient's **request** — `request_status`, nurse/physician assignment, symptoms, priority, `type` |
| `ConsultationSession` | `consultations` | `id` | the clinical **session**, 1:1 with a request via `request_id` (unique) — `consultation_status`, clinical notes, prescription, `slot_id`, CHIS sync columns, takeover columns |

The table names are misleading (`consultations` holds *sessions*). Always check which model you are in.

### Status machines

`consultation_requests.request_status` (enum):
`pending → reviewed → (assigned) → scheduled → active → completed`, plus `rejected`, `cancelled`.

- `Consultation::IN_FLIGHT_STATUSES = ['pending','reviewed','scheduled','active']`
- `Consultation::CONCLUDED_STATUSES = ['completed','rejected','cancelled']`
- `Consultation::PRIORITY_LEVELS = ['High','Normal']` (nullable until nurse sets it)
- `type`: `initial` | `follow_up` (CHIS payload / exports call `initial` "general"); `parent_consultation_id` → originating `ConsultationSession` for follow-ups.

`consultations.consultation_status`: `scheduled → active → completed` (`scheduled` added by MySQL-only migration).

`schedule_slots.status`: `available → booked → completed`, or `booked → missed` (`missed`/`completed` MySQL-only enum values).

`follow_up_requests.status`: `pending → forwarded → approved | rejected`, or `cancelled` (patient, while pending/forwarded). `FollowUpRequest::IN_FLIGHT_STATUSES = ['pending','forwarded']`.

`physician_availability_sessions.status`: `open | closed | expired`; `mode`: `scheduled | overtime`; `end_reason`: `manual_close | logout | stale_expiry | continued_as_overtime | schedule_ended | overtime_limit_reached`.

### All tables (key columns)

- **users** (PK `user_id`): `first_name, last_name, email (unique), email_verified_at, password, role, account_status (inactive|active|suspended), online_status (offline|online), clsu_id (unique, nullable), user_type (student|staff|faculty, nullable), department, contact_num, staff_position, specialization, last_seen_at, chis_consent_at (HISTORIC — never read)`.
- **consultation_requests** (PK `request_id`, `CREATED_AT='submitted_at'`): `patient_id, assigned_nurse_id, assigned_physician_id, concern_category, symptoms_desc (JSON-ish text of symptoms w/ severity 1–4 & onset), file_attachments, request_status, rejection_reason, preffered_consultation_type (sic — video|chat|audio), online_reason, additional_information, priority_level, type, parent_consultation_id`.
- **consultations** (sessions): `request_id (unique), physician_id, slot_id, follow_up_request_id (unique), consultation_status, assessment, plan, recommendations, diagnosis, cancellation_reason, follow_up_required, follow_up_date, prescription_file_name/_path/_mime_type/_size, assigned_at, started_at, completed_at, original_physician_id, taken_over_by_physician_id, taken_over_at, chis_sync_status, chis_reference, chis_synced_at, chis_attempts, chis_last_error`.
- **consultation_messages** (model `Message`, PK `message_id`): `consultation_id (→ consultations.id), sender_id, message, read_at`.
- **message_attachments** (PK `attachment_id`): `message_id, file_name, file_path (a storage *reference*), mime_type, file_size`.
- **schedule_slots** (PK `slot_id`): `physician_id, slot_date, start_time, end_time, status, reminder_sent_at`; unique on physician+date+time.
- **follow_up_requests**: `consultation_id (→ consultations.id), patient_id, reviewed_by_nurse_id, decided_by_physician_id, reason, status, decision_notes, reviewed_at, decided_at`.
- **notifications** (PK `notification_id`): `user_id, type, title, message, data (JSON), read_at`.
- **consultation_video_sessions**: `consultation_id, room_name (unique), ended_at` (null = running).
- **physician_schedules**: recurring weekly intake hours — `physician_id, day_of_week, start_time, end_time, is_active`.
- **physician_availability_sessions**: live intake — `physician_id, started_at, last_seen_at, ended_at, planned_end_at, end_reason, end_warning_sent_at, status, mode`.
- **staff_invitation_tokens**: exactly `email (PK), token, created_at` (Laravel broker shape — do NOT add columns).
- **password_reset_tokens**, **personal_access_tokens** (Sanctum), **sessions**, **cache**, **jobs**, **failed_jobs**, **job_batches**.
- **chis_audit_logs**: append-only — `action, outcome, source, patient_user_id, actor_user_id, consultation_session_id, clsu_id, http_status, reason, chis_reference, duration_ms, created_at`. No FKs, no `updated_at`.

### Non-standard keys (check before assuming `id`)

`User.user_id`, `Consultation.request_id`, `ScheduleSlot.slot_id`, `Notification.notification_id`, `Message.message_id`, `MessageAttachment.attachment_id`. `ConsultationSession` uses standard `id`.

### Useful model helpers

- `Consultation` scopes: `completed, concluded, inFlight, initial, followUp, forNurse($id), forPhysician($id), submittedBetween($a,$b), pending, unclaimed, active, highPriority`. Relations: `patient, nurse, physician, messages, consultationSession, parentConsultation`.
- `ConsultationSession`: `request, physician, originalPhysician, takenOverByPhysician, slot, messages, followUpRequests, followUpRequest, videoSessions, activeVideoSession`; `wasTakenOver()`, `hasDiagnosis()`, `hasMeaningfulAssessment/Plan/Recommendations()` (reject placeholder boilerplate), `hasPrescription()`, `hasClinicalDocumentation()`.
- `User::awaitsStaffActivation()` (inactive + nurse/physician), `User::activeConsultationSession()`, `User::INVITED_ROLES`.
- `FollowUpRequest::hasInFlightForPatient($patientId)`.

---

## 5. Architecture rules (non-negotiable)

### State transitions → `App\Services\ConsultationOwnershipService`

Never write `request_status` / `consultation_status` directly from a controller.
Every method runs in `DB::transaction` with `lockForUpdate()` and **re-checks the
status under the lock** (this fixed a real double-claim race —
`tests/Feature/ConsultationConcurrencyTest.php`). Errors are thrown as
`RuntimeException` with a user-facing message; controllers catch and display them.

| Method | Transition |
|---|---|
| `claimByNurse($requestId, $nurseId, $priority)` | `pending → reviewed`, sets nurse + priority |
| `rejectByNurse($requestId, $nurseId, $reason)` | `pending → rejected` |
| `cancelByPatient($requestId, $patientId)` | `pending|reviewed → cancelled` |
| `scheduleByPhysician($requestId, $physicianId, $slotId)` | `reviewed|assigned|scheduled → scheduled`; slot must be `available` and future; books it; releases previous slot on reschedule; creates/updates session `scheduled` |
| `startByPhysician($requestId, $physicianId)` | → `active` (request + session); slot must be booked/missed, owned (or taken over), within its window |
| `takeOverByPhysician($requestId, $claimingPhysicianId)` | another physician claims a `scheduled`, not-started consultation after `TAKEOVER_GRACE_MINUTES = 10` past slot start; records original/taken-over-by/at |
| `takeoverEligibleAt(ScheduleSlot)` (static) | when takeover becomes allowed |
| `rejectReviewedByPhysician($requestId, $physicianId, $reason)` | `reviewed → rejected` |
| `forwardFollowUpByNurse($id, $nurseId, $notes)` | follow-up `pending → forwarded` |
| `rejectFollowUpByNurse($id, $nurseId, $notes)` | follow-up `pending → rejected` |
| `cancelFollowUpByPatient($id, $patientId)` | follow-up `pending|forwarded → cancelled` |
| `decideFollowUpByPhysician(...)` | `forwarded → approved|rejected`; approve modes `immediate` (session `active`) or `scheduled` (needs available future slot); creates new `Consultation`+`ConsultationSession` with `type='follow_up'`; blocks if a follow-up is already in flight |

Completion is the exception: `ConsultationMessageController::complete()` does
its own locked transaction (session → completed, request → completed, slot →
completed, ends video), requires Diagnosis/Assessment/Plan/Recommendations to be
meaningfully filled, then **after commit** calls `ChisEncounterSync::queue()` and
notifies the patient (in-app + email, email failure caught).

**Known duplication / tech debt:** `PhysicianController::createPhysicianFollowUp`
→ private `createFollowUpConsultationFromSource()` duplicates the locking and
slot-booking logic of `decideFollowUpByPhysician`. Any change to follow-up
creation must be made in **both** places (or refactor them into the service).

### Authorization

- Nurse/physician routes are `nurses/{nurse}/...` and `physicians/{physician}/...`. Each controller action calls `authorizeNurse(User $nurse)` / `authorizePhysician(User $physician)`, checking `Auth::user()->role` **and** `Auth::id() === $nurse->user_id`. Never trust the route param alone; copy this pattern.
- Admin controllers use a private `authorizeAdmin()`.
- Policies (only two): `ConsultationPolicy::view` (patient owns request); `ConsultationSessionPolicy`: `viewMessaging` (owning patient or assigned physician, session+request `active|completed`), `sendMessage` (+ must be `active`), `joinVideo` (= sendMessage), `startVideo` (+ assigned physician).
- `AttachmentController` lets nurses/physicians view intake attachments for pool statuses `reviewed|assigned|scheduled`.
- Middleware on `web`: `EnsureAccountIsActive` (logs out suspended/inactive users immediately) **then** `TrackUserPresence` (updates `online_status`/`last_seen_at`). Order matters.
- Main routes are in an `auth`+`verified` group. Some patient routes (`/consultations/create|history|{id}`, `POST /consultations`, `/profile`) are `auth`-only.

### Notifications → `App\Services\NotificationService`

Never `Notification::create()` from a controller.
- `send($user, $type, $title, $message, $data)`
- `sendToRole($role, ...)` — all active users of a role
- `sendUnique(...)` — de-dupes on same type + matching entity id in `data` (`consultation_id`, `follow_up_request_id`, `schedule_slot_id`, `session_id`, `request_id`, `message_id`)

Types live in `App\Enums\NotificationType`: `consultation_submitted, _reviewed, _assigned, _scheduled, _rescheduled, _starting_soon, _started, _completed, _missed, _cancelled, new_message, new_attachment, follow_up_submitted, _approved, _rejected, _scheduled, _starting_soon, high_priority_consultation, physician_request, system_alert, intake_end_warning, intake_auto_closed`. Add new ones there. Notification `data` must not hold clinical details (`NotificationDataPrivacyTest`).

Emails (`app/Notifications`): `ConsultationScheduled`, `ConsultationReminder`, `ConsultationCompleted`, `FollowUpScheduled`, `StaffAccountInvitation`. Sends are wrapped in try/catch so mail failure never breaks the workflow.

### Medical files → `App\Services\MedicalFileStorage`

> Note: `CLAUDE.md`'s "Messaging & attachments" section is **outdated** on this point — trust this.

The single place that stores and serves intake attachments, message attachments and prescriptions.
- `store(UploadedFile, $cloudinaryFolder, $localDirectory)`: uploads to Cloudinary with delivery type **`authenticated`** (plain URL returns 401); on failure falls back to private disk `message_attachments` (outside `storage/app/public`, `serve => false`). Never throws.
- DB stores a **reference**, not a URL: `cloudinary:{resource_type}:{format}:{public_id}` or a disk-relative path. Legacy rows may hold an `http(s)` URL — redirected as-is (unprotected).
- `response(...)` serves only after the controller authorized: mints a short-lived signed Cloudinary URL (`CLOUDINARY_SIGNED_URL_TTL`, default 300 s) or streams from disk.
- Limits (`ConsultationMessageController`): max 3 attachments/message, 10 MB each, 1 video ≤ 50 MB; extensions `jpg jpeg png pdf doc docx mp4`. Intake form: images ≤ 10 MB.

### Messaging (polled, no websockets)

`ConsultationMessageController`: `show` (page), `index` (poll messages), `store` (send + attachments), `markRead`, `unreadCounts`, `typing` (cache, TTL 8 s), `presence`, `markOffline`, `updateClinicalDetails` (diagnosis/assessment/plan/recommendations + prescription upload, physician only), `complete`, `downloadPrescription`, `downloadAttachment`. `/presence/heartbeat` (CSRF-exempt) keeps idle pages alive.

### Video (Jitsi as a Service)

- `JitsiService`: random room names, RS256 JWT (`issueToken($room, $displayName, $isModerator)`), config `JITSI_DOMAIN`, `JITSI_APP_ID`, `JITSI_API_KEY_ID`, `JITSI_PRIVATE_KEY` (one line, `\n` escaped).
- `ConsultationVideoService`: `startForPhysician` (idempotent; locks parent `consultations` row), `activeFor`, `end`.
- `ConsultationVideoController`: `start` (physician only), `join` (patient may join only a running room), `end`. Room is ended inside the completion transaction. Requires HTTPS (getUserMedia) — hence `trustProxies('*')` in `bootstrap/app.php`.

### Physician consultation intake (service availability)

`App\Services\PhysicianAvailabilityService` is the **only writer** of `physician_availability_sessions`. "Intake open" = the service accepts NEW requests; it never touches existing consultations.
- Patients can submit only when `isServiceAvailable()` = `hasOpenIntake()` (an open, non-stale session of a recently-present physician) **and** `pendingQueueHasCapacity()` (global pending count < `CONSULTATION_QUEUE_LIMIT`, default 20). Otherwise `store` returns 503 / the UI shows when intake next opens (`nextScheduledWindow()`, `weeklyScheduleOverview()`).
- Physician page `/physicians/{id}/consultation-intake`: CRUD recurring `physician_schedules`, and live `open` / `close` / `continue` (as overtime) / `heartbeat` (browser every 60 s).
- Each session records `planned_end_at` (end of its weekly window, or start + `CONSULTATION_INTAKE_MAX_OVERTIME`=120 min). At planned end → warning notification; no answer within `CONSULTATION_INTAKE_SCHEDULE_END_GRACE`=15 min → auto-close. Stale heartbeat > `CONSULTATION_INTAKE_STALE_AFTER`=120 s → treated closed. Logout closes it. Config in `config/consultations.php`.
- Locks the physician's `users` row to serialise opens.
- Do not confuse with **schedule slots** (`scheduled_consultation` pages), which are concrete bookable appointment times.

### Schedule slots

`PhysicianController::generateScheduleSlots` (preview from a date/time range) → `saveScheduleSlots`; rejects past and overlapping slots. `syncMissedSlotsForPhysician` also flips missed slots on page load. `resolveCanStart` decides if the Start button is enabled.

### Staff invitations (nurses/physicians never self-register)

Admin `UserManagementController::store` creates user `inactive`, unverified, random discarded password → token from the **`staff_invitations` password broker** (`config/auth.php`, table `staff_invitation_tokens`, expires 7 days) → `StaffAccountInvitation` mail → `Auth\StaffInvitationController` (`GET staff/activate/{token}`, `POST staff/activate`) sets password, activates, verifies.
Invariants: plaintext token only in memory (never stored/logged/flashed/queued); `StaffAccountInvitation` must **not** implement `ShouldQueue`; mail sent after commit, `Throwable` caught, only exception class logged; eligibility = `User::awaitsStaffActivation()`; changing a pending invitee's email revokes the old invitation first; resend locks the user row and re-checks; password reset is a separate 60-min broker and tokens don't cross over.

### CHIS integration (external REST API)

- Local simulator repo `clsu-chis-simulation` at `http://localhost:8001/api/v1`; env `CHIS_BASE_URL`, `CHIS_TOKEN`, `CHIS_TIMEOUT`. Tests use fake host `chis.test`.
- `ChisClient` (only thing that talks to CHIS): `health()`, `findPatient($clsuId)`, `pushEncounter($payload)`. Timeouts/connection/429/5xx/bad body → `ChisUnavailableException` (transient, `reason`); other 4xx → `ChisRequestException` (permanent, `status`, `chisCode`). Never retries itself.
- Identity = `users.clsu_id` (format `App\Rules\ClsuId`: `^(\d{2}|\d{4})-\d{4,6}$`, e.g. `23-0001`, unique, write-once for patients). `ChisIdentity::matches()` compares normalised first+last name.
- **Lawful basis = medical treatment (RA 10173 Sec. 13), NOT consent.** No consent gate anywhere; patients see a privacy notice. Payload carries `"lawful_basis": "medical_treatment"`, `"consent": null`. MISO's requirement is verbal — written confirmation pending; never describe it as approved in writing.
- Flow: `complete()` → `ChisEncounterSync::queue()` → status `skipped` (`chis_last_error = no_clsu_id`) or `pending` + dispatch `SyncEncounterToChis` (5 tries, backoff 60/300/900/3600 s, `ShouldBeUnique` per session, carries only session id, no row lock during HTTP). Statuses: `pending | synced | failed | skipped | NULL`. Out of tries → stays `pending`; `chis:sync-pending` re-queues.
- `ChisEncounterPayload::build()` — only CLSU ID/name, physician, symptoms, clinical fields, follow-up, prescription file name+type. **Never** chat, attachments, file path/URL, priority, nurse, email, phone, Telemed user id. Invalid → `failed` + `invalid_payload:<reason>`, never truncated.
- Audit: `ChisAuditLog::record($action, $outcome, $source, $context)` returns bool, never throws; one row per real CHIS call, written by the caller (never inside `ChisClient`); append-only (update/delete throw). Admin page `/admin/chis-audit`.
- Physician CHIS profile: `GET /consultation-sessions/{session}/chis-profile` (`ChisProfileController`), lazy-loaded by Alpine on the Patient tab; assigned physician only; fails closed if the audit write fails; minimised fields, `no-store`, never cached; rendered with `x-text` only.
- Admin `/admin/chis-sync` (`ChisSyncController`): list + Retry.
- **API** (`routes/api.php`, `/api/v1`, Sanctum bearer only, `throttle:60,1`): `GET encounters`, `GET encounters/{session}`, `POST encounters/{session}/sync` (runs job synchronously; `skipped` → 409 `NOT_ELIGIBLE`, held lock → 409 `SYNC_IN_PROGRESS`, transient failure → 200 `pending`). Owner must be an active admin; abilities `encounters:read`, `encounters:sync`. Sync status only — no clinical data. API errors are always JSON `{message, code}`. Postman files in `docs/postman/`.
- Error strings/logs hold short codes only — never bodies, token or patient data.

### Dashboards, analytics, exports

- `DashboardController::index` branches on role (nurse → `nurse.dashboard`). Patient dashboard shows active request, follow-up status, intake availability.
- `DashboardAnalyticsService::forNurse/forPhysician/forAdmin(DateRange)` — volume over time, status/priority distribution, initial vs follow-up, completion rate; `SymptomAnalytics::summarize()` (standardised symptom vocabulary + custom terms reported ≥ 3 times).
- `App\Support\DateRange` presets `today, this_week, this_month, last_30_days, this_year, custom` (custom ≤ 730 days).
- Exports (CSV + PDF via dompdf): dashboards (`*.dashboard.export`) and consultation history (`*.consultation_history.export`, `consultations.history.export`). Built by `Services/Export/ConsultationHistoryQuery`, `ConsultationHistoryRows` (PDF cap 500 rows), `DashboardExportRows`. CSV via `Support\CsvDownload::stream()` which guards against formula injection (`= + - @ \t \r`).
- `Support\StatusBadge` centralises status/priority/severity badge labels, Tailwind classes and patient-facing meanings.

### Patient follow-up rules (`FollowUpRequestController::store`)

Session must be `completed`; request within **7 days** of completion; patient must have no other in-flight follow-up request; no existing pending/forwarded/approved request for the session; no in-flight follow-up consultation already.

---

## 6. Controllers → what they own

| Controller | Responsibilities |
|---|---|
| `DashboardController` | role dashboards, patient active consultation, `newconsultation` page, admin dashboard export |
| `ConsultationController` | patient create/store/show/history(+export), nurse approve/reject, patient cancel |
| `NurseController` | dashboard(+export), consultation inbox (+ JSON refresh poll), follow-up forward/reject, history(+export) |
| `PhysicianController` (~2100 lines, biggest file) | dashboard, inbox(+refresh), approve/reject reviewed, available slots, schedule, start, take over, follow-ups (decide + physician-initiated), history, active/scheduled pages, slot generate/save, intake page + schedules CRUD + open/close/continue/heartbeat |
| `ConsultationMessageController` | chat, clinical details, prescription, complete, attachments, typing/presence |
| `ConsultationVideoController` | Jitsi start/join/end |
| `FollowUpRequestController` | patient follow-up list/store/cancel |
| `AttachmentController` | intake attachment viewing for staff/patient |
| `NotificationController` | panel JSON, all page, unread count, mark read / all read |
| `PresenceController` | heartbeat |
| `ProfileController` | profile edit/update (CHIS privacy notice for patients) |
| `ChisProfileController` | physician CHIS profile lookup |
| `Admin\UserManagementController` | users list/search(JSON)/create/store/edit/update/resend invitation |
| `Admin\ChisSyncController`, `Admin\ChisAuditController` | CHIS sync status/retry, audit log |
| `Api\EncounterController` | Sanctum API |
| `Auth\*` | Breeze auth + `StaffInvitationController` |

Views live in `resources/views/{patient,nurse,physician,admin,consultations,profile,auth,components,exports,notifications,layouts}`. Shared Blade components: `x-button-primary/secondary/danger/ghost`, `x-dash.*` (stat, chart, table, filter-bar, export-menu, badge, empty, state, section), `x-modal`, `x-physician.intake-status-card`, etc. Use the existing button components rather than raw buttons (tests audit this). Mobile has a bottom nav and table→card layouts (tested).

---

## 7. Environment variables (see `.env.example`)

`APP_*`, `DB_*` (mysql in practice), `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE` (true in prod over HTTPS, false on http://localhost), `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `MAIL_*` (`MAIL_MAILER=sendgrid` + `SENDGRID_API_KEY` on Railway), `CLOUDINARY_URL`, `CLOUDINARY_UPLOAD_TIMEOUT`, `CLOUDINARY_SIGNED_URL_TTL`, `JITSI_DOMAIN/APP_ID/API_KEY_ID/PRIVATE_KEY`, `CONSULTATION_QUEUE_LIMIT`, `CONSULTATION_INTAKE_STALE_AFTER`, `CONSULTATION_INTAKE_SCHEDULE_END_GRACE`, `CONSULTATION_INTAKE_MAX_OVERTIME`, `ADMIN_EMAIL/ADMIN_PASSWORD/ADMIN_FIRST_NAME/ADMIN_LAST_NAME` (one-time seeding), `CHIS_BASE_URL/CHIS_TOKEN/CHIS_TIMEOUT`. Production: `APP_ENV=production`, `APP_DEBUG=false`, `LOG_LEVEL=error`, `LOG_STACK=daily`, fresh `APP_KEY`.

---

## 8. Tests

~116 Pest feature test files in `tests/Feature` (grouped: `Admin/`, `Analytics/`, `Api/`, `Auth/`, `Export/`, `Mail/`, plus top-level files named after the feature, e.g. `ConsultationConcurrencyTest`, `FollowUpRequestTest`, `PhysicianTakeoverTest`, `ChisEncounterSyncTest`, `ConsultationVideoSessionTest`, `PhysicianIntakeControlsTest`). Unit tests for `StatusBadge`. Many UI tests assert on rendered Blade HTML strings, so renaming a label/class can break a test — search tests for the string before changing view text.

---

## 9. Development rules for any change

1. Before touching consultation/follow-up workflow: read `ConsultationOwnershipService`, the relevant controller, DB constraints, and the Feature tests.
2. Preserve transactions + `lockForUpdate()` + "re-check status under the lock".
3. Check whether an existing path already implements the rule before writing new logic (especially follow-up creation — two copies exist).
4. New status values need a MySQL enum migration; tests on SQLite will not catch this.
5. Notifications via `NotificationService` + `NotificationType`; files via `MedicalFileStorage`; CHIS via `ChisClient` only.
6. Blade + Tailwind + Alpine only. No Bootstrap, no new frameworks/dependencies without a reason.
7. Nurse/physician actions: `authorizeNurse`/`authorizePhysician` first line.
8. Never put clinical data in logs, notifications `data`, queue payloads, exception messages, or the API.
9. Run `vendor/bin/pint --dirty` and `composer test` before committing.

---

## 10. Further reading inside the repo

- `CLAUDE.md` — the canonical rules file (paste it too).
- `docs/paper/00-feature-inventory.md` and `docs/paper/features/*.md` — per-feature write-ups (with evidence paths) for the capstone manuscript; `docs/paper/glossary.md`.
- `docs/CHIS_DEMO.md`, `docs/STAFF_INVITATION_SYSTEM.md`, `docs/JITSI_API_IMPLEMENTATION.md`, `docs/CONSULTATION_INBOX.md`, `docs/DASHBOARD_ANALYTICS.md`, `docs/EXPORTS.md`, `docs/HOSTINGER_QA.md`, `docs/RAILWAY_DEPLOYMENT.md`, `docs/postman/README.md`.

---

## 11. Prompt template for a new chat

```
You are helping me maintain a Laravel 12 telemedicine app. Attached: PROJECT_CONTEXT.md
and CLAUDE.md (rules — follow them strictly). Below are the current contents of the
files involved. Only modify these files; if you need another file, ask me to paste it.
Return full updated methods (not fragments) and list any test I should add or run.
Point out if my request breaks a rule in CLAUDE.md.

Task: <what you want>

--- app/Path/To/File.php ---
<paste>
```
