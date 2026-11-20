<?php

namespace Database\Factories;

use App\Models\Dentist;
use App\Models\DentistRoster;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DentistRoster>
 */
class DentistRosterFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsAt = now()->subHour();

        return [
            'dentist_id' => Dentist::factory(),
            'kind' => 'shift',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addHours(8),
            'notes' => null,
            'created_by' => null,
        ];
    }

    /**
     * Indicate that the roster window is approved leave.
     */
    public function leave(): static
    {
        return $this->state(fn (array $attributes): array => ['kind' => 'leave']);
    }
}
