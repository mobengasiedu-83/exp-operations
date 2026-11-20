<?php

namespace Database\Factories;

use App\Models\Callback;
use App\Models\CallerCase;
use App\Models\ConsentRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Callback> */
class CallbackFactory extends Factory
{
    public function definition(): array
    {
        return [
            'case_id' => CallerCase::factory(),
            'consent_record_id' => ConsentRecord::factory(),
            'assigned_to' => User::factory(),
            'booked_by' => User::factory(),
            'status' => 'pending',
            'priority' => 'routine',
            'reason' => 'Caller requested a routine follow-up call.',
            'due_at' => now()->addHour(),
            'window_start' => '08:00:00',
            'window_end' => '18:00:00',
            'revision' => 1,
        ];
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => ['due_at' => now()->subHours(2)]);
    }
}
