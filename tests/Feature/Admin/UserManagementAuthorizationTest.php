<?php

use App\Models\User;

test('non-admins cannot list users', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $this->actingAs($patient)->get(route('admin.users.index'))->assertForbidden();
});

test('non-admins cannot view the create-user form', function () {
    $nurse = User::factory()->create(['role' => 'nurse']);

    $this->actingAs($nurse)->get(route('admin.users.create'))->assertForbidden();
});

test('non-admins cannot create a user', function () {
    $physician = User::factory()->create(['role' => 'physician']);

    $response = $this->actingAs($physician)->post(route('admin.users.store'), [
        'first_name' => 'Eve',
        'last_name' => 'Hacker',
        'email' => 'eve@example.com',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'role' => 'admin',
        'account_status' => 'active',
    ]);

    $response->assertForbidden();
    $this->assertDatabaseMissing('users', ['email' => 'eve@example.com']);
});

test('non-admins cannot view the edit-user form', function () {
    $patient = User::factory()->create(['role' => 'patient']);
    $target = User::factory()->create(['role' => 'patient']);

    $this->actingAs($patient)->get(route('admin.users.edit', $target))->assertForbidden();
});

test('a patient cannot escalate their own role to admin via update', function () {
    $patient = User::factory()->create(['role' => 'patient']);

    $response = $this->actingAs($patient)->put(route('admin.users.update', $patient), [
        'first_name' => $patient->first_name,
        'last_name' => $patient->last_name,
        'email' => $patient->email,
        'role' => 'admin',
        'account_status' => 'active',
    ]);

    $response->assertForbidden();
    expect($patient->fresh()->role)->toBe('patient');
});

test('admins can still list, create, and edit users', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $target = User::factory()->create(['role' => 'patient']);

    $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
    $this->actingAs($admin)->get(route('admin.users.create'))->assertOk();
    $this->actingAs($admin)->get(route('admin.users.edit', $target))->assertOk();

    // Staff are now provisioned by invitation: no password is submitted and the
    // account is created inactive rather than active.
    $this->actingAs($admin)->post(route('admin.users.store'), [
        'first_name' => 'New',
        'last_name' => 'Staffer',
        'email' => 'new.staffer@example.com',
        'clsu_id' => '26-000105',
        'role' => 'nurse',
    ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseHas('users', [
        'email' => 'new.staffer@example.com',
        'clsu_id' => '26-000105',
        'role' => 'nurse',
        'account_status' => 'inactive',
        'email_verified_at' => null,
    ]);
});
