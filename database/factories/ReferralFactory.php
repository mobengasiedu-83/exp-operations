<?php

namespace Database\Factories;

use App\Models\CallerCase;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Referral> */
class ReferralFactory extends Factory
{
    public function definition(): array
    {
        return [
            'case_id' => CallerCase::factory(),
            'referred_by' => User::factory(),
            'route' => 'routine',
            'destination_name' => 'Korle Bu Dental Clinic',
            'destination_contact' => '+233302000000',
            'reason' => 'Routine clinician follow-up agreed with the caller.',
            'status' => 'pending',
            'revision' => 1,
        ];
    }

    public function urgent(): static
    {
        return $this->state(fn (): array => ['route' => 'urgent']);
    }
}
