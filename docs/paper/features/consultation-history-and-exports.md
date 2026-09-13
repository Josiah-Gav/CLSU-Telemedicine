# Consultation History and Exports

> Terminology follows `docs/paper/glossary.md` — note §4: `general` is a **filter
> label**, not the stored value. Figure and table numbers use the `X.n` placeholder
> pending final manuscript numbering.

## 1. Feature Name

Consultation History and Exports — the filtered history pages for patient, nurse,
and physician, and the CSV/PDF export of both those pages and the dashboards.

## 2. Purpose

To give each role a searchable record of concluded consultations, and a downloadable
report of exactly what is on screen. The governing design rule is that **the export
and the page can never disagree**, because both are built from the same query and
the same row mapper.

## 3. Actors / Roles

| Actor | History page | History export | Dashboard export |
|---|---|---|---|
| Patient | own consultations | yes | — |
| Nurse | consultations they claimed | yes | yes |
| Physician | consultations assigned to them | yes | yes |
| Admin | — | **no** | yes |

## 4. User Workflow

1. The role opens its history page with optional `date_filter`, `status`,
   `consultation_type`, and (physician/nurse) `search`.
2. `ConsultationHistoryQuery` normalises the filters and builds the scoped query.
3. The page renders; an AJAX request returns the same rows as an HTML partial for
   live search.
4. The export control links to the same route with `format=csv|pdf` and the current
   filters preserved in the query string.
5. The export re-runs the identical query, maps rows through
   `ConsultationHistoryRows`, and streams a CSV or renders a PDF.

## 5. Routes

| Method | URI | Name |
|---|---|---|
| GET | `/consultations/history` | `consultations.history` |
| GET | `/consultations/history/export` | `consultations.history.export` |
| GET | `/nurses/{nurse}/consultation-history` | `nurse.consultation_history` |
| GET | `/nurses/{nurse}/consultation-history/export` | `nurse.consultation_history.export` |
| GET | `/physicians/{physician}/consultation-history` | `physician.consultation_history` |
| GET | `/physicians/{physician}/consultation-history/export` | `physician.consultation_history.export` |
| GET | `/nurses/{nurse}/dashboard/export` | `nurse.dashboard.export` |
| GET | `/physicians/{physician}/dashboard/export` | `physician.dashboard.export` |
| GET | `/admin/dashboard/export` | `admin.dashboard.export` |

The patient history **page** sits in the `auth`-only group; its **export** is
deliberately placed in `auth` + `verified`. The route comment says so: *"an export
is a deliberate download action and gets the stronger middleware even though its
HTML sibling currently does not (Phase 6)."*

## 6. Controllers

`ConsultationController::history/historyExport`,
`NurseController::consultationHistory/consultationHistoryExport/dashboardExport`,
`PhysicianController::consultationHistory/consultationHistoryExport/dashboardExport`,
`DashboardController::adminDashboardExport`, plus a private
`sanitizeExportFilename()` repeated in four controllers.

## 7. Services

| Class | Responsibility |
|---|---|
| `Export\ConsultationHistoryQuery` | Filter normalisation and query construction. *"Ownership is still supplied by the controller (`auth()->id()`); the service never touches Auth or authorizes anything."* |
| `Export\ConsultationHistoryRows` | Row mapping, per-role headers, the merged patient history list, `PDF_ROW_CAP = 500` |
| `Export\DashboardExportRows` | Maps analytics into export sections, **never recomputing a metric** |
| `Support\CsvDownload` | The only CSV producer in the application |
| `Support\DateRange` | Range resolution for dashboard exports |

## 8. Models

`Consultation` and its analytics scopes; `ConsultationSession`; `FollowUpRequest`
(rejected follow-ups are merged into patient history); `User` for names.

## 9. Database

Read-only. Physician history eager-loads `patient`, `nurse`, and
`consultationSession` — asserted by a dedicated test, because the export would
otherwise N+1.

## 10. Validation and Authorization

**Filter normalisation** is centralised and total — every unrecognised value falls
back to `all`:

| Constant | Values |
|---|---|
| `ALLOWED_DATE_FILTERS` | `today`, `last_7_days`, `last_30_days`, `all` |
| `ALLOWED_STATUS_FILTERS` | `completed`, `cancelled`, `rejected`, `all` |
| `ALLOWED_TYPE_FILTERS` | `follow_up`, **`general`**, `all` |

**`general` is a filter label only.** Its branch resolves to
`whereNull('type')->orWhere('type', '!=', 'follow_up')`, and the class docblock
explains the `whereNull` is for legacy rows. The stored enum value is `initial`.
The manuscript must not present `general` as a database value.

**Authorization** differs by role and is worth tabulating, because the patient
export is stricter than the patient page:

| Endpoint | Guard |
|---|---|
| `consultations.history` | Implicit — scoped to `auth()->id()`, with no role check |
| `consultations.history.export` | **Explicit** `if (auth()->user()?->role !== 'patient') abort(403)` |
| nurse/physician history and exports | `authorizeNurse()` / `authorizePhysician()` |
| dashboard exports | the same, plus `authorizeAdmin()` for admin |

The export's own docblock explains the asymmetry: *"the existing page's scoping
already prevents data leakage, but an export is a deliberate download action and
gets its own explicit role check."*

Every export additionally validates `format` against `['csv','pdf']`, aborting 422
otherwise.

## 11. Business Rules

| # | Rule | Enforced by | Enforcement type |
|---|---|---|---|
| BR-1 | An export and its page can never disagree | Both call the same `ConsultationHistoryQuery` and `ConsultationHistoryRows` methods | **Application (shared code)** |
| BR-2 | Unknown filter values degrade to `all` | `normalizeOne()` | **Application** |
| BR-3 | A patient sees only their own consultations | `forPatient($patientId, …)` | **Application** |
| BR-4 | Rejected follow-up requests are merged into patient history | `mergePatientHistoryItems()` | **Application** |
| BR-5 | Patient history orders by `submitted_at` desc; physician history by **`updated_at`** desc | Two different orderings, each tested | **Application** |
| BR-6 | PDF output is capped at 500 rows and reports truncation | `PDF_ROW_CAP`, with `totalCount`/`truncated`/`rowCap` passed to the view | **Application** |
| BR-7 | Every CSV cell is guarded against formula injection | `CsvDownload::guardRow()`, applied unconditionally | **Application (security boundary)** |
| BR-8 | CSV is streamed, never buffered | `streamDownload()` + `fputcsv()` | **Application** |
| BR-9 | CSV carries a UTF-8 BOM | `fwrite($out, "\xEF\xBB\xBF")` before any row | **Application** |
| BR-10 | Reports must not be cached | `no-store` headers on PDF, mirrored from `CsvDownload` | **Application** |
| BR-11 | Export mapping never recomputes a metric | `DashboardExportRows` class docblock | **Application (convention)** |
| BR-12 | k=3 symptom suppression carries into exports unchanged | *"the suppressed count is reported, the suppressed terms never are"* | **Application** |

No database constraint participates — this feature is entirely read-side.

**BR-7 is the security story worth telling.** The `CsvDownload` docblock names the
threat precisely: free-text fields flowing into an export are patient-controlled
because `ConsultationController::store` validates `symptoms_payload` only as
`required|string` with no per-entry validation, *"so a cell can legitimately start
with =, +, -, or @, which spreadsheet software treats as 'this cell is a
formula'."* The guard prefixes a single quote and runs *"here, unconditionally —
call sites never opt out of it and never re-implement it themselves."*
`DANGEROUS_PREFIXES` covers `=`, `+`, `-`, `@`, tab, and carriage return.

## 12. Concurrency

**None.** Read-only, no transactions, no locks.

## 13. Status / State Transitions

None. History reads the concluded statuses (`completed`, `cancelled`, `rejected`)
and never writes.

## 14. Error and Edge-Case Handling

| Case | Behaviour | Evidence |
|---|---|---|
| Unsupported `format` | 422 *"Unsupported export format."* | every export action |
| Non-patient hits the patient export | 403 | explicit role check |
| Another nurse's or physician's route id | 403 | `authorizeNurse()` / `authorizePhysician()` |
| Whitespace-only search | Treated as no search | *"treats a whitespace-only search as no search at all"* |
| Search with surrounding whitespace | Trimmed | dedicated test |
| Invalid filter combination | Each falls back to `all`, search key preserved | *"falls back to all for every invalid filter value and keeps the search key"* |
| More than 500 rows in a PDF | Truncated, with the total and cap shown | `PDF_ROW_CAP` |
| Non-ASCII symptom text in CSV | Renders correctly in Excel | UTF-8 BOM |
| Cell beginning with `=` | Prefixed with `'`, rendered as text | `CsvDownload` |
| Filename containing `/ : \ * ? " < > \|` | Replaced with `-` | `sanitizeExportFilename()` |
| Nurse dashboard | Has **no** history export control, because that route did not exist at the time | *"does not render a history export control on the nurse dashboard…"* and *"confirms the nurse consultation-history export route now exists, and admin's still does not"* |

## 15. UI Implementation

Shared components: `<x-dash.filter-bar>` and `<x-dash.export-menu>`.

Verified by `ConsultationHistoryExportUiTest` and `DashboardExportUiTest`:

- The export control renders on all three history pages and all three dashboards.
- Each links to **both** the CSV and PDF routes.
- **Current filters are preserved** in the export links, including `search` for
  nurse and physician — and `search` is **omitted** when no search is active.
- Export links are scoped to the authenticated owner: *"does not point one nurse's
  export link at another nurse's id"*, and the same for physicians.
- Dashboard export links carry the resolved preset, and carry `start`/`end` **only**
  when the preset is `custom` — reflecting the **clamped** range, not the raw
  requested end date.
- The dropdown trigger is *"a real button element"*, and the CSV and PDF options
  have *"meaningful visible text, not icon-only labels"* — accessibility details
  worth citing.

Physician and nurse history return an HTML **partial as JSON** for AJAX live
search (`partials/consultation_history_table.blade.php`), so the filter experience
does not reload the page.

PDF rendering uses `barryvdh/laravel-dompdf` with
`resources/views/exports/consultation-history.blade.php` (A4 landscape) and
`exports/dashboard.blade.php` (A4 portrait).

## 16. Tests

**7 files, 300 cases — the most heavily tested area of the entire system.**

| File | Cases |
|---|---|
| `tests/Feature/Export/ConsultationHistoryExportTest.php` | **87** |
| `tests/Feature/ConsultationHistoryTest.php` | **51** |
| `tests/Feature/Export/ConsultationHistoryQueryTest.php` | 50 |
| `tests/Feature/Export/DashboardPdfExportTest.php` | 41 |
| `tests/Feature/Export/DashboardExportTest.php` | 39 |
| `tests/Feature/ConsultationHistoryExportUiTest.php` | 17 |
| `tests/Feature/Export/CsvDownloadTest.php` | 16 |

`ConsultationHistoryTest` alone covers every date boundary in both directions
(day 6 vs day 8, day 29 vs day 31), each status filter, both type filters, invalid
fallbacks, cross-user isolation for all three roles, ordering, the merged rejected
follow-ups, eager loading, and the AJAX partial.

## 17. Source-Code Evidence

| Claim | Evidence |
|---|---|
| Page and export share one query | `ConsultationController::historyExport` docblock: *"both call ConsultationHistoryQuery and ConsultationHistoryRows::mergePatientHistoryItems(), so the export can never disagree with what the HTML page currently shows"* |
| The query service never authorizes | `ConsultationHistoryQuery` docblock |
| `general` is a filter label | `ALLOWED_TYPE_FILTERS`; the `if ($type === 'general')` branch resolving to `whereNull('type')->orWhere('type','!=','follow_up')` |
| Explicit role check on the patient export | `if (auth()->user()?->role !== 'patient') abort(403)` with its docblock |
| Stronger middleware on the export route | The comment above `consultations.history.export` in `routes/web.php` |
| Formula-injection guard | `CsvDownload::DANGEROUS_PREFIXES` and the class docblock |
| Streaming and BOM | `CsvDownload::stream()` |
| PDF cap | `ConsultationHistoryRows::PDF_ROW_CAP = 500` |
| No-store on PDF | The `withHeaders([...])` block, with its comment mirroring `CsvDownload` |
| Mapper never recomputes | `DashboardExportRows` class docblock |
| Filename sanitisation | `sanitizeExportFilename()` and its docblock |

## 18. Limitations / Gaps

| # | Limitation |
|---|---|
| EX-1 | **Admin has no consultation-history export.** A test asserts this explicitly as current behaviour. Admins get only the dashboard export, so system-wide consultation-level data cannot be exported by anyone. |
| EX-2 | **The patient history page is not `verified`-gated, though its export is.** The route comment acknowledges the inconsistency and marks it "Phase 6". |
| EX-3 | **`sanitizeExportFilename()` is duplicated four times**, identically, in `ConsultationController`, `NurseController`, `PhysicianController`, and `DashboardController` — each with the same docblock. |
| EX-4 | **`ALLOWED_DATE_FILTERS` exists twice.** `ConsultationHistoryQuery` and `NotificationController` each define their own copy. The latter's docblock argues the duplication is deliberate — notifications should not change in lockstep with history filtering — which is defensible but must be described as a decision, not an accident. |
| EX-5 | **PDF truncation is silent to the query.** Beyond 500 rows the PDF reports the cap, but the full result set was still built in memory before slicing. The cap protects the rendered document, not the request. |
| EX-6 | **CSV injection is guarded; PDF is not the same problem but is unbounded in width.** Long free-text symptom values are rendered into a landscape A4 table with no column truncation. |
| EX-7 | **Two different orderings across roles.** Patient history sorts by `submitted_at`, physician history by `updated_at`. Both are tested and deliberate, but a reader comparing two exports of the same consultation will see them in different relative positions. |
| EX-8 | **Export authorization does not restrict date range.** Any role may export `date_filter=all`, producing their entire history in one file. There is no row limit on CSV and no rate limit on any export route. |
