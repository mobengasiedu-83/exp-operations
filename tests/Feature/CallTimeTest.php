<?php

use App\Models\CallLeg;
use App\Models\Dentist;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\TransferAttempt;
use App\Models\User;
use App\Services\CallTimeAnalytics;
use App\Services\PrimaryNavigation;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

function callTimeConnection(): PbxConnection
{
    return PbxConnection::create([
        'name' => config('services.exp_pbx.connection_name'),
        'host' => '127.0.0.1',
        'status' => 'connected',
        'last_heartbeat_at' => now(),
        'last_event_at' => now(),
    ]);
}

function callTimeUser(): User
{
    Permission::findOrCreate('view call time', 'web');

    $user = User::factory()->create();
    $user->givePermissionTo('view call time');

    return $user;
}

/** A completed journey that started the given number of minutes ago. */
function callTimeJourney(PbxConnection $connection, int $minutesAgo = 20): Interaction
{
    $start = CarbonImmutable::now()->subMinutes($minutesAgo);

    return Interaction::factory()->create([
        'pbx_connection_id' => $connection->id,
        'status' => 'completed',
        'started_at' => $start,
        'ended_at' => $start->addSeconds(200),
    ]);
}

/** @param array<string, mixed> $attributes */
function callTimeLeg(Interaction $journey, string $role, array $attributes = []): CallLeg
{
    return CallLeg::create([
        'interaction_id' => $journey->id,
        'pbx_connection_id' => $journey->pbx_connection_id,
        'uniqueid' => fake()->unique()->uuid(),
        'channel' => $role === 'mobile' ? 'PJSIP/196.201.48.139-00000826' : 'PJSIP/204-0000074a',
        'role' => $role,
        'started_at' => $journey->started_at,
        ...$attributes,
    ]);
}

test('call time pages require authentication and the call time permission', function () {
    $this->withoutVite();
    callTimeConnection();

    $urls = [
        route('call-time.overview'),
        route('call-time.agents'),
        route('call-time.dentists'),
    ];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    $this->actingAs(User::factory()->create());

    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }

    $this->actingAs(callTimeUser())->get(route('call-time.overview'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('CallTime/Overview')
            ->where('filters.days', CallTimeAnalytics::DEFAULT_DAYS)
            ->has('overview.totals.total_seconds')
            ->has('windows', 4));
});

test('the overview measures total, wait, agent and dentist time from evidence', function () {
    $connection = callTimeConnection();
    $journey = callTimeJourney($connection);
    $start = $journey->started_at;

    callTimeLeg($journey, 'caller', [
        'started_at' => $start,
        'answered_at' => $start->addSeconds(20),
        'ended_at' => $journey->ended_at,
    ]);
    callTimeLeg($journey, 'agent', [
        'started_at' => $start,
        'answered_at' => $start->addSeconds(20),
        'ended_at' => $journey->ended_at,
    ]);
    $mobile = callTimeLeg($journey, 'mobile', [
        'started_at' => $start->addSeconds(55),
        'dial_started_at' => $start->addSeconds(60),
        'ringing_at' => $start->addSeconds(62),
        'answered_at' => $start->addSeconds(70),
        'ended_at' => $start->addSeconds(110),
    ]);

    TransferAttempt::factory()->create([
        'interaction_id' => $journey->id,
        'call_leg_id' => $mobile->id,
        'dial_started_at' => $start->addSeconds(60),
        'answered_at' => $start->addSeconds(70),
        'ended_at' => $start->addSeconds(110),
        'dial_to_answer_seconds' => 10,
        'ringing_seconds' => 10,
        'mobile_connected_seconds' => 40,
        'consultation_seconds' => 25,
    ]);

    $overview = app(CallTimeAnalytics::class)->overview(CallTimeAnalytics::DEFAULT_DAYS);

    expect($overview['totals']['total_seconds'])->toBe(200.0)
        ->and($overview['totals']['wait_seconds'])->toBe(20.0)
        ->and($overview['totals']['agent_seconds'])->toBe(40.0)
        ->and($overview['totals']['dentist_seconds'])->toBe(40.0)
        ->and($overview['totals']['consultation_seconds'])->toBe(25.0)
        ->and($overview['totals']['ringing_seconds'])->toBe(10.0)
        ->and($overview['averages']['total_seconds'])->toBe(200.0)
        ->and($overview['calls'])->toMatchArray([
            'total' => 1,
            'completed' => 1,
            'answered' => 1,
            'transferred' => 1,
            'abandoned' => 0,
        ])
        ->and($overview['daily'])->toHaveCount(1);
});

test('agent time is grouped by the PBX extension that handled the call', function () {
    $connection = callTimeConnection();

    foreach (['204', '205'] as $index => $extension) {
        $journey = callTimeJourney($connection, 40 - ($index * 10));
        $start = $journey->started_at;

        callTimeLeg($journey, 'caller', [
            'started_at' => $start,
            'answered_at' => $start->addSeconds(20),
            'ended_at' => $journey->ended_at,
        ]);
        callTimeLeg($journey, 'agent', [
            'channel' => 'PJSIP/'.$extension.'-0000'.($index + 1).'a2b',
            'started_at' => $start,
            'answered_at' => $start->addSeconds(20),
            'ended_at' => $journey->ended_at,
        ]);
    }

    // A journey whose agent channel was never retained is kept, not dropped.
    callTimeJourney($connection, 15);

    $agents = app(CallTimeAnalytics::class)->agents(CallTimeAnalytics::DEFAULT_DAYS);
    $rows = collect($agents['rows'])->keyBy('key');

    expect($rows['204']['calls'])->toBe(1)
        ->and($rows['204']['talk_seconds'])->toBe(180.0)
        ->and($rows['204']['answered'])->toBe(1)
        ->and($rows['205']['talk_seconds'])->toBe(180.0)
        ->and($rows[CallTimeAnalytics::UNATTRIBUTED_AGENT]['calls'])->toBe(1)
        ->and($agents['summary']['agents'])->toBe(2)
        ->and($agents['summary']['unattributed_calls'])->toBe(1)
        ->and($agents['summary']['answered'])->toBe(2);
});

test('dentist time is attributed through the transfer attempt and keeps unmatched time', function () {
    $connection = callTimeConnection();
    $dentist = Dentist::factory()->create(['name' => 'Dr Ama Mensah']);

    $matched = callTimeJourney($connection, 30);
    $matchedLeg = callTimeLeg($matched, 'mobile', [
        'started_at' => $matched->started_at,
        'dial_started_at' => $matched->started_at->addSeconds(10),
        'answered_at' => $matched->started_at->addSeconds(20),
        'ended_at' => $matched->started_at->addSeconds(60),
    ]);
    TransferAttempt::factory()->create([
        'interaction_id' => $matched->id,
        'call_leg_id' => $matchedLeg->id,
        'dentist_id' => $dentist->id,
        'dial_started_at' => $matched->started_at->addSeconds(10),
        'answered_at' => $matched->started_at->addSeconds(20),
        'ended_at' => $matched->started_at->addSeconds(60),
        'mobile_connected_seconds' => 40,
        'consultation_seconds' => 30,
    ]);

    $unmatched = callTimeJourney($connection, 20);
    $unmatchedLeg = callTimeLeg($unmatched, 'mobile', [
        'started_at' => $unmatched->started_at,
        'dial_started_at' => $unmatched->started_at->addSeconds(10),
        'answered_at' => $unmatched->started_at->addSeconds(20),
        'ended_at' => $unmatched->started_at->addSeconds(32),
    ]);
    TransferAttempt::factory()->create([
        'interaction_id' => $unmatched->id,
        'call_leg_id' => $unmatchedLeg->id,
        'dentist_id' => null,
        'dial_started_at' => $unmatched->started_at->addSeconds(10),
        'answered_at' => $unmatched->started_at->addSeconds(20),
        'ended_at' => $unmatched->started_at->addSeconds(32),
        'mobile_connected_seconds' => 12,
    ]);

    $dentists = app(CallTimeAnalytics::class)->dentists(CallTimeAnalytics::DEFAULT_DAYS);
    $rows = collect($dentists['rows'])->keyBy('key');

    expect($dentists['summary']['attempts'])->toBe(2)
        ->and($dentists['summary']['answered'])->toBe(2)
        ->and($dentists['summary']['matched_attempts'])->toBe(1)
        ->and($dentists['summary']['unmatched_attempts'])->toBe(1)
        ->and($dentists['summary']['match_rate'])->toBe(0.5)
        ->and($dentists['summary']['connected_seconds'])->toBe(52.0)
        ->and($rows[(string) $dentist->id]['name'])->toBe('Dr Ama Mensah')
        ->and($rows[(string) $dentist->id]['connected_seconds'])->toBe(40.0)
        ->and($rows[(string) $dentist->id]['consultation_seconds'])->toBe(30.0)
        ->and($rows[CallTimeAnalytics::UNATTRIBUTED_DENTIST]['connected_seconds'])->toBe(12.0);
});

test('the window excludes journeys that started before it', function () {
    $connection = callTimeConnection();
    callTimeJourney($connection, 60);
    callTimeJourney($connection, 60 * 24 * 40);

    $analytics = app(CallTimeAnalytics::class);

    expect($analytics->overview(7)['calls']['total'])->toBe(1)
        ->and($analytics->overview(90)['calls']['total'])->toBe(2);
});

test('the menu publishes call time and the operations overview', function () {
    $user = User::factory()->create();
    $user->givePermissionTo([
        Permission::findOrCreate('view call time', 'web'),
        Permission::findOrCreate('view operations overview', 'web'),
    ]);

    $navigation = collect(app(PrimaryNavigation::class)->forUser($user));
    $callTime = $navigation->firstWhere('key', 'call-time');

    expect($navigation->pluck('key')->all())->toContain('operations', 'call-time')
        ->and(collect($callTime['children'])->pluck('title')->all())->toBe(['Overview', 'By agent', 'By dentist'])
        ->and($navigation->firstWhere('key', 'operations')['href'])->toBe(route('exp.dashboard'));

    $unauthorised = collect(app(PrimaryNavigation::class)->forUser(User::factory()->create()));

    expect($unauthorised->pluck('key')->all())->not->toContain('call-time', 'operations');
});

test('agent and viewer roles open the read-only operations overview', function () {
    $this->withoutVite();
    callTimeConnection();

    foreach (['Agent', 'Viewer'] as $role) {
        $user = User::factory()->create();
        $user->givePermissionTo(Permission::findOrCreate('view operations overview', 'web'));

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Dashboard')
                ->where('canViewOverview', true)
                ->where('canOpenConsole', true)
                ->where('canViewLiveDetails', false));

        $this->get(route('exp.dashboard'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Exp/Dashboard')
                ->where('canSavePreferences', false)
                ->where('canOpenLiveDetails', false)
                ->where('canAcknowledge', false));

        $this->getJson(route('exp.live'))->assertOk();

        // The deeper live-operations screens stay behind `view pbx`.
        $this->get(route('live-operations.active-calls'))->assertForbidden();
        $this->get(route('live-operations.transfers'))->assertForbidden();
        $this->get(route('live-operations.alerts'))->assertForbidden();
    }
});
