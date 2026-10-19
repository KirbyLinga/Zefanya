<?php

namespace Database\Factories\Admin;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<Admin>
 */
class AdminFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role' => AdminRole::Admin->value,
        ];
    }

    public function role(AdminRole $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role->value,
        ]);
    }

    public function superAdmin(): static
    {
        return $this->role(AdminRole::SuperAdmin);
    }
}
