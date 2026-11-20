<?php

namespace Database\Factories;

use App\Models\AlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'name' => fake()->sentence(3),
            'category' => fake()->randomElement(['telemetry', 'ringing', 'transfer']),
            'metric' => 'threshold_value',
            'threshold_seconds' => 60,
            'severity' => 'warning',
            'enabled' => true,
            'description' => fake()->sentence(),
        ];
    }
}
