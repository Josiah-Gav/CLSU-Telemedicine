# Feature Inventory — CLSU Infirmary Telemedicine System

**Purpose.** This is the source-of-truth list of major implemented features for the
capstone manuscript. Every row was derived by reading the current source in this
repository. Nothing here is taken from `CLAUDE.md`, the project description, or
prior conversation.

**Status vocabulary.**

- **Implemented** — the full path exists: route (or command), controller/service
  logic, persistence, and UI.
- **Partial** — the path exists but a material part is missing, stubbed, or
  enforced only in application code where the schema does not back it.
- **Not Found** — referenced somewhere (UI label, project description) but with no
  implementation behind it.

**Reading the evidence column.** Paths are repository-relative. Class members are
given as `Class::method`. Line numbers are given only where the exact line was
opened and verified during this pass.

---

## Table X.1 Feature Inventory — Authentication and Account Management

| Feature | User Role | Description | Implementation Evidence | Main Routes | Controllers | Services | Models / Tables | Tests | Status |
|---|---|---|---|---|---|---|---|---|---|
| Patient self-registration | Patient | Public registration form; patients are the only role that self-registers. | `RegisteredUserController::create/store`; `users` created with `role` defaulting to `patient` per `create_users_table` | `GET/POST register` | `Auth\RegisteredUserController` | — | `User` / `users` | `tests/Feature/Auth/RegistrationTest.php` | Implemented |
| Email verification | All | Signed verification link; `verified` middleware gates the main route group. | `VerifyEmailController::__invoke`; `EmailVerificationPromptController`; `EmailVerificationNotificationController`; route group in `routes/web.php` uses `['auth','verified']` | `GET verify-email`, `GET verify-email/{id}/{hash}`, `POST email/verification-notification` | `Auth\VerifyEmail*`, `Auth\EmailVerification*` | — | `User` / `users.email_verified_at` | `tests/Feature/Auth/EmailVerificationTest.php` | Implemented |
| Login / logout | All | Session login. Logout closes a physician's open intake session before logging out, and writes `online_status = offline`. | `AuthenticatedSessionController::store`, `::destroy` — the physician branch calls `PhysicianAvailabilityService::close($user)` before `Auth::logout()`, then sets `online_status`/`last_seen_at` | `GET/POST login`, `POST logout` | `Auth\AuthenticatedSessionController` | `PhysicianAvailabilityService` | `User` / `users`, `physician_availability_sessions` | `tests/Feature/Auth/AuthenticationTest.php` | Implemented |
| Password reset | All | Standard Laravel broker, 60-minute expiry, separate from staff invitations. | `PasswordResetLinkController`, `NewPasswordController`; `password_reset_tokens` from `2026_07_15_000000_create_password_reset_tokens_table.php` | `GET/POST forgot-password`, `GET reset-password/{token}`, `POST reset-password` | `Auth\PasswordResetLinkController`, `Auth\NewPasswordController` | — | `password_reset_tokens` | `tests/Feature/Auth/PasswordResetTest.php` | Implemented |
| Profile and password update | All | Edit own profile; change own password; confirm-password gate. | `ProfileController::edit/update`, `PasswordController::update`, `ConfirmablePasswordController` | `GET/PATCH profile`, `PUT password`, `GET/POST confirm-password` | `ProfileController`, `Auth\PasswordController`, `Auth\ConfirmablePasswordController` | — | `User` / `users` | `tests/Feature/ProfileTest.php`, `Auth/PasswordUpdateTest.php`, `Auth/PasswordConfirmationTest.php` | Implemented |
| Staff account invitation and activation | Admin creates; Nurse / Physician activate | Staff never self-register and are never given a password. Admin creates an `inactive`, unverified account; an invitation token is mailed; the invitee sets their own password, which activates and verifies the account. Uses a second password broker named `staff_invitations` (7-day expiry), not a bespoke token table. | `Admin\UserManagementController::store` (`INVITED_ROLES = ['nurse','physician']`), `::resendInvitation`, `::broker`; `Auth\StaffInvitationController::create/store/invitee/isEligible/reject`; `User::awaitsStaffActivation`, `User::INVITED_ROLES`; `Notifications\StaffAccountInvitation`; `staff_invitation_tokens` (`email` PK, `token`, `created_at`); `bootstrap/app.php` adds `token` to `dontFlash` | `GET staff/activate/{token}`, `POST staff/activate`, `POST /admin/users/{user}/resend-invitation` | `Admin\UserManagementController`, `Auth\StaffInvitationController` | — (broker via `config/auth.php`) | `User` / `users`, `staff_invitation_tokens` | `tests/Feature/Admin/StaffAccountCreationTest.php`, `StaffAccountInvitationEmailTest.php`, `StaffInvitationResendTest.php`, `StaffInvitationRevocationTest.php`, `StaffInvitationMailFailureTest.php`, `StaffInvitationCleanupTest.php`; `tests/Feature/Auth/StaffAccountActivationTest.php`, `StaffInvitationTokenTest.php`, `StaffInvitationHardeningTest.php` | Implemented |
| Admin user management | Admin | List, create, edit, and update staff accounts; view invitation state. Changing a pending invitee's email revokes the old invitation first. | `Admin\UserManagementController::index/create/store/edit/update/invitationStates/authorizeAdmin` | `GET /admin/users`, `GET /admin/users/create`, `POST /admin/users`, `GET /admin/users/{user}/edit`, `PUT /admin/users/{user}` | `Admin\UserManagementController` | — | `User` / `users`, `staff_invitation_tokens` | `tests/Feature/Admin/UserManagementAuthorizationTest.php` | Implemented |

---

## Table X.2 Feature Inventory — Consultation Request Lifecycle

| Feature | User Role | Description | Implementation Evidence | Main Routes | Controllers | Services | Models / Tables | Tests | Status |
|---|---|---|---|---|---|---|---|---|---|
| Consultation request submission | Patient | Symptom-intake form. Enforces one open request per patient, then the intake availability gate, then validation, then attachment upload. A future symptom-onset date is rejected server-side. | `ConsultationController::create/store` — duplicate-request check across `['pending','reviewed','assigned','scheduled','active']`; intake gate returns HTTP 503; validation of `concern_category`, `symptoms_payload`, `online_reason`, `additional_notes`, `attachments.*` (`image`, max 10240 KB) | `GET /consultations/create`, `POST /consultations`, `GET /newconsultation` | `ConsultationController`, `DashboardController::newconsultation` | `PhysicianAvailabilityService`, `NotificationService` | `Consultation` / `consultation_requests` | `tests/Feature/ConsultationIntakeGateTest.php`, `ConsultationSymptomOnsetDateTest.php`, `ConsultationAdditionalInformationTest.php`, `ConsultationPriorityDefaultTest.php` | Implemented |
| Consultation intake availability | Patient (gated), Physician (controls) | Whether the service accepts new requests at all. Combines an open, non-stale physician intake session with a global pending-queue ceiling. Both limits are configurable without redeployment. | `PhysicianAvailabilityService::isServiceAvailable` = `hasOpenIntake() && pendingQueueHasCapacity()`; `config/consultations.php` → `intake.queue_limit` (default 20), `intake.stale_after_seconds` (default 120); `PRESENCE_FRESHNESS_MINUTES = 2` | Consumed by `ConsultationController::create/store`, `DashboardController::index/newconsultation` | `ConsultationController`, `DashboardController` | `PhysicianAvailabilityService` | `PhysicianAvailabilitySession`, `PhysicianSchedule` / `physician_availability_sessions`, `physician_schedules` | `tests/Feature/ConsultationIntakeGateTest.php`, `ConsultationIntakeAvailabilityUiTest.php`, `PhysicianAvailabilityServiceTest.php` | Implemented |
| Physician intake hours and live intake session | Physician | Recurring weekly intake hours, plus an open/close/heartbeat live session that decides whether the physician is actually taking requests now. Stale sessions expire on a scheduled command. | `PhysicianController::consultationIntake/storePhysicianSchedule/updatePhysicianSchedule/destroyPhysicianSchedule/consultationIntakeOpen/consultationIntakeClose/consultationIntakeHeartbeat`; `PhysicianAvailabilityService::open/close/touch/expireStaleSessions/evaluateMode/nextScheduledWindow/weeklyScheduleOverview`; `ExpireStaleIntakeSessions` (`consultations:expire-intake-sessions`, scheduled `everyMinute`) | `GET /physicians/{physician}/consultation-intake`, `POST|PUT|DELETE .../consultation-intake/schedules`, `POST .../consultation-intake/open|close|heartbeat` | `PhysicianController` | `PhysicianAvailabilityService` | `PhysicianSchedule`, `PhysicianAvailabilitySession` / `physician_schedules`, `physician_availability_sessions` | `tests/Feature/PhysicianConsultationIntakeTest.php`, `PhysicianIntakeControlsTest.php`, `PhysicianIntakeExpiryTest.php`, `PhysicianIntakeFoundationTest.php` | Implemented |
| Nurse triage and consultation inbox | Nurse | Nurse claims a pending request (setting priority) or rejects it with a reason. Inbox has a polled refresh endpoint. | `ConsultationController::approveConsultation/rejectionConsultation`; `ConsultationOwnershipService::claimByNurse` (`request_status` → `reviewed`), `::rejectByNurse`; `NurseController::consultationInbox/consultationInboxRefresh/getConsultationInboxData/serializeConsultations` guarded by `NurseController::authorizeNurse` | `POST /consultations/{consultation}/approve`, `POST /consultations/{consultation}/reject`, `GET /nurses/{nurse}/consultation-inbox(/refresh)` | `ConsultationController`, `NurseController` | `ConsultationOwnershipService`, `NotificationService` | `Consultation` / `consultation_requests` | `tests/Feature/NurseConsultationInboxTableTest.php`, `ConsultationConcurrencyTest.php` | Implemented |
| Physician inbox and reviewed-request decision | Physician | Physician sees reviewed requests and either proceeds to scheduling or rejects with a reason. | `PhysicianController::consultationInbox/consultationInboxRefresh/approveReviewedConsultation/rejectReviewedConsultation`; `ConsultationOwnershipService::rejectReviewedByPhysician`; `PhysicianController::authorizePhysician` | `GET /physicians/{physician}/consultation-inbox(/refresh)`, `POST .../consultations/{consultation}/approve-reviewed`, `.../reject-reviewed` | `PhysicianController` | `ConsultationOwnershipService`, `NotificationService` | `Consultation` / `consultation_requests` | `tests/Feature/PhysicianConsultationInboxTableTest.php` | Implemented |
| Consultation scheduling (slot booking) | Physician | Physician books the request onto one of their available slots, creating the clinical session and moving the request to `scheduled`. | `ConsultationOwnershipService::scheduleByPhysician`; `PhysicianController::availableScheduleSlotsForConsultation/scheduleConsultation`; unique index `consultations_request_id_unique_ownership` (`2026_08_20_120000_add_consultation_session_uniques.php`) | `GET .../consultations/{consultation}/available-slots`, `POST .../consultations/{consultation}/schedule` | `PhysicianController` | `ConsultationOwnershipService` | `Consultation`, `ConsultationSession`, `ScheduleSlot` / `consultation_requests`, `consultations`, `schedule_slots` | `tests/Feature/ConsultationConcurrencyTest.php`, `PhysicianScheduleSlotPastTimeTest.php` | Implemented |
| Schedule slot management | Physician | Generate candidate slots from a date/time range and save them; overlapping and past slots are rejected. | `PhysicianController::generateScheduleSlots` (`GenerateScheduleSlotsRequest`), `::saveScheduleSlots` (`StoreScheduleSlotsRequest`), `::overlapsExistingSlots/overlapsRange/isScheduleSlotInPast/combineDateAndTime`; `schedule_slots` unique index on physician + date + time | `GET .../scheduled_consultation`, `GET .../scheduled_consultation/slots`, `POST .../scheduled_consultation/generate`, `POST .../scheduled_consultation/save` | `PhysicianController` | — | `ScheduleSlot` / `schedule_slots` | `tests/Feature/PhysicianScheduleSlotPastTimeTest.php` | Implemented |
| Missed schedule slot handling | Physician, Patient (notified) | A booked slot whose end time passes without the consultation starting is flipped to `missed`; the patient is notified. Also synced on page load. | `MarkMissedScheduleSlots` (`consultations:mark-missed-slots`, scheduled `everyMinute` with `withoutOverlapping`); `PhysicianController::syncMissedSlotsForPhysician`; `schedule_slots.status` enum extended to include `missed`/`completed` by `2026_08_05_125250_alter_status_enum_on_schedule_slots_table.php` | Scheduler command; consumed on physician pages | `PhysicianController` | — | `ScheduleSlot` / `schedule_slots` | `tests/Feature/PhysicianStartMissedSlotTest.php` | Implemented |
| Physician takeover | Physician | After a grace period past the slot start, another physician may claim a scheduled consultation that has not begun. Records who it was scheduled to, who claimed it, and when — introducing no new status value. | `ConsultationOwnershipService::takeOverByPhysician`, `TAKEOVER_GRACE_MINUTES = 10`; `PhysicianController::takeOverConsultation/resolveTakeoverInfo`; `ConsultationSession::wasTakenOver/originalPhysician/takenOverByPhysician`; columns from `2026_09_01_120000_add_takeover_columns_to_consultations_table.php` | `POST .../consultations/{consultation}/take-over` | `PhysicianController` | `ConsultationOwnershipService` | `ConsultationSession` / `consultations` | `tests/Feature/PhysicianTakeoverTest.php` | Implemented |
| Start consultation | Physician | Moves the booked consultation into the active state and opens the session. | `ConsultationOwnershipService::startByPhysician`; `PhysicianController::startConsultation/resolveCanStart/buildCanStartInfoFromSlot`; `PhysicianController::activeConsultations/scheduledConsultations` | `POST .../consultations/{consultation}/start`, `GET .../active_consultation`, `GET .../scheduled_consultation` | `PhysicianController` | `ConsultationOwnershipService` | `Consultation`, `ConsultationSession`, `ScheduleSlot` | `tests/Feature/PhysicianActiveConsultationTableTest.php`, `PhysicianStartMissedSlotTest.php`, `ConsultationConcurrencyTest.php` | Implemented |
| Patient cancellation | Patient | Patient withdraws their own request. | `ConsultationController::cancelConsultation` → `ConsultationOwnershipService::cancelByPatient` | `POST /consultations/{consultation}/cancel` | `ConsultationController` | `ConsultationOwnershipService` | `Consultation` / `consultation_requests` | Covered indirectly; no dedicated cancellation test file found | Implemented |
| Patient request tracking and consultation details | Patient | Dashboard status card and the detail page for a single request; viewing is policy-gated to the owning patient. | `DashboardController::index/activeConsultation/serializePatientConsultation/getPatientStatusBadgeClass/getConsultationSummary`; `ConsultationController::show`; `ConsultationPolicy::view` | `GET /dashboard`, `GET /dashboard/active-consultation`, `GET /consultations/{consultation}` | `DashboardController`, `ConsultationController` | `PhysicianAvailabilityService` | `Consultation` / `consultation_requests` | `tests/Feature/DashboardTest.php`, `ConsultationAccessTest.php` | Implemented |

---

## Table X.3 Feature Inventory — Consultation Session (Clinical Encounter)

| Feature | User Role | Description | Implementation Evidence | Main Routes | Controllers | Services | Models / Tables | Tests | Status |
|---|---|---|---|---|---|---|---|---|---|
| In-consultation messaging | Patient, Physician | Text messaging inside an active session. Delivery is HTTP-polled, not websocket: separate endpoints for listing, sending, marking read, unread counts, typing, presence, and going offline. | `ConsultationMessageController::show/index/store/markRead/unreadCounts/typing/presence/markOffline/serializeMessage/setTyping/touchLastSeen`; `TYPING_TTL_SECONDS = 8`; `ConsultationSessionPolicy::viewMessaging/sendMessage` | `GET .../messaging`, `GET|POST .../messages`, `POST .../messages/read`, `GET /consultation-sessions/unread-counts`, `POST .../typing`, `GET .../presence`, `POST .../offline` | `ConsultationMessageController` | `NotificationService` | `Message` / `consultation_messages` | `tests/Feature/ConsultationMessagingUiTest.php`, `ConsultationMessagePerformanceTest.php` | Implemented |
| Attachments and prescriptions | Patient, Physician, Nurse (view) | File upload on messages and prescription upload on the session. Cloudinary is attempted first with a local-disk fallback; download branches on whether the stored value is an `http(s)` URL. Locally-stored message attachments live on a private disk, with a command to migrate legacy files off the public disk. | `ConsultationMessageController::store/updateClinicalDetails/downloadPrescription/downloadAttachment/forceCloudinaryDownload/deletePrescriptionFile`; `PRIVATE_DISK = 'message_attachments'`, `MAX_ATTACHMENTS_PER_MESSAGE = 3`, `MAX_FILE_SIZE_MB = 10`, `MAX_VIDEO_SIZE_MB = 50`, `MAX_VIDEOS_PER_MESSAGE = 1`, `ATTACHMENT_EXTENSIONS = ['jpg','jpeg','png','pdf','doc','docx','mp4']`; `AttachmentController::show/canViewAttachments` with `PHYSICIAN_POOL_STATUSES = ['reviewed','assigned','scheduled']`; `MoveMessageAttachmentsToPrivateDisk` (`attachments:move-to-private`) | `GET /consultation-sessions/{session}/prescription/download`, `GET /consultation-message-attachments/{attachment}/download`, `GET /consultations/{consultation}/attachments/{file}` | `ConsultationMessageController`, `AttachmentController` | — | `MessageAttachment` / `message_attachments`, `consultations` prescription columns | `tests/Feature/AttachmentPrivateStorageTest.php`, `ConsultationMessageAttachmentLimitsTest.php`, `PhysicianAttachmentAccessTest.php` | Implemented |
| Clinical documentation | Physician | Assessment, plan, recommendations, diagnosis, and prescription file recorded against the session; "meaningful content" helpers distinguish a filled field from a placeholder. | `ConsultationMessageController::updateClinicalDetails/buildClinicalDetailsPayload`; `ConsultationSession::hasMeaningfulAssessment/hasMeaningfulPlan/hasMeaningfulRecommendations/hasDiagnosis/hasPrescription/hasClinicalDocumentation`; columns from `create_consultations_table` and `add_prescription_fields_to_consultations_table` | `POST /consultation-sessions/{session}/clinical-details` | `ConsultationMessageController` | — | `ConsultationSession` / `consultations` | Covered within `tests/Feature/ConsultationMessagingUiTest.php`; no dedicated clinical-documentation test file found | Implemented |
| Consultation completion | Physician | Ends the encounter, closing any running video session as part of completion. | `ConsultationMessageController::complete(ConsultationSession $session, ConsultationVideoService $videoSessions)` | `POST /consultation-sessions/{session}/complete` | `ConsultationMessageController` | `ConsultationVideoService`, `NotificationService` | `ConsultationSession`, `ScheduleSlot` | `tests/Feature/ConsultationCompletionVideoTest.php` | Implemented |
| Video consultation | Physician (starts/ends), Patient (joins) | Jitsi-based video. The physician creates the room; the patient may only join a running one. Access is JWT-signed with a random room name. | `ConsultationVideoController::start/join/end/joinPayload/isAssignedPhysician/displayNameFor`; `ConsultationVideoService::startForPhysician/activeFor/end/assertConsultationIsActive`; `JitsiService::domain/generateRoomName/iframeRoomName/issueToken/sign/normalizePem/jwtTtl` with `ROOM_NAME_BYTES = 16`, `NBF_SKEW_SECONDS = 10`; `ConsultationSessionPolicy::startVideo/joinVideo`; `consultation_video_sessions` with unique `room_name` | `POST .../video/start`, `POST .../video/join` (both `throttle:30,1`), `POST .../video/end` | `ConsultationVideoController` | `ConsultationVideoService`, `JitsiService` | `ConsultationVideoSession` / `consultation_video_sessions` | `tests/Feature/ConsultationVideoSessionTest.php`, `ConsultationVideoAccessTest.php`, `ConsultationVideoJoinUiTest.php`, `ConsultationVideoPresenceTest.php`, `JitsiServiceTest.php`, `JitsiConfigTest.php` | Implemented |

---

## Table X.4 Feature Inventory — Follow-Up Care

| Feature | User Role | Description | Implementation Evidence | Main Routes | Controllers | Services | Models / Tables | Tests | Status |
|---|---|---|---|---|---|---|---|---|---|
| Patient-initiated follow-up request | Patient | Patient asks for a follow-up against a completed session; may cancel while pending. | `FollowUpRequestController::index/store/cancel`; `ConsultationOwnershipService::cancelFollowUpByPatient` | `GET /follow-up-list`, `POST /consultation-sessions/{session}/follow-up-requests`, `POST /follow-up-requests/{followUpRequest}/cancel` | `FollowUpRequestController` | `ConsultationOwnershipService` | `FollowUpRequest` / `follow_up_requests` | `tests/Feature/FollowUpRequestTest.php` | Implemented |
| Nurse follow-up review | Nurse | Nurse forwards a pending follow-up request to a physician, or rejects it with notes. | `NurseController::followUpRequests/forwardFollowUpRequest/rejectFollowUpRequest`; `ConsultationOwnershipService::forwardFollowUpByNurse/rejectFollowUpByNurse` | `GET /nurses/{nurse}/follow-up-requests`, `POST .../forward`, `POST .../reject` | `NurseController` | `ConsultationOwnershipService` | `FollowUpRequest` / `follow_up_requests` | `tests/Feature/FollowUpRequestTest.php` | Implemented |
| Physician follow-up decision and creation | Physician | Two distinct creation paths: deciding a nurse-forwarded request, and initiating a follow-up directly from a completed session. Both spawn a new `consultation_requests` + `consultations` pair with `type = 'follow_up'`. | Nurse-forwarded: `ConsultationOwnershipService::decideFollowUpByPhysician`. Physician-initiated: `PhysicianController::createPhysicianFollowUp` → private `PhysicianController::createFollowUpConsultationFromSource`, which does **not** call the ownership service. Also `PhysicianController::availableSlotsForFollowUpRequest/availableSlotsForPhysicianFollowUp/decideFollowUpRequest` | `POST .../follow-up-requests/{followUpRequest}/decide`, `POST .../consultation-sessions/{session}/follow-up`, plus the two available-slots endpoints | `PhysicianController` | `ConsultationOwnershipService` (one path only) | `Consultation`, `ConsultationSession`, `FollowUpRequest`, `ScheduleSlot` | `tests/Feature/FollowUpRequestTest.php` | Implemented — see Limitation L-1 |

---

## Table X.5 Feature Inventory — Cross-Cutting Services

| Feature | User Role | Description | Implementation Evidence | Main Routes | Controllers | Services | Models / Tables | Tests | Status |
|---|---|---|---|---|---|---|---|---|---|
| In-app notifications | All | Centralized notification creation with a typed vocabulary and de-duplication for events that can re-fire. | `NotificationService::send/sendToRole/sendUnique/alreadyNotified` (all `static`); `App\Enums\NotificationType` — 19 cases across consultation workflow, messaging, follow-up, and operational groups; `NotificationController::index/all/unreadCount/markAsRead/markAllAsRead/serialize` with `ALLOWED_DATE_FILTERS = ['today','last_7_days','last_30_days','all']` | `GET /notifications`, `GET /notifications/all`, `GET /notifications/unread-count`, `PATCH /notifications/{notification}/read`, `PATCH /notifications/read-all` | `NotificationController` | `NotificationService` | `Notification` / `notifications` (PK `notification_id`, JSON `data`, indexes on `[user_id, read_at]` and `type`) | `tests/Feature/NotificationTest.php` | Implemented |
| Presence tracking | All | `online_status` and `last_seen_at` are updated on every authenticated web request; a CSRF-exempt heartbeat covers idle pages. | `TrackUserPresence` registered as global `web` middleware in `bootstrap/app.php`; `PresenceController::heartbeat`; `presence/heartbeat` listed in `validateCsrfTokens(except:)` | `POST /presence/heartbeat` | `PresenceController` | — | `User` / `users.online_status`, `users.last_seen_at` | Exercised via `tests/Feature/ConsultationVideoPresenceTest.php` and inbox tests; no dedicated middleware test file found | Implemented |
| Dashboard analytics | Nurse, Physician, Admin | Role-scoped metrics and Chart.js charts: volume over time, status distribution, priority distribution, initial vs follow-up, completion rate, plus a symptom vocabulary summary. | `DashboardAnalyticsService::forNurse/forPhysician/forAdmin/completionRate/buildCharts/volumeOverTime/statusDistribution/priorityDistribution/initialVsFollowUp/filtersPayload`; `SymptomAnalytics::summarize/normalize/toSortedPairs` with `STANDARDIZED_SYMPTOMS`, `CUSTOM_TERM_MIN_REPORTS = 3`, `VALID_SEVERITIES = [1,2,3,4]`; `Support\DateRange` with `PRESETS` and `MAX_CUSTOM_RANGE_DAYS = 730`; `chart.js` in `package.json` | `GET /dashboard`, role dashboards under `nurses/{nurse}` and `physicians/{physician}` | `DashboardController`, `NurseController`, `PhysicianController` | `DashboardAnalyticsService`, `SymptomAnalytics` | `Consultation`, `ConsultationSession` | `tests/Feature/Analytics/` (10 files, incl. `DashboardAnalyticsServiceTest.php`, `SymptomAnalyticsTest.php`, `ChartPayloadEscapingTest.php`, `DateRangeTest.php`) | Implemented |
| Consultation history and exports | Patient, Nurse, Physician, Admin | Filtered history pages per role, exportable to CSV and PDF. CSV output is hardened against formula injection; PDF output is row-capped. | `ConsultationController::history/historyExport`; `NurseController::consultationHistory/consultationHistoryExport`; `PhysicianController::consultationHistory/consultationHistoryExport`; `DashboardController::adminDashboardExport`; `Export\ConsultationHistoryQuery` (`ALLOWED_DATE_FILTERS`, `ALLOWED_STATUS_FILTERS`, `ALLOWED_TYPE_FILTERS`), `Export\ConsultationHistoryRows` (`PDF_ROW_CAP = 500`, per-role header sets), `Export\DashboardExportRows`; `Support\CsvDownload` with `DANGEROUS_PREFIXES = ['=','+','-','@',"\t","\r"]`; `barryvdh/laravel-dompdf`; `resources/views/exports/*.blade.php` | `GET /consultations/history`, `GET /consultations/history/export`, `GET /admin/dashboard/export`, `GET .../consultation-history(/export)`, `GET .../dashboard/export` | `ConsultationController`, `NurseController`, `PhysicianController`, `DashboardController` | `Export\ConsultationHistoryQuery`, `Export\ConsultationHistoryRows`, `Export\DashboardExportRows` | `Consultation`, `ConsultationSession` | `tests/Feature/Export/` (5 files), `ConsultationHistoryTest.php`, `ConsultationHistoryExportUiTest.php`, `DashboardExportUiTest.php` | Implemented |
| Concurrency control for state transitions | Nurse, Physician | Every ownership transition runs inside `DB::transaction` with `lockForUpdate()` pessimistic locks, then re-checks status under the lock. | `ConsultationOwnershipService` — `claimByNurse`, `rejectByNurse`, `cancelByPatient`, `startByPhysician`, `takeOverByPhysician`, `rejectReviewedByPhysician`, `scheduleByPhysician`, `forwardFollowUpByNurse`, `rejectFollowUpByNurse`, `cancelFollowUpByPatient`, `decideFollowUpByPhysician` | n/a (service layer) | — | `ConsultationOwnershipService` | `Consultation`, `ConsultationSession`, `ScheduleSlot`, `FollowUpRequest` | `tests/Feature/ConsultationConcurrencyTest.php` | Implemented |

---

## Not Implemented

The following appears in the project description or the user interface but has **no
implementation in the current source**. It is recorded here so it is not mistaken
for a feature, and so the manuscript does not claim it.

### CHIS integration — Not Implemented

**Classification: Not Implemented. Future enhancement only.**

The system does **not** have CHIS interoperability. There is no CHIS API client, no
simulated API, no dummy CHIS database, no controller, no service, no route, no
configuration key, no model, no migration, and no test.

The only trace anywhere in the repository is presentational. A repository-wide
case-insensitive search for `chis` across `app/` and `resources/views/` returns
exactly one match:
`resources/views/consultations/messaging.blade.php:633`, which renders a card
headed "CHIS Sync Status" containing a hardcoded badge reading "Not connected" and
the static text "Last synced: No Data". The two cards immediately above it,
"Immunization History" and "Family Medical History", are likewise hardcoded to
"No Data" with no data source behind them.

Consequences for the manuscript, per the author's instruction:

- No `chis-simulation.md` or `chis-integration.md` feature file will be created.
- CHIS must not be described as implemented, integrated, simulated, or partially
  working anywhere in the paper.
- No CHIS endpoint, API contract, data flow, sequence, or workflow may be
  documented, because none exists to document.
- CHIS may appear only in the limitations / future enhancements chapter, phrased as
  planned future integration — and only if the manuscript scope supports it.
- The static placeholder cards should be described, if at all, as UI placeholders
  reserved for future integration, never as a sync status.

---

## Phase 4 Review Summary

### 1. Number of major features identified

**22 major implemented features**, grouped into five domains: Authentication and
Account Management (7), Consultation Request Lifecycle (12), Consultation Session
(5), Follow-Up Care (3), Cross-Cutting Services (6). The inventory tables above
carry 33 rows; several rows are grouped into a single documentation file, and the
mapping is in section 2. CHIS is excluded from this count entirely — it is not a
feature (see **Not Implemented** above).

### 2. Final feature documentation files

Final contents of `docs/paper/features/` — **22 files**:

| # | Filename | Covers which inventory rows |
|---|---|---|
| 1 | `authentication-and-registration.md` | Patient self-registration, email verification, login/logout, password reset, profile and password update |
| 2 | `staff-invitation-and-activation.md` | Staff account invitation and activation |
| 3 | `admin-user-management.md` | Admin user management |
| 4 | `consultation-request-submission.md` | Consultation request submission |
| 5 | `physician-intake.md` | Consultation intake availability (patient-side gate), physician intake hours, live intake session, **and presence tracking** |
| 6 | `nurse-triage-and-inbox.md` | Nurse triage and consultation inbox |
| 7 | `physician-inbox-and-review-decision.md` | Physician inbox and reviewed-request decision |
| 8 | `consultation-scheduling.md` | Consultation scheduling (slot booking) |
| 9 | `schedule-slot-management.md` | Schedule slot management |
| 10 | `missed-schedule-slots.md` | Missed schedule slot handling |
| 11 | `physician-takeover.md` | Physician takeover |
| 12 | `active-consultation.md` | Start consultation, active/scheduled consultation pages, consultation completion |
| 13 | `patient-request-tracking-and-cancellation.md` | Patient request tracking and consultation details, **and patient cancellation** |
| 14 | `consultation-messaging.md` | In-consultation messaging, incl. typing indicator and in-session presence polling |
| 15 | `attachments-and-prescriptions.md` | Attachments and prescriptions |
| 16 | `clinical-documentation.md` | Clinical documentation |
| 17 | `video-consultation.md` | Video consultation |
| 18 | `follow-up-consultation.md` | All three follow-up rows (patient request, nurse review, physician decision and creation) |
| 19 | `notifications.md` | In-app notifications |
| 20 | `dashboard-analytics.md` | Dashboard analytics |
| 21 | `consultation-history-and-exports.md` | Consultation history and exports |
| 22 | `concurrency-and-state-transitions.md` | Concurrency control for state transitions |

No CHIS file is created, in either direction. CHIS has no implementation to
document and appears only in the limitations / future enhancements chapter.

### 3. Grouping decisions and why

- **Authentication rows merged into one file.** Registration, verification, login,
  password reset, and profile update are all stock Laravel Breeze paths with no
  Telemed-specific business rules. Splitting them would produce five thin files.
- **Staff invitation kept separate from authentication.** It is the opposite case:
  a custom second password broker, a bespoke eligibility rule
  (`User::awaitsStaffActivation`), nine dedicated test files, and several security
  invariants. This is a strong defense topic and earns its own file.
- **Intake availability and physician intake session merged.** `isServiceAvailable()`
  is one rule with two faces — the patient sees a gate, the physician sees controls.
  Documenting them apart would split a single business rule across two files.
- **Start / active pages / completion merged into `active-consultation.md`.** They
  are one continuous lifecycle segment on the same session row.
- **All three follow-up rows merged.** The three paths converge on the same
  outcome (a new request + session pair of `type = 'follow_up'`) and the most
  important thing to document is how they differ; that only works in one file.
- **Messaging, attachments, and clinical documentation kept separate** despite all
  living in `ConsultationMessageController`. That controller is 761 lines covering
  three genuinely different concerns with different authorization, different
  storage behavior, and different panel questions.
- **Concurrency given its own file.** It is the strongest technical claim in the
  system, it spans eleven service methods and every workflow feature, and it has a
  dedicated test file. Documenting it inside each feature would repeat it a dozen
  times.
- **Patient cancellation merged into `patient-request-tracking-and-cancellation.md`.**
  Cancellation is a single controller method delegating to a single service method,
  and the action is surfaced on the very page that file already covers — the cancel
  control lives at `resources/views/patient/consultation-details.blade.php:154`,
  wired to `route('consultations.cancel', $consultation)`. Documenting it apart
  would split one screen across two files.
- **Presence tracking merged into `physician-intake.md`.** Presence is what makes
  intake availability real: `PhysicianAvailabilityService` treats a physician as
  available only when their presence is fresh within `PRESENCE_FRESHNESS_MINUTES = 2`,
  so the middleware and the intake rule are one mechanism. One caveat to state
  plainly in that file: `TrackUserPresence` is registered as **global `web`
  middleware** in `bootstrap/app.php` and therefore updates `users.online_status`
  and `users.last_seen_at` for every authenticated role, not physicians only. The
  separate in-session presence polling used by messaging is a different mechanism
  and is documented in `consultation-messaging.md`, which cross-references this file.

### 4. Features from the project description that could NOT be verified

- **CHIS integration / CHIS simulation — classified Not Implemented.** See the
  **Not Implemented** section above for the full evidence and the resulting
  documentation rules. In short: one presentational placeholder, no backend of any
  kind, and the manuscript must not claim CHIS interoperability.

### 5. Unexpected features discovered in the code

These are all implemented and all absent from `CLAUDE.md`:

- **Video consultation via Jitsi** with server-signed JWTs, random room names, and
  a dedicated `consultation_video_sessions` table (6 test files).
- **Physician consultation intake** — recurring weekly schedules plus a live
  open/close/heartbeat availability session with staleness expiry (4 test files).
- **Dashboard analytics** for three roles with Chart.js and a symptom-vocabulary
  normalizer (10 test files).
- **CSV and PDF exports** for history and dashboards, including CSV formula-injection
  hardening in `Support\CsvDownload` (5 test files).
- **Physician takeover** with a 10-minute grace period and its own audit columns.
- **`attachments:move-to-private`** command migrating legacy public-disk attachments
  to a private disk.
- **ngrok/TLS proxy trust** configured in `bootstrap/app.php` — evidence the system
  has been demonstrated over a public HTTPS tunnel.

### 6. Contradictions between `CLAUDE.md` and the actual implementation

| # | `CLAUDE.md` says | The source says |
|---|---|---|
| C-1 | `consultation_requests.type` is `general` or `follow_up` | The column is `enum('type', ['initial','follow_up'])` with default `initial` (`2026_08_06_180500_add_follow_up_fields_to_consultation_requests_table.php:12`). `'general'` is a **filter vocabulary**, not a stored value: `ConsultationHistoryQuery::ALLOWED_TYPE_FILTERS = ['follow_up','general','all']`, and the `'general'` branch resolves to `whereNull('type')->orWhere('type','!=','follow_up')`. The manuscript must not present `general` as a database value. |
| C-2 | `ConsultationSession` status is `scheduled/active/completed` | The MySQL enum is `('scheduled','active','completed','cancelled')` with default `'scheduled'` (`2026_08_05_123545_alter_consultations_status_enum.php`). `cancelled` is omitted from `CLAUDE.md`. |
| C-3 | `FollowUpRequest` status is `pending → forwarded → approved/rejected`, or `cancelled` | The enum also contains `expired` (`2026_08_06_172148_create_follow_up_requests_table.php`). **Now resolved:** no code path writes it. Every `'expired'` occurrence in `app/` belongs to `physician_availability_sessions.status` or to the admin invitation-state label, never to `follow_up_requests`. It is a second dead enum value — see gap L-9. |
| C-4 | Attachments fall back to "the local `public` disk", served via `Storage::disk('public')` | Only `AttachmentController` still reads the `public` disk. `ConsultationMessageController` uses `PRIVATE_DISK = 'message_attachments'`, and `MoveMessageAttachmentsToPrivateDisk` migrates files from public to private. The public→private move is a real security decision `CLAUDE.md` predates. |
| C-5 | Lists one scheduled command (`consultations:mark-missed-slots`) plus `auth:clear-resets` | `routes/console.php` schedules three: those two and `consultations:expire-intake-sessions` (`everyMinute`). |
| C-6 | Describes only `ConsultationController`, `ConsultationMessageController`, `NurseController`, `PhysicianController`, `DashboardController` | Also present and substantial: `ConsultationVideoController`, `NotificationController`, `AttachmentController`, `PresenceController`, `FollowUpRequestController`, `Admin\UserManagementController`. |

### 7. Implementation gaps and unfinished work

| # | Gap | Evidence |
|---|---|---|
| L-1 | **Duplicated follow-up creation logic.** `ConsultationOwnershipService::decideFollowUpByPhysician` and the private `PhysicianController::createFollowUpConsultationFromSource` both implement locking and slot booking for follow-up creation. A fix to one does not reach the other. | Both methods exist; the physician-initiated route calls only the controller-private one. |
| L-2 | **One slot could hold two sessions.** `consultations` has unique indexes on `request_id` and `follow_up_request_id`, but **none on `slot_id`**. The one-slot-one-session rule is enforced only by application code under `lockForUpdate()`. | `2026_08_20_120000_add_consultation_session_uniques.php` adds exactly two unique indexes, neither on `slot_id`. |
| L-3 | **`assigned` is a dead status.** `consultation_requests.request_status` includes `assigned` in its enum, but `Consultation::MEANINGFUL_STATUSES` deliberately omits it and no transition writes it. It must not appear in any state diagram. | The model constant's own docblock states this. |
| L-4 | **Authorization is not uniform.** Only `ConsultationPolicy` and `ConsultationSessionPolicy` are registered in `AppServiceProvider::boot`. Nurse, physician, and admin authorization is done by private controller methods (`authorizeNurse`, `authorizePhysician`, `authorizeAdmin`). The manuscript must not claim a single policy layer. | `AppServiceProvider::boot` registers exactly two policies. |
| L-5 | **Scheduler commands require an external runner.** All three scheduled commands only fire if `schedule:run` (OS cron) or `schedule:work` is actually running. Registering them in `routes/console.php` does not make them run. This is a deployment requirement, not an implemented behavior. | `routes/console.php`; nothing in the repository invokes the scheduler. |
| L-6 | **Test/production database engine split.** Feature tests run in-memory SQLite (`phpunit.xml`), while development and production run MySQL/MariaDB. The three enum-altering migrations return early on SQLite, so enum constraints exercised in production are never enforced in tests. | `2026_08_05_123545`, `2026_08_05_125250`, `2026_08_29_154539` all begin with a `DB::getDriverName() === 'sqlite'` early return. |
| L-7 | **Features without dedicated tests.** Patient cancellation, clinical documentation, and the `TrackUserPresence` middleware have no test file of their own, though each is exercised indirectly. | No matching file in the 70-file test inventory. |
| L-8 | **Legacy column — resolved, do not document.** `consultation_requests.preffered_consultation_type` was created misspelled, then dropped by `2026_06_29_054405_alter_consultation_requests_table.php`, and survives only in that migration's `down()`. It is **absent from the live MySQL schema** and must not appear in the data dictionary. | Verified against the live database: `Schema::getColumnListing('consultation_requests')` on the `mysql` connection returns 16 columns and does not include it. |
| L-9 | **`expired` is a second dead enum value.** `follow_up_requests.status` includes `expired` in its enum, but no code path ever writes it — unlike `physician_availability_sessions.status`, where `expired` is written by `PhysicianAvailabilityService::expireStaleSessions`. Like `assigned` on `request_status` (L-3), it must not appear as a state in any follow-up state diagram. | Searched every `'expired'` occurrence in `app/`; none targets `follow_up_requests`. |
| L-11 | **A registered route points at a controller method that does not exist.** `routes/web.php:115` registers `physician.consultations.approve_reviewed` → `PhysicianController::approveReviewedConsultation`. The method is defined nowhere in `app/`. The route is registered (verified with `php artisan route:list`), so a POST to it fails at dispatch rather than 404ing. No view or test references it, so it is unreachable in practice. | Repository-wide search for the method name returns nothing; `route:list --name=approve_reviewed` returns the route. |
| L-12 | **Dead code in `ConsultationController`.** It imports `App\Models\SymptomLog`, a class that does not exist, and its `index()` method returns `view('consultations.index')`, a view that does not exist and has no route. Neither breaks anything today. | `app/Http/Controllers/ConsultationController.php`; `ls app/Models/`; `ls resources/views/consultations/`. |
| L-13 | **Two consultation endpoints have no role check.** `consultations.approve` and `consultations.reject` (`ConsultationController::approveConsultation` / `::rejectionConsultation`) perform no role, policy, or middleware authorization, and `ConsultationOwnershipService::claimByNurse` accepts the nurse id as a bare integer. Any authenticated, verified user can claim or reject a pending consultation request. No test covers the negative case. | Both method bodies; the controller constructor adds no middleware; `routes/web.php` adds no role middleware. |
| L-15 | **`/consultation-sessions/unread-counts` leaks across consultations for nurse and admin roles.** `ConsultationMessageController::unreadCounts` calls no `authorize()`; it scopes by role inside a `where` closure that has branches for `patient` and `physician` only. For any other role neither branch runs, Laravel compiles the empty nested group away, and the query becomes `select * from consultations where consultation_status = ?` — every active consultation session in the system. The response then returns unread message counts keyed by session id for all of them. Verified by building the same query with no branch taken. | `ConsultationMessageController::unreadCounts`; query SQL confirmed via `toSql()`. |
| L-16 | **`updateClinicalDetails` is a full replace and can write null into `NOT NULL` columns.** Omitted fields are mapped to null, but `consultations.assessment`, `.plan`, and `.recommendations` are `NOT NULL` in the live MySQL schema (verified with `SHOW COLUMNS`). Unreachable through the UI, unguarded at the application layer, and completely untested. | `ConsultationMessageController::updateClinicalDetails`; live schema. |
| L-17 | **`PhysicianController::activeConsultations` writes on a GET request.** It backfills a missing consultation session and may rewrite `physician_id` and `consultation_status` on an existing one, with no transaction, no lock, and no route through `ConsultationOwnershipService`. | `PhysicianController::activeConsultations`. |
| L-14 | **`MarkMissedScheduleSlots` has no test at all**, unlike its sibling `ExpireStaleIntakeSessions` (19 cases). Related: when the scheduled command marks a slot missed, **no notification is sent** — only the page-triggered `syncMissedSlotsForPhysician` notifies, and it skips slots already marked `missed`. | `tests/` contains no file referencing the command; `MarkMissedScheduleSlots::handle` sends no notifications. |
| L-10 | **The system is single-institution and has no external clinical integration.** There is no CHIS client, no HL7/FHIR layer, no external EMR exchange, and no interoperability surface of any kind. The Immunization History and Family Medical History panels on the messaging screen are hardcoded "No Data" with no data source. | Repository-wide search; see the **Not Implemented** section. |

### 8. Terminology conflicts

| # | Conflict | Recommended manuscript usage |
|---|---|---|
| T-1 | **"Consultation" is ambiguous.** `Consultation` the model maps to table `consultation_requests`; `ConsultationSession` the model maps to table `consultations`. The names are effectively inverted. | Always write **"consultation request"** for `consultation_requests` and **"consultation session"** for `consultations`. Never use bare "consultation" for either in a sentence where both could apply. |
| T-2 | **`initial` vs `general`.** The stored enum value is `initial`; the history filter and `DashboardController` use `general` for the same idea. | Use **`initial`** whenever describing the database, and note `general` explicitly as the filter-layer label. |
| T-3 | **"Intake" means two different things.** `physician_schedules` / `physician_availability_sessions` are "consultation intake" (whether the service accepts requests). `schedule_slots` are the bookable appointment inventory. `routes/web.php` explicitly warns they are different concepts. | Use **"intake availability"** for the former and **"schedule slots"** for the latter. Never call slot generation "intake". |
| T-4 | **"Follow-up request" vs "follow-up consultation."** `follow_up_requests` is the ask; the approved outcome is a new `consultation_requests` + `consultations` pair. | Use **"follow-up request"** for the `follow_up_requests` row and **"follow-up consultation"** for the spawned pair. |
| T-5 | **`message_attachments` vs consultation-request attachments.** Files uploaded at request submission (`consultation_requests.file_attachments`) are a different mechanism from `message_attachments` rows, and they are served by different controllers. | Distinguish **"request attachments"** from **"message attachments"** throughout. |

### 9. Decisions applied

1. **CHIS — no file.** Classified Not Implemented, removed from the feature tables,
   and recorded under **Not Implemented** and gap L-10. No CHIS endpoint, simulated
   API, dummy database, controller, service, or workflow is documented anywhere,
   because none exists. CHIS may appear only under limitations / future
   enhancements, and the system is never described as having CHIS interoperability.
2. **Grouping applied.** `patient-cancellation.md` merged into
   `patient-request-tracking-and-cancellation.md`; `presence-tracking.md` merged
   into `physician-intake.md`. File count 24 → 22. The one-feature-one-file rule is
   kept for every substantial feature.
3. **Chapter numbering.** All figures and tables stay `Figure X.n` / `Table X.n`.
   No chapter number is invented. **Flagged for final manuscript numbering** — the
   `X` placeholders must be replaced once the chapter each section lands in is
   fixed.
4. **Glossary seeded** at `docs/paper/glossary.md` before Phase 5, built strictly
   from verified source terminology.
5. **Phase 5 verification standard.** Before any feature file is written, the large
   files deferred in this pass — `PhysicianController` (1,990 lines),
   `ConsultationMessageController` (761), `ConsultationController` (499) — plus all
   relevant Blade views and tests must be read in full. No UI or workflow claim may
   rest on controller logic alone.
6. **Source is the sole authority.** Where `CLAUDE.md`, this inventory, or the
   documentation skill conflicts with the code, the code wins, the documentation is
   corrected, and the contradiction is reported rather than quietly resolved.

---

## Verification Statement

Every row above was derived from files opened during this reconnaissance pass:
`composer.json`, `package.json`, `bootstrap/app.php`, `routes/web.php`,
`routes/auth.php`, `routes/console.php`, `config/consultations.php`,
`config/filesystems.php`, all 30 files in `database/migrations/`, all 11 files in
`app/Models/`, all 24 controllers, all 9 services, `app/Enums/NotificationType.php`,
both policies, `app/Support/`, `app/Console/Commands/`, `app/Providers/AppServiceProvider.php`,
the Blade view inventory, and the 70-file test inventory.

Three claims were additionally verified against the **live MySQL database** via
`Schema::getColumnListing()` on the `mysql` connection: the absence of
`preffered_consultation_type` (gap L-8), and the column sets of `consultations`,
`schedule_slots`, `follow_up_requests`, and `users`, all of which match the
accumulated migration history.

Where a claim could not be evidenced, the text says so explicitly rather than
filling the gap. No credential value from `.env` appears anywhere in this document.

**Not yet read in full** (deferred to per-feature documentation, where the detail is
actually needed): the bodies of the largest controllers — `PhysicianController`
(1,990 lines), `ConsultationMessageController` (761), `ConsultationController` (499) —
beyond the sections cited here, and the Blade views other than
`consultations/messaging.blade.php`. Feature-level UI claims in Phase 5 must be
verified against the views before they are written.
