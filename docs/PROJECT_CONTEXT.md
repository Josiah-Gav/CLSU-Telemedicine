# Telemed — Project Context for AI Assistants

This document hands the project over to a new Claude (or any AI assistant) session with no prior memory. Read it fully before changing anything. It covers what the project is, who it is for, how it is built, the rules that must never be broken, the current state, and how the owner wants to be worked with.

> **Source of truth order.** When documents disagree: **the code** wins, then `CLAUDE.md` (repository root, loaded automatically by Claude Code), then `docs/SYSTEM_REFERENCE.md`, then this document. Known disagreements are listed in §10. This document is a snapshot dated **2026-10-22**; verify anything time-sensitive against the code.

---

## 1. The project in one paragraph

**Telemed** is the CLSU (Central Luzon State University, Philippines) Infirmary's telemedicine web application, built as a capstone/thesis project by Kim Josiah Gavino (GitHub `Josiah-Gav`, repo `Josiah-Gav/CLSU-Telemedicine`). Students and staff (patients) submit an online consultation request; an infirmary **nurse** triages it; a **physician** schedules or starts it, consults through in-app chat and Jitsi video, documents assessment/plan/diagnosis, uploads a prescription, completes it, and may create follow-ups. An **admin** manages users and analytics. Completed consultation summaries are pushed to **CHIS** (CLSU Comprehensive Health Information System) — in testing, a researcher-run local simulator. The system is evaluated with a **TAM (Technology Acceptance Model)** survey, which is why the patient UI prompts for an evaluation after a completed consultation.

---

## 2. How to work with the owner

- The owner asked to be treated as a learner with an **expert mentor**: correct them when they are wrong, think critically, be **brutally honest**, do not just agree.
- Use **they/them** for anyone whose pronouns are not stated.
- The owner is preparing a **defense/presentation**. Accuracy matters more than polish: every claim must be traceable to code. Flag wrong terminology (example: they called Cloudinary signed URLs "hashing" — it is signing, see §7).
- Never describe the CHIS arrangement as approved in writing or as a live CHIS connection (see §8).
- Development branches follow `claude/<name>`; commits and pushes only to the branch you were assigned; open PRs only when asked.

---

## 3. Stack and environments

| Item | Value |
|---|---|
| Backend | Laravel 12, PHP |
| Frontend | Server-rendered Blade + Tailwind CSS + Alpine.js (+ some jQuery `$.ajax`), Vite. **No Bootstrap.** |
| Database | MySQL/MariaDB (`telemed` under XAMPP locally). Tests: in-memory SQLite |
| Queue / cache / session | all `database` driver |
| Files | Cloudinary (authenticated delivery) → private local disk fallback |
| Video | Jitsi as a Service (8x8 JaaS), RS256 JWT |
| Exports | CSV + PDF (barryvdh/laravel-dompdf) |
| API | Laravel Sanctum bearer tokens under `/api/v1` |
| Tests | Pest, ~89 Feature test files + Unit tests |
| Deployments | Local XAMPP (dev); **Hostinger** (operated by CLSU MISO, their QA/production); **Railway** (Docker, team-run client/TAM environment). See `docs/HOSTINGER_QA.md`, `docs/RAILWAY_DEPLOYMENT.md` |

**Commands**
- Install: `composer install && npm install`
- Dev (server + queue + logs + vite): `composer run dev`
- Tests: **`composer test`** (clears config cache first) — single file: `vendor/bin/pest tests/Feature/X.php` (run `php artisan config:clear` first!)
- Format: `vendor/bin/pint --dirty`
- Scheduler locally: `php artisan schedule:work`

---

## 4. Dangerous gotchas (read before running anything)

1. **Stale config cache wipes the dev database.** If `bootstrap/cache/config.php` exists (from `config:cache`/`optimize`), tests ignore `phpunit.xml` and `RefreshDatabase` runs `migrate:fresh` on the real MySQL `telemed` DB. This has already destroyed data once. Always use `composer test`, or `php artisan config:clear` before `pest`.
2. **Stale route cache hides new routes** (`bootstrap/cache/routes-*.php`) → run `php artisan route:clear`.
3. **SQLite skips the enum ALTER migrations**, so tests accept status values MySQL would reject. MySQL is the real target.
4. **Scheduler and queue worker must be running** or reminders, missed-slot marking, intake expiry, CHIS sync and most emails silently never happen.

---

## 5. Architecture essentials

### 5.1 Two tables per consultation (most important concept)
- `Consultation` → table `consultation_requests`, PK `request_id`, `request_status` (`pending → reviewed → scheduled → active → completed`, or `rejected` / `cancelled`; `assigned` exists in the enum but is never written), `type` `initial`/`follow_up`, `parent_consultation_id` → a `ConsultationSession.id`.
- `ConsultationSession` → table `consultations`, PK `id`, `consultation_status` (`scheduled/active/completed`), clinical fields, prescription, `slot_id`, takeover columns, CHIS sync columns.
- Keep both in sync inside one transaction on every state change.

### 5.2 Non-standard keys
`User.user_id`, `Consultation.request_id` (created column `submitted_at`), `ScheduleSlot.slot_id`, `Notification.notification_id`. Check before assuming `id`.

### 5.3 Services (where the rules live)

| Service | Responsibility |
|---|---|
| `ConsultationOwnershipService` | **Every** workflow state transition, with `DB::transaction` + `lockForUpdate()` + re-check under lock. Role-agnostic: controllers check roles. |
| `PhysicianAvailabilityService` | Consultation intake (is the clinic accepting new requests?); sole writer of `physician_availability_sessions` |
| `NotificationService` | All in-app notifications (`send`, `sendToRole`, `sendUnique`); types in `App\Enums\NotificationType` |
| `MedicalFileStorage` | All medical file upload/download (Cloudinary authenticated + signed URLs, private-disk fallback) |
| `ConsultationVideoService` / `JitsiService` | Video room lifecycle / JWT minting |
| `ChisClient`, `ChisEncounterSync`, `ChisEncounterPayload`, `ChisIdentity` | CHIS HTTP, queueing/eligibility, payload, name match |
| `DashboardAnalyticsService`, `SymptomAnalytics`, `Services/Export/*` | Dashboards and CSV/PDF exports |

### 5.4 Controllers
Role-specific: `NurseController`, `PhysicianController` (very large, ~2,100 lines), `ConsultationController` (patient submit + nurse approve/reject), `ConsultationMessageController` (chat, clinical details, completion, downloads), `ConsultationVideoController`, `FollowUpRequestController`, `Admin\*`, `Api\EncounterController`, `ChisProfileController`.

Nurse/physician routes: `nurses/{nurse}/…`, `physicians/{physician}/…`; each action calls `authorizeNurse()`/`authorizePhysician()` checking role **and** that `Auth::id()` equals the route user. Follow this pattern for new actions.

### 5.5 Real-time = polling
No WebSockets. Intervals: chat messages 3 s, chat presence/typing/video 4 s, patient dashboard 5 s, physician unread badges 6 s, notification bell 30 s, nurse/physician inbox 30 s, presence heartbeat 60 s (+ intake heartbeat piggybacked when intake is open). Typing state lives in cache with an 8 s TTL. Online = `online_status = online` and `last_seen_at` within 2 minutes. Full table in `docs/SYSTEM_REFERENCE.md` §7.

### 5.6 Background work
- Queue job: `SyncEncounterToChis` (5 tries, backoff 60/300/900/3600 s, `ShouldBeUnique` per session, carries ids only).
- Queued mails: `ConsultationScheduled`, `ConsultationReminder`, `ConsultationCompleted`, `FollowUpScheduled`. `StaffAccountInvitation` is **deliberately synchronous**.
- Scheduled (`routes/console.php`): `consultations:mark-missed-slots` (1 min), `consultations:expire-intake-sessions` (1 min), `consultations:send-reminders` (15 min), `chis:sync-pending` (15 min), `auth:clear-resets staff_invitations` (daily).

---

## 6. Invariants — never break these

1. Never write `request_status`/`consultation_status` directly from a controller; go through `ConsultationOwnershipService` and keep lock-then-recheck.
2. When changing follow-up creation, update **both** `ConsultationOwnershipService::decideFollowUpByPhysician` **and** `PhysicianController::createFollowUpConsultationFromSource` (known duplication).
3. Never call `Notification::create()` from a controller; use `NotificationService`. New types go into the `NotificationType` enum.
4. Every medical file goes through `MedicalFileStorage`. Store references, never URLs. Authorize before calling `response()`. Delivery type stays the constant `authenticated`.
5. Staff invitations use Laravel's password broker `staff_invitations` (7 days). No custom token table. Plaintext token never stored/logged/flashed/queued; mail after commit; `User::awaitsStaffActivation()` is the single eligibility rule; email change revokes the pending invitation first.
6. Queue payloads carry ids only — never clinical data (`jobs.payload` is a DB table).
7. CHIS: only `ChisClient` talks to CHIS; never hold a DB lock during HTTP; one audit row per real call, written by the caller; audit stores *that*, never *what*; profile view fails closed; nothing from CHIS is cached; payload excludes chat, attachments, file URL, priority, nurse, email, phone, internal patient id.
8. API returns sync status only; tokens: active admins, explicit abilities, expiry ≤365 days.
9. Intake heartbeat can never open a session; closing intake never touches a consultation.
10. Mail is sent after the transaction commits and failures are caught (log the exception class only).
11. No Bootstrap; prefer existing conventions over new abstractions/dependencies; match the surrounding comment density (the codebase uses long explanatory docblocks).

---

## 7. Security model (summary)

- Auth: CLSU-email-only registration (`@clsu.edu.ph`/`@clsu2.edu.ph`), email verification, 5-attempt login lockout, inactive status disclosed only after a correct password, suspended accounts logged out on next request (`EnsureAccountIsActive`).
- Files: Cloudinary `authenticated` assets + **signed, 5-minute download URLs** minted after authorization (this is *signing*, not hashing); fallback private disk `storage/app/medical`, `serve => false`.
- Hashing is used for passwords (bcrypt) and broker tokens; random names for fallback files; RS256 for Jitsi; 128-bit random room names.
- CSRF everywhere except `/presence/heartbeat`. CSV formula-injection guard on exports.
- Known weak spots: `SESSION_ENCRYPT=false`; `trustProxies('*')` safe only behind a proxy; CHIS audit immutability is app-level only; legacy rows may hold public Cloudinary URLs.

---

## 8. CHIS and privacy (handle carefully)

- Lawful basis: RA 10173 (Philippine Data Privacy Act) Sec. 13, *medical treatment* — **no consent gate**; patients get a privacy notice. `users.chis_consent_at` is a historic, unread column.
- CLSU MISO's requirement that every completed consultation goes to CHIS was given **verbally only**; written confirmation not obtained; DPO review recommended before production.
- CHIS in testing is the separate repo `clsu-chis-simulation` on `http://localhost:8001/api/v1`. The deployment never connected to a real CHIS. Testing was role-played.
- Sessions skipped under the old consent rule are sent once with `php artisan chis:send-skipped --confirm`.

---

## 9. Current state (as of 2026-10-22)

- 56 commits on the main line; last merged work: CHIS phase 8 (lawful basis replaces consent) and privacy-policy update.
- Feature-complete areas: request/triage/scheduling/consultation/completion, follow-ups (patient + physician), takeover, intake availability with planned end/overtime/auto-close, video, notifications + emails, reminders, analytics dashboards, CSV/PDF exports, staff invitations, account suspension, CHIS push + profile + audit + admin sync page, Sanctum API + Postman collection, mobile navigation.
- New documentation added on branch `claude/dazzling-wozniak-1ctdgo`: `docs/SYSTEM_REFERENCE.md` + `docs/Telemed_System_Reference.docx` (presentation guide) and this file.

### Open issues / technical debt
- Duplicated physician follow-up logic (Invariant 2).
- Messages endpoint returns the whole conversation every 3 s (no incremental cursor); `TrackUserPresence` writes the DB on every request.
- `PhysicianController` is very large.
- `/consultations/history` and `/consultations/create` use `auth` only, not `verified`.
- Replaced Cloudinary prescriptions are never deleted (retention undecided).
- `README.md` is still the default Laravel boilerplate.

---

## 10. Known documentation discrepancies

| Where | Says | Reality (code) |
|---|---|---|
| `CLAUDE.md` → Messaging & attachments | Cloudinary failure falls back to the **public** disk; download branches on `http(s)://` URL vs. path | Falls back to the **private** disk `message_attachments` (`storage/app/medical`); DB stores `cloudinary:{type}:{format}:{public_id}` references; served via `MedicalFileStorage::response()` with signed URLs |
| `docs/HOSTINGER_QA.md` vs `docs/RAILWAY_DEPLOYMENT.md` | Hostinger doc: "there is one deployed environment" | Railway doc describes a second, independent team-run environment. Clarify with the owner before advising on deployment |
| Owner's wording | "Cloudinary hashing" | Authenticated delivery + expiring signed URLs |

Fixing `CLAUDE.md` is a reasonable first task if the owner agrees.

---

## 11. Where to look

| Need | File |
|---|---|
| Auto-loaded AI guidance | `CLAUDE.md` |
| Every rule/number for the defense | `docs/SYSTEM_REFERENCE.md` |
| Feature inventory and glossary (thesis paper material) | `docs/paper/00-feature-inventory.md`, `docs/paper/glossary.md`, `docs/paper/features/` |
| Staff invitations | `docs/STAFF_INVITATION_SYSTEM.md` |
| Jitsi | `docs/JITSI_API_IMPLEMENTATION.md` |
| CHIS demo / Postman | `docs/CHIS_DEMO.md`, `docs/postman/` |
| Inbox, analytics, exports | `docs/CONSULTATION_INBOX.md`, `docs/DASHBOARD_ANALYTICS.md`, `docs/EXPORTS.md` |
| Deployment | `docs/HOSTINGER_QA.md`, `docs/RAILWAY_DEPLOYMENT.md`, `Dockerfile`, `railway.json` |
| Routes | `routes/web.php`, `routes/api.php`, `routes/auth.php`, `routes/console.php` |
| Tunables | `config/consultations.php`, `config/cloudinary.php`, `config/services.php` (jitsi, chis) |

---

## 12. Before changing workflow code — checklist

1. Read the relevant transition in `ConsultationOwnershipService` and the matching Feature tests.
2. Check whether an existing path already implements the rule (avoid a third copy).
3. Keep transaction + `lockForUpdate()` + re-check.
4. Notifications through `NotificationService`, after commit.
5. Run `composer test` (never bare `pest` with a config cache present) and `vendor/bin/pint --dirty`.
6. Update `CLAUDE.md` / `docs/SYSTEM_REFERENCE.md` if a rule or number changed.
