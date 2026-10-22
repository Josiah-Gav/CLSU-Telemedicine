<?php

use App\Models\User;
use App\Rules\ClsuId;
use Database\Seeders\QaTestUsersSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function clsuRegister(string $clsuId, string $email = 'new.patient@clsu.edu.ph')
{
    return test()->from('/register')->post('/register', [
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'clsu_id' => $clsuId,
        'email' => $email,
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);
}

function clsuAdmin(): User
{
    return User::factory()->create(['role' => 'admin']);
}

function clsuMigration(): object
{
    return require database_path('migrations/2026_10_22_120000_add_unique_index_to_users_clsu_id.php');
}

// --- the rule itself ---------------------------------------------------------

it('rejects a trailing newline when called directly, bypassing TrimStrings', function () {
    $failed = false;
    (new ClsuId)->validate('clsu_id', "23-0001\n", function () use (&$failed) {
        $failed = true;
    });

    expect($failed)->toBeTrue();
});

// --- registration -------------------------------------------------------------

it('accepts every registrar format at registration', function (string $clsuId) {
    clsuRegister($clsuId)->assertSessionHasNoErrors();

    expect(User::where('email', 'new.patient@clsu.edu.ph')->value('clsu_id'))->toBe($clsuId);
})->with(['student' => '23-0001', 'staff/faculty' => '26-000001', 'long-tenured' => '1996-0042']);

it('rejects malformed ids at registration with a hint showing valid ones', function (string $clsuId) {
    clsuRegister($clsuId)->assertSessionHasErrors(['clsu_id' => 'Enter a valid CLSU ID, '.ClsuId::HINT]);

    $this->assertGuest();
    expect(User::where('email', 'new.patient@clsu.edu.ph')->exists())->toBeFalse();
})->with(['23-1', '2023', 'abc', '23-0001x']);

it('rejects an id already taken at registration', function () {
    User::factory()->create(['clsu_id' => '23-0001']);

    clsuRegister('23-0001')->assertSessionHasErrors(['clsu_id' => ClsuId::TAKEN]);

    expect(User::where('email', 'new.patient@clsu.edu.ph')->exists())->toBeFalse();
});

it('trims surrounding whitespace at registration', function () {
    clsuRegister('  23-0002 ')->assertSessionHasNoErrors();

    expect(User::where('email', 'new.patient@clsu.edu.ph')->value('clsu_id'))->toBe('23-0002');
});

// --- profile -------------------------------------------------------------------

it('lets a user with no id set one once, then ignores later changes', function () {
    $user = User::factory()->create(['clsu_id' => null]);

    $this->actingAs($user)->patch('/profile', [
        'first_name' => 'A', 'last_name' => 'B', 'clsu_id' => ' 23-0003 ',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->clsu_id)->toBe('23-0003');

    $this->actingAs($user)->patch('/profile', [
        'first_name' => 'A', 'last_name' => 'B', 'clsu_id' => '23-0004',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->clsu_id)->toBe('23-0003');
});

it('never lets a crafted request change or blank an id that is already set', function (?string $attempt) {
    $user = User::factory()->create(['clsu_id' => '23-0003']);

    $this->actingAs($user)->patch('/profile', [
        'first_name' => 'A', 'last_name' => 'B', 'clsu_id' => $attempt,
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->clsu_id)->toBe('23-0003');
})->with(['different id' => '23-0009', 'empty string' => '', 'null' => null]);

it('applies format and uniqueness when a user sets their id on the profile', function () {
    User::factory()->create(['clsu_id' => '23-0005']);
    $user = User::factory()->create(['clsu_id' => null]);

    $this->actingAs($user)->patch('/profile', ['first_name' => 'A', 'last_name' => 'B', 'clsu_id' => '23-0005'])
        ->assertSessionHasErrors(['clsu_id' => ClsuId::TAKEN]);
    $this->actingAs($user)->patch('/profile', ['first_name' => 'A', 'last_name' => 'B', 'clsu_id' => '23-5'])
        ->assertSessionHasErrors('clsu_id');

    expect($user->refresh()->clsu_id)->toBeNull();
});

it('shows a set id read-only with no submittable field', function () {
    $user = User::factory()->create(['clsu_id' => '23-0003']);

    $this->actingAs($user)->get('/profile')
        ->assertSee('Contact the clinic administrator to correct your CLSU ID.')
        ->assertDontSee('name="clsu_id"', false);
});

// --- admin ---------------------------------------------------------------------

it('enforces format and uniqueness when an admin creates a user', function () {
    User::factory()->create(['clsu_id' => '26-000001']);

    $payload = fn (string $clsuId) => [
        'first_name' => 'Maria', 'last_name' => 'Cruz', 'email' => 'maria@clsu.edu.ph',
        'role' => 'nurse', 'clsu_id' => $clsuId,
    ];

    $admin = clsuAdmin();
    $this->actingAs($admin)->post(route('admin.users.store'), $payload('26-1'))->assertSessionHasErrors('clsu_id');
    $this->actingAs($admin)->post(route('admin.users.store'), $payload('26-000001'))
        ->assertSessionHasErrors(['clsu_id' => ClsuId::TAKEN]);

    expect(User::where('email', 'maria@clsu.edu.ph')->exists())->toBeFalse();
});

it('lets an admin change an existing id, ignoring the user own current one', function () {
    $patient = User::factory()->create(['role' => 'patient', 'clsu_id' => '23-0003']);
    User::factory()->create(['clsu_id' => '23-0007']);

    $update = fn (string $clsuId) => test()->actingAs(clsuAdmin())->put(route('admin.users.update', $patient), [
        'first_name' => $patient->first_name, 'last_name' => $patient->last_name, 'email' => $patient->email,
        'role' => 'patient', 'account_status' => 'active', 'clsu_id' => $clsuId,
    ]);

    $update('23-0003')->assertSessionHasNoErrors();
    $update('23-0007')->assertSessionHasErrors(['clsu_id' => ClsuId::TAKEN]);
    $update('23-07')->assertSessionHasErrors('clsu_id');
    expect($patient->refresh()->clsu_id)->toBe('23-0003');

    $update('23-0008')->assertSessionHasNoErrors();
    expect($patient->refresh()->clsu_id)->toBe('23-0008');
});

// --- migration -----------------------------------------------------------------

it('turns empty strings into NULL and trims before adding the index', function () {
    $migration = clsuMigration();
    $migration->down();

    $a = User::factory()->create(['clsu_id' => '']);
    $b = User::factory()->create(['clsu_id' => '']);
    $c = User::factory()->create(['clsu_id' => ' 23-0005 ']);

    $migration->up();

    expect($a->refresh()->clsu_id)->toBeNull()
        ->and($b->refresh()->clsu_id)->toBeNull()
        ->and($c->refresh()->clsu_id)->toBe('23-0005');

    expect(fn () => User::factory()->create(['clsu_id' => '23-0005']))
        ->toThrow(QueryException::class);
});

it('fails naming the duplicates and leaves the data untouched', function () {
    $migration = clsuMigration();
    $migration->down();

    User::factory()->create(['clsu_id' => '23-0006']);
    User::factory()->create(['clsu_id' => ' 23-0006']);
    User::factory()->create(['clsu_id' => '']);

    expect(fn () => $migration->up())->toThrow(RuntimeException::class, '23-0006');

    expect(DB::table('users')->pluck('clsu_id')->sort()->values()->all())
        ->toBe(['', ' 23-0006', '23-0006']);
});

// --- QA seeder -----------------------------------------------------------------

it('seeds the CHIS patient ids and is idempotent', function () {
    (new QaTestUsersSeeder)->run();
    $count = User::count();
    (new QaTestUsersSeeder)->run();

    expect(User::count())->toBe($count)
        ->and(User::where('email', 'patient.test1@clsu.edu.ph')->value('clsu_id'))->toBe('23-0001')
        ->and(User::where('email', 'patient.test2@clsu.edu.ph')->value('clsu_id'))->toBe('23-0002');

    $roberto = User::where('email', 'patient.test3@clsu.edu.ph')->first();
    expect($roberto->only(['first_name', 'last_name', 'role', 'user_type', 'clsu_id']))->toBe([
        'first_name' => 'Roberto', 'last_name' => 'Villanueva', 'role' => 'patient',
        'user_type' => 'staff', 'clsu_id' => '1996-0042',
    ]);
});

it('backfills a missing id on an existing QA account but never overwrites one', function () {
    $test1 = User::factory()->create(['email' => 'patient.test1@clsu.edu.ph', 'clsu_id' => null]);
    $test2 = User::factory()->create(['email' => 'patient.test2@clsu.edu.ph', 'clsu_id' => '23-0999', 'first_name' => 'Kept']);

    (new QaTestUsersSeeder)->run();

    expect($test1->refresh()->clsu_id)->toBe('23-0001')
        ->and($test2->refresh()->clsu_id)->toBe('23-0999')
        ->and($test2->first_name)->toBe('Kept');
});

it('skips a QA account whose id is already held by someone else', function () {
    $other = User::factory()->create(['email' => 'real.student@clsu.edu.ph', 'clsu_id' => '23-0001']);

    (new QaTestUsersSeeder)->run();

    expect(User::where('email', 'patient.test1@clsu.edu.ph')->exists())->toBeFalse()
        ->and($other->refresh()->clsu_id)->toBe('23-0001');
});
