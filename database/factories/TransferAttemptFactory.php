<?php

namespace Database\Factories;

use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\TransferAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransferAttempt> */
class TransferAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'interaction_id' => Interaction::factory(),
            'call_leg_id' => function (array $attributes): int {
                $interaction = Interaction::query()->whereKey($attributes['interaction_id'])->firstOrFail();

                return CallLeg::create([
                    'interaction_id' => $interaction->id,
                    'pbx_connection_id' => $interaction->pbx_connection_id,
                    'uniqueid' => fake()->unique()->uuid(), 'role' => 'mobile', 'dial_started_at' => now(),
                ])->id;
            },
            'dial_started_at' => now(),
        ];
    }
}
