<?php

use App\Models\AmiEvent;
use App\Models\Interaction;
use App\Models\IvrStep;
use App\Models\PbxConnection;
use App\Models\User;
use App\Services\CallJourneyTimeline;
use App\Services\IvrJourney;
use App\Services\PbxTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/** @param list<string> $permissions */
function inboundCallUser(array $permissions): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission, 'web');
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/**
 * A caller who pressed the agent option, plus a self-service caller who only
 * listened to the recorded message.
 *
 * @return array{agent: Interaction, selfServe: Interaction}
 */
function ivrCallFixtures(): array
{
    Config::set('services.exp_pbx.ivr_contexts', ['exp-ivr']);

    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);

    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'agent-caller', 'Linkedid' => 'agent-caller', 'Context' => 'exp-ivr', 'Exten' => 's', 'Channel' => 'PJSIP/0241234567-0001', 'CallerIDNum' => '0241234567'],
        ['t' => 2, 'Event' => 'Newexten', 'Uniqueid' => 'agent-caller', 'Linkedid' => 'agent-caller', 'Context' => 'exp-ivr', 'Exten' => '3', 'Application' => 'Goto'],
        ['t' => 3, 'Event' => 'Newchannel', 'Uniqueid' => 'agent-leg', 'Linkedid' => 'agent-caller', 'Channel' => 'PJSIP/204-test', 'Context' => 'exp-agent'],
        ['t' => 20, 'Event' => 'Hangup', 'Uniqueid' => 'agent-caller', 'Linkedid' => 'agent-caller'],
    ]);

    correlateCallFrames($connection, [
        ['t' => 100, 'Event' => 'Newchannel', 'Uniqueid' => 'self-caller', 'Linkedid' => 'self-caller', 'Context' => 'exp-ivr', 'Exten' => 's', 'Channel' => 'PJSIP/0247654321-0002', 'CallerIDNum' => '0247654321'],
        ['t' => 101, 'Event' => 'Newexten', 'Uniqueid' => 'self-caller', 'Linkedid' => 'self-caller', 'Context' => 'exp-ivr', 'Exten' => '1', 'Application' => 'Playback'],
        ['t' => 115, 'Event' => 'Hangup', 'Uniqueid' => 'self-caller', 'Linkedid' => 'self-caller'],
    ]);

    return [
        'agent' => Interaction::query()->whereHas('legs', fn ($legs) => $legs->where('role', 'agent'))->firstOrFail(),
        'selfServe' => Interaction::query()->whereDoesntHave('legs', fn ($legs) => $legs->where('role', 'agent'))->firstOrFail(),
    ];
}

test('menu key presses are projected as retained evidence', function () {
    $fixtures = ivrCallFixtures();

    $agentSteps = IvrStep::query()->where('interaction_id', $fixtures['agent']->id)->get();
    $selfSteps = IvrStep::query()->where('interaction_id', $fixtures['selfServe']->id)->get();

    expect($agentSteps)->toHaveCount(1)
        ->and($agentSteps->first()->option)->toBe('3')
        ->and($agentSteps->first()->menu)->toBe('exp-ivr')
        ->and($selfSteps->first()->option)->toBe('1');
});

test('agent calls list only the calls that reached the agent', function () {
    $fixtures = ivrCallFixtures();

    $this->withoutVite();
    $this->actingAs(inboundCallUser(['view call history', 'view pbx']))
        ->get(route('calls.agent'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calls/Agent')
            ->has('calls.data', 1)
            ->where('calls.data.0.id', $fixtures['agent']->id)
            ->where('calls.data.0.basis', 'ivr')
            ->where('agentOption', '3'));
});

test('option two is a self-service product recording without an agent case', function () {
    $fixtures = ivrCallFixtures();
    $interaction = $fixtures['selfServe'];
    $interaction->ivrSteps()->update(['option' => '2']);
    $interaction->load('ivrSteps');
    $journey = app(IvrJourney::class);

    expect($journey->selections($interaction)[0])
        ->toMatchArray([
            'option' => '2',
            'label' => 'Option 2 · Listen to Pepsodent reach, products and availability information',
            'kind' => 'recording',
        ])
        ->and($journey->outcome($interaction, []))->toBe('Recorded message option · caller did not request an agent')
        ->and($journey->selectedAgentOption($interaction))->toBeFalse()
        ->and(Interaction::agentSelected()->whereKey($interaction->id)->exists())->toBeFalse()
        ->and($interaction->callerCase)->toBeNull();
});

test('vitalpbx dtmf presses are captured once and agent dials are ignored', function () {
    Config::set('services.exp_pbx.ivr_contexts', ['IVR-*', 'app-ivr', 'app-announcement']);

    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);

    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'dtmf-caller', 'Linkedid' => 'dtmf-caller', 'Context' => 'IVR-2', 'Exten' => 's', 'Channel' => 'PJSIP/0241234567-0003', 'CallerIDNum' => '0241234567'],
        ['t' => 5, 'Event' => 'DTMFEnd', 'Uniqueid' => 'dtmf-caller', 'Linkedid' => 'dtmf-caller', 'Context' => 'app-announcement', 'Exten' => 's', 'Digit' => '2', 'Direction' => 'Received', 'Channel' => 'PJSIP/0241234567-0003'],
        ['t' => 6, 'Event' => 'DTMFEnd', 'Uniqueid' => 'dtmf-caller', 'Linkedid' => 'dtmf-caller', 'Context' => 'IVR-2', 'Exten' => 's', 'Digit' => '2', 'Direction' => 'Received', 'Channel' => 'PJSIP/0241234567-0003'],
        ['t' => 30, 'Event' => 'Newchannel', 'Uniqueid' => 'dtmf-agent', 'Linkedid' => 'dtmf-caller', 'Context' => 'exp-agent', 'Channel' => 'PJSIP/204-test'],
        ['t' => 40, 'Event' => 'DTMFEnd', 'Uniqueid' => 'dtmf-agent', 'Linkedid' => 'dtmf-caller', 'Context' => 'IVR-2', 'Exten' => 's', 'Digit' => '9', 'Direction' => 'Received', 'Channel' => 'PJSIP/204-test'],
        ['t' => 60, 'Event' => 'Hangup', 'Uniqueid' => 'dtmf-caller', 'Linkedid' => 'dtmf-caller'],
        ['t' => 61, 'Event' => 'Hangup', 'Uniqueid' => 'dtmf-agent', 'Linkedid' => 'dtmf-caller'],
    ]);

    $interaction = Interaction::query()->firstOrFail();
    $steps = IvrStep::query()->where('interaction_id', $interaction->id)->get();

    expect($steps)->toHaveCount(1)
        ->and($steps->first()->option)->toBe('2')
        ->and($steps->first()->menu)->toBe('app-announcement')
        ->and($steps->first()->application)->toBe('DTMF')
        ->and($interaction->status)->toBe('completed');
});

test('retained ami events can be replayed to recover an unrecorded menu press', function () {
    Config::set('services.exp_pbx.ivr_contexts', ['IVR-*', 'app-ivr', 'app-announcement']);

    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);

    foreach ([
        ['Event' => 'Newchannel', 'Uniqueid' => 'replay-caller', 'Linkedid' => 'replay-caller', 'Context' => 'IVR-2', 'Exten' => 's', 'Channel' => 'PJSIP/0241234567-0004', 'CallerIDNum' => '0241234567'],
        ['Event' => 'DTMFEnd', 'Uniqueid' => 'replay-caller', 'Linkedid' => 'replay-caller', 'Context' => 'IVR-2', 'Exten' => 's', 'Digit' => '3', 'Direction' => 'Received', 'Channel' => 'PJSIP/0241234567-0004'],
    ] as $index => $frame) {
        AmiEvent::create([
            'pbx_connection_id' => $connection->id,
            'event_name' => $frame['Event'],
            'uniqueid' => $frame['Uniqueid'],
            'linkedid' => $frame['Linkedid'],
            'payload' => app(PbxTelemetry::class)->redact($frame),
            'received_at' => now()->addSeconds($index),
            'correlation_data' => null,
        ]);
    }

    expect(IvrStep::count())->toBe(0);

    $this->artisan('exp:correlate-calls', ['--since' => now()->subMinute()->toDateTimeString()])->assertSuccessful();

    expect(IvrStep::query()->sole()->option)->toBe('3');
});

test('the DID log lists every inbound call with its menu journey', function () {
    ivrCallFixtures();

    $this->withoutVite();
    $this->actingAs(inboundCallUser(['view call history', 'view pbx']))
        ->get(route('calls.did'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calls/Did')
            ->has('calls.data', 2)
            ->where('calls.data.0.chips', fn ($chips): bool => collect($chips)->pluck('label')->contains('Option 1'))
            ->where('calls.data.1.chips', fn ($chips): bool => collect($chips)->pluck('label')->contains('Option 3')));
});

test('the DID log filters self-service callers away from agent calls', function () {
    ivrCallFixtures();
    $user = inboundCallUser(['view call history', 'view pbx']);

    $this->withoutVite();
    $this->actingAs($user)
        ->get(route('calls.did', ['agent' => 'no']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('calls.data', 1)
            ->where('calls.data.0.basis', 'ivr_unrecorded'));
});

test('the call detail timeline shows the recorded key press', function () {
    $fixtures = ivrCallFixtures();
    $timeline = app(CallJourneyTimeline::class)->for($fixtures['agent']->fresh(['legs', 'ivrSteps', 'bridges', 'events', 'attempts.dentist', 'attempts.session', 'attempts.events']));

    expect(collect($timeline)->pluck('label'))->toContain('Caller pressed 3 on the IVR menu');
});

test('inbound call screens reject users without call access', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));

    $this->withoutVite();
    $this->actingAs(inboundCallUser(['view dentists']))->get(route($route))->assertForbidden();
})->with(['calls.agent', 'calls.did']);
