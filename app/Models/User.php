<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    // No HasApiTokens here on purpose: Sanctum is installed for the CHIS
    // integration, but that token principal is App\Models\ChisIntegrationClient,
    // not a User row — see that class for why. Nothing else in this
    // application issues API tokens to a CLSU account.
    use HasFactory, Notifiable;

    // Tell Eloquent the table's primary key name
    protected $primaryKey = 'user_id';

    // Tell Laravel it doesn't auto-increment a column named 'id'
    public $incrementing = true;

    protected $keyType = 'int';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'account_status',
        'online_status',
        'clsu_id',
        'user_type',
        'department',
        'contact_num',
        'staff_position',
        'specialization',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_seen_at' => 'datetime',
    ];

    /**
     * Roles that are provisioned by admin invitation rather than by
     * self-registration or direct creation.
     */
    public const INVITED_ROLES = ['nurse', 'physician'];

    /**
     * Whether this account is a staff account still waiting to be activated
     * through its invitation.
     *
     * Single source of truth for that rule: activation (StaffInvitationController)
     * and invitation resend (Admin\UserManagementController) must agree, or the
     * admin could issue an invitation that the activation flow then refuses.
     */
    public function awaitsStaffActivation(): bool
    {
        return $this->account_status === 'inactive'
            && in_array($this->role, self::INVITED_ROLES, true);
    }

    /**
     * The patient's one live consultation session, or null when they have none.
     *
     * A patient can only ever have a single consultation in flight, so this
     * returns at most one row. The 'active' + 'active' pair is deliberately the
     * exact condition ConsultationSessionPolicy::sendMessage allows, so anything
     * linking to this session (the mobile floating action button) can never send
     * the patient somewhere the policy would then refuse.
     */
    public function activeConsultationSession(): ?ConsultationSession
    {
        return ConsultationSession::query()
            ->where('consultation_status', 'active')
            ->whereHas('request', function ($query) {
                $query->where('patient_id', $this->user_id)
                    ->where('request_status', 'active');
            })
            ->latest('id')
            ->first();
    }

    protected static function booted(): void
    {
        // Admins are provisioned directly (no self-registration, no invitation flow),
        // so they would otherwise be locked out by the 'verified' middleware.
        // Nurses and physicians are deliberately excluded: they verify by accepting
        // their activation invitation.
        static::creating(function (self $user): void {
            if (empty($user->email_verified_at) && $user->role === 'admin') {
                $user->email_verified_at = now();
            }
        });
    }
}
