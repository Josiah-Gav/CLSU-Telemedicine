<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\StaffAccountInvitation;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class UserManagementController extends Controller
{
    /**
     * Roles that are provisioned by invitation rather than by the admin
     * choosing a password. Kept in step with StaffInvitationController.
     */
    private const INVITED_ROLES = ['nurse', 'physician'];

    private function authorizeAdmin(): void
    {
        if (Auth::user()->role !== 'admin') {
            abort(403, 'Unauthorized access.');
        }
    }

    public function index(): View
    {
        $this->authorizeAdmin();

        $users = User::orderBy('created_at', 'desc')->get();

        return view('admin.users.index', [
            'users' => $users,
            'invitations' => self::invitationStates($users),
        ]);
    }

    /**
     * Derive each account's invitation state for the listing.
     *
     * Read from the user record and staff_invitation_tokens.created_at, never
     * from a stored status column: a column would be a second source of truth
     * able to drift from the tokens the activation flow actually honours.
     *
     * Public and static (no $this usage) so DashboardController's admin
     * "Needs Attention" band can derive the same pending/expired counts
     * without a second, potentially divergent definition of "pending".
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, array{state: string, label: string}|null>
     */
    public static function invitationStates($users)
    {
        // One query for every outstanding invitation rather than one per row.
        $issuedAt = DB::table('staff_invitation_tokens')->pluck('created_at', 'email');
        $lifetime = (int) config('auth.passwords.staff_invitations.expire');

        return $users->mapWithKeys(function (User $user) use ($issuedAt, $lifetime): array {
            if (! $user->awaitsStaffActivation()) {
                return [$user->user_id => null];
            }

            $created = $issuedAt[$user->email] ?? null;

            if ($created === null) {
                return [$user->user_id => ['state' => 'missing', 'label' => 'Not sent']];
            }

            $expiresAt = Carbon::parse($created)->addMinutes($lifetime);

            return [$user->user_id => $expiresAt->isPast()
                ? ['state' => 'expired', 'label' => 'Expired']
                : ['state' => 'pending', 'label' => 'Expires '.$expiresAt->diffForHumans()],
            ];
        });
    }

    public function create(): View
    {
        $this->authorizeAdmin();

        return view('admin.users.create');
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin();

        // The submitted role decides which path applies; there is no separate
        // flag a caller could use to opt into the invitation path.
        $invited = in_array($request->input('role'), self::INVITED_ROLES, true);

        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'role' => ['required', 'in:patient,nurse,physician,admin'],
            'clsu_id' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:100'],
            'contact_num' => ['nullable', 'string', 'max:20'],
            'staff_position' => ['nullable', 'string', 'max:100'],
            'specialization' => ['nullable', 'string', 'max:100'],
        ];

        if ($invited) {
            // Invited staff always start inactive; an admin may not pre-activate
            // an account whose owner has not set a password yet.
            $rules['account_status'] = ['nullable', 'in:inactive'];
        } else {
            $rules['password'] = ['required', 'string', 'min:8', 'confirmed'];
            $rules['account_status'] = ['required', 'in:active,inactive'];
        }

        $validated = $request->validate($rules);

        $payload = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'role' => $validated['role'],
            'clsu_id' => ! empty($validated['clsu_id']) ? $validated['clsu_id'] : null,
            // No form field submits this any more; every admin-created staff
            // account is simply 'staff'.
            'user_type' => 'staff',
            'department' => ! empty($validated['department']) ? $validated['department'] : 'General',
            'contact_num' => ! empty($validated['contact_num']) ? $validated['contact_num'] : null,
            'staff_position' => ! empty($validated['staff_position']) ? $validated['staff_position'] : null,
            'specialization' => ! empty($validated['specialization']) ? $validated['specialization'] : null,
            'online_status' => 'offline',
        ];

        if ($invited) {
            $payload += [
                // Not a temporary password: a discarded random value that nobody
                // ever learns, because the column is NOT NULL. Login is blocked
                // by account_status anyway, and activation overwrites this.
                'password' => Hash::make(Str::random(64)),
                'account_status' => 'inactive',
                'email_verified_at' => null,
            ];
        } else {
            $payload += [
                'password' => Hash::make($validated['password']),
                'account_status' => $validated['account_status'],
                'email_verified_at' => $validated['role'] === 'admin' ? now() : null,
            ];
        }

        // The account and its invitation are written together: if the token
        // insert fails the user must not be left behind unable to activate.
        // The plaintext token is only obtainable from createToken()'s return
        // value (the table stores a hash), so it is captured here for the
        // email and never re-fetched from the database.
        [$user, $invitationToken] = DB::transaction(function () use ($payload, $invited): array {
            $user = User::create($payload);

            $token = $invited ? Password::broker('staff_invitations')->createToken($user) : null;

            return [$user, $token];
        });

        if (! $invited) {
            return Redirect::route('admin.users.index')->with('status', 'User created successfully.');
        }

        // Sent only after DB::transaction() has returned, i.e. after the
        // account and invitation have committed — never before, and never if
        // the transaction rolled back and threw instead of reaching here.
        //
        // A transport failure must not roll anything back: the account and its
        // invitation are already committed and both stay valid, so a future
        // resend can use them. The admin is told delivery failed rather than
        // being shown a 500 that leaves them guessing what was saved.
        try {
            $user->notify(new StaffAccountInvitation($user, $invitationToken));
        } catch (Throwable $exception) {
            // Only the exception class is recorded. Symfony transport failures
            // embed the SMTP username in their message, and the notification
            // carries the plaintext invitation token — neither belongs in a log.
            Log::error('Staff invitation email could not be sent.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            return Redirect::route('admin.users.index')->with(
                'warning',
                'Staff account created, but the invitation email could not be sent. The account remains inactive.'
            );
        }

        return Redirect::route('admin.users.index')->with(
            'status',
            'Staff account created and invitation email sent. The invitation is valid for 7 days.'
        );
    }

    /**
     * Issue a fresh invitation for a staff account that has not activated yet.
     *
     * Recovers every dead end the invitation flow can reach: an expired link, a
     * failed first email, a link the staff member lost, and an invitation this
     * controller revoked when an admin corrected the address.
     */
    public function resendInvitation(User $user)
    {
        $this->authorizeAdmin();

        // Judged on the stored record only. Nothing about the target comes from
        // the request body: the user is resolved by route model binding, and
        // role and status are read from the row.
        if (! $user->awaitsStaffActivation()) {
            return Redirect::route('admin.users.index')->with(
                'warning',
                'That account is not awaiting activation, so no invitation was sent.'
            );
        }

        // A token issued moments ago means a double-click or a duplicate submit.
        // Issuing another would send a second email and silently kill the link
        // in the first, which is the more confusing outcome.
        if ($this->broker()->getRepository()->recentlyCreatedToken($user)) {
            return Redirect::route('admin.users.index')->with(
                'warning',
                'An invitation was already sent to '.$user->email.' moments ago. Please wait a minute before sending another.'
            );
        }

        // createToken() deletes any existing row for this address before
        // inserting, so rotation is atomic within the repository and there is
        // never a moment when two invitations are valid. The row is locked so
        // two admins resending at once serialize rather than racing the
        // delete/insert pair against each other.
        $invitationToken = DB::transaction(function () use ($user): ?string {
            $locked = User::where('user_id', $user->user_id)->lockForUpdate()->first();

            // Re-checked under the lock: the account may have been activated or
            // suspended between the check above and acquiring the row.
            if (! $locked instanceof User || ! $locked->awaitsStaffActivation()) {
                return null;
            }

            return $this->broker()->createToken($locked);
        });

        if ($invitationToken === null) {
            return Redirect::route('admin.users.index')->with(
                'warning',
                'That account is not awaiting activation, so no invitation was sent.'
            );
        }

        // After the commit, exactly as in store(): a transport failure must not
        // discard an invitation that is already valid and usable.
        try {
            $user->notify(new StaffAccountInvitation($user, $invitationToken));
        } catch (Throwable $exception) {
            Log::error('Staff invitation email could not be resent.', [
                'user_id' => $user->user_id,
                'exception' => $exception::class,
            ]);

            return Redirect::route('admin.users.index')->with(
                'warning',
                'Account remains inactive, but the invitation email could not be sent.'
            );
        }

        return Redirect::route('admin.users.index')->with(
            'status',
            'Invitation email sent to '.$user->email.'.'
        );
    }

    private function broker(): PasswordBroker
    {
        return Password::broker('staff_invitations');
    }

    public function edit(User $user): View
    {
        $this->authorizeAdmin();

        return view('admin.users.edit', compact('user'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeAdmin();

        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email,'.$user->getKey().','.$user->getKeyName()],
            'role' => ['required', 'in:patient,nurse,physician,admin'],
            'department' => ['nullable', 'string', 'max:100'],
            'staff_position' => ['nullable', 'string', 'max:100'],
            'specialization' => ['nullable', 'string', 'max:100'],
        ];

        // 'inactive' means "awaiting email verification" (a freshly invited
        // nurse/physician who hasn't activated yet, or a freshly self-registered
        // patient) and is set only by that verification/activation flow, never
        // chosen by an admin. While the account is still in that state the edit
        // form has no status field to submit at all, so nothing is required here
        // and account_status is simply left off $validated — fill() then leaves
        // the existing 'inactive' value untouched. Once verified, an admin may
        // only ever toggle between active and suspended.
        $rules['account_status'] = $user->account_status === 'inactive'
            ? ['nullable', 'in:inactive']
            : ['required', 'in:active,suspended'];

        $validated = $request->validate($rules);

        // An invited account holds a random password nobody knows, so flipping
        // it active would produce an account its owner still cannot sign in to
        // while quietly skipping the activation the invitation exists to drive.
        // Judged on the stored record, never on what this request submits.
        $activating = ($validated['account_status'] ?? null) === 'active' && $user->account_status !== 'active';

        if ($activating && $user->email_verified_at === null && in_array($user->role, self::INVITED_ROLES, true)) {
            throw ValidationException::withMessages([
                'account_status' => 'This staff account has not been activated yet. The invited staff member must set their own password through their invitation link before the account can become active.',
            ]);
        }

        // Compared before filling: the broker deletes by the model's *current*
        // email, so this must be decided while $user still holds the old one.
        $emailChanged = $validated['email'] !== $user->email;

        DB::transaction(function () use ($user, $validated, $emailChanged): void {
            if ($emailChanged) {
                // Invitations are keyed by email. Leaving the old row behind
                // would let the original link activate whoever is assigned that
                // address next. Deleting here, before fill(), targets the old
                // address; doing it afterwards would target the new one.
                Password::broker('staff_invitations')->deleteToken($user);
            }

            $user->fill($validated);

            if (empty($user->email_verified_at) && $user->role === 'admin') {
                $user->email_verified_at = now();
            }

            $user->save();
        });

        return Redirect::route('admin.users.index')->with('status', 'User updated successfully.');
    }
}
