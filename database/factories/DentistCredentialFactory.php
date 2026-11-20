<?php

namespace Database\Factories;

use App\Models\Dentist;
use App\Models\DentistCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DentistCredential>
 */
class DentistCredentialFactory extends Factory
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
            'type' => 'license',
            'number' => 'GD-'.(string) fake()->unique()->numerify('#####'),
            'issuer' => 'Ghana Dental Association',
            'issued_on' => now()->subYears(2)->toDateString(),
            'expires_on' => now()->addYear()->toDateString(),
            'verified_at' => now(),
            'notes' => null,
        ];
    }

    /**
     * Indicate that the credential has lapsed.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => ['expires_on' => now()->subDay()->toDateString()]);
    }
}
