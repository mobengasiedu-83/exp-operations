<?php

namespace Database\Factories;

use App\Models\ScreeningProtocol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ScreeningProtocol> */
class ScreeningProtocolFactory extends Factory
{
    public function definition(): array
    {
        return [
            'version' => fake()->unique()->uuid(), 'title' => 'Simulation protocol', 'status' => 'active', 'is_active' => true,
            'privacy_notice' => 'Simulation privacy notice. Not approved for live use.', 'allow_guardian' => false,
            'guidance' => array_fill_keys(['P1', 'P2', 'P3', 'S', 'Review'], 'Simulation routing instruction.'),
            'faq_answer' => 'Simulation service FAQ.',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => 'active', 'is_active' => true]);
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => 'draft', 'is_active' => false]);
    }
}
