# Admin User Management

> Terminology follows `docs/paper/glossary.md`. Figure and table numbers use the
> `X.n` placeholder pending final manuscript numbering.

## 1. Feature Name

Admin User Management — the administrator's listing, creation, and editing of all
user accounts across the four roles.

## 2. Purpose

To give an administrator a single place to provision staff, correct account
details, and change account status, while preventing the administrator from
short-circuiting the two rules the system depends on: that invited staff set their
own passwords, and that account status reflects what actually happened rather than
what an admin asserted.

## 3. Actors / Roles

| Actor | Involvement |
|---|---|
| Admin | The only actor. Every method begins with `authorizeAdmin()`. |
| Nurse, Physician, Patient | Subjects of management, never operators. Any attempt returns 403. |

## 4. User Workflow

**Listing.** `GET /admin/users` renders every user ordered by `created_at`
descending, each row annotated with a derived invitation state and, where
applicable, a resend button.

**Creation.** `GET /admin/users/create` → submit → `POST /admin/users`. The
controller branches on the submitted role:

- **Invited path** (`nurse`, `physician`): no password is requested or accepted;
  the account is created `inactive` and unverified with a discarded random
  password, and an invitation is issued and emailed. Detailed in
  `staff-invitation-and-activation.md`.
- **Direct path** (`patient`, `admin`): a password is required and confirmed, and
  `account_status` may be `active` or `inactive`. An `admin` is additionally
  stamped verified at creation.

**Editing.** `GET /admin/users/{user}/edit` → submit → `PUT /admin/users/{user}`.
The available status options depend on the account's *stored* status, and an email
change revokes any outstanding invitation inside the same transaction as the save.

## 5. Routes

All in `routes/web.php`, inside the `auth` + `verified` group.

| Method | URI | Name | Extra middleware |
|---|---|---|---|
| GET | `/admin/users` | `admin.users.index` | — |
| GET | `/admin/users/create` | `admin.users.create` | — |
| POST | `/admin/users` | `admin.users.store` | — |
| GET | `/admin/users/{user}/edit` | `admin.users.edit` | — |
| PUT | `/admin/users/{user}` | `admin.users.update` | — |
| POST | `/admin/users/{user}/resend-invitation` | `admin.users.resend_invitation` | `throttle:30,1` |

The throttle on resend is the only rate limit in the group. The route comment
explains the choice: generous enough to invite a batch of staff in one sitting,
tight enough to bound mailbox spam if the session is misused.

Note there is **no destroy route**. Accounts cannot be deleted through this
feature.

## 6. Controllers

`App\Http\Controllers\Admin\UserManagementController` (358 lines) — public
`index`, `create`, `store`, `edit`, `update`, `resendInvitation`; private
`authorizeAdmin`, `invitationStates`, `broker`.

## 7. Services

**None.** The controller talks to `User`, the `staff_invitations` password broker,
`DB`, `Hash`, `Log`, and the `StaffAccountInvitation` notification directly. There
is no user-management service layer.

## 8. Models

`App\Models\User` — `INVITED_ROLES`, `awaitsStaffActivation()`, and the `booted()`
creating hook that verifies admins on creation. `$fillable` governs what `fill()`
may write during update.

`App\Notifications\StaffAccountInvitation` — sent from `store()` and
`resendInvitation()`.

## 9. Database

Reads and writes `users` (PK `user_id`). Reads
`staff_invitation_tokens.created_at` for the listing, and writes it indirectly
through the broker.

Fields written by this feature: `first_name`, `last_name`, `email`, `role`,
`clsu_id`, `user_type`, `department`, `contact_num`, `staff_position`,
`specialization`, `online_status`, `password`, `account_status`,
`email_verified_at`.

Two defaults are applied on creation and are worth noting for the data dictionary,
because they mean these columns are rarely null in practice regardless of what the
schema allows:

- `user_type` is written as the literal `'staff'` for **every** admin-created
  account, including patients and admins.
- `department` falls back to the literal `'General'` when the field is left blank.

## 10. Validation and Authorization

**Authorization.** Every public method calls `authorizeAdmin()`:

```php
if (Auth::user()->role !== 'admin') {
    abort(403, 'Unauthorized access.');
}
```

This is **controller-level authorization, not a policy.** No policy is registered
for `User` (`AppServiceProvider::boot` registers only `ConsultationPolicy` and
`ConsultationSessionPolicy`).

**Creation rules** (`store`):

| Field | Rule |
|---|---|
| `first_name`, `last_name` | required, string, max 100 |
| `email` | required, email, max 150, `unique:users,email` |
| `role` | required, `in:patient,nurse,physician,admin` |
| `clsu_id` | nullable, max 50 |
| `department` | nullable, max 100 |
| `contact_num` | nullable, max 20 |
| `staff_position`, `specialization` | nullable, max 100 |
| `account_status` | invited path: `nullable, in:inactive`. Direct path: `required, in:active,inactive` |
| `password` | direct path only: `required, string, min:8, confirmed` |

**Update rules** (`update`): the same name/email/role/profile rules, with `email`
uniqueness scoped to the current record via
`'unique:users,email,'.$user->getKey().','.$user->getKeyName()` — necessary because
the primary key is `user_id`, not `id`.

`account_status` is conditional on the **stored** value:

```php
$rules['account_status'] = $user->account_status === 'inactive'
    ? ['nullable', 'in:inactive']
    : ['required', 'in:active,suspended'];
```

The comment explains the semantics: `inactive` means "awaiting email verification"
and is set only by the verification or activation flow, never chosen by an admin.
While an account is in that state the edit form renders no status field at all, so
nothing is submitted and `fill()` leaves the existing value untouched. Once
verified, an admin may only toggle between `active` and `suspended`.

## 11. Business Rules

| # | Rule | Enforced by | Enforcement type |
|---|---|---|---|
| BR-1 | Only admins may reach any part of this feature | `authorizeAdmin()` in all six public methods | **Application** |
| BR-2 | The submitted role alone decides the invitation path | `$invited = in_array($request->input('role'), self::INVITED_ROLES, true)`; there is no opt-in flag | **Application** |
| BR-3 | An admin may not pre-activate an invited account at creation | `account_status` rule `['nullable','in:inactive']` on the invited path | **Application (validation)** |
| BR-4 | An admin may not flip a pending staff account to active | `update()` throws `ValidationException` when `$activating && email_verified_at === null && role ∈ INVITED_ROLES` | **Application** |
| BR-5 | An active account cannot be set back to `inactive` | Update rule `in:active,suspended` once the account is not inactive | **Application (validation)** |
| BR-6 | Email changes revoke the outstanding invitation | `deleteToken($user)` before `fill()`, inside the update transaction | **Application (transaction)** |
| BR-7 | Email addresses stay unique | `users.email` unique index **and** the scoped `unique:` rule | **Database and application** |
| BR-8 | Invitation state is derived, never stored | `invitationStates()` reads `staff_invitation_tokens.created_at` | **Application** |
| BR-9 | Admins are verified on save as well as on create | `update()` repeats the `email_verified_at = now()` stamp for role `admin` | **Application** |
| BR-10 | A patient cannot escalate their own role | `authorizeAdmin()` blocks the endpoint entirely | **Application**, asserted by test |

**Database-enforced vs application-enforced.** BR-7 is the only rule with a
database constraint behind it. Every other rule in this table — including the
activation guard and the revocation-on-rename — is application logic inside this
one controller. A direct `UPDATE users SET account_status = 'active'` would bypass
all of them.

## 12. Status / State Transitions

This feature drives `users.account_status`. Which transitions an admin may perform
depends on the account's stored state, and two edges are deliberately unreachable
from this feature.

| From | To | Allowed here? | Mechanism |
|---|---|---|---|
| (none) | `active` | Yes, direct path only | `store()` for patient/admin |
| (none) | `inactive` | Yes | `store()`, either path |
| `inactive` | `active` | **No** | Belongs to the activation flow (BR-4) |
| `active` | `suspended` | Yes | `update()` |
| `suspended` | `active` | Yes | `update()` |
| `active` | `inactive` | **No** | Blocked by the conditional rule (BR-5) |

*Table X.3 — Account status transitions available to an administrator.*

The listing additionally shows three **derived display states** for accounts
awaiting activation: `missing` ("Not sent"), `pending` ("Expires …"), and
`expired` ("Expired"). These are computed per request in `invitationStates()` and
are **not** stored anywhere — do not present them as a database enum.

## 13. Error and Edge-Case Handling

| Case | Behaviour | Evidence |
|---|---|---|
| Non-admin reaches any endpoint | `abort(403)` | `UserManagementAuthorizationTest`: six cases across list, create form, create, edit form, update |
| Patient attempts self-escalation to admin | 403 before any write | "a patient cannot escalate their own role via update" |
| Duplicate email on create | Validation failure; **no invitation row left behind** | `StaffAccountCreationTest` |
| Invitation write fails during create | The whole transaction rolls back; the user is not created | "the user is rolled back if the invitation cannot be written" |
| Mail transport failure | Caught; admin sees a `warning` flash; account and invitation persist | `StaffInvitationMailFailureTest` |
| Admin tries to activate a pending staff account | `ValidationException` with an explanatory message | `StaffInvitationRevocationTest`: "an admin cannot flip a pending staff account to active", and the same block "applies even once the invitation has expired" |
| Admin tries to set an active account inactive | Validation failure | `StaffAccountCreationTest`: "an admin cannot set an active account to inactive" |
| Editing unrelated fields on an invited account | Invitation untouched and still works | `StaffInvitationRevocationTest` |
| Resubmitting the same email | Invitation **not** revoked — the comparison is `!==`, so an unchanged address is a no-op | "resubmitting the same email does not revoke the invitation" |
| Update fails after `deleteToken` | The invitation deletion rolls back with it | "the invitation deletion rolls back if the user update fails" |
| Editing an invited nurse | Does not silently verify them | `StaffAccountCreationTest` |
| Resend for a nonexistent user | 404 via route-model binding | `StaffInvitationResendTest` |

## 14. UI Implementation

Verified by reading the three Blade views.

**`resources/views/admin/users/index.blade.php`** (85 lines) — renders
`session('status')` and `session('warning')` banners, a "create" link, then a table
of `$users`. Per row it resolves
`@php($invitation = $invitations[$user->user_id] ?? null)`, shows the invitation
label inside `@if($invitation)`, an Edit link, and — again only inside
`@if($invitation)` — a POST form to `route('admin.users.resend_invitation', $user)`.
The resend control is therefore invisible for any account not awaiting activation,
matching the controller's eligibility rule rather than duplicating it.

**`resources/views/admin/users/create.blade.php`** (82 lines) — posts to
`route('admin.users.store')`, renders an `$errors->any()` summary block, and
carries inputs for `first_name`, `last_name`, `email`, a `role` select, `clsu_id`,
`department`, `contact_num`, `staff_position`, `specialization`. **No password
input exists in the markup for any role** — meaning the direct path's `password`
rule cannot currently be satisfied through this form (see gap AU-1).

**`resources/views/admin/users/edit.blade.php`** (76 lines) — posts to
`route('admin.users.update', $user)`. Carries `first_name`, `last_name`, `email`,
a `role` select, `department`, `staff_position`, `specialization`, and the
conditional status control: `@if($user->account_status === 'inactive')` renders
non-editable text, `@else` renders a `select name="account_status"`. The view and
the controller's conditional validation rule agree.

Note the edit form omits `clsu_id` and `contact_num`, which the create form
includes and the update rules do not mention — so neither can be changed after
creation through this UI.

No Alpine or JavaScript governs this feature; the views are plain server-rendered
forms.

## 15. Tests

| File | Cases | Relevance |
|---|---|---|
| `tests/Feature/Admin/UserManagementAuthorizationTest.php` | 6 | The authorization boundary: non-admins blocked on all five endpoints; admins can still operate |
| `tests/Feature/Admin/StaffAccountCreationTest.php` | 21 | Creation across all four roles, both paths, rollback, listing rendering, status guards |
| `tests/Feature/Admin/StaffInvitationRevocationTest.php` | 13 | Update behaviour: email-change revocation, the activation guard, rollback |
| `tests/Feature/Admin/StaffInvitationResendTest.php` | 23 | Resend eligibility, authorization, input-tampering resistance, listing display |

The remaining invitation-focused files are covered in
`staff-invitation-and-activation.md`.

## 16. Source-Code Evidence

| Claim | Evidence |
|---|---|
| Admin-only authorization, no policy | `UserManagementController::authorizeAdmin()`; `AppServiceProvider::boot` registers no `User` policy |
| Role decides the path | `UserManagementController::store`, `$invited = in_array(...)` |
| Invited accounts cannot be pre-activated | `$rules['account_status'] = ['nullable', 'in:inactive']` |
| Conditional status rules on edit | `$rules['account_status'] = $user->account_status === 'inactive' ? ... : ...` |
| Activation guard | `if ($activating && $user->email_verified_at === null && in_array($user->role, self::INVITED_ROLES, true))` |
| Revoke before rename | `$emailChanged = $validated['email'] !== $user->email;` then `deleteToken()` before `fill()` inside `DB::transaction` |
| Derived invitation state | `UserManagementController::invitationStates()` with its "second source of truth" docblock |
| Single query for invitation state | `DB::table('staff_invitation_tokens')->pluck('created_at', 'email')` |
| Email uniqueness scoped by custom PK | `'unique:users,email,'.$user->getKey().','.$user->getKeyName()` |
| `user_type` and `department` defaults | `'user_type' => 'staff'`, `'department' => ... ?: 'General'` |
| Resend throttle | `routes/web.php`, `->middleware('throttle:30,1')` |

## 17. Limitations / Gaps

| # | Limitation |
|---|---|
| AU-1 | **The direct-creation path is unreachable through the UI.** `store()` requires `password` (required, min 8, confirmed) for `patient` and `admin` roles, but `admin/users/create.blade.php` contains no password input. Patients and admins can therefore only be created programmatically or by a hand-built request — the tests exercise this path directly. The form works for the two invited roles it was redesigned around. |
| AU-2 | **Accounts cannot be deleted.** There is no destroy route, controller method, or UI control, and `ProfileTest` confirms self-deletion was removed too. Deactivation is by `suspended` status only. This has a data-retention consequence worth stating in the manuscript. |
| AU-3 | **`clsu_id` and `contact_num` are create-only.** Both appear on the create form but on neither the edit form nor the update rule set, so neither can be corrected after creation through the interface. |
| AU-4 | **`user_type` is written as `'staff'` for every admin-created account**, including patients and admins, so the column does not distinguish what it was designed to distinguish. See also gap A-6 in `authentication-and-registration.md`. |
| AU-5 | **`department` silently defaults to `'General'`.** A blank field is stored as a real value rather than null, so "General" cannot be distinguished from a deliberate choice. |
| AU-6 | **Authorization is duplicated, not centralized.** `authorizeAdmin()` is a private method repeated in both this controller and `DashboardController`. Adding an admin endpoint elsewhere requires remembering to repeat it; no policy or middleware enforces it. |
| AU-7 | **`INVITED_ROLES` exists twice.** Duplicated between `User::INVITED_ROLES` and this controller's private constant, kept in step only by a comment. |
| AU-8 | **No audit log.** Nothing records which admin created, edited, suspended, or reinstated an account, or when. The only temporal evidence is `users.created_at` / `updated_at`. |
