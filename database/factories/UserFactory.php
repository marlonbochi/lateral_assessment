<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'role' => Role::User,
        ];
    }

    public function user(): static
    {
        return $this->state(['role' => Role::User]);
    }

    public function admin(): static
    {
        return $this->state(['role' => Role::Admin]);
    }
}
