<?php

use App\Models\CallBridge;
use App\Models\CallerCase;
use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\Dentist;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\Screening;
use App\Models\ScreeningAnswer;
use App\Models\ScreeningProtocol;
use App\Models\TransferAttempt;
use App\Models\User;
use App\Services\GhanaPhoneNumber;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

test('call journey and diagnostic routes enforce authentication and permission', function () {
    $interaction = Interaction::factory()->create();
    $urls = [route('calls.index'), route('calls.show', $interaction), route('correlation-exceptions.index')];
    foreach ($urls as $url) {
        $this->get($url)->assertRedirect(route('login'));
    }
    $user = User::factory()->create();
    $this->actingAs($user);
    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }
    Permission::findOrCreate('view pbx');
    $user->givePermissionTo('view pbx');
    $this->get(route('calls.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Calls/Index')->has('interactions.data', 1));
    $this->get(route('calls.show', $interaction))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Calls/Show')->has('legs')->has('events')->has('metrics')->has('bridges'));
    $this->get(route('correlation-exceptions.index'))->assertOk();
});

test('PBX viewers cannot trigger reconciliation and authorised operators can', function () {
    $interaction = Interaction::factory()->create(['status' => 'completed']);
    Permission::findOrCreate('view pbx');
    Permission::findOrCreate('edit settings');
    $user = User::factory()->create();
    $user->givePermissionTo('view pbx');
    $this->actingAs($user)->post(route('calls.reconcile', $interaction))->assertForbidden();
    expect($interaction->fresh()->reconciled_at)->toBeNull();
    $user->givePermissionTo('edit settings');
    $this->post(route('calls.reconcile', $interaction))->assertRedirect();
    expect($interaction->fresh()->reconciled_at)->not->toBeNull();
});

test('authorised manual reconciliation records an administration audit entry', function () {
    $interaction = Interaction::factory()->create(['status' => 'completed']);
    Permission::findOrCreate('view pbx');
    Permission::findOrCreate('edit settings');
    $user = User::factory()->create();
    $user->givePermissionTo(['view pbx', 'edit settings']);
    $this->actingAs($user)->post(route('calls.reconcile', $interaction))->assertRedirect();
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'administration',
        'description' => 'Administration change',
        'causer_id' => $user->id,
    ]);
});

test('call history shows masked caller, inbound and dentist numbers and searches by number', function () {
    Permission::findOrCreate('view pbx');
    $user = User::factory()->create();
    $user->givePermissionTo('view pbx');
    $interaction = Interaction::factory()->create();
    CallLeg::create([
        'interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id,
        'uniqueid' => 'caller-leg', 'role' => 'caller', 'channel' => 'PJSIP/trunk-1', 'observed_creation' => true,
        'caller_masked' => '+233241*****67', 'caller_hash' => GhanaPhoneNumber::fingerprint('0241234567'),
        'inbound_masked' => '030*****56',
    ]);
    CallLeg::create([
        'interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id,
        'uniqueid' => 'mobile-leg', 'role' => 'mobile', 'channel' => 'PJSIP/trunk-2', 'observed_creation' => true,
        'destination_masked' => '+233201*****23', 'destination_hash' => GhanaPhoneNumber::fingerprint('0201234523'),
    ]);

    $this->actingAs($user)->get(route('calls.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Calls/Index')
            ->where('interactions.data.0.caller_number', '+233241*****67')
            ->where('interactions.data.0.inbound_line', '030*****56')
            ->where('interactions.data.0.dentist_number', '+233201*****23'));

    $this->get(route('calls.index', ['number' => '0241234567']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 1));
    $this->get(route('calls.index', ['number' => '0201234523']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 1));
    $this->get(route('calls.index', ['number' => '+233241*****67']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 1));
    $this->get(route('calls.index', ['number' => '0240000000']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 0));

    $this->get(route('calls.show', $interaction))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('interaction.caller_number', '+233241*****67')
            ->where('interaction.inbound_line', '030*****56')
            ->where('interaction.dentist_number', '+233201*****23')
            ->has('legs.data', 2)
            ->where('legs.data.0.caller_masked', '+233241*****67'));
});

test('invalid journey filters return validation errors', function () {
    Permission::findOrCreate('view pbx');
    $user = User::factory()->create();
    $user->givePermissionTo('view pbx');
    $this->actingAs($user)->get(route('calls.index', ['status' => 'invented']))->assertSessionHasErrors('status');
    $this->actingAs($user)->get(route('calls.index', ['number' => str_repeat('9', 65)]))->assertSessionHasErrors('number');
});

test('connection configuration cleanup preserves derived call history', function () {
    $interaction = Interaction::factory()->create();
    $connection = PbxConnection::findOrFail($interaction->pbx_connection_id);
    $this->artisan('config:exp-pbx:delete', ['name' => $connection->name])->assertFailed();
    $this->artisan('config:exp-pbx:clear')->assertFailed();
    $user = User::factory()->create();
    Permission::findOrCreate('edit settings');
    $user->givePermissionTo('edit settings');
    $this->actingAs($user)->delete(route('admin.pbx-connections.destroy', $connection))->assertSessionHasErrors('connection');
    $this->assertDatabaseHas('interactions', ['id' => $interaction->id]);
    $this->assertDatabaseHas('pbx_connections', ['id' => $connection->id]);
    expect(fn () => PbxConnection::whereKey($connection->id)->delete())->toThrow(QueryException::class);
});

test('history scopes agent calls and protects case content from telemetry only viewers', function () {
    foreach (['view call history', 'view cases', 'view all cases', 'view pbx'] as $permission) {
        Permission::findOrCreate($permission);
    }
    $agent = User::factory()->create();
    $agent->givePermissionTo(['view call history', 'view cases']);
    $own = CallerCase::factory()->create(['assigned_to' => $agent->id, 'preferred_name' => 'Ama']);
    $other = CallerCase::factory()->create(['preferred_name' => 'Private caller']);

    $this->actingAs($agent)->get(route('calls.index'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->has('interactions.data', 1)->where('interactions.data.0.caller_name', 'Ama'));
    $this->get(route('calls.show', $other->interaction_id))->assertNotFound();
    $this->get(route('calls.index', ['caller' => 'AMA', 'agent_id' => $agent->id, 'call_id' => $own->interaction_id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 1));
    $this->get(route('calls.index', ['caller' => 'Private caller']))->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 0));

    $viewer = User::factory()->create();
    $viewer->givePermissionTo('view pbx');
    $this->actingAs($viewer)->get(route('calls.show', $other->interaction_id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('record.case_record', null)->where('record.caller_name', null));
    $agent->givePermissionTo('view all cases');
    $this->actingAs($agent)->get(route('calls.show', $other->interaction_id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('record.caller_name', 'Private caller'));
    $this->assertDatabaseHas('activity_log', ['log_name' => 'call-history', 'description' => 'Call detail viewed', 'causer_id' => $agent->id]);
});

test('history identifies only the accepted bridged dentist and retains failed attempts', function () {
    Permission::findOrCreate('view pbx');
    $user = User::factory()->create();
    $user->givePermissionTo('view pbx');
    $interaction = Interaction::factory()->create(['started_at' => '2026-11-19 10:00:00', 'ended_at' => '2026-11-19 10:05:00']);
    $first = TransferAttempt::factory()->create(['interaction_id' => $interaction->id, 'sequence' => 1, 'status' => 'unanswered']);
    $dentist = Dentist::factory()->create(['name' => 'Dr Second']);
    $second = TransferAttempt::factory()->create([
        'interaction_id' => $interaction->id, 'dentist_id' => $dentist->id, 'sequence' => 2, 'retry_of_id' => $first->id,
        'status' => 'network_answered', 'human_outcome' => 'accepted', 'human_accepted_at' => now(), 'destination_masked' => '+233201*****23',
    ]);
    $this->actingAs($user)->get(route('calls.show', $interaction))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('record.connected_dentist', 'No connected dentist')->has('attempts', 2)->where('attempts.0.status', 'unanswered'));
    $second->session()->create(['status' => 'human_confirmed', 'started_at' => now(), 'intervals' => []]);
    $this->get(route('calls.index', ['dentist_id' => $dentist->id, 'from' => '2026-11-19', 'to' => '2026-11-19']))->assertInertia(fn (AssertableInertia $page) => $page
        ->has('interactions.data', 1)->where('interactions.data.0.connected_dentist', 'Dr Second')->where('interactions.data.0.duration_seconds', 300));
    $this->get(route('calls.show', $interaction))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('record.connected_destination', '+233201*****23')->where('attempts.1.retry_of_id', $first->id));
    $second->update(['human_outcome' => 'voicemail', 'human_accepted_at' => null]);
    $this->get(route('calls.show', $interaction))->assertInertia(fn (AssertableInertia $page) => $page->where('record.connected_dentist', 'No connected dentist'));
});

test('call detail preserves historical question answers drafts consent and notes with encrypted storage', function () {
    foreach (['view call history', 'view cases'] as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo(['view call history', 'view cases']);
    $case = CallerCase::factory()->create(['assigned_to' => $user->id]);
    $protocol = ScreeningProtocol::factory()->create();
    $question = $protocol->questions()->create(['prompt' => 'Historical question?', 'position' => 1, 'positive_priority' => 'P2']);
    $screening = $case->screenings()->create(['screening_protocol_id' => $protocol->id, 'recorded_by' => $user->id, 'priority' => 'Review', 'complete' => false, 'completed_at' => now()]);
    $answer = $screening->answers()->create(['screening_question_id' => $question->id, 'answer' => 'unknown', 'answer_status' => 'unknown', 'question_version' => $protocol->version, 'recorded_by' => $user->id, 'saved_at' => '2026-11-19 10:00:00']);
    $case->notes()->create(['body' => 'Factual note', 'recorded_by' => $user->id]);
    $case->consents()->create(['screening_protocol_id' => $protocol->id, 'recorded_by' => $user->id, 'status' => 'granted', 'callback_allowed' => false, 'recorded_at' => now()]);
    $draft = $case->screeningDrafts()->create(['screening_protocol_id' => $protocol->id, 'recorded_by' => $user->id, 'priority' => 'Review', 'answers' => [['question_id' => $question->id, 'answer' => '', 'answer_status' => 'unanswered']], 'draft_saved_at' => now()]);
    expect($answer->getRawOriginal('answer'))->not->toBe('unknown');
    expect($draft->getRawOriginal('answers'))->not->toContain('unanswered');
    $protocol->update(['status' => 'retired']);

    $this->actingAs($user)->get(route('calls.show', $case->interaction_id))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('protocols.0.questions.0.prompt', 'Historical question?')
        ->where('record.case_record.screenings.0.answers.0.answer', 'unknown')
        ->where('record.case_record.screenings.0.answers.0.question_version', $protocol->version)
        ->where('record.case_record.screening_drafts.0.answers.0.answer_status', 'unanswered')
        ->where('record.case_record.consents.0.status', 'granted')
        ->where('record.case_record.notes.0.body', 'Factual note'));
});

test('call detail derives an ordered journey timeline with evidence source and confidence', function () {
    Permission::findOrCreate('view pbx');
    $user = User::factory()->create();
    $user->givePermissionTo('view pbx');
    $interaction = Interaction::factory()->create(['started_at' => '2026-11-19 10:00:00', 'ended_at' => '2026-11-19 10:10:00']);
    $dentist = Dentist::factory()->create(['name' => 'Dr Timeline']);

    CallLeg::create([
        'interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id,
        'uniqueid' => 'timeline-caller', 'role' => 'caller', 'channel' => 'PJSIP/trunk-1', 'observed_creation' => true,
        'inbound_masked' => '030*****56', 'caller_masked' => '+233241*****67',
        'started_at' => '2026-11-19 10:00:00', 'ringing_at' => '2026-11-19 10:00:03',
        'answered_at' => '2026-11-19 10:00:20', 'ended_at' => '2026-11-19 10:10:00',
    ]);
    CallLeg::create([
        'interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id,
        'uniqueid' => 'timeline-mobile', 'role' => 'mobile', 'channel' => 'PJSIP/0241234567-00000001',
        'destination_masked' => '+233201*****23', 'started_at' => '2026-11-19 10:02:00',
        'dial_started_at' => '2026-11-19 10:02:00', 'ringing_at' => '2026-11-19 10:02:04',
        'answered_at' => '2026-11-19 10:02:12', 'ended_at' => '2026-11-19 10:09:00',
    ]);
    CallEvent::create([
        'pbx_connection_id' => $interaction->pbx_connection_id, 'interaction_id' => $interaction->id,
        'fingerprint' => str_repeat('a', 64), 'event_name' => 'Hold', 'occurred_at' => '2026-11-19 10:01:00',
        'timing_quality' => 'received', 'data' => [],
    ]);
    CallBridge::create([
        'interaction_id' => $interaction->id, 'bridge_uniqueid' => 'timeline-bridge', 'stage' => 'private_handover',
        'participants' => ['timeline-mobile'], 'started_at' => '2026-11-19 10:02:12',
        'ended_at' => '2026-11-19 10:04:00', 'duration_seconds' => 108.0,
    ]);
    $attempt = TransferAttempt::factory()->create([
        'interaction_id' => $interaction->id, 'dentist_id' => $dentist->id, 'sequence' => 1,
        'status' => 'completed', 'human_outcome' => 'accepted', 'acceptance_confidence' => 'agent_confirmed',
        'destination_masked' => '+233201*****23', 'dial_started_at' => '2026-11-19 10:02:00',
        'ringing_at' => '2026-11-19 10:02:04', 'answered_at' => '2026-11-19 10:02:12',
        'human_accepted_at' => '2026-11-19 10:04:00', 'transfer_completed_at' => '2026-11-19 10:04:05',
        'ended_at' => '2026-11-19 10:09:00',
    ]);
    $attempt->session()->create([
        'status' => 'human_confirmed', 'started_at' => '2026-11-19 10:04:00',
        'ended_at' => '2026-11-19 10:09:00', 'consultation_seconds' => 300.0, 'intervals' => [],
    ]);

    $timeline = collect();
    $this->actingAs($user)->get(route('calls.show', $interaction))->assertOk()
        ->assertInertia(function (AssertableInertia $page) use (&$timeline): void {
            $timeline = collect($page->toArray()['props']['timeline']);
        });

    expect($timeline->pluck('stage')->all())->toContain(
        'inbound_start', 'inbound_ring', 'agent_answer', 'hold_start', 'dentist_dial',
        'dentist_ring', 'dentist_answer', 'private_briefing', 'attempt_acceptance', 'consultation_start',
    );
    expect($timeline->firstWhere('stage', 'inbound_start'))->toMatchArray([
        'label' => 'Caller reached the inbound line', 'source' => 'ami', 'confidence' => 'observed',
    ]);
    expect($timeline->firstWhere('stage', 'dentist_answer'))->toMatchArray(['source' => 'ami', 'confidence' => 'network_only']);
    expect($timeline->firstWhere('stage', 'private_briefing'))->toMatchArray(['source' => 'bridge', 'confidence' => 'observed']);
    expect($timeline->firstWhere('stage', 'attempt_acceptance'))->toMatchArray(['source' => 'operator', 'confidence' => 'operator_confirmed']);
    expect($timeline->firstWhere('stage', 'consultation_start'))->toMatchArray(['source' => 'consultation_session']);

    $stamps = $timeline->pluck('at')->filter()->values()->all();
    expect($stamps)->toBe(collect($stamps)->sort()->values()->all());

    test('history rows retain attempted dentists and distinguish verified from unverified connections', function () {
        Permission::findOrCreate('view pbx');
        $user = User::factory()->create();
        $user->givePermissionTo('view pbx');
        $interaction = Interaction::factory()->create(['started_at' => '2026-11-19 10:00:00', 'ended_at' => '2026-11-19 10:05:00']);
        $first = Dentist::factory()->create(['name' => 'Dr First']);
        $second = Dentist::factory()->create(['name' => 'Dr Second']);
        $failed = TransferAttempt::factory()->create([
            'interaction_id' => $interaction->id, 'dentist_id' => $first->id, 'sequence' => 1,
            'status' => 'unanswered', 'destination_masked' => '+233201*****11',
        ]);
        $bridged = TransferAttempt::factory()->create([
            'interaction_id' => $interaction->id, 'dentist_id' => $second->id, 'sequence' => 2, 'retry_of_id' => $failed->id,
            'status' => 'network_answered', 'answered_at' => '2026-11-19 10:02:12',
            'transfer_completed_at' => '2026-11-19 10:04:05', 'destination_masked' => '+233201*****23',
        ]);
        $bridged->session()->create(['status' => 'unconfirmed', 'started_at' => '2026-11-19 10:04:05', 'intervals' => []]);

        $this->actingAs($user)->get(route('calls.index'))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('interactions.data.0.connected_dentist', 'No connected dentist')
            ->where('interactions.data.0.connection_state', 'unverified')
            ->where('interactions.data.0.unverified_dentist', 'Dr Second')
            ->where('interactions.data.0.attempts_count', 2)
            ->where('interactions.data.0.attempted_dentists', ['Dr First', 'Dr Second'])
            ->where('interactions.data.0.outcome_label', 'Unverified bridge · human acceptance not confirmed'));
        $this->get(route('calls.index', ['connection' => 'unverified']))->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 1));
        $this->get(route('calls.index', ['connection' => 'verified']))->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 0));
        $this->get(route('calls.index', ['connection' => 'none']))->assertInertia(fn (AssertableInertia $page) => $page->has('interactions.data', 0));

        $bridged->update(['human_outcome' => 'accepted', 'human_accepted_at' => '2026-11-19 10:04:00']);
        $bridged->session()->update(['status' => 'human_confirmed']);

        $this->get(route('calls.index', ['connection' => 'verified']))->assertInertia(fn (AssertableInertia $page) => $page
            ->has('interactions.data', 1)
            ->where('interactions.data.0.connected_dentist', 'Dr Second')
            ->where('interactions.data.0.connection_state', 'verified')
            ->where('interactions.data.0.attempts_count', 2));
        $this->get(route('calls.show', $interaction))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('attempts.0.connected', false)
            ->where('attempts.1.connected', true)
            ->where('record.connection_label', 'Connected'));
    });

});

test('call history export requires its own permission, masks numbers and is audited', function () {
    foreach (['view call history', 'export call history'] as $permission) {
        Permission::findOrCreate($permission);
    }
    $user = User::factory()->create();
    $user->givePermissionTo('view call history');
    $interaction = Interaction::factory()->create(['started_at' => '2026-11-19 10:00:00', 'ended_at' => '2026-11-19 10:05:00']);
    CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $user->id, 'preferred_name' => 'Private caller']);
    CallLeg::create([
        'interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id,
        'uniqueid' => 'export-caller', 'role' => 'caller', 'channel' => 'PJSIP/trunk-1', 'observed_creation' => true,
        'caller_masked' => '+233241*****67', 'caller_hash' => GhanaPhoneNumber::fingerprint('0241234567'),
        'inbound_masked' => '030*****56',
    ]);

    $this->actingAs($user)->get(route('calls.export'))->assertForbidden();
    $this->assertDatabaseMissing('activity_log', ['log_name' => 'call-history', 'description' => 'Call history exported']);

    $user->givePermissionTo('export call history');
    $response = $this->get(route('calls.export', ['from' => '2020-01-01']));
    $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
    $csv = $response->streamedContent();

    expect($csv)->toContain('Call ID')->toContain('+233241*****67')->not->toContain('0241234567');
    $this->assertDatabaseHas('activity_log', [
        'log_name' => 'call-history', 'description' => 'Call history exported', 'causer_id' => $user->id,
    ]);
});

test('call detail returns durable question snapshots, durations and the access audit trail', function () {
    foreach (['view call history', 'view cases'] as $permission) {
        Permission::findOrCreate($permission);
    }
    $agent = User::factory()->create();
    $agent->givePermissionTo(['view call history', 'view cases']);
    $interaction = Interaction::factory()->create(['started_at' => '2026-11-19 10:00:00', 'ended_at' => '2026-11-19 10:05:00']);
    $case = CallerCase::factory()->create(['interaction_id' => $interaction->id, 'assigned_to' => $agent->id, 'preferred_name' => 'Ama']);
    $protocol = ScreeningProtocol::factory()->create();
    $question = $protocol->questions()->create(['prompt' => 'Main concern?', 'type' => 'text', 'required' => true, 'position' => 1, 'positive_priority' => 'P3']);
    $swelling = $protocol->questions()->create(['prompt' => 'Swelling?', 'type' => 'yes_no_unknown', 'required' => true, 'position' => 2, 'positive_priority' => 'P2']);
    $screening = Screening::create([
        'case_id' => $case->id, 'screening_protocol_id' => $protocol->id, 'recorded_by' => $agent->id,
        'priority' => 'P3', 'complete' => true, 'completed_at' => '2026-11-19 10:03:00',
    ]);
    ScreeningAnswer::create([
        'screening_id' => $screening->id, 'screening_question_id' => $question->id,
        'question_prompt' => 'Main concern as asked?', 'answer' => 'Toothache', 'answer_status' => 'answered',
        'question_version' => 4, 'saved_at' => '2026-11-19 10:02:00', 'recorded_by' => $agent->id,
    ]);
    ScreeningAnswer::create([
        'screening_id' => $screening->id, 'screening_question_id' => $swelling->id,
        'question_prompt' => 'Swelling?', 'answer' => '', 'answer_status' => 'unknown',
        'question_version' => 4, 'saved_at' => '2026-11-19 10:02:30', 'recorded_by' => $agent->id,
    ]);
    CallLeg::create([
        'interaction_id' => $interaction->id, 'pbx_connection_id' => $interaction->pbx_connection_id,
        'uniqueid' => 'detail-caller', 'role' => 'caller', 'channel' => 'PJSIP/trunk-1', 'observed_creation' => true,
        'caller_masked' => '+233241*****67', 'started_at' => '2026-11-19 10:00:00', 'answered_at' => '2026-11-19 10:00:20',
    ]);

    $this->actingAs($agent)->get(route('calls.show', $interaction))->assertInertia(fn (AssertableInertia $page) => $page
        ->where('record.answered_questions', 1)
        ->where('record.question_count', 2)
        ->where('record.case_record.screenings.0.answers.0.question_prompt', 'Main concern as asked?')
        ->where('record.case_record.screenings.0.answers.0.question_version', '4')
        ->where('record.case_record.screenings.0.answers.1.answer_status', 'unknown')
        ->where('durations.inbound_wait_seconds', 20)
        ->where('durations.total_seconds', 300)
        ->where('durations.dial_to_answer_seconds', null)
        ->where('audit.0.description', 'Call detail viewed')
        ->where('audit.0.causer', $agent->name));
});
