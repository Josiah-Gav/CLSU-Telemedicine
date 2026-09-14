<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

/**
 * Phase 2 (IA/UX): the admin dashboard was 100% analytics — no surfacing of
 * pending/expired staff invitations, the thing an admin actually needs to
 * act on. Reuses Admin\UserManagementController::invitationStates() (the
 * same derivation already driving admin/users/index.blade.php) rather than
 * recomputing "pending"/"expired" a second, potentially divergent way.
 */
function adminUser(): User
{
    return User::factory()->create(['role' => 'admin', 'user_type' => 'staff']);
}

function adminAttentionInvitedStaff(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'role' => 'nurse',
        'account_status' => 'inactive',
        'email_verified_at' => null,
    ], $overrides));
}

it('surfaces a pending staff invitation on the admin dashboard', function () {
    $admin = adminUser();
    $nurse = adminAttentionInvitedStaff();
    Password::broker('staff_invitations')->createToken($nurse);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('1 pending', false);
    $response->assertSee(route('admin.users.index'), false);
});

it('surfaces an expired staff invitation on the admin dashboard', function () {
    $admin = adminUser();
    $nurse = adminAttentionInvitedStaff();
    Password::broker('staff_invitations')->createToken($nurse);
    DB::table('staff_invitation_tokens')
        ->where('email', $nurse->email)
        ->update(['created_at' => now()->subDays(30)]);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('1 expired', false);
});

it('shows an all-clear state when no staff invitation needs attention', function () {
    $admin = adminUser();

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertOk();
    $response->assertSee('No staff invitations need attention', false);
});
