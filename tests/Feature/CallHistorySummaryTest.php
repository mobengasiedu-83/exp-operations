<?php

use App\Models\CallerCase;
use App\Models\Dentist;
use App\Models\Interaction;
use App\Models\TransferAttempt;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** A reader who may see the whole call history. */
function callHistorySummaryReader(): User
{
    Permission::findOrCreate('view pbx');

    $user = User::factory()->create();
    $user->givePermissionTo('view pbx');

    return $user;
}

test('call history summary totals the filtered calls and their durations', function () {
    $user = callHistorySummaryReader();
    $dentist = Dentist::factory()->create();

    $connected = Interaction::factory()->create([
        'started_at' => '2026-11-20 08:00:00', 'ended_at' => '2026-11-20 08:10:30', 'status' => 'completed',
    ]);
    $verified = TransferAttempt::factory()->create([
        'interaction_id' => $connected->id, 'dentist_id' => $dentist->id, 'sequence' => 1,
        'status' => 'network_answered', 'answered_at' => '2026-11-20 08:05:00',
        'human_accepted_at' => '2026-11-20 08:05:10', 'human_outcome' => 'accepted',
        'transfer_completed_at' => '2026-11-20 08:05:15',
    ]);
    $verified->session()->create([
        'status' => 'human_confirmed', 'started_at' => '2026-11-20 08:05:15', 'intervals' => [],
    ]);

    $unverified = Interaction::factory()->create([
        'started_at' => '2026-11-20 09:00:00', 'ended_at' => '2026-11-20 09:05:00', 'status' => 'completed',
    ]);
    $bridged = TransferAttempt::factory()->create([
        'interaction_id' => $unverified->id, 'dentist_id' => $dentist->id, 'sequence' => 1,
        'status' => 'network_answered', 'answered_at' => '2026-11-20 09:02:00',
        'transfer_completed_at' => '2026-11-20 09:02:05',
    ]);
    $bridged->session()->create([
        'status' => 'unconfirmed', 'started_at' => '2026-11-20 09:02:05', 'intervals' => [],
    ]);

    Interaction::factory()->create([
        'started_at' => '2026-11-20 10:00:00', 'ended_at' => '2026-11-20 10:01:15', 'status' => 'completed',
    ]);
    Interaction::factory()->create([
        'started_at' => '2026-11-21 11:00:00', 'ended_at' => null, 'status' => 'active',
    ]);
    Interaction::factory()->create([
        'started_at' => '2026-11-22 12:00:00', 'ended_at' => '2026-11-22 12:01:00', 'status' => 'completed',
    ]);

    $range = ['from' => '2026-11-20', 'to' => '2026-11-21'];

    $this->actingAs($user)->get(route('calls.index', $range))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calls/Index')
            ->where('summary.total_calls', 4)
            ->where('summary.completed_calls', 3)
            ->where('summary.active_calls', 1)
            ->where('summary.measured_calls', 3)
            ->where('summary.total_seconds', 1005)
            ->where('summary.average_seconds', 335)
            ->where('summary.connected_calls', 1)
            ->where('summary.unverified_calls', 1)
            ->where('summary.no_connection_calls', 2)
            ->where('summary.connected_share', 25));

    $this->get(route('calls.index', [...$range, 'connection' => 'verified']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total_calls', 1)
            ->where('summary.connected_calls', 1)
            ->where('summary.no_connection_calls', 0)
            ->where('summary.connected_share', 100));

    // A call without hangup evidence is counted, but never as zero seconds.
    $this->get(route('calls.index', [...$range, 'status' => 'active']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total_calls', 1)
            ->where('summary.measured_calls', 0)
            ->where('summary.total_seconds', 0)
            ->where('summary.average_seconds', null));
});

test('call history summary only counts calls the reader may see', function () {
    foreach (['view call history', 'view cases'] as $permission) {
        Permission::findOrCreate($permission);
    }

    $agent = User::factory()->create();
    $agent->givePermissionTo(['view call history', 'view cases']);

    $own = Interaction::factory()->create([
        'started_at' => '2026-11-20 08:00:00', 'ended_at' => '2026-11-20 08:02:00',
    ]);
    CallerCase::factory()->create(['interaction_id' => $own->id, 'assigned_to' => $agent->id]);

    Interaction::factory()->create([
        'started_at' => '2026-11-20 09:00:00', 'ended_at' => '2026-11-20 09:30:00',
    ]);

    $this->actingAs($agent)->get(route('calls.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('summary.total_calls', 1)
            ->where('summary.measured_calls', 1)
            ->where('summary.total_seconds', 120));
});
