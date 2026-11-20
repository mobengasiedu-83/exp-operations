<?php

use App\Models\CallControlCommand;
use App\Models\CallerCase;
use App\Models\CallLeg;
use App\Models\DentistNumber;
use App\Models\PbxConnection;
use App\Models\ScreeningProtocol;
use App\Models\TransferAttempt;
use App\Models\User;
use App\Services\CallHistoryRecord;
use App\Services\GhanaPhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** @return array{user: User, case: CallerCase, snapshot: array<string, mixed>} */
function controlFixture(string $state = 'incoming'): array
{
    foreach (['view cases', 'edit cases', 'control calls', 'view all cases'] as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['view cases', 'edit cases', 'control calls']);
    $case = CallerCase::factory()->create(['assigned_to' => $user->id, 'priority' => 'P3']);
    $interaction = $case->interaction;
    PbxConnection::whereKey($interaction->pbx_connection_id)->update(['status' => 'connected', 'last_heartbeat_at' => now(), 'ami_username' => 'readonly']);
    foreach (['caller' => 'caller-control', 'agent' => 'agent-control'] as $role => $id) {
        CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id, 'uniqueid' => $id, 'role' => $role]);
    }
    config(['services.exp_call_control.enabled' => true, 'services.exp_call_control.lab_approved' => true,
        'services.exp_call_control.url' => 'https://control.example.test', 'services.exp_call_control.token' => 'separate-control-secret', 'services.exp_call_control.identity' => 'exp-control']);
    $hasDentist = in_array($state, ['dentist_dialling', 'dentist_ringing', 'dentist_connected', 'private_briefing', 'caller_dentist_bridge'], true);
    if ($hasDentist) {
        CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id, 'uniqueid' => 'mobile-control', 'role' => 'mobile', 'dial_started_at' => now()->subSeconds(10), 'answered_at' => now()->subSeconds(5)]);
    }
    $snapshot = ['interaction_key' => $interaction->correlation_key, 'state' => $state, 'version' => 'v1', 'observed_at' => now()->toIso8601String(),
        'control_identity' => 'exp-control', 'agent_extension' => (string) config('services.exp_pbx.screening_extension'),
        'caller_uniqueid' => 'caller-control', 'agent_uniqueid' => 'agent-control', 'dentist_uniqueid' => $hasDentist ? 'mobile-control' : null,
        'caller_present' => true, 'agent_present' => true, 'agent_media_ready' => true, 'topology_verified' => true, 'last_command_key' => null];
    Http::preventStrayRequests();

    return compact('user', 'case', 'snapshot');
}

/** @return array<string, mixed> */
function controlPayload(string $action = 'answer'): array
{
    return ['request_key' => (string) Str::uuid(), 'action' => $action, 'expected_version' => 'v1'];
}

/** @param array<string, mixed> $snapshot */
function fakeControlGateway(array $snapshot): void
{
    Http::fake(fn (ClientRequest $request) => $request->method() === 'POST'
        ? Http::response(['request_key' => $request['request_key']], 202) : Http::response($snapshot));
}

function controlConsentAndScreen(CallerCase $case, User $user): void
{
    $protocol = ScreeningProtocol::factory()->active()->create();
    $case->consents()->create(['screening_protocol_id' => $protocol->id, 'recorded_by' => $user->id, 'status' => 'granted', 'callback_allowed' => false, 'recorded_at' => now()]);
    $case->screenings()->create(['screening_protocol_id' => $protocol->id, 'recorded_by' => $user->id, 'priority' => 'P3', 'complete' => true, 'completed_at' => now()]);
}

test('call controls require authentication permission and physical case ownership', function () {
    ['user' => $user, 'case' => $case] = controlFixture();
    $this->getJson(route('call-control.show', $case))->assertUnauthorized();
    $user->revokePermissionTo('control calls');
    $this->actingAs($user)->getJson(route('call-control.show', $case))->assertForbidden();
    $this->postJson(route('call-control.store', $case), controlPayload())->assertForbidden();
    $other = User::factory()->create();
    $other->givePermissionTo(['view cases', 'edit cases', 'view all cases', 'control calls']);
    $this->actingAs($other)->getJson(route('call-control.show', $case))->assertNotFound();
    $this->postJson(route('call-control.store', $case), controlPayload())->assertNotFound();
    Http::assertNothingSent();
});

test('disabled or unapproved controls use manual fallback without a network request', function (string $setting) {
    ['user' => $user, 'case' => $case] = controlFixture();
    config(['services.exp_call_control.'.$setting => false]);
    $this->actingAs($user)->getJson(route('call-control.show', $case))->assertOk()->assertJsonPath('available', false)->assertJsonPath('actions', []);
    $this->postJson(route('call-control.store', $case), controlPayload())->assertServiceUnavailable();
    Http::assertNothingSent();
    $this->assertDatabaseCount('call_control_commands', 0);
})->with(['enabled', 'lab_approved']);

test('only state appropriate call actions are offered', function (string $state, array $actions) {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture($state);
    fakeControlGateway($snapshot);
    $this->actingAs($user)->getJson(route('call-control.show', $case))->assertOk()->assertJsonPath('state', $state)->assertJsonPath('actions', $actions);
})->with([
    ['incoming', ['answer']], ['agent_connected', ['hold']], ['caller_held', ['resume', 'dial_dentist']],
    ['dentist_dialling', ['cancel_attempt']], ['dentist_ringing', ['cancel_attempt']],
    ['dentist_connected', ['begin_briefing', 'cancel_attempt']], ['private_briefing', ['complete_transfer', 'cancel_attempt']],
    ['caller_dentist_bridge', ['release_agent']], ['completed', []],
]);

test('call command retries never submit twice and new keys cannot bypass an unresolved command', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture();
    fakeControlGateway($snapshot);
    $payload = controlPayload();
    $this->actingAs($user)->postJson(route('call-control.store', $case), $payload)->assertOk()->assertJsonPath('command.status', 'pending');
    $this->postJson(route('call-control.store', $case), $payload)->assertOk();
    $this->postJson(route('call-control.store', $case), controlPayload())->assertConflict();
    $this->postJson(route('call-control.store', $case), [...$payload, 'expected_version' => 'changed'])->assertConflict();
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'))->toHaveCount(1);
    $this->assertDatabaseCount('call_control_commands', 1);
    $this->assertDatabaseHas('activity_log', ['log_name' => 'call-control', 'description' => 'Call command reserved', 'causer_id' => $user->id]);
});

test('stale mismatched media or identity evidence blocks all command dispatch', function (string $field, mixed $value) {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture();
    $snapshot[$field] = $value;
    fakeControlGateway($snapshot);
    $this->actingAs($user)->postJson(route('call-control.store', $case), controlPayload())->assertConflict();
    $this->assertDatabaseCount('call_control_commands', 0);
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'))->toHaveCount(0);
})->with([
    ['observed_at', '2000-01-01T00:00:00Z'], ['interaction_key', 'different-call'], ['caller_uniqueid', 'another-caller'],
    ['agent_uniqueid', 'another-agent'], ['control_identity', 'wrong-control-user'], ['agent_extension', '999'],
    ['topology_verified', false], ['agent_media_ready', false], ['caller_present', false],
]);

test('state changes and unsafe actions are rejected before a command is reserved', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture('agent_connected');
    fakeControlGateway($snapshot);
    $this->actingAs($user)->postJson(route('call-control.store', $case), controlPayload())->assertConflict();
    $this->postJson(route('call-control.store', $case), [...controlPayload('hold'), 'expected_version' => 'old'])->assertConflict();
    $this->assertDatabaseCount('call_control_commands', 0);
});

test('a delivery timeout stays uncertain and cannot originate a duplicate command', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture();
    Http::fake(fn (ClientRequest $request) => $request->method() === 'POST' ? throw new ConnectionException('control-secret') : Http::response($snapshot));
    $payload = controlPayload();
    $this->actingAs($user)->postJson(route('call-control.store', $case), $payload)->assertOk()->assertJsonPath('command.status', 'uncertain');
    $this->postJson(route('call-control.store', $case), $payload)->assertOk();
    expect(CallControlCommand::sole()->toJson())->not->toContain('control-secret');
    expect(collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'))->toHaveCount(0);
    $this->postJson(route('call-control.store', $case), controlPayload())->assertConflict();
});

test('command acknowledgement is not success and confirmation needs changed verified PBX state', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture();
    $payload = controlPayload();
    Http::fake(function (ClientRequest $request) use (&$snapshot, $payload) {
        if ($request->method() === 'POST') {
            return Http::response(['request_key' => $request['request_key']], 202);
        }

        return str_contains($request->url(), '/v1/commands/')
            ? Http::response(['request_key' => $payload['request_key'], 'status' => 'applied']) : Http::response($snapshot);
    });
    $this->actingAs($user)->postJson(route('call-control.store', $case), $payload)->assertOk();
    $this->getJson(route('call-control.show', $case))->assertJsonPath('pending.status', 'pending')->assertJsonPath('actions', []);
    $snapshot = [...$snapshot, 'version' => 'v2', 'state' => 'agent_connected', 'last_command_key' => $payload['request_key']];
    $this->getJson(route('call-control.show', $case))->assertJsonPath('pending', null)->assertJsonPath('actions', ['hold']);
    expect(CallControlCommand::sole()->status)->toBe('confirmed');
});

test('dentist dial uses only a server selected approved number after consent and screening', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture('caller_held');
    $number = DentistNumber::factory()->create();
    fakeControlGateway($snapshot);
    $payload = [...controlPayload('dial_dentist'), 'dentist_number_id' => $number->id, 'destination' => '+233000000000'];
    $this->actingAs($user)->postJson(route('call-control.store', $case), $payload)->assertUnprocessable();
    controlConsentAndScreen($case, $user);
    $this->postJson(route('call-control.store', $case), $payload)->assertOk();
    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST' && $request['destination'] === $number->number && $request->hasHeader('Idempotency-Key', $payload['request_key']));
    expect(CallControlCommand::sole()->toJson())->not->toContain($number->number);
});

test('emergency unapproved inactive and manually busy dentist selections cannot be dialled', function (string $reason) {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture('caller_held');
    controlConsentAndScreen($case, $user);
    $number = DentistNumber::factory()->create();
    match ($reason) {
        'emergency' => $case->update(['priority' => 'P1']),
        'unapproved' => $number->update(['status' => 'pending']),
        'inactive' => $number->dentist->update(['active' => false]),
        'busy' => $number->dentist->availability()->create(['status' => 'busy', 'starts_at' => now()->subMinute(), 'ends_at' => now()->addHour()]),
    };
    fakeControlGateway($snapshot);
    $this->actingAs($user)->postJson(route('call-control.store', $case), [...controlPayload('dial_dentist'), 'dentist_number_id' => $number->id])->assertUnprocessable();
    $this->assertDatabaseCount('call_control_commands', 0);
})->with(['emergency', 'unapproved', 'inactive', 'busy']);

test('transfer completion requires explicit human acceptance and an approved answered mobile leg', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture('private_briefing');
    $snapshot['dentist_uniqueid'] = 'mobile-control';
    fakeControlGateway($snapshot);
    $this->actingAs($user)->postJson(route('call-control.store', $case), controlPayload('complete_transfer'))->assertUnprocessable()->assertJsonValidationErrors('human_accepted');
    $this->postJson(route('call-control.store', $case), [...controlPayload('complete_transfer'), 'human_accepted' => true])->assertConflict();
    $this->assertDatabaseCount('call_control_commands', 0);
});

test('verified transfer confirmation records human acceptance once and preserves the caller dentist bridge', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture('private_briefing');
    controlConsentAndScreen($case, $user);
    $number = DentistNumber::factory()->create();
    $interaction = $case->interaction;
    $mobile = $interaction->legs()->where('uniqueid', 'mobile-control')->firstOrFail();
    $mobile->update(['destination_hash' => GhanaPhoneNumber::fingerprint($number->number), 'destination_masked' => $number->masked(), 'dial_started_at' => now()->subSeconds(10), 'answered_at' => now()->subSeconds(5)]);
    $attempt = TransferAttempt::factory()->create(['interaction_id' => $interaction->id, 'call_leg_id' => $mobile->id, 'dentist_id' => $number->dentist_id, 'match_status' => 'approved', 'answered_at' => $mobile->answered_at]);
    $briefing = $interaction->bridges()->create(['bridge_uniqueid' => 'private-control', 'stage' => 'private_handover', 'participants' => ['agent-control', 'mobile-control'], 'started_at' => now()->subSeconds(4)]);
    $snapshot['dentist_uniqueid'] = $mobile->uniqueid;
    $payload = [...controlPayload('complete_transfer'), 'human_accepted' => true];
    Http::fake(function (ClientRequest $request) use (&$snapshot, $payload) {
        if ($request->method() === 'POST') {
            return Http::response(['request_key' => $request['request_key']], 202);
        }

        return str_contains($request->url(), '/v1/commands/') ? Http::response(['request_key' => $payload['request_key'], 'status' => 'applied']) : Http::response($snapshot);
    });
    $this->actingAs($user)->postJson(route('call-control.store', $case), $payload)->assertOk()->assertJsonPath('command.status', 'pending');
    expect($attempt->fresh()->human_accepted_at)->toBeNull();
    $briefing->update(['ended_at' => now()]);
    $interaction->bridges()->create(['bridge_uniqueid' => 'consultation-control', 'stage' => 'consultation', 'participants' => ['caller-control', 'mobile-control'], 'started_at' => now()]);
    $snapshot = [...$snapshot, 'version' => 'v2', 'state' => 'caller_dentist_bridge', 'last_command_key' => $payload['request_key']];

    $this->getJson(route('call-control.show', $case))->assertJsonPath('state', 'caller_dentist_bridge')->assertJsonPath('pending', null);
    $this->getJson(route('call-control.show', $case))->assertOk();

    expect($attempt->events()->where('kind', 'operator_outcome')->count())->toBe(1);
    expect($attempt->fresh()->human_accepted_at)->not->toBeNull();
    expect($interaction->bridges()->where('stage', 'consultation')->whereNull('ended_at')->count())->toBe(1);
    expect(app(CallHistoryRecord::class)->summary($interaction->fresh()->load(['callerCase.agent', 'attempts.dentist', 'attempts.session']), $user)['connected_dentist'])->toBe($number->dentist->name);
});

test('a safety escalation during the gateway lookup prevents dentist dispatch', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture('caller_held');
    controlConsentAndScreen($case, $user);
    $number = DentistNumber::factory()->create();
    Http::fake(function () use ($case, $snapshot) {
        CallerCase::whereKey($case->id)->update(['priority' => 'P1']);

        return Http::response($snapshot);
    });

    $this->actingAs($user)->postJson(route('call-control.store', $case), [...controlPayload('dial_dentist'), 'dentist_number_id' => $number->id])->assertUnprocessable();

    $this->assertDatabaseCount('call_control_commands', 0);
});

test('safe command transitions target the verified participant identifiers', function (string $state, string $action) {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture($state);
    if ($action === 'release_agent') {
        $case->interaction->bridges()->create(['bridge_uniqueid' => 'release-proof', 'stage' => 'consultation', 'participants' => ['caller-control', 'mobile-control'], 'started_at' => now()->subSecond()]);
    }
    fakeControlGateway($snapshot);

    $this->actingAs($user)->postJson(route('call-control.store', $case), controlPayload($action))->assertOk()->assertJsonPath('command.status', 'pending');

    Http::assertSent(fn (ClientRequest $request) => $request->method() === 'POST' && $request['action'] === $action
        && $request['caller_uniqueid'] === 'caller-control' && $request['agent_uniqueid'] === 'agent-control');
})->with([
    ['agent_connected', 'hold'], ['caller_held', 'resume'], ['dentist_dialling', 'cancel_attempt'],
    ['dentist_ringing', 'cancel_attempt'], ['dentist_connected', 'begin_briefing'], ['caller_dentist_bridge', 'release_agent'],
]);

test('agent release without a verified caller dentist bridge is refused', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture('caller_dentist_bridge');
    fakeControlGateway($snapshot);

    $this->actingAs($user)->postJson(route('call-control.store', $case), controlPayload('release_agent'))->assertConflict();

    $this->assertDatabaseCount('call_control_commands', 0);
});

test('gateway rejection releases the command reservation for a new verified action', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture();
    $payload = controlPayload();
    Http::fake(function (ClientRequest $request) use ($snapshot, $payload) {
        if ($request->method() === 'POST') {
            return Http::response(['request_key' => $request['request_key']], 202);
        }

        return str_contains($request->url(), '/v1/commands/') ? Http::response(['request_key' => $payload['request_key'], 'status' => 'rejected']) : Http::response($snapshot);
    });
    $this->actingAs($user)->postJson(route('call-control.store', $case), $payload)->assertOk();

    $this->getJson(route('call-control.show', $case))->assertJsonPath('pending', null)->assertJsonPath('actions', ['answer']);

    expect(CallControlCommand::sole()->status)->toBe('rejected');
    expect(CallControlCommand::sole()->active_interaction_id)->toBeNull();
});

test('offline telemetry prevents any control-service traffic', function () {
    ['user' => $user, 'case' => $case] = controlFixture();
    PbxConnection::whereKey($case->interaction->pbx_connection_id)->update(['status' => 'disconnected']);

    $this->actingAs($user)->postJson(route('call-control.store', $case), controlPayload())->assertConflict();

    Http::assertNothingSent();
});

test('super administrator can control another agents call with audit attribution', function () {
    ['case' => $case, 'snapshot' => $snapshot] = controlFixture();
    $admin = User::factory()->create();
    $admin->assignRole(Role::findOrCreate('Super Administrator', 'web'));
    fakeControlGateway($snapshot);

    $this->actingAs($admin)->getJson(route('call-control.show', $case))->assertOk();
    $this->postJson(route('call-control.store', $case), controlPayload())->assertOk();

    expect(CallControlCommand::firstOrFail()->requested_by)->toBe($admin->id)
        ->and($case->fresh()->assigned_to)->not->toBe($admin->id);
});

test('a completed safety screen permits dentist dialling without a consent record', function () {
    ['user' => $user, 'case' => $case, 'snapshot' => $snapshot] = controlFixture('caller_held');
    $protocol = ScreeningProtocol::factory()->active()->create();
    $case->screenings()->create(['screening_protocol_id' => $protocol->id, 'recorded_by' => $user->id, 'priority' => 'P3', 'complete' => true, 'completed_at' => now()]);
    $number = DentistNumber::factory()->create();
    fakeControlGateway($snapshot);

    $this->actingAs($user)->postJson(route('call-control.store', $case), [...controlPayload('dial_dentist'), 'dentist_number_id' => $number->id])->assertOk();

    expect($case->consents()->count())->toBe(0)
        ->and(CallControlCommand::sole()->action)->toBe('dial_dentist');
});
