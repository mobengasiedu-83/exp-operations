<?php

namespace Database\Factories;

use App\Models\CallerCase;
use App\Models\ConsentRecord;
use App\Models\ScreeningProtocol;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ConsentRecord> */
class ConsentRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'case_id' => CallerCase::factory(),
            'screening_protocol_id' => ScreeningProtocol::factory(),
            'status' => 'granted',
            'callback_allowed' => true,
            'recorded_at' => now(),
        ];
    }

    public function withoutCallback(): static
    {
        return $this->state(fn (): array => ['callback_allowed' => false]);
    }
}
