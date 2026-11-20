<?php

namespace Database\Factories;

use App\Models\Dentist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dentist>
 */
class DentistFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Dr '.fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'active' => true,
            'notes' => null,
        ];
    }

    /**
     * Indicate that the dentist is no longer contactable.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['active' => false]);
    }
}
