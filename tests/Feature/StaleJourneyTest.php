<?php

use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use Carbon\CarbonImmutable;

/**
 * Create the PBX connection the stale journey closer resolves per interaction.
 *
 * @param  array<string, mixed>  $attributes
 */
function staleJourneyConnection(array $attributes = []): PbxConnection
{
    return PbxConnection::create(array_merge([
        'name' => config('services.exp_pbx.connection_name'),
        'host' => '127.0.0.1',
        'status' => 'connected',
        'last_heartbeat_at' => now(),
        'last_event_at' => now(),
    ], $attributes));
}

/**
 * Create an interaction whose AMI evidence stopped arriving mid-call.
 */
function staleJourney(PbxConnection $connection, int $quietMinutes, int $ageMinutes = 40): Interaction
{
    $interaction = Interaction::create([
        'pbx_connection_id' => $connection->id,
        'correlation_key' => fake()->uuid(),
        'status' => 'active',
        'timing_quality' => 'received',
        'reconciliation_status' => 'pending',
        'started_at' => now()->subMinutes($ageMinutes),
    ]);

    CallLeg::create([
        'interaction_id' => $interaction->id,
        'pbx_connection_id' => $connection->id,
        'uniqueid' => fake()->unique()->uuid(),
        'channel' => 'PJSIP/204-00000001',
        'role' => 'agent',
        'observed_creation' => true,
        'started_at' => $interaction->started_at,
        'answered_at' => $interaction->started_at,
    ]);

    CallEvent::create([
        'pbx_connection_id' => $connection->id,
        'interaction_id' => $interaction->id,
        'fingerprint' => hash('sha256', fake()->uuid()),
        'event_name' => 'Newstate',
        'occurred_at' => CarbonImmutable::now()->subMinutes($quietMinutes),
        'timing_quality' => 'received',
        'data' => [
            'participants' => [], 'primary_id' => null, 'destination_id' => null,
            'state' => 'Up', 'dial_status' => null, 'transfer_type' => null,
            'transfer_result' => null, 'invalid_timestamp' => false, 'ivr' => null,
        ],
    ]);

    return $interaction;
}

test('a journey with no recent PBX evidence is closed at its last event', function () {
    $interaction = staleJourney(staleJourneyConnection(), quietMinutes: 40);
    $lastEventAt = CarbonImmutable::parse((string) $interaction->events()->max('occurred_at'));

    $this->artisan('exp:close-stale-journeys', ['--minutes' => 15])->assertSuccessful();

    $interaction->refresh();

    expect($interaction->status)->toBe('completed')
        ->and($interaction->ended_at?->toIso8601String())->toBe($lastEventAt->toIso8601String())
        ->and($interaction->reconciliation_status)->toBe('pending')
        ->and($interaction->legs()->whereNull('ended_at')->count())->toBe(0)
        ->and(Interaction::query()->recentlyActive()->count())->toBe(0)
        ->and(CorrelationException::query()->where('interaction_id', $interaction->id)->where('code', 'journey_closed_stale')->exists())->toBeTrue();
});

test('a journey that still has recent PBX evidence stays active', function () {
    $interaction = staleJourney(staleJourneyConnection(), quietMinutes: 2);

    $this->artisan('exp:close-stale-journeys', ['--minutes' => 15])->assertSuccessful();

    expect($interaction->refresh()->status)->toBe('active')
        ->and($interaction->ended_at)->toBeNull()
        ->and(CorrelationException::query()->where('interaction_id', $interaction->id)->exists())->toBeFalse();
});

test('journeys are not closed while the connection feed itself is stale', function () {
    $connection = staleJourneyConnection([
        'last_heartbeat_at' => now()->subMinutes(30),
        'last_event_at' => now()->subMinutes(30),
    ]);
    $interaction = staleJourney($connection, quietMinutes: 40);

    $this->artisan('exp:close-stale-journeys', ['--minutes' => 15])->assertSuccessful();

    expect($interaction->refresh()->status)->toBe('active')
        ->and($interaction->ended_at)->toBeNull();
});

test('a dry run reports stale journeys without closing them', function () {
    $interaction = staleJourney(staleJourneyConnection(), quietMinutes: 40);

    $this->artisan('exp:close-stale-journeys', ['--minutes' => 15, '--dry-run' => true])
        ->expectsOutputToContain('Would close 1 stale journey(s)')
        ->assertSuccessful();

    expect($interaction->refresh()->status)->toBe('active')
        ->and($interaction->ended_at)->toBeNull();
});

test('the configured threshold is used when no window is passed', function () {
    config(['services.exp_pbx.stale_journey_minutes' => 10]);
    $interaction = staleJourney(staleJourneyConnection(), quietMinutes: 12);

    $this->artisan('exp:close-stale-journeys')->assertSuccessful();

    expect($interaction->refresh()->status)->toBe('completed');
});
