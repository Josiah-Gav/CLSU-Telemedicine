<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'patient',
            'account_status' => 'active',
            'online_status' => 'offline',
            // Unique and in the staff YY-NNNNNN format (users.clsu_id is unique).
            // The 90-99 range is used so it can never collide with test
            // fixtures (20-…, 23-…, 26-…, 1996-…).
            'clsu_id' => fake()->unique()->numerify('9#-######'),
            'user_type' => fake()->randomElement(['student', 'staff', 'faculty']),
            'department' => fake()->optional()->word(),
            'contact_num' => fake()->optional()->phoneNumber(),
            'staff_position' => null,
            'specialization' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
