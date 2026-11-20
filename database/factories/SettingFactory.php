<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(),
            'group' => 'general',
            'value' => fake()->word(),
            'cast' => 'string',
            'is_feature_flag' => false,
            'description' => fake()->sentence(),
        ];
    }

    /**
     * Indicate that the setting is a feature flag.
     */
    public function featureFlag(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_feature_flag' => true,
        ]);
    }
}
