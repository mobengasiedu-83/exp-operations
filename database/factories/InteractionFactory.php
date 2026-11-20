<?php

namespace Database\Factories;

use App\Models\Interaction;
use App\Models\PbxConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interaction>
 */
class InteractionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pbx_connection_id' => fn () => PbxConnection::create(['name' => fake()->uuid(), 'host' => '127.0.0.1'])->id,
            'correlation_key' => fake()->uuid(),
            'started_at' => now(),
        ];
    }
}
