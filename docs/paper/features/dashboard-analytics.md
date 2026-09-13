# Dashboard Analytics

> Terminology follows `docs/paper/glossary.md`. Figure and table numbers use the
> `X.n` placeholder pending final manuscript numbering.

## 1. Feature Name

Dashboard Analytics — role-scoped operational and historical metrics, four Chart.js
series, and symptom-vocabulary aggregation, for nurse, physician, and admin
dashboards.

## 2. Purpose

To turn the consultation tables into the decision-support view each role needs,
from **one set of canonical definitions** so that no two screens can disagree about
what "completed" means.

The architectural rule is stated in the service docblock: *"Controllers stay thin:
authorize, build a DateRange from the request, call one method here, pass the
result to the view. No business logic belongs in a controller or a Blade view."*

## 3. Actors / Roles

| Actor | Sees |
|---|---|
| Nurse | Shared-queue pressure, own open cases, own reviewed/completed counts, four charts |
| Physician | Own active/scheduled counts, completion rate, four charts, symptom summary (top 10) |
| Admin | System-wide metrics, four charts, full symptom summary |
| Patient | Nothing — patients have no analytics |

## 4. User Workflow

1. The role's dashboard is opened, optionally with `?range=`, `?start=`, `?end=`.
2. The controller builds a `DateRange` and calls exactly one service method.
3. The service returns a four-part array — `operational`, `period`, `charts`,
   `filters` (plus `symptoms` for physician and admin).
4. The view renders stat tiles and Chart.js canvases from that array.
5. An export control offers the same data as CSV or PDF
   (`consultation-history-and-exports.md`).

## 5. Routes

| Method | URI | Name | Default range |
|---|---|---|---|
| GET | `/dashboard` (admin branch) | `dashboard` | `last_30_days` |
| GET | `/dashboard` (physician branch) | `dashboard` | `this_month` |
| GET | `/nurses/{nurse}/dashboard` | `nurse.dashboard` | `last_30_days` |
| GET | `/physicians/{physician}/dashboard` | `physician.dashboard` | — |

Note the physician's two entry points carry **different defaults**: the
`/dashboard` branch uses `this_month`, `PhysicianController::dashboard` its own.

## 6. Controllers

`DashboardController::index` (three branches), `NurseController::dashboard`,
`PhysicianController::dashboard`.

## 7. Services

**`DashboardAnalyticsService`** — `forNurse`, `forPhysician`, `forAdmin`, and the
private `completionRate`, `buildCharts`, `volumeOverTime`, `statusDistribution`,
`priorityDistribution`, `initialVsFollowUp`, `filtersPayload`.

**`SymptomAnalytics`** — `summarize()` over already-fetched `symptoms_desc` values.

**`App\Support\DateRange`** — presets, clamping, and a `cacheKey()`.

## 8. Models

`Consultation` supplies the canonical definitions as **query scopes**, which is
what makes the "no two screens disagree" guarantee real:

| Scope | Definition |
|---|---|
| `completed()` | `request_status = 'completed'` **OR** the session's `consultation_status = 'completed'` |
| `concluded()` | `completed()` OR `request_status` in `rejected`, `cancelled` |
| `inFlight()` | `request_status` in `IN_FLIGHT_STATUSES` |
| `initial()` | `type = 'initial'` **OR** `type IS NULL` |
| `followUp()` | `type = 'follow_up'` |
| `forNurse()` / `forPhysician()` | by `assigned_nurse_id` / `assigned_physician_id` |
| `pending()`, `unclaimed()`, `active()`, `highPriority()`, `submittedBetween()` | as named |

The `completed()` scope's docblock explains the OR: completion writes both columns
in one transaction so they agree in practice, but the OR is kept because the
existing history controllers already rely on it *"and this scope must never
disagree with those."*

`initial()`'s docblock explains the NULL branch: legacy rows predating the `type`
migration are treated as initial.

## 9. Database

Reads only — this feature writes nothing. It queries `consultation_requests` (with
`whereHas` into `consultations`), `follow_up_requests`, and `users`.

Aggregates use `selectRaw('DATE(submitted_at) as day, COUNT(*) …')` and
`groupBy`, executed against the database rather than in PHP — except symptom
analytics, which is deliberately the opposite (section 11).

## 10. Validation and Authorization

`authorizeNurse()` / `authorizePhysician()` / `authorizeAdmin()` — controller-level
as everywhere else. `DashboardController::index` ends with
`default: abort(403, 'Unauthorized action. Role not recognized.')`.

Range input is validated by `DateRange::fromInput()`, which accepts the six presets
(`today`, `this_week`, `this_month`, `last_30_days`, `this_year`, `custom`) and
clamps a custom range to `MAX_CUSTOM_RANGE_DAYS = 730`.

## 11. Business Rules

| # | Rule | Enforced by | Enforcement type |
|---|---|---|---|
| BR-1 | Operational metrics are **never** date-filtered | Structural: the `operational` block never touches `$range` | **Application (by design)** |
| BR-2 | Period metrics are scoped by `submitted_at` | `submittedBetween($range->start, $range->end)` | **Application** |
| BR-3 | Every metric derives from a `Consultation` scope | The scopes are composed, never re-derived | **Application (convention)** |
| BR-4 | Volume charts are zero-filled across the whole range | The `while` loop emitting a label per day | **Application** |
| BR-5 | Status charts label from `MEANINGFUL_STATUSES` | `$labels = Consultation::MEANINGFUL_STATUSES;` | **Application** |
| BR-6 | Symptom analytics exclude follow-ups | `->initial()` before `pluck('symptoms_desc')` in both `forPhysician` and `forAdmin` | **Application** |
| BR-7 | Custom symptom terms below k=3 are suppressed | `CUSTOM_TERM_MIN_REPORTS = 3` | **Application (privacy floor)** |
| BR-8 | Classification is by vocabulary membership, never the client's `custom` flag | `STANDARDIZED_SYMPTOMS` is *"the sole classification authority"* | **Application (security fix H-4)** |
| BR-9 | Malformed symptom rows never break a dashboard | Skipped and counted in `malformed_requests` | **Application** |
| BR-10 | A custom range may not exceed 730 days | `DateRange::MAX_CUSTOM_RANGE_DAYS` | **Application** |
| BR-11 | Completion rate is null, not zero, when nothing concluded | `$concluded > 0 ? round(...) : null` | **Application** |

**No database constraint participates in any rule here** — this feature is entirely
read-side.

**BR-5 is why `assigned` never appears on a chart.** The status distribution labels
from `MEANINGFUL_STATUSES`, which deliberately omits the dead `assigned` value.
This is the cleanest example in the codebase of a dead enum value being kept out of
a user-facing artifact, and it is worth citing in the manuscript.

**BR-8 is a documented security fix.** `SymptomAnalytics`' docblock records finding
H-4: classification used to trust a client-supplied `custom` boolean that
`ConsultationController::store` never validates, so a patient could force free text
into the standardized bucket — which carries **no** privacy suppression. Membership
in `STANDARDIZED_SYMPTOMS` is now the sole authority, so *"an unrecognized name can
never bypass the k=3 suppression regardless of what the client claims."*

**Why symptom analytics is PHP, not SQL** — the docblock gives a concrete
portability reason worth quoting: `JSON_TABLE` requires MySQL 8+, MariaDB (the
XAMPP dev driver) does not support it at all, and the Pest suite runs SQLite with a
different JSON dialect. *"A SQL-side implementation would therefore work in neither
dev nor test."*

## 12. Concurrency

**None, and none needed** — this feature only reads.

One correctness detail is worth documenting because it looks like a quirk:
`buildCharts()` takes a `Closure(): Builder` rather than a `Builder`, and every use
site calls `(clone $scopedQuery())`. The parameter docblock explains why: *"Returns
a fresh, already role- and date-scoped builder each call — a Builder can't be safely
reused across multiple independent aggregate queries."*

## 13. Status / State Transitions

None. This feature has no state.

## 14. Error and Edge-Case Handling

| Case | Behaviour |
|---|---|
| Unrecognised role on `/dashboard` | 403 |
| No requests in range | Charts render zero-filled; `completion_rate.rate` is null |
| Custom range beyond 730 days | Clamped by `DateRange` — a test asserts the export links carry the **clamped** end date, not the raw request |
| Malformed `symptoms_desc` row | Skipped, counted in `malformed_requests`, never thrown |
| Fewer than 3 reports of a custom term | Suppressed from output; the suppressed **count** is still reported, the terms never are |
| Chart data containing user text | Escaped — `ChartPayloadEscapingTest` (7 cases) |

## 15. UI Implementation

Three dashboards (`nurse`, `physician`, `admin`) share a component vocabulary under
`resources/views/components/dash/`: `stat`, `chart`, `section`, `table`, `badge`,
`empty`, `state`, `filter-bar`, `export-menu`.

Charts are Chart.js (`chart.js ^4.5.1`, the project's **only** runtime npm
dependency), wired in `resources/js/dashboards.js`, consuming the
`{labels, datasets}` shape the service emits.

The physician dashboard additionally renders the shared
`<x-physician.intake-status-card>` — the same component the intake page uses, fed
by `dashboardIntakeSummary()` so the two can never disagree
(`physician-intake.md`).

## 16. Tests

**9 files, 103 cases.**

| File | Cases | Focus |
|---|---|---|
| `Analytics/ConsultationAnalyticsScopesTest.php` | 22 | Every canonical scope, including the `completed()` OR and the `initial()` NULL branch |
| `Analytics/SymptomAnalyticsTest.php` | 20 | Aggregation, severity buckets, malformed input, k=3 suppression |
| `Analytics/DashboardAnalyticsServiceTest.php` | 15 | Per-role payload shape, operational vs period separation |
| `Analytics/SymptomVocabularyTest.php` | 12 | The standardized vocabulary and the H-4 classification rule |
| `Analytics/DateRangeTest.php` | 10 | Presets, clamping, boundaries |
| `Analytics/ChartPayloadEscapingTest.php` | 7 | User-supplied text cannot break out of the chart payload |
| `Analytics/AdminInFlightMetricTest.php` | 6 | The in-flight metric |
| `Analytics/DashboardControllerWiringTest.php` | 6 | Each controller calls the right service method with the right range |
| `Analytics/DashboardViewRenderingTest.php` | 5 | The views render the payload |

## 17. Source-Code Evidence

| Claim | Evidence |
|---|---|
| Thin-controller rule | `DashboardAnalyticsService` class docblock |
| Three-part result shape | Same docblock, listing `operational` / `period` / `charts` / `symptoms` |
| Operational is never date-filtered | The `operational` arrays contain no `$range` reference |
| Scopes are canonical | `Consultation::scopeCompleted` docblock: *"Compose these instead of re-deriving…"* |
| Follow-ups excluded from symptoms | `(clone $periodQuery())->initial()->pluck('symptoms_desc')` in both methods, each with a comment |
| k=3 floor | `CUSTOM_TERM_MIN_REPORTS = 3` |
| H-4 classification fix | `STANDARDIZED_SYMPTOMS` docblock |
| PHP-not-SQL rationale | `SymptomAnalytics` class docblock |
| Never throws | Same docblock: *"A single unparseable row is skipped and counted in `malformed_requests`; it can never take down a dashboard."* |
| Closure-not-Builder | `buildCharts()` parameter docblock |
| Dead status kept off charts | `statusDistribution()`'s `$labels = Consultation::MEANINGFUL_STATUSES;` |

## 18. Limitations / Gaps

| # | Limitation |
|---|---|
| DA-1 | **The symptom vocabulary is hand-maintained in two places.** `STANDARDIZED_SYMPTOMS` must be kept in sync by hand with the `x-for` picker list in `newconsultation.blade.php`. Its own docblock says so: *"there is no database table or config for it, so this list must be kept in sync by hand if the intake form's picker ever changes."* A drift silently reclassifies real symptoms as custom, where k=3 suppression then hides them. |
| DA-2 | **Symptom aggregation is unbounded in memory.** `pluck('symptoms_desc')` loads every matching row's JSON into PHP before summarizing. Correct and portable, but it scales with the number of requests in the range, not with the number of distinct symptoms. |
| DA-3 | **`volumeOverTime` is always daily.** The code carries an explicit `ponytail:` marker acknowledging this: daily granularity even across a multi-year custom range, with coarser bucketing deferred as a frontend concern *"upgrade if a chart ever needs to render more than ~730 points."* At the 730-day clamp the chart emits 730 points. |
| DA-4 | **No caching.** `DateRange` exposes a `cacheKey()` method, but nothing calls it — every dashboard load re-runs every aggregate. |
| DA-5 | **The physician's two entry points default to different ranges.** `/dashboard` uses `this_month`; `PhysicianController::dashboard` builds its own. A physician arriving by the post-login redirect sees a different default period than one who clicks the nav link. |
| DA-6 | **`DEFAULT_SEVERITY_BUCKET = 3` is an artifact, not a judgement.** The constant's own comment says it is *"the severity value every symptom starts at the instant it's selected — not necessarily a deliberate choice."* Any severity chart therefore over-represents 3. |
| DA-7 | **Patients see no analytics at all.** Not a defect, but worth stating explicitly so the manuscript does not imply a patient-facing dashboard beyond the status card. |
| DA-8 | **`authorizeAdmin()` is duplicated.** The same private method exists in `DashboardController` and `Admin\UserManagementController`, with a comment in one pointing at the other. See gap AU-6. |
