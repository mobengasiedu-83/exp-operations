<?php

namespace Database\Factories;

use App\Models\Interaction;
use App\Models\IvrStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<IvrStep> */
class IvrStepFactory extends Factory
{
    public function definition(): array
    {
        $option = (string) fake()->numberBetween(1, 3);

        return [
            'interaction_id' => Interaction::factory(),
            'pbx_connection_id' => fn (array $attributes): int => Interaction::query()
                ->whereKey($attributes['interaction_id'])->firstOrFail()->pbx_connection_id,
            'uniqueid' => fake()->unique()->uuid(),
            'menu' => 'ivr-exp-main',
            'option' => $option,
            'application' => 'Goto',
            'app_data' => 'ivr-exp-'.$option.',s,1',
            'occurred_at' => now(),
            'timing_quality' => 'pbx',
            'fingerprint' => hash('sha256', fake()->unique()->uuid()),
        ];
    }
}
