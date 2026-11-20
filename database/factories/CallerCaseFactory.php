<?php

namespace Database\Factories;

use App\Models\CallerCase;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CallerCase> */
class CallerCaseFactory extends Factory
{
    public function definition(): array
    {
        return ['interaction_id' => Interaction::factory(), 'assigned_to' => User::factory(), 'association_verified_at' => now()];
    }
}
