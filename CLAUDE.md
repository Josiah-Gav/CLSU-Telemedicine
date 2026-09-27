# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

This is a Laravel 12 telemedicine application ("Telemed"). It manages the full lifecycle of a patient consultation request — submission, nurse triage, physician scheduling/treatment, in-consultation messaging, prescriptions, and physician- or patient-initiated follow-ups — across four roles: `patient`, `nurse`, `physician`, `admin`. Frontend is server-rendered Blade + Tailwind + Alpine.js, bundled with Vite. File storage (attachments, prescriptions) prefers Cloudinary with a local-disk fallback — see Messaging & attachments below.

## Commands

- Install deps: `composer install && npm install`
- Run everything (server + queue listener + log tailer + vite) concurrently: `composer run dev`
- Serve only: `php artisan serve`
- Frontend build: `npm run build`; dev/watch: `npm run dev`
- Run all tests: `composer test` (clears config cache, then `php artisan test`) — **prefer this over running `php artisan test` / `vendor/bin/pest` directly**; see the config-cache gotcha below for why.
- Run a single test file: `vendor/bin/pest tests/Feature/FollowUpRequestTest.php`
- Run a single test by name: `vendor/bin/pest --filter="test description or Pest it() name"`
- Lint/format PHP: `vendor/bin/pint` (Laravel Pint; run `vendor/bin/pint --dirty` to format only changed files)
- Tinker/REPL: `php artisan tinker`
- Migrate: `php artisan migrate`. **Local dev and production both run MySQL/MariaDB** (`DB_CONNECTION=mysql`, database `telemed` under XAMPP) — the `database/database.sqlite` file is a leftover and is not the dev database. Feature tests use an in-memory SQLite DB (`phpunit.xml`) via Pest's `RefreshDatabase` (`tests/Pest.php` applies it only `->in('Feature')`), so they never touch the dev database **as long as no config cache is present — see the gotcha below, this has caused real data loss.** This split matters: tests and dev run different engines, so driver-specific behaviour can pass in tests and differ in dev (see the gotchas below).
- Cron-driven housekeeping: `php artisan consultations:mark-missed-slots` (registered in `routes/console.php` to run `everyMinute`) — flips booked `schedule_slots` to `missed` once their end time passes without the consultation starting. This only actually fires if something invokes the Laravel scheduler periodically (`schedule:run` via OS cron, or `schedule:work` locally) — registering the command alone does not make it run.

### Gotcha: a stale config cache makes tests wipe the real dev database

`phpunit.xml`'s `<env name="DB_CONNECTION" value="sqlite">` / `DB_DATABASE=:memory:` only takes effect while Laravel resolves config from `.env` at boot. If `bootstrap/cache/config.php` exists (created by `php artisan config:cache` or `php artisan optimize`, run any time, by anyone, against the real `.env`), Laravel skips `.env`/`phpunit.xml` env resolution entirely and serves the *cached* values instead — which point at `mysql`/`telemed`. Pest's `RefreshDatabase` trait then runs its initial `migrate:fresh` against whatever connection `config('database.default')` resolves to: with a stale cache present, that is the real dev database, not the in-memory test one. This is not hypothetical — it is exactly how the dev database got wiped once already (someone ran `php artisan optimize`, then a later `vendor/bin/pest`/`php artisan test` run silently dropped and recreated every table in `telemed`).

`composer test` is safe because its script runs `php artisan config:clear` immediately before `php artisan test`. Running `vendor/bin/pest` or `php artisan test` **directly** is not safe by default — it skips that clear. Always run `php artisan config:clear` first (or use `composer test`) before running tests directly, especially after anyone has run `config:cache`/`optimize`, and treat `bootstrap/cache/config.php` existing during local dev as a red flag worth clearing.

### Gotcha: SQLite enum migrations are no-ops

`alter_consultations_status_enum.php` and `alter_status_enum_on_schedule_slots_table.php` both intentionally `return` early when `DB::getDriverName() === 'sqlite'`. Dev runs MySQL, so these migrations **do** execute there and the enum values are enforced; it is only the test database (`:memory:` SQLite) where they never execute — the extra enum values they add (`scheduled` for `consultations.consultation_status`; `missed`/`completed` for `schedule_slots.status`) are never enforced by a SQLite constraint, only by application code. MySQL is the target driver where these ALTER migrations actually matter. Don't assume the enum migrations reflect what SQLite will accept: a status value the tests happily insert can still be rejected by the MySQL enum in dev and production.

## Architecture

### Two-table consultation model — this is the most important thing to understand

A single patient request is split across **two tables/models** with different primary keys, and both must be kept in sync whenever workflow state changes:

- **`Consultation`** (table `consultation_requests`, PK `request_id`) — the patient-facing *request*. Tracks `request_status` (`pending → reviewed → assigned/scheduled → active → completed`, or `rejected`/`cancelled`), the assigned nurse/physician, symptoms, priority, and `type` (`general` or `follow_up`, with `parent_consultation_id` pointing back to the originating `ConsultationSession` for follow-ups).
- **`ConsultationSession`** (table `consultations`, PK `id`) — the clinical *session* tied 1:1 to a `Consultation` via `request_id`. Tracks `consultation_status` (`scheduled/active/completed`), assessment/plan/recommendations/diagnosis, prescription file metadata, and the booked `slot_id`.

Both models exist because the schema evolved (`consultation_requests` came first, `consultations`/session data was added later) — do not assume the table name tells you which model it is. Never write directly to `request_status` or `consultation_status` from a controller; always go through **`App\Services\ConsultationOwnershipService`**, which wraps every state transition (claim, reject, cancel, start, schedule, follow-up decision) in a `DB::transaction` with `lockForUpdate()` pessimistic locks to prevent two nurses/physicians from claiming the same request simultaneously — this was a real race condition fixed in this codebase (see `tests/Feature/ConsultationConcurrencyTest.php`), so preserve the lock-then-check-status pattern when adding new transitions.

`ScheduleSlot` (table `schedule_slots`, PK `slot_id`) represents a physician's bookable time slot (`available`/`booked`/`missed`/`completed`); scheduling a consultation books a slot, and `MarkMissedScheduleSlots` reclaims stale booked slots.

`FollowUpRequest` (table `follow_up_requests`) is the patient-initiated ask for a follow-up (`pending → forwarded → approved/rejected`, or `cancelled`) tied to a `ConsultationSession`. A physician can also initiate a follow-up directly without a patient request, via `PhysicianController::createPhysicianFollowUp`, which delegates to the controller's own private `createFollowUpConsultationFromSource` — **not** `ConsultationOwnershipService`. That private method duplicates substantial locking/slot-booking logic that also lives in `ConsultationOwnershipService::decideFollowUpByPhysician` (used for the nurse-forwarded, physician-decided flow). This is a real maintenance/consistency risk: a fix to slot-booking or locking rules made in one place will not automatically apply to the other — check both when changing follow-up creation behavior. Either path spawns a brand-new `Consultation` + `ConsultationSession` pair of `type = 'follow_up'`.

### Models use non-standard keys throughout

Almost every model overrides Eloquent defaults — check before assuming `id`:
- `User`: PK `user_id`.
- `Consultation`: table `consultation_requests`, PK `request_id`, custom timestamp columns (`CREATED_AT = 'submitted_at'`).
- `ConsultationSession`: table `consultations`, PK `id` (standard), but relates to `Consultation` via `request_id`.
- `ScheduleSlot`: PK `slot_id`.
- `Notification`: PK `notification_id`.

### Role-based routing

All authenticated routes live in `routes/web.php` inside an `auth`+`verified` middleware group. `DashboardController::index` branches on `$user->role` to route to the right dashboard view (nurse role redirects to `nurse.dashboard`). Nurse and physician routes are scoped under `nurses/{nurse}/...` and `physicians/{physician}/...` prefixes with the acting user's own ID as a route parameter. `NurseController` and `PhysicianController` each guard every action with a private `authorizeNurse(User $nurse)` / `authorizePhysician(User $physician)` method that checks both `Auth::user()->role` and that `Auth::id()` matches the route-bound user's `user_id` — never trust the route parameter alone; follow this same pattern for new nurse/physician actions. Controllers are role-specific: `NurseController`, `PhysicianController` own their dashboards, inboxes, and consultation-history views; `ConsultationController` and `ConsultationMessageController` handle the shared patient/physician-facing consultation and in-session messaging flows.

### Staff account invitations (nurse/physician provisioning)

Nurses and physicians do not self-register and are never given a password by an
admin. `Admin\UserManagementController::store` creates them `account_status =
inactive` with `email_verified_at = null` and a discarded random filler password
(the column is `NOT NULL`), then issues an invitation and emails it. The invitee
sets their own password through the activation link, which is what flips the
account to `active` and verified.

Invitations are **Laravel's password broker**, not a bespoke token system — a
second broker named `staff_invitations` in `config/auth.php`, backed by
`staff_invitation_tokens`, expiring in 7 days (`expire => 10080`). Keep it that
way; do not add an invitations table or a custom token. The schema is exactly
`email`/`token`/`created_at` because `DatabaseTokenRepository::getPayload()`
inserts exactly those three columns — an extra `NOT NULL` column breaks the
insert, which is why the table has no `user_id` and why invitations are
email-keyed.

Invariants that must not be broken:

- **The plaintext token exists only in memory.** `createToken()` returns it, it
  goes straight into `StaffAccountInvitation`, and into the activation URL. It is
  never stored, logged, flashed (`bootstrap/app.php` adds `token` to
  `dontFlash`), or queued.
- **`StaffAccountInvitation` must stay synchronous.** `QUEUE_CONNECTION=database`,
  so queuing it would serialize the plaintext token into `jobs.payload`. A test
  asserts it does not implement `ShouldQueue`.
- **Mail is sent after the transaction commits, never inside it,** and a transport
  failure is caught so a committed account is not lost behind a 500. The catch is
  deliberately `Throwable`. Only the exception class is logged; the message can
  carry the SMTP username.
- **Eligibility is `User::awaitsStaffActivation()`** (inactive + nurse/physician),
  the single source of truth shared by activation and resend. Changing one
  without the other lets an admin issue invitations the activation flow refuses.
- **Changing a pending invitee's email must revoke their invitation first**, while
  the model still holds the old address — `update()` does this. Otherwise the old
  link stays redeemable against whoever is assigned that address next.
- **Resend locks the user row and re-checks eligibility under the lock.**
  `createToken()` deletes the previous row before inserting, so exactly one
  invitation is ever valid.
- Expired rows are flushed by `auth:clear-resets staff_invitations` on the
  scheduler. The broker name is required — omitting it would target
  `password_reset_tokens` instead.

Password reset is a **separate broker** at 60 minutes and must stay isolated:
neither token type works in the other flow, and tests assert this.

### Notifications

Never call `Notification::create()` directly from a controller — go through `App\Services\NotificationService`. Use `send()` for a one-off, `sendToRole()` to fan out to every active user of a role, and `sendUnique()` when the same event could fire more than once (e.g. re-triggered by a page refresh) — it de-dupes by checking whether a notification of the same `NotificationType` already carries a matching entity id (`consultation_id`, `follow_up_request_id`, `schedule_slot_id`, `session_id`, `request_id`, or `message_id`) in its `data` JSON column. All valid type strings are centralized in the `App\Enums\NotificationType` backed enum — add new notification types there rather than using raw strings.

### Authorization

Only two models have registered policies (`AppServiceProvider::boot`): `ConsultationPolicy` (a patient may only view their own `Consultation`) and `ConsultationSessionPolicy` (gates viewing/messaging in a `ConsultationSession` to the owning patient or assigned physician, and only while status is `active`/`completed`). Most other authorization (nurse/physician ownership checks, role checks) is done ad hoc inside controllers and `ConsultationOwnershipService` rather than via policies — follow that existing pattern for new nurse/physician actions unless refactoring toward policies deliberately.

### Messaging & attachments

`ConsultationMessageController` handles the real-time-feeling chat within an active `ConsultationSession` (send/read/typing/presence/mark-offline, all polled rather than websocket-driven), clinical detail updates (assessment/plan/recommendations/diagnosis), prescription upload/download, and message attachments.

`TrackUserPresence` is registered as global `web` middleware (`bootstrap/app.php`), so it updates `users.online_status`/`last_seen_at` on every authenticated web request, not just consultation activity. The `/presence/heartbeat` endpoint (`PresenceController`, CSRF-exempt) is supplementary: it's a client-side ping for pages that might otherwise sit idle without generating any requests.

File attachments (`MessageAttachment`) and prescriptions try Cloudinary (`cloudinary-labs/cloudinary-laravel`) first — `ConsultationController::store`, `ConsultationMessageController::store`, and `ConsultationMessageController::updateClinicalDetails` are the upload sites. If the Cloudinary call throws, each falls back to storing the file on the local `public` disk instead of failing the request. Because of this, the download/serving side (`ConsultationMessageController::downloadPrescription`/`downloadAttachment`, `AttachmentController::show`) always branches on the stored value: an `http(s)://` URL is redirected to directly, while anything else is treated as a relative path and served via `Storage::disk('public')`. Don't assume a stored path is always a Cloudinary URL.

## Development rules

- Before changing consultation or follow-up workflow, inspect the existing state transitions, `ConsultationOwnershipService`, related database constraints, and Feature tests.
- Preserve transaction boundaries and `lockForUpdate()` protections when changing ownership or state-transition logic.
- When adding a new workflow path, check whether an existing path already implements the same business rule before creating duplicate logic.
- Do not introduce Bootstrap; the frontend is Blade + Tailwind CSS + Alpine.js.
- Prefer existing project conventions over introducing new abstractions or dependencies.