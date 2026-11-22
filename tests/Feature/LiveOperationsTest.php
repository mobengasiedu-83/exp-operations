<?php

use App\Models\AlertEvent;
use App\Models\CallLeg;
use App\Models\DashboardPreference;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\User;
use App\Services\LiveOperationsSnapshot;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/**
 * Create the PBX connection the live snapshot resolves by configuration name.
 *
 * @param  array<string, mixed>  $attributes
 */
function expLiveConnection(array $attributes = []): PbxConnection
{
    return PbxConnection::create(array_merge([
        'name' => config('services.exp_pbx.connection_name'),
        'host' => '127.0.0.1',
        'status' => 'connected',
        'last_heartbeat_at' => now(),
        'last_event_at' => now(),
    ], $attributes));
}

/** @param list<string> $permissions */
function expLiveOperator(array $permissions = ['view pbx']): User
{
    foreach (array_unique([...$permissions, 'view pbx']) as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->create();
    $user->givePermissionTo(array_unique([...$permissions, 'view pbx']));

    return $user;
}

/** @param array<string, mixed> $attributes */
function expLiveLeg(Interaction $interaction, array $attributes = []): CallLeg
{
    return CallLeg::create(array_merge([
        'interaction_id' => $interaction->id,
        'pbx_connection_id' => $interaction->pbx_connection_id,
        'uniqueid' => fake()->unique()->uuid(),
        'channel' => 'PJSIP/204-00000001',
        'role' => 'agent',
        'started_at' => $interaction->started_at,
    ], $attributes));
}

test('live operations routes require authentication and the PBX permission', function () {
    $urls = [
        route('exp.dashboard'),
        route('exp.live'),
        route('live-operations.active-calls'),
        route('live-operations.transfers'),
        route('live-operations.alerts'),
    ];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }

    $user = User::factory()->create();
    $this->actingAs($user);

    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }

    $user->givePermissionTo(Permission::findOrCreate('view pbx'));

    $this->get(route('exp.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Exp/Dashboard')
            ->where('refreshSeconds', 3)
            ->where('refreshBounds', ['min' => LiveOperationsSnapshot::MIN_REFRESH_SECONDS, 'max' => LiveOperationsSnapshot::MAX_REFRESH_SECONDS])
            ->where('canAcknowledge', false)
            ->has('filters.from')
            ->has('filters.state'));
});

test('live snapshot reports freshness, masked channels and journey KPIs', function () {
    $connection = expLiveConnection();
    $active = Interaction::factory()->create([
        'pbx_connection_id' => $connection->id,
        'status' => 'active',
        'started_at' => now()->subMinute(),
    ]);
    expLiveLeg($active, ['channel' => 'PJSIP/204-00000001', 'answered_at' => now()->subSeconds(50)]);
    expLiveLeg($active, ['role' => 'mobile', 'channel' => 'PJSIP/trunk/0241234567', 'ringing_at' => now()->subSeconds(20)]);

    $abandoned = Interaction::factory()->create([
        'pbx_connection_id' => $connection->id,
        'status' => 'completed',
        'started_at' => now()->subMinutes(10),
    ]);
    expLiveLeg($abandoned, ['answered_at' => null, 'ended_at' => now()->subMinutes(9)]);

    $this->actingAs(expLiveOperator());

    $this->getJson(route('exp.live'))
        ->assertOk()
        ->assertJsonPath('freshness.state', 'live')
        ->assertJsonPath('connection.name', $connection->name)
        ->assertJsonPath('kpis.inbound', 2)
        ->assertJsonPath('kpis.active', 1)
        ->assertJsonPath('kpis.abandoned', 1)
        ->assertJsonPath('kpis.answered', 1)
        ->assertJsonPath('kpis.dentist_ringing', 1)
        ->assertJsonCount(1, 'active_calls')
        ->assertJsonPath('active_calls.0.stage', 'dentist_ringing')
        ->assertJsonPath('active_calls.0.agent_channel', 'PJSIP/204-000***01')
        ->assertJsonPath('active_calls.0.destination_channel', 'PJSIP/trunk/024*****67')
        ->assertJsonPath('agent_presence.state', 'on_call')
        ->assertJsonPath('agent_presence.inferred', true);
});

test('live snapshot flags stale telemetry and validates filters', function () {
    expLiveConnection([
        'status' => 'connected',
        'last_heartbeat_at' => now()->subMinutes(5),
    ]);

    $this->actingAs(expLiveOperator());

    $this->getJson(route('exp.live'))
        ->assertOk()
        ->assertJsonPath('freshness.state', 'stale')
        ->assertJsonPath('freshness.stale_after_seconds', (int) config('services.exp_pbx.heartbeat_stale_seconds', 90));

    $this->getJson(route('exp.live', ['from' => 'yesterday']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');

    $this->getJson(route('exp.live', ['state' => 'invented']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('state');
});

test('live snapshot reports an unconfigured connection when none matches', function () {
    PbxConnection::create(['name' => 'Another PBX', 'host' => '10.0.0.5']);

    $this->actingAs(expLiveOperator());

    $this->getJson(route('exp.live'))
        ->assertOk()
        ->assertJsonPath('connection', null)
        ->assertJsonPath('freshness.state', 'unconfigured')
        ->assertJsonPath('agent_presence.state', 'unknown')
        ->assertJsonPath('kpis.inbound', 0);
});

test('filters scope the live snapshot by agent, destination and state', function () {
    $connection = expLiveConnection();

    $answered = Interaction::factory()->create([
        'pbx_connection_id' => $connection->id,
        'status' => 'completed',
        'started_at' => now()->subMinutes(5),
    ]);
    expLiveLeg($answered, ['channel' => 'PJSIP/204-00000002', 'answered_at' => now()->subMinutes(4), 'ended_at' => now()->subMinutes(2)]);
    expLiveLeg($answered, ['role' => 'mobile', 'channel' => 'PJSIP/trunk/0241234567', 'dial_started_at' => now()->subMinutes(5), 'answered_at' => now()->subMinutes(4), 'ended_at' => now()->subMinutes(2)]);

    $abandoned = Interaction::factory()->create([
        'pbx_connection_id' => $connection->id,
        'status' => 'completed',
        'started_at' => now()->subMinutes(3),
    ]);
    expLiveLeg($abandoned, ['channel' => 'PJSIP/205-00000003', 'answered_at' => null, 'ended_at' => now()->subMinutes(2)]);
    expLiveLeg($abandoned, ['role' => 'mobile', 'channel' => 'PJSIP/trunk/0247654321', 'dial_status' => 'NOANSWER', 'ended_at' => now()->subMinutes(2)]);

    $this->actingAs(expLiveOperator());

    $this->getJson(route('exp.live', ['state' => 'abandoned']))
        ->assertOk()
        ->assertJsonPath('kpis.inbound', 1)
        ->assertJsonPath('kpis.abandoned', 1)
        ->assertJsonPath('kpis.answered', 0);

    $this->getJson(route('exp.live', ['agent' => '204']))
        ->assertOk()
        ->assertJsonPath('kpis.inbound', 1)
        ->assertJsonPath('kpis.answered', 1);

    $this->getJson(route('exp.live', ['destination' => '0247654321']))
        ->assertOk()
        ->assertJsonPath('kpis.inbound', 1)
        ->assertJsonPath('kpis.abandoned', 1);

    $this->getJson(route('exp.live', ['destination' => '0241234567']))
        ->assertOk()
        ->assertJsonCount(1, 'transfers')
        ->assertJsonPath('transfers.0.dial_to_answer_seconds', 60)
        ->assertJsonPath('transfers.0.channel', 'PJSIP/trunk/024*****67');
});

test('dashboard preferences persist filters and clamp refresh seconds', function () {
    $user = expLiveOperator();
    $this->actingAs($user);

    $this->from(route('exp.dashboard'))
        ->patch(route('exp.preferences'), ['refresh_seconds' => 1])
        ->assertSessionHasErrors('refresh_seconds');

    $this->from(route('exp.dashboard'))
        ->patch(route('exp.preferences'), ['refresh_seconds' => 9])
        ->assertSessionHasErrors('refresh_seconds');

    $this->from(route('exp.dashboard'))
        ->patch(route('exp.preferences'), [
            'filters' => ['state' => 'invented'],
            'refresh_seconds' => 3,
        ])
        ->assertSessionHasErrors('filters.state');

    $this->from(route('exp.dashboard'))
        ->patch(route('exp.preferences'), [
            'filters' => ['from' => '2026-01-01T10:00', 'to' => '2026-01-01T09:00'],
            'refresh_seconds' => 3,
        ])
        ->assertSessionHasErrors('filters.to');

    $this->from(route('exp.dashboard'))
        ->patch(route('exp.preferences'), [
            'filters' => ['agent' => 'abc'],
            'refresh_seconds' => 3,
        ])
        ->assertSessionHasErrors('filters.agent');

    $this->from(route('exp.dashboard'))
        ->patch(route('exp.preferences'), [
            'filters' => ['agent' => '204', 'state' => 'active', 'destination' => '0241234567'],
            'refresh_seconds' => 5,
        ])
        ->assertRedirect(route('exp.dashboard'));

    $preference = DashboardPreference::query()->where('user_id', $user->id)->firstOrFail();
    expect($preference->refresh_seconds)->toBe(5)
        ->and($preference->filters)->toMatchArray(['agent' => '204', 'state' => 'active']);

    $this->get(route('exp.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Exp/Dashboard')
            ->where('refreshSeconds', 5)
            ->where('filters.agent', '204')
            ->where('filters.state', 'active')
            ->where('filters.from', ''));
});

test('stored refresh intervals outside the polling bounds are clamped when read', function () {
    $user = expLiveOperator();
    DashboardPreference::factory()->create(['user_id' => $user->id, 'refresh_seconds' => 60]);

    $this->actingAs($user)
        ->get(route('exp.dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('refreshSeconds', LiveOperationsSnapshot::MAX_REFRESH_SECONDS));
});

test('alert acknowledgements require edit settings and record the operator', function () {
    $alert = AlertEvent::factory()->create(['status' => 'open', 'severity' => 'warning']);

    $viewer = expLiveOperator();
    $this->actingAs($viewer)
        ->post(route('live-operations.alerts.acknowledge', $alert))
        ->assertForbidden();

    expect($alert->fresh()->status)->toBe('open');

    $supervisor = expLiveOperator(['edit settings']);
    $this->actingAs($supervisor)
        ->from(route('live-operations.alerts'))
        ->post(route('live-operations.alerts.acknowledge', $alert))
        ->assertRedirect(route('live-operations.alerts'));

    $alert->refresh();
    expect($alert->status)->toBe('acknowledged')
        ->and($alert->acknowledged_by)->toBe($supervisor->id)
        ->and($alert->acknowledged_at)->not->toBeNull();

    $resolved = AlertEvent::factory()->create(['status' => 'resolved', 'resolved_at' => now()]);
    $this->actingAs($supervisor)
        ->post(route('live-operations.alerts.acknowledge', $resolved))
        ->assertRedirect();

    expect($resolved->fresh()->status)->toBe('resolved')
        ->and($resolved->fresh()->acknowledged_by)->toBeNull();
});

test('alerts page exposes counts, filters and configuration to viewers', function () {
    AlertEvent::factory()->create(['status' => 'open', 'severity' => 'critical']);
    AlertEvent::factory()->create(['status' => 'acknowledged', 'severity' => 'warning']);
    AlertEvent::factory()->create(['status' => 'resolved', 'severity' => 'info', 'resolved_at' => now()->subHour()]);

    $this->actingAs(expLiveOperator());

    $this->get(route('live-operations.alerts'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('LiveOperations/Alerts')
            ->where('counts.open', 1)
            ->where('counts.acknowledged', 1)
            ->where('counts.resolved_24h', 1)
            ->where('canAcknowledge', false)
            ->has('alerts.data', 3)
            ->has('rules')
            ->where('filters.status', ''));

    $this->get(route('live-operations.alerts', ['status' => 'open', 'severity' => 'critical']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('alerts.data', 1)
            ->where('alerts.data.0.status', 'open')
            ->where('filters.status', 'open')
            ->where('filters.severity', 'critical'));
});

test('old calls missing hangup evidence are excluded from live counts without fabricating completion', function () {
    $this->freezeTime();
    $connection = expLiveConnection();
    $stale = Interaction::factory()->create([
        'pbx_connection_id' => $connection->id,
        'status' => 'active',
        'started_at' => now()->subHours(6),
        'ended_at' => null,
    ]);
    $snapshot = app(LiveOperationsSnapshot::class)->build();

    expect($snapshot['kpis']['active'])->toBe(0)
        ->and($snapshot['active_calls'])->toBeEmpty()
        ->and($stale->fresh()->status)->toBe('active')
        ->and($stale->fresh()->ended_at)->toBeNull();
});

test('long calls with recent PBX evidence remain in the live count', function () {
    $this->freezeTime();
    $connection = expLiveConnection();
    $call = Interaction::factory()->create([
        'pbx_connection_id' => $connection->id,
        'status' => 'active',
        'started_at' => now()->subHours(6),
    ]);
    $call->events()->create([
        'pbx_connection_id' => $connection->id,
        'event_name' => 'Newstate',
        'occurred_at' => now()->subMinute(),
        'fingerprint' => fake()->uuid(),
        'timing_quality' => 'pbx',
        'data' => [],
    ]);

    $snapshot = app(LiveOperationsSnapshot::class)->build();
    expect($snapshot['kpis']['active'])->toBe(1)
        ->and($snapshot['active_calls'][0]['id'])->toBe($call->id);
});
