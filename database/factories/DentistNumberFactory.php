<?php

namespace Database\Factories;

use App\Models\Dentist;
use App\Models\DentistNumber;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DentistNumber>
 */
class DentistNumberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dentist_id' => Dentist::factory(),
            'number' => '+23324'.(string) fake()->unique()->numerify('#######'),
            'original' => '024'.(string) fake()->unique()->numerify('#######'),
            'label' => 'Mobile',
            'is_primary' => false,
            'status' => 'approved',
            'verified_at' => now(),
            'notes' => null,
        ];
    }

    /**
     * Indicate that the number is the dentist's primary contact.
     */
    public function primary(): static
    {
        return $this->state(fn (array $attributes): array => ['is_primary' => true]);
    }

    /**
     * Indicate that the number has not been approved for transfers.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => 'pending', 'verified_at' => null]);
    }
}
