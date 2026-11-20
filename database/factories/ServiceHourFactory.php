<?php

namespace Database\Factories;

use App\Models\ServiceHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceHour>
 */
class ServiceHourFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Standard Hours',
            'opens_at' => '08:00:00',
            'closes_at' => '17:00:00',
            'is_closed' => false,
            'effective_from' => null,
            'effective_until' => null,
            'description' => 'Standard business hours for the dental assistant service.',
        ];
    }

    /**
     * Indicate that the service hours are closed.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_closed' => true,
        ]);
    }
}
