<?php

namespace Database\Factories;

use App\Models\Dentist;
use App\Models\DentistAvailability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DentistAvailability>
 */
class DentistAvailabilityFactory extends Factory
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
            'status' => 'break',
            'starts_at' => now()->subMinutes(10),
            'ends_at' => now()->addMinutes(20),
            'reason' => null,
            'recorded_by' => null,
        ];
    }
}
