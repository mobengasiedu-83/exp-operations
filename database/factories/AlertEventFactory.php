<?php

namespace Database\Factories;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\PbxConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AlertEvent>
 */
class AlertEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'alert_rule_id' => fn () => AlertRule::factory()->create()->id,
            'pbx_connection_id' => fn () => PbxConnection::create(['name' => fake()->uuid(), 'host' => '127.0.0.1'])->id,
            'interaction_id' => null,
            'fingerprint' => Str::uuid()->toString(),
            'status' => 'open',
            'severity' => 'warning',
            'subject' => 'PBX connection',
            'details' => [],
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
