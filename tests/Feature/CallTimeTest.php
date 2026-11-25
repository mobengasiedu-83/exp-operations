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
    return callTimeJourneyAt($connection, CarbonImmutable::now()->subMinutes($minutesAgo));
}

/** A completed journey that started at an exact instant, for range boundaries. */
function callTimeJourneyAt(PbxConnection $connection, CarbonImmutable $start): Interaction
{
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
            ->where('filters.from', null)
            ->where('filters.to', null)
            ->where('overview.window.range', false)
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

    $analytics = app(CallTimeAnalytics::class);
    $overview = $analytics->overview($analytics->window(CallTimeAnalytics::DEFAULT_DAYS));

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

    $analytics = app(CallTimeAnalytics::class);
    $agents = $analytics->agents($analytics->window(CallTimeAnalytics::DEFAULT_DAYS));
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

    $analytics = app(CallTimeAnalytics::class);
    $dentists = $analytics->dentists($analytics->window(CallTimeAnalytics::DEFAULT_DAYS));
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

    expect($analytics->overview($analytics->window(7))['calls']['total'])->toBe(1)
        ->and($analytics->overview($analytics->window(90))['calls']['total'])->toBe(2);
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

test('dentist answers proven by a consultation bridge count without a network answer timestamp', function () {
    $connection = callTimeConnection();
    $dentist = Dentist::factory()->create(['name' => 'Dr Bridge']);
    $journey = callTimeJourney($connection, 30);
    $leg = callTimeLeg($journey, 'mobile', [
        'started_at' => $journey->started_at,
        'dial_started_at' => $journey->started_at->addSeconds(10),
        'answered_at' => null,
        'ended_at' => $journey->started_at->addSeconds(60),
    ]);

    TransferAttempt::factory()->create([
        'interaction_id' => $journey->id,
        'call_leg_id' => $leg->id,
        'dentist_id' => $dentist->id,
        'status' => 'consultation_observed',
        'dial_started_at' => $journey->started_at->addSeconds(10),
        'answered_at' => null,
        'ended_at' => $journey->started_at->addSeconds(60),
        'consultation_seconds' => 30,
    ]);

    $analytics = app(CallTimeAnalytics::class);
    $dentists = $analytics->dentists($analytics->window(CallTimeAnalytics::DEFAULT_DAYS));
    $row = collect($dentists['rows'])->firstWhere('dentist_id', $dentist->id);

    expect($dentists['summary']['answered'])->toBe(1)
        ->and($row['answered'])->toBe(1)
        ->and($row['answer_rate'])->toBe(1.0);
});

test('an explicit single day selects only the journeys that started that day', function () {
    $connection = callTimeConnection();

    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-14 08:00:00'));
    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-15 00:05:00'));
    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-15 23:55:00'));
    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-16 00:05:00'));

    $analytics = app(CallTimeAnalytics::class);
    $window = $analytics->window(CallTimeAnalytics::DEFAULT_DAYS, '2026-09-15', '2026-09-15');
    $overview = $analytics->overview($window);

    expect($window['range'])->toBeTrue()
        ->and($window['days'])->toBe(1)
        ->and($window['from_date'])->toBe('2026-09-15')
        ->and($window['to_date'])->toBe('2026-09-15')
        ->and($overview['calls']['total'])->toBe(2)
        ->and($overview['window']['range'])->toBeTrue()
        ->and($overview['window']['from_date'])->toBe('2026-09-15')
        ->and($overview['window']['to_date'])->toBe('2026-09-15')
        ->and($overview['daily'])->toHaveCount(1);
});

test('a month range includes the first and last day of that month', function () {
    $connection = callTimeConnection();

    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-08-31 23:59:00'));
    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-01 00:00:00'));
    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-30 23:59:59'));
    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-10-01 00:00:00'));

    $analytics = app(CallTimeAnalytics::class);
    $window = $analytics->window(CallTimeAnalytics::DEFAULT_DAYS, '2026-09-01', '2026-09-30');
    $overview = $analytics->overview($window);

    expect($window['days'])->toBe(30)
        ->and($window['to_date'])->toBe('2026-09-30')
        ->and($overview['calls']['total'])->toBe(2)
        ->and($overview['daily'])->toHaveCount(2);
});

test('a range may supply only one of its ends', function () {
    $connection = callTimeConnection();
    $today = CarbonImmutable::now()->startOfDay();

    callTimeJourneyAt($connection, $today->subDays(2)->addHour());
    callTimeJourneyAt($connection, $today->subDays(10)->addHour());

    $analytics = app(CallTimeAnalytics::class);

    // From an earlier day: everything after it, up to now.
    $fromOnly = $analytics->window(7, $today->subDays(2)->toDateString());
    // Up to an earlier day: everything before it, from the retained ceiling.
    $toOnly = $analytics->window(7, null, $today->subDays(10)->toDateString());

    expect($analytics->overview($fromOnly)['calls']['total'])->toBe(1)
        ->and($analytics->overview($toOnly)['calls']['total'])->toBe(1)
        ->and($fromOnly['to_date'])->toBe($today->toDateString())
        ->and($toOnly['to_date'])->toBe($today->subDays(10)->toDateString());
});

test('the call time pages accept an inclusive date range from the query string', function () {
    $this->withoutVite();
    $connection = callTimeConnection();

    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-15 10:00:00'));
    callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-20 10:00:00'));

    $this->actingAs(callTimeUser())->get(route('call-time.overview', [
        'from' => '2026-09-15',
        'to' => '2026-09-15',
    ]))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('CallTime/Overview')
            ->where('filters.from', '2026-09-15')
            ->where('filters.to', '2026-09-15')
            ->where('overview.window.range', true)
            ->where('overview.window.from_date', '2026-09-15')
            ->where('overview.window.to_date', '2026-09-15')
            ->where('overview.calls.total', 1));
});

test('an inverted or malformed date range is rejected', function () {
    $this->withoutVite();
    callTimeConnection();

    $this->actingAs(callTimeUser())
        ->from(route('call-time.overview'))
        ->get(route('call-time.overview', ['from' => '2026-09-30', 'to' => '2026-09-01']))
        ->assertSessionHasErrors('to');

    $this->actingAs(callTimeUser())
        ->from(route('call-time.overview'))
        ->get(route('call-time.overview', ['from' => '30/09/2026']))
        ->assertSessionHasErrors('from');
});

test('dentist time is selected by dial start, so a month keeps its last dial', function () {
    $connection = callTimeConnection();
    $dentist = Dentist::factory()->create(['name' => 'Dr Late Dial']);
    $journey = callTimeJourneyAt($connection, CarbonImmutable::parse('2026-09-30 23:40:00'));
    $dialledAt = CarbonImmutable::parse('2026-09-30 23:45:00');

    $leg = callTimeLeg($journey, 'mobile', [
        'started_at' => $journey->started_at,
        'dial_started_at' => $dialledAt,
        'answered_at' => CarbonImmutable::parse('2026-09-30 23:50:00'),
        'ended_at' => CarbonImmutable::parse('2026-10-01 00:05:00'),
    ]);

    TransferAttempt::factory()->create([
        'interaction_id' => $journey->id,
        'call_leg_id' => $leg->id,
        'dentist_id' => $dentist->id,
        'dial_started_at' => $dialledAt,
        'answered_at' => CarbonImmutable::parse('2026-09-30 23:50:00'),
        'ended_at' => CarbonImmutable::parse('2026-10-01 00:05:00'),
        'mobile_connected_seconds' => 900,
    ]);

    $analytics = app(CallTimeAnalytics::class);
    $september = $analytics->dentists($analytics->window(7, '2026-09-01', '2026-09-30'));
    $october = $analytics->dentists($analytics->window(7, '2026-10-01', '2026-10-31'));

    expect($september['summary']['attempts'])->toBe(1)
        ->and($september['summary']['connected_seconds'])->toBe(900.0)
        ->and($september['window']['from_date'])->toBe('2026-09-01')
        ->and($october['summary']['attempts'])->toBe(0);
});
