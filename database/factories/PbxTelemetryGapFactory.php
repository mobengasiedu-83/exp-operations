<?php

namespace Database\Factories;

use App\Models\PbxConnection;
use App\Models\PbxTelemetryGap;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PbxTelemetryGap> */
class PbxTelemetryGapFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pbx_connection_id' => fn (): int => PbxConnection::create(['name' => fake()->unique()->uuid(), 'host' => '127.0.0.1'])->id,
            'started_at' => now()->subMinute(), 'ended_at' => now(), 'reason' => 'connection_lost',
        ];
    }
}
