# Clinical Documentation

> Terminology follows `docs/paper/glossary.md`. Prescription file handling is
> documented in `attachments-and-prescriptions.md`; this file covers the clinical
> text fields and the "meaningful content" rules around them. Figure and table
> numbers use the `X.n` placeholder pending final manuscript numbering.

## 1. Feature Name

Clinical Documentation — the assessment, plan, recommendations, and diagnosis a
physician records against a **consultation session**.

## 2. Purpose

To capture the clinical record of the encounter. The interesting design problem
here is not capture but **emptiness**: three of the four columns are `NOT NULL` and
are seeded with placeholder sentences when the session is created, so "has the
physician documented anything?" cannot be answered with a null check.

## 3. Actors / Roles

| Actor | Involvement |
|---|---|
| Physician | The **assigned** physician only. May write while the session is `active`. |
| Patient | May read the resulting record through the messaging screen and consultation history. |
| Nurse | No access — excluded by `ConsultationSessionPolicy`. |

## 4. User Workflow

1. The physician opens the **Assessment** tab on the messaging screen.
2. They fill in assessment, plan, recommendations, and diagnosis, and optionally
   attach a prescription file.
3. A POST to `.../clinical-details` validates and saves all four fields together,
   returning a rebuilt `clinical_details` payload.
4. The record becomes read-only once the consultation is completed.

## 5. Routes

| Method | URI | Name |
|---|---|---|
| POST | `/consultation-sessions/{session}/clinical-details` | `consultations.messaging.clinical_details.update` |

There is no separate GET — the payload is rebuilt and returned by the same POST,
and rendered server-side on first page load.

## 6. Controllers

`ConsultationMessageController::updateClinicalDetails`, plus the private
`buildClinicalDetailsPayload` and `deletePrescriptionFile`.

## 7. Services

**None.**

## 8. Models

`App\Models\ConsultationSession` supplies six predicates that define what
"documented" means:

| Method | Meaning |
|---|---|
| `hasMeaningfulAssessment()` | assessment is present and not the seeded placeholder |
| `hasMeaningfulPlan()` | same for plan |
| `hasMeaningfulRecommendations()` | same for recommendations |
| `hasDiagnosis()` | diagnosis is non-empty |
| `hasPrescription()` | a prescription file path exists |
| `hasClinicalDocumentation()` | any of the above |

These exist because the placeholders written at session creation are real strings,
not nulls.

## 9. Database

Four columns on `consultations`, from `2026_07_20_104502_create_consultations_table.php`:

| Column | Type | Null | Seeded at creation with |
|---|---|---|---|
| `assessment` | text | **NOT NULL** | `'Initial assessment pending.'` |
| `plan` | text | **NOT NULL** | `'Plan to be documented during consultation.'` |
| `recommendations` | text | **NOT NULL** | `'Recommendations to follow after evaluation.'` |
| `diagnosis` | string | nullable | not seeded |

The three `NOT NULL` columns are why `ConsultationOwnershipService::scheduleByPhysician()`
and `::startByPhysician()` — and `PhysicianController::activeConsultations()`'s
backfill — all write the same three placeholder sentences when creating a session.

Also on the same table and written by this endpoint: the four prescription columns.

## 10. Validation and Authorization

**Three layers, in order:**

```php
$this->authorize('viewMessaging', $session);                          // policy

abort_if($session->consultation_status !== 'active',
         Response::HTTP_FORBIDDEN,
         'Clinical details can only be updated while the consultation is active.');

abort_unless(
    Auth::user()->role === 'physician' && (int) $session->physician_id === (int) Auth::user()->user_id,
    403,
    'Only the assigned physician can update clinical details.'
);
```

The policy alone would admit the patient, so the third check is what makes this
physician-only. Note the status check returns **403**, not 422 — a deliberate
difference from `complete()`, which returns 422 for the same kind of condition.

**Validation:**

| Field | Rule |
|---|---|
| `assessment`, `plan`, `recommendations` | nullable, string, max **10000** |
| `diagnosis` | nullable, string, max **255** |
| `prescription` | nullable, file, max 10240 KB, `mimes:pdf,jpg,jpeg,png,doc,docx` |
| `remove_prescription` | nullable, boolean |

The 255 cap on `diagnosis` matches its `string` column; the 10000 caps on the three
text fields are application-only, since `text` holds far more.

## 11. Business Rules

| # | Rule | Enforced by | Enforcement type |
|---|---|---|---|
| BR-1 | Only the assigned physician may write | `abort_unless` | **Application** |
| BR-2 | Writable only while the session is `active` | `abort_if` | **Application** |
| BR-3 | All four fields are written together | One `fill()` with all four keys | **Application** |
| BR-4 | An omitted field is written as **null**, not left alone | `$validated['assessment'] ?? null` for each | **Application** |
| BR-5 | Placeholder text is not meaningful content | `hasMeaningfulAssessment()` and siblings, each delegating to a shared `hasMeaningfulText($value, [$placeholder])` with the placeholder given in lowercase — so the comparison is case-insensitive against the exact seeded sentence | **Application** |
| BR-6 | Removing a prescription clears all four prescription columns | The `remove_prescription` branch | **Application** |
| BR-7 | Uploading a prescription replaces the previous file | `deletePrescriptionFile()` then `forceFill` | **Application** |
| BR-8 | `diagnosis` is capped at 255 characters | Validation **and** the `string` column length | **Application and database** |

BR-8 is the only rule with database participation, and only incidentally.

**BR-4 deserves emphasis.** The endpoint is a **full replace, not a patch**:

```php
$session->fill([
    'assessment'      => $validated['assessment'] ?? null,
    'plan'            => $validated['plan'] ?? null,
    'recommendations' => $validated['recommendations'] ?? null,
    'diagnosis'       => $validated['diagnosis'] ?? null,
]);
```

A request that omits `plan` sets `plan` to null. Combined with the `NOT NULL`
constraint on three of those columns, this has a real consequence — see gap CD-1.

## 12. Concurrency

**None.** No transaction, no lock. Two writes from the assigned physician in two
tabs are last-write-wins across all four fields simultaneously, because of BR-4.

No other actor can write these columns, so there is no cross-actor race. The
`active`-only rule (BR-2) additionally means a completed consultation's record
cannot be altered.

## 13. Status / State Transitions

The clinical record has no status of its own. Its **writability** is derived:

| Session status | Read | Write |
|---|---|---|
| `scheduled` | no (policy refuses messaging) | no |
| `active` | yes | yes |
| `completed` | yes | **no** |

*Table X.5 — Clinical documentation access by session status.*

`buildClinicalDetailsPayload()` returns `status` and `completed_at` alongside the
fields, so the client can render the read-only state without a second request.

## 14. Error and Edge-Case Handling

| Case | Response | Evidence |
|---|---|---|
| Non-assigned physician | 403 *"Only the assigned physician can update clinical details."* | `abort_unless` |
| Patient attempts a write | 403 — passes the policy, fails the role check | Same |
| Nurse attempts | 403 at the policy | `ConsultationSessionPolicy::viewMessaging` |
| Session not `active` | 403 *"Clinical details can only be updated while the consultation is active."* | `abort_if` |
| Diagnosis over 255 characters | 422 | Validation |
| Prescription of a disallowed type | 422 | `mimes:` rule |
| `remove_prescription` sent **with** a new file | The removal branch is skipped (`&& !$request->hasFile('prescription')`) and the upload wins | `updateClinicalDetails` |
| Cloudinary unavailable | Prescription falls back to the private disk | See `attachments-and-prescriptions.md` |

## 15. UI Implementation

The **Assessment** tab in `messaging.blade.php`. Verified from the view:

- The tab is one of four (`messages`, `details`, `patient`, `assessment`) and sets
  `assessmentTabOpened = true` on first click.
- It is headed *"Clinical Documentation"* with the subtitle *"Assessment, plan,
  recommendations, diagnosis, and prescription"*.
- A `role="status" aria-live="polite"` region announces `saveMessage` after a save —
  an accessibility detail worth noting in the manuscript.
- When the session is completed, an `<x-dash.badge status="completed" size="sm" />`
  renders in the header.
- The physician can cancel a selected prescription file before saving, and the
  prescription preview popup gates thumbnails to images only — both asserted by
  test.
- On completion the Alpine component switches `activeTab = 'assessment'`, so the
  physician lands on the record they just finalised.

The same tab also renders the **Patient** tab's static panels — Immunization
History, Family Medical History, and the "CHIS Sync Status" card — which are
hardcoded "No Data" placeholders with **no data source**. They are not part of this
feature and must not be described as clinical data. See `00-feature-inventory.md`,
*Not Implemented*.

## 16. Tests

| File | Relevance |
|---|---|
| `tests/Feature/ConsultationMessagingUiTest.php` | 11 cases; two directly relevant — the prescription preview popup and its image-only thumbnail gates, and the physician cancelling a selected prescription before saving |
| `tests/Feature/ConsultationCompletionVideoTest.php` | Establishes the completed state this feature becomes read-only in |

**Coverage gap — the largest in Group D.** There is **no test file for
`updateClinicalDetails`**. None of the following is asserted anywhere: the
assigned-physician restriction (BR-1), the active-only restriction (BR-2), the
full-replace semantics (BR-4), the `remove_prescription` behaviour (BR-6), the
prescription replace path (BR-7), the 10000/255 caps, or any of the six
`hasMeaningful*()` predicates. This is the clinical record of the consultation, and
it is the least-tested feature documented so far.

## 17. Source-Code Evidence

| Claim | Evidence |
|---|---|
| Three-layer authorization | `updateClinicalDetails` — `authorize`, `abort_if`, `abort_unless`, in that order |
| Full replace, not patch | The `fill()` call with `?? null` on all four keys |
| Placeholder seeding | `ConsultationOwnershipService::scheduleByPhysician` and `::startByPhysician`; `PhysicianController::activeConsultations` |
| `NOT NULL` on three columns | `2026_07_20_104502_create_consultations_table.php` — `$table->text('assessment')` etc. with no `->nullable()` |
| Meaningfulness predicates | `ConsultationSession::hasMeaningfulAssessment/Plan/Recommendations/hasDiagnosis/hasPrescription/hasClinicalDocumentation` |
| Payload shape | `buildClinicalDetailsPayload()` |
| Removal requires no new file | `if ($removePrescription && !$request->hasFile('prescription'))` |
| 403 vs 422 asymmetry | `abort_if(..., Response::HTTP_FORBIDDEN, ...)` here versus `abort_if(..., Response::HTTP_UNPROCESSABLE_ENTITY, ...)` in `complete()` |

## 18. Limitations / Gaps

| # | Limitation |
|---|---|
| **CD-1** | **The endpoint can write null into `NOT NULL` columns.** BR-4 maps an omitted field to null, and the validation rules mark all three `nullable` — but the columns are not. Verified against the live MySQL schema with `SHOW COLUMNS FROM consultations`: `assessment`, `plan`, and `recommendations` each report `Null = NO` with `Default = NULL`. A save that omits one of them — a partial form submission, or a hand-built request — is accepted by validation and then rejected by the database as an integrity-constraint violation, surfacing as a 500 rather than a 422. The UI always submits all four fields, so this is unreachable through the interface; it is unguarded at the application layer nonetheless. With no test exercising this endpoint at all (CD-2), nothing would catch a regression that made it reachable. |
| CD-2 | **No test coverage whatsoever.** The clinical record — the most defensible "this is a medical system" artifact in the project — has no dedicated test file. |
| CD-3 | **Placeholders are indistinguishable from real text at the database level.** Any SQL or reporting query that treats a non-null `assessment` as "documented" will count every session ever created. Only the `hasMeaningful*()` predicates encode the distinction, and they live in PHP. |
| CD-4 | **No revision history.** Each save overwrites in place. There is no audit of who wrote what when, beyond `consultations.updated_at`, and no way to recover a field a physician accidentally cleared. |
| CD-5 | **No transaction around the prescription replace.** `deletePrescriptionFile()` deletes the old file before `$session->save()` commits. See gap AP-5. |
| CD-6 | **Status-check HTTP codes are inconsistent.** This endpoint returns 403 for a non-`active` session; `complete()` returns 422 for the same class of condition. A client cannot rely on the code to distinguish "not allowed" from "wrong state". |
| CD-7 | **The 10000-character caps are application-only.** The `text` columns accept far more, so a direct write bypasses them. |
