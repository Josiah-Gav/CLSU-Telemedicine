# Glossary and Terminology Ledger

**Purpose.** This file fixes the vocabulary for the entire capstone manuscript.
Feature files, chapters, and diagrams are written in separate sessions; without a
fixed vocabulary, the same thing acquires three names and a panel member will find
the inconsistency.

**Rule.** Every term below was taken from the current source code — a table name, a
column, a model, a class constant, a route name, or an enum value. Nothing here is
invented, and nothing is carried over from `CLAUDE.md`. When a new term is
introduced during feature documentation, append it here with its table or class and
a one-line definition.

**Standing prohibition.** Do not use the bare word **"consultation"** in any
sentence where a *request* and a *clinical session* could both be meant. This is
the single largest source of ambiguity in this system, and section 1 exists to
prevent it.

---

## 1. The request / session distinction — read this first

A single patient encounter is split across **two tables with two models**, and the
names are effectively inverted relative to what a reader expects.

| Model class | Table | Primary key | What it represents |
|---|---|---|---|
| `App\Models\Consultation` | `consultation_requests` | `request_id` | The patient-facing **request** |
| `App\Models\ConsultationSession` | `consultations` | `id` | The clinical **session** |

**Manuscript usage:**

| Write this | Never write this | Why |
|---|---|---|
| **consultation request** | "consultation" when the request is meant | The model is named `Consultation` but the table is `consultation_requests`; the bare word makes the sentence unfalsifiable |
| **consultation session** | "consultation" when the session is meant | The table is `consultations` but the model is `ConsultationSession` |
| **`consultation_requests` table** | "the Consultation table" | The model name and table name disagree; always name the table |
| **`consultations` table** | "the session table" alone | Same reason, in the other direction |

**In diagrams:** label ERD entities with the **table** name in uppercase
(`CONSULTATION_REQUESTS`, `CONSULTATIONS`) and give the model class in a footnote
or relationship label. Never let the two swap.

**The relationship:** one consultation request has at most one consultation
session, joined by `consultations.request_id` → `consultation_requests.request_id`,
enforced by the unique index `consultations_request_id_unique_ownership`
(`2026_08_20_120000_add_consultation_session_uniques.php`). A request that is
rejected or cancelled before scheduling never acquires a session row.

---

## 2. Primary keys — none of the obvious assumptions hold

| Table | Primary key | Source |
|---|---|---|
| `users` | `user_id` | `create_users_table`; `User::$primaryKey` |
| `consultation_requests` | `request_id` | `create_consultation_requests_table`; `Consultation::$primaryKey` |
| `consultations` | `id` | `create_consultations_table` (the only standard one) |
| `schedule_slots` | `slot_id` | `create_schedule_slots_table`; `ScheduleSlot::$primaryKey` |
| `consultation_messages` | `message_id` | `create_consultation_messages_table`; `Message::$primaryKey` |
| `message_attachments` | `attachment_id` | `create_message_attachments_table`; `MessageAttachment::$primaryKey` |
| `notifications` | `notification_id` | `create_notifications_table`; `Notification::$primaryKey` |
| `follow_up_requests` | `id` | `create_follow_up_requests_table` |
| `consultation_video_sessions` | `id` | `create_consultation_video_sessions_table` |
| `physician_schedules` | `id` | `create_physician_schedules_table` |
| `physician_availability_sessions` | `id` | `create_physician_availability_sessions_table` |
| `staff_invitation_tokens` | `email` | `create_staff_invitation_tokens_table` — email-keyed, no `user_id` column |

Foreign keys referencing users point at **`users.user_id`**, never `users.id`. Write
them that way in the data dictionary.

**Custom timestamps:** `Consultation` overrides `CREATED_AT = 'submitted_at'` and
`UPDATED_AT = 'updated_at'`. The `consultation_requests` table therefore has no
`created_at` column — it has `submitted_at`.

---

## 3. Status vocabularies

### `consultation_requests.request_status`

| Value | Meaning | Note |
|---|---|---|
| `pending` | Submitted, awaiting nurse review | Default |
| `reviewed` | Claimed and triaged by a nurse | Set by `ConsultationOwnershipService::claimByNurse` |
| `assigned` | — | **Dead value.** Present in the enum, never written by any code path. Excluded from `Consultation::MEANINGFUL_STATUSES`. Must not appear in any state diagram. |
| `scheduled` | Booked onto a schedule slot | |
| `active` | Consultation under way | |
| `completed` | Concluded normally | |
| `rejected` | Refused by nurse or physician, with a reason | |
| `cancelled` | Withdrawn by the patient | |

Model constants: `Consultation::MEANINGFUL_STATUSES` (the seven live values),
`Consultation::CONCLUDED_STATUSES` (`completed`, `rejected`, `cancelled`),
`Consultation::IN_FLIGHT_STATUSES` (`pending`, `reviewed`, `scheduled`, `active`).

### `consultations.consultation_status`

Live MySQL enum: `scheduled`, `active`, `completed`, `cancelled`, default
`scheduled` (`2026_08_05_123545_alter_consultations_status_enum.php`). The
`create_consultations_table` migration originally declared only
`active`/`completed`/`cancelled` with default `active`; the ALTER is the current
truth. That ALTER returns early on SQLite, so the test database never enforces it.

### `schedule_slots.status`

Live MySQL enum: `available`, `booked`, `missed`, `completed`, default `available`
(`2026_08_05_125250_alter_status_enum_on_schedule_slots_table.php`). The create
migration declared only `available`/`booked`. Same SQLite caveat.

### `follow_up_requests.status`

| Value | Meaning |
|---|---|
| `pending` | Submitted by the patient, awaiting nurse review |
| `forwarded` | Nurse forwarded it to a physician |
| `approved` | Physician approved; a follow-up consultation is created |
| `rejected` | Refused by nurse or physician |
| `cancelled` | Withdrawn by the patient |
| `expired` | **Dead value.** In the enum, never written by any code path. Must not appear in a state diagram. |

### `users.account_status` and `users.online_status`

`account_status`: `inactive`, `active`, `suspended` (default `active`). Staff
accounts are created `inactive` and become `active` only on invitation activation.
`online_status`: `offline`, `online` (default `offline`), maintained by
`TrackUserPresence`.

### `physician_availability_sessions.status` and `.mode`

`status`: `open`, `closed`, `expired` (default `open`). Here `expired` **is**
written — by `PhysicianAvailabilityService::expireStaleSessions`. Do not confuse it
with the dead `expired` on `follow_up_requests`.
`mode`: `scheduled`, `overtime`.

---

## 4. Consultation type — `initial` is the stored value, `general` is not

| Term | Where it is real | Where it is not |
|---|---|---|
| **`initial`** | The stored database value. `consultation_requests.type` is `enum('type', ['initial','follow_up'])` with default `initial` (`2026_08_06_180500_add_follow_up_fields_to_consultation_requests_table.php:12`). | — |
| **`follow_up`** | Stored value, both in the database and in filters. | — |
| **`general`** | A **filter-layer label only**. `Export\ConsultationHistoryQuery::ALLOWED_TYPE_FILTERS = ['follow_up','general','all']`, and the `general` branch resolves to `whereNull('type')->orWhere('type','!=','follow_up')`. `DashboardController` maps a row to `'general'` for display in the same way. | **Never** describe `general` as a database value. |

**Manuscript usage:** write **`initial`** whenever describing the schema or a state
diagram. Mention `general` only when documenting the history filter or dashboard
display, and say explicitly that it is a presentation label meaning "not a
follow-up".

---

## 5. "Intake" means two different things — keep them apart

`routes/web.php` carries an explicit comment warning that these are different
concepts. The manuscript must preserve that separation.

| Term | Backed by | Means |
|---|---|---|
| **Intake availability** (also "consultation intake") | `physician_schedules`, `physician_availability_sessions`; `PhysicianAvailabilityService` | Whether the telemedicine service will accept a **new consultation request** right now |
| **Intake hours** | `physician_schedules` (`day_of_week`, `start_time`, `end_time`, `is_active`) | A physician's recurring weekly availability windows |
| **Intake session** | `physician_availability_sessions` | One live open/close episode with a heartbeat, which expires if the heartbeat goes stale |
| **Schedule slot** | `schedule_slots` | A concrete, bookable appointment on a specific date and time |

Never call schedule-slot generation "intake", and never call intake hours "slots".

**Governing rule:** `PhysicianAvailabilityService::isServiceAvailable()` =
`hasOpenIntake() && pendingQueueHasCapacity()`. The two limits are
`config/consultations.php` → `intake.queue_limit` (default 20, counted globally
across all `pending` requests) and `intake.stale_after_seconds` (default 120).
Presence freshness is `PhysicianAvailabilityService::PRESENCE_FRESHNESS_MINUTES = 2`.

---

## 6. Follow-up vocabulary

| Term | Backed by | Means |
|---|---|---|
| **Follow-up request** | `follow_up_requests` row | The patient's *ask* for a follow-up, raised against a completed consultation session |
| **Follow-up consultation** | A new `consultation_requests` + `consultations` pair with `type = 'follow_up'` | The outcome once a follow-up is approved or physician-initiated |
| **Parent session** | `consultation_requests.parent_consultation_id` → `consultations.id` | The originating clinical session a follow-up came from. Note it references the **session**, not the request. |
| **Nurse-forwarded path** | `ConsultationOwnershipService::decideFollowUpByPhysician` | Patient asks → nurse forwards → physician decides |
| **Physician-initiated path** | `PhysicianController::createPhysicianFollowUp` → private `createFollowUpConsultationFromSource` | Physician creates a follow-up directly, with no patient request and **without** going through `ConsultationOwnershipService` |

The two paths duplicate locking and slot-booking logic. Describe them as two paths,
never as one.

---

## 7. Attachments — two different mechanisms

| Term | Backed by | Served by |
|---|---|---|
| **Request attachment** | `consultation_requests.file_attachments` (longText, added by `2026_06_29_054405_alter_consultation_requests_table.php`) | `AttachmentController::show`, route `consultation.attachment` |
| **Message attachment** | `message_attachments` rows (PK `attachment_id`), linked to `consultation_messages` | `ConsultationMessageController::downloadAttachment` |
| **Prescription** | Four columns on `consultations`: `prescription_file_name`, `prescription_file_path`, `prescription_mime_type`, `prescription_file_size` | `ConsultationMessageController::downloadPrescription` |

**Storage terms:**

| Term | Meaning |
|---|---|
| **Cloudinary-first with local fallback** | Upload is attempted against Cloudinary; if the call throws, the file is written to a local disk instead and the request still succeeds |
| **Private disk** | `ConsultationMessageController::PRIVATE_DISK = 'message_attachments'` — where locally-stored message attachments and prescriptions now live |
| **Legacy public disk** | `AttachmentController` still reads `Storage::disk('public')`; `MoveMessageAttachmentsToPrivateDisk` (`attachments:move-to-private`) migrates files from public to private |
| **URL-vs-path branch** | Download code tests whether the stored value begins with `http(s)://`: a URL is redirected to, anything else is treated as a relative path on a local disk |

Attachment limits (`ConsultationMessageController`):
`MAX_ATTACHMENTS_PER_MESSAGE = 3`, `MAX_FILE_SIZE_MB = 10`,
`MAX_VIDEO_SIZE_MB = 50`, `MAX_VIDEOS_PER_MESSAGE = 1`,
`ATTACHMENT_EXTENSIONS = ['jpg','jpeg','png','pdf','doc','docx','mp4']`.

---

## 8. Roles and authorization

**Roles** (`users.role` enum): `patient`, `nurse`, `physician`, `admin`.

| Term | Meaning |
|---|---|
| **Policy** | One of exactly two registered classes — `ConsultationPolicy` (patient viewing their own request) and `ConsultationSessionPolicy` (`viewMessaging`, `sendMessage`, `joinVideo`, `startVideo`). Registered in `AppServiceProvider::boot`. |
| **Controller-level authorization** | The private guards `NurseController::authorizeNurse`, `PhysicianController::authorizePhysician`, `Admin\UserManagementController::authorizeAdmin` and `DashboardController::authorizeAdmin`. These check both the role **and** that `Auth::id()` matches the route-bound user. |
| **Self-scoped route** | A route under `nurses/{nurse}` or `physicians/{physician}` where the parameter is the acting user's own ID. The parameter is never trusted on its own. |

Do **not** write that the system uses a uniform policy layer. Most authorization is
done in controllers; only the two policies above are registered.

---

## 9. Concurrency

| Term | Meaning |
|---|---|
| **Ownership transition** | Any workflow state change routed through `ConsultationOwnershipService` — eleven public methods covering claim, reject, cancel, start, take over, schedule, and the follow-up decisions |
| **Lock-then-check** | The pattern every transition uses: open `DB::transaction`, acquire `lockForUpdate()` on the row, **re-read and re-check the status under the lock**, then write |
| **Takeover grace period** | `ConsultationOwnershipService::TAKEOVER_GRACE_MINUTES = 10` — how long past a slot's start a scheduled consultation must sit unstarted before another physician may claim it |

---

## 10. Messaging, presence, and video

| Term | Meaning |
|---|---|
| **Polled messaging** | Messages, read receipts, typing, and presence are delivered by repeated HTTP requests to dedicated endpoints. There is no websocket layer anywhere in this system — never write "real-time websocket". |
| **Typing indicator** | Cache-backed flag with `ConsultationMessageController::TYPING_TTL_SECONDS = 8` |
| **Global presence** | `users.online_status` / `users.last_seen_at`, updated by `TrackUserPresence`, registered as global `web` middleware in `bootstrap/app.php` for **every** authenticated role |
| **Presence heartbeat** | `POST /presence/heartbeat` (`PresenceController`), CSRF-exempt, for pages that would otherwise sit idle |
| **In-session presence** | A separate mechanism — `ConsultationMessageController::presence` / `markOffline` — scoped to one consultation session. Distinct from global presence. |
| **Video session** | A `consultation_video_sessions` row with a unique `room_name`. Created only by the assigned physician (`ConsultationSessionPolicy::startVideo`); a patient may join a running room but never create one. |
| **Jitsi room name** | Random, `JitsiService::ROOM_NAME_BYTES = 16`; access is granted by a server-signed JWT (`JitsiService::issueToken`, `NBF_SKEW_SECONDS = 10`) |

---

## 11. Staff provisioning

| Term | Meaning |
|---|---|
| **Staff invitation** | The mechanism by which nurses and physicians get accounts. They never self-register, and an admin never sets their password. |
| **`staff_invitations` broker** | A second Laravel password broker declared in `config/auth.php`, backed by `staff_invitation_tokens`, expiring in 7 days (`expire => 10080`). Not a bespoke token system. |
| **Password reset broker** | The separate, default broker at 60 minutes, backed by `password_reset_tokens`. Neither token type works in the other flow. |
| **Activation** | The invitee setting their own password through the invitation link, which is what flips `account_status` to `active` and sets `email_verified_at`. |
| **Eligibility** | `User::awaitsStaffActivation()` — inactive **and** role in `User::INVITED_ROLES = ['nurse','physician']`. The single source of truth shared by activation and resend. |

---

## 12. Analytics and exports

| Term | Meaning |
|---|---|
| **Date range** | `Support\DateRange`, with `PRESETS = ['today','this_week','this_month','last_30_days','this_year','custom']` and `MAX_CUSTOM_RANGE_DAYS = 730` |
| **Standardized symptom** | A term in `SymptomAnalytics::STANDARDIZED_SYMPTOMS`; free-text terms are surfaced only after `CUSTOM_TERM_MIN_REPORTS = 3` reports |
| **Severity** | `SymptomAnalytics::VALID_SEVERITIES = [1,2,3,4]`, with `DEFAULT_SEVERITY_BUCKET = 3` |
| **PDF row cap** | `Export\ConsultationHistoryRows::PDF_ROW_CAP = 500` |
| **CSV injection hardening** | `Support\CsvDownload::DANGEROUS_PREFIXES = ['=','+','-','@',"\t","\r"]` — cells beginning with these are neutralised so a spreadsheet does not execute them as formulas |

---

## 13. Deployment and environment terms

| Term | Meaning |
|---|---|
| **Scheduler** | Three commands are registered in `routes/console.php`: `consultations:mark-missed-slots` (every minute), `consultations:expire-intake-sessions` (every minute), and `auth:clear-resets staff_invitations` (daily). **They only run if `schedule:run` or `schedule:work` is actually invoked.** Registration alone does not make them run — state this as a deployment requirement. |
| **Engine split** | Development and production run **MySQL/MariaDB**; feature tests run **in-memory SQLite** (`phpunit.xml`). Three enum-altering migrations return early on SQLite, so enum constraints enforced in production are never enforced in tests. |
| **Proxy trust** | `bootstrap/app.php` trusts loopback proxies for `X-Forwarded-*` headers so the application generates `https://` URLs when served through a TLS tunnel. |

---

## 14. Terms that must not be used

| Do not write | Because |
|---|---|
| "CHIS integration", "CHIS sync", "CHIS interoperability" | Not implemented. One static UI placeholder exists (`resources/views/consultations/messaging.blade.php:633`), with no backend of any kind. CHIS may be mentioned only under limitations / future enhancements. |
| "real-time websocket", "socket connection", "push" | All messaging and presence are HTTP-polled. |
| "`general` consultation type" (as a stored value) | The stored value is `initial`; `general` is a filter label only. |
| "status `assigned`" as a workflow state | Dead enum value, never written. |
| "status `expired`" for a follow-up request | Dead enum value on `follow_up_requests`; it is live only on `physician_availability_sessions`. |
| "`preffered_consultation_type`" | Dropped; verified absent from the live MySQL schema. |
| "policy layer" (as a description of the whole system) | Only two policies are registered; the rest is controller-level authorization. |
| bare "consultation" where request vs session matters | See section 1. |

---

## Maintenance

Append new terms as feature documentation is written, with the table or class that
backs them. If a term here is later contradicted by the source, correct this file
and report the contradiction rather than silently diverging — the source is the
sole authority.
