<?php

use App\Models\CallerCase;
use App\Models\CallLeg;
use App\Models\DentistNumber;
use App\Models\Interaction;
use App\Models\IvrStep;
use App\Models\PbxConnection;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role;

function lifecycleOperator(): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Super Administrator', 'web'));

    return $user;
}

test('only explicit option three permits workspace lists and case entry even for superadmin', function (?string $option) {
    config(['services.exp_pbx.connection_name' => 'workspace-pbx']);
    $connection = PbxConnection::create(['name' => 'workspace-pbx', 'host' => 'localhost']);
    $interaction = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'is_exp' => true]);
    CallLeg::create(['interaction_id' => $interaction->id, 'pbx_connection_id' => $connection->id, 'uniqueid' => 'agent-in', 'role' => 'agent', 'observed_creation' => true]);
    if ($option !== null) {
        IvrStep::factory()->create(['interaction_id' => $interaction->id, 'option' => $option]);
    }
    $user = lifecycleOperator();
    $this->actingAs($user);

    $this->get(route('workspace.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('callContext.calls', 0)->has('callContext.recent', 0)->has('cases.data', 0));
    $this->post(route('workspace.store'), ['interaction_id' => $interaction->id, 'association_confirmed' => true, 'ambiguity_acknowledged' => true])->assertUnprocessable();
    expect(CallerCase::count())->toBe(0);

    $legacyCase = CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $user->id]);
    $this->get(route('workspace.show', $legacyCase))->assertNotFound();
    $this->patch(route('workspace.update', $legacyCase), ['operation' => 'note', 'revision' => $legacyCase->revision, 'body' => 'Must not save'])->assertNotFound();
    $this->patchJson(route('workspace.draft', $legacyCase), [])->assertNotFound();
    expect($legacyCase->notes()->count())->toBe(0);
    $this->get(route('workspace.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('cases.data', 0));
})->with(['recording' => ['1'], 'callback' => ['2'], 'unrecorded' => [null], 'sequence is not a confirmed three' => ['*3']]);

test('a confirmed option three qualifies after another menu selection and keeps one case', function () {
    $user = lifecycleOperator();
    $interaction = Interaction::factory()->create(['is_exp' => true]);
    foreach (['1', '3'] as $option) {
        IvrStep::factory()->create(['interaction_id' => $interaction->id, 'option' => $option]);
    }
    $this->actingAs($user);
    $data = ['interaction_id' => $interaction->id, 'association_confirmed' => true, 'ambiguity_acknowledged' => true];

    $this->post(route('workspace.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
    $case = CallerCase::sole();
    $this->post(route('workspace.store'), $data)->assertRedirect(route('workspace.show', $case));
    expect(CallerCase::count())->toBe(1);
});

test('pbx events persist arrival transfer answer and final end on the same agent case', function () {
    config(['services.exp_pbx.ivr_contexts' => ['ivr-exp']]);
    $connection = PbxConnection::create(['name' => 'lifecycle', 'host' => 'localhost']);
    $number = DentistNumber::factory()->create(['number' => '+233241234567']);
    $frames = warmTransferFrames();
    array_splice($frames, 1, 0, [[
        't' => 1, 'Event' => 'Newexten', 'Uniqueid' => 'caller', 'Linkedid' => 'caller',
        'Context' => 'ivr-exp', 'Exten' => '3', 'Application' => 'Goto',
    ]]);
    correlateCallFrames($connection, $frames);
    $interaction = Interaction::sole();
    $user = lifecycleOperator();
    $case = CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $user->id]);
    $at = fn (int $seconds): string => CarbonImmutable::parse('2026-01-01 10:00:00 UTC')->addSeconds($seconds)->toIso8601String();

    $this->actingAs($user)->get(route('workspace.show', $case))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lifecycle.arrived_at', $at(0))
            ->where('lifecycle.selected_agent_at', $at(1))
            ->where('lifecycle.agent_answered_at', $at(2))
            ->where('lifecycle.ended_at', $at(61))
            ->where('lifecycle.status', 'completed')
            ->has('lifecycle.attempts', 1)
            ->where('lifecycle.attempts.0.dentist', $number->dentist->name)
            ->where('lifecycle.attempts.0.dialled_at', $at(5))
            ->where('lifecycle.attempts.0.answered_at', $at(10))
            ->where('lifecycle.attempts.0.transferred_at', $at(21))
            ->where('lifecycle.attempts.0.ended_at', $at(61))
            ->where('lifecycle.attempts.0.human_accepted_at', null));

    $attempt = $interaction->attempts()->sole();
    expect($attempt->answered_at)->not->toBeNull()
        ->and($attempt->transfer_completed_at)->not->toBeNull()
        ->and($attempt->human_accepted_at)->toBeNull();
});

test('a failed dentist attempt records its actual disposition without inventing an answer', function (string $dialStatus, string $outcome) {
    config(['services.exp_pbx.ivr_contexts' => ['ivr-exp']]);
    $connection = PbxConnection::create(['name' => 'failed-lifecycle', 'host' => 'localhost']);
    $frames = [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'Newexten', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Context' => 'ivr-exp', 'Exten' => '3'],
        ['t' => 2, 'Event' => 'Newchannel', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-one'],
        ['t' => 3, 'Event' => 'DialBegin', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'mobile', 'DestLinkedid' => 'caller', 'DestChannel' => 'PJSIP/trunk-2', 'DialString' => 'PJSIP/trunk/0241234567'],
        ['t' => 10, 'Event' => 'DialEnd', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'mobile', 'DialStatus' => $dialStatus],
        ['t' => 10, 'Event' => 'Hangup', 'Uniqueid' => 'mobile', 'Linkedid' => 'caller'],
        ['t' => 11, 'Event' => 'Hangup', 'Uniqueid' => 'agent', 'Linkedid' => 'caller'],
        ['t' => 12, 'Event' => 'Hangup', 'Uniqueid' => 'caller', 'Linkedid' => 'caller'],
    ];
    correlateCallFrames($connection, $frames);
    $interaction = Interaction::sole();
    $user = lifecycleOperator();
    $case = CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $user->id]);

    $this->actingAs($user)->get(route('workspace.show', $case))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('lifecycle.attempts.0.outcome', $outcome)
            ->where('lifecycle.attempts.0.dial_status', $dialStatus)
            ->where('lifecycle.attempts.0.answered_at', null)
            ->where('lifecycle.attempts.0.transferred_at', null)
            ->where('lifecycle.status', 'completed'));
})->with([['NOANSWER', 'No answer'], ['BUSY', 'Busy'], ['CANCEL', 'Cancelled'], ['CHANUNAVAIL', 'Failed']]);

test('an unobserved final hangup remains missing even after the agent has left', function () {
    config(['services.exp_pbx.ivr_contexts' => ['ivr-exp']]);
    $connection = PbxConnection::create(['name' => 'partial-lifecycle', 'host' => 'localhost']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'Newexten', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Context' => 'ivr-exp', 'Exten' => '3'],
        ['t' => 2, 'Event' => 'Newchannel', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-one'],
        ['t' => 5, 'Event' => 'Hangup', 'Uniqueid' => 'agent', 'Linkedid' => 'caller'],
    ]);
    $interaction = Interaction::sole();

    $lifecycle = app(App\Services\CallLifecycle::class)->for($interaction);
    expect($lifecycle['ended_at'])->toBeNull()
        ->and($lifecycle['agent_answered_at'])->toBeNull()
        ->and($lifecycle['status'])->toBe('active');
});
