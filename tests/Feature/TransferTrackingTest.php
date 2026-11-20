<?php

use App\Models\CallerCase;
use App\Models\Dentist;
use App\Models\DentistNumber;
use App\Models\PbxConnection;
use App\Models\TransferAttempt;
use App\Models\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;

/** @param list<string> $permissions */
function transferUser(array $permissions = ['view transfers', 'edit transfers', 'view cases']): User
{
    foreach ($permissions as $permission) {
        Permission::findOrCreate($permission);
    }

    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function transferConnection(): PbxConnection
{
    return PbxConnection::create(['name' => 'Transfer PBX', 'host' => '127.0.0.1']);
}

function transferDentistNumber(string $number = '+233241234567'): DentistNumber
{
    return DentistNumber::factory()->create(['number' => $number, 'original' => '0241234567']);
}

/** @return list<array<string, mixed>> */
function transferFailureFrames(string $dialStatus): array
{
    return [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/inbound-1', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'Newchannel', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-one'],
        ['t' => 2, 'Event' => 'Newstate', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Up'],
        ['t' => 2, 'Event' => 'Newstate', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Up'],
        ['t' => 4, 'Event' => 'Newchannel', 'Uniqueid' => 'mobile', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/trunk-2'],
        ['t' => 4, 'Event' => 'DialBegin', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-one', 'DestUniqueid' => 'mobile', 'DestLinkedid' => 'caller', 'DestChannel' => 'PJSIP/trunk-2', 'DialString' => 'PJSIP/trunk/0249999999'],
        ['t' => 6, 'Event' => 'DialEnd', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'mobile', 'DestLinkedid' => 'caller', 'DialStatus' => $dialStatus],
        ['t' => 6, 'Event' => 'Hangup', 'Uniqueid' => 'mobile', 'Linkedid' => 'caller'],
        ['t' => 7, 'Event' => 'Hangup', 'Uniqueid' => 'agent', 'Linkedid' => 'caller'],
        ['t' => 7, 'Event' => 'Hangup', 'Uniqueid' => 'caller', 'Linkedid' => 'caller'],
    ];
}

/** @return list<array<string, mixed>> */
function transferRetryFrames(): array
{
    return [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/inbound-1', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'Newchannel', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-one'],
        ['t' => 2, 'Event' => 'Newstate', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Up'],
        ['t' => 2, 'Event' => 'Newstate', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Up'],
        ['t' => 3, 'Event' => 'Newchannel', 'Uniqueid' => 'first', 'Linkedid' => 'caller'],
        ['t' => 3, 'Event' => 'DialBegin', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-one', 'DestUniqueid' => 'first', 'DestLinkedid' => 'caller', 'DialString' => 'PJSIP/trunk/0241234567'],
        ['t' => 4, 'Event' => 'Newstate', 'Uniqueid' => 'first', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Ringing'],
        ['t' => 6, 'Event' => 'DialEnd', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'first', 'DestLinkedid' => 'caller', 'DialStatus' => 'NOANSWER'],
        ['t' => 6, 'Event' => 'Hangup', 'Uniqueid' => 'first', 'Linkedid' => 'caller'],
        ['t' => 7, 'Event' => 'Newchannel', 'Uniqueid' => 'second', 'Linkedid' => 'caller'],
        ['t' => 7, 'Event' => 'DialBegin', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-one', 'DestUniqueid' => 'second', 'DestLinkedid' => 'caller', 'DialString' => 'PJSIP/trunk/0549999999'],
        ['t' => 8, 'Event' => 'Newstate', 'Uniqueid' => 'second', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Ringing'],
        ['t' => 10, 'Event' => 'Newstate', 'Uniqueid' => 'second', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Up'],
        ['t' => 10, 'Event' => 'DialEnd', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'second', 'DestLinkedid' => 'caller', 'DialStatus' => 'ANSWER'],
        ['t' => 20, 'Event' => 'Hangup', 'Uniqueid' => 'second', 'Linkedid' => 'caller'],
        ['t' => 20, 'Event' => 'Hangup', 'Uniqueid' => 'agent', 'Linkedid' => 'caller'],
        ['t' => 20, 'Event' => 'Hangup', 'Uniqueid' => 'caller', 'Linkedid' => 'caller'],
    ];
}

test('transfer routes require authentication and separated transfer permissions', function () {
    $attempt = TransferAttempt::factory()->create();

    $this->get(route('transfers.index'))->assertRedirect(route('login'));
    $this->get(route('transfers.show', $attempt))->assertRedirect(route('login'));

    $this->actingAs(transferUser([]))->get(route('transfers.index'))->assertForbidden();

    $this->actingAs(transferUser(['view transfers']))->get(route('transfers.index'))->assertOk();
    $this->post(route('transfers.record', $attempt), [])->assertForbidden();
});

test('a successful warm transfer records separated timings and a masked approved destination', function () {
    $connection = transferConnection();
    $number = transferDentistNumber();

    correlateCallFrames($connection, warmTransferFrames());

    $attempt = TransferAttempt::sole();
    expect($attempt->status)->toBe('consultation_observed')
        ->and($attempt->sequence)->toBe(1)
        ->and($attempt->match_status)->toBe('approved')
        ->and($attempt->dentist_id)->toBe($number->dentist_id)
        ->and($attempt->destination_masked)->toBe('+233241*****67')
        ->and($attempt->dial_to_answer_seconds)->toBe(5.0)
        ->and($attempt->ringing_seconds)->toBe(3.0)
        ->and($attempt->private_handover_seconds)->toBe(8.0)
        ->and($attempt->mobile_connected_seconds)->toBe(51.0)
        ->and($attempt->consultation_seconds)->toBe(36.0)
        ->and($attempt->transfer_completed_at)->not->toBeNull()
        ->and($attempt->acceptance_confidence)->toBe('network_only')
        ->and($attempt->human_accepted_at)->toBeNull()
        ->and($attempt->session->status)->toBe('unconfirmed')
        ->and($attempt->session->intervals)->toHaveCount(1);
});

test('missing ringing indications stay unknown instead of being inferred', function () {
    $connection = transferConnection();
    $frames = array_values(array_filter(warmTransferFrames(), fn (array $frame): bool => ($frame['ChannelStateDesc'] ?? '') !== 'Ringing'));

    correlateCallFrames($connection, $frames);

    $attempt = TransferAttempt::sole();
    expect($attempt->ringing_seconds)->toBeNull()
        ->and($attempt->ringing_at)->toBeNull()
        ->and($attempt->dial_to_answer_seconds)->toBe(5.0);
});

test('PBX dial outcomes stay distinguishable for busy unanswered cancelled and failed attempts', function (string $dialStatus, string $status) {
    $connection = transferConnection();

    correlateCallFrames($connection, transferFailureFrames($dialStatus));

    $attempt = TransferAttempt::sole();
    expect($attempt->status)->toBe($status)
        ->and($attempt->failure_reason)->toBe($dialStatus)
        ->and($attempt->acceptance_confidence)->toBe('none')
        ->and($attempt->human_outcome)->toBe('unknown')
        ->and($attempt->session)->toBeNull();
})->with([
    'busy' => ['BUSY', 'busy'],
    'unanswered' => ['NOANSWER', 'unanswered'],
    'cancelled' => ['CANCEL', 'cancelled'],
    'failed' => ['CONGESTION', 'failed'],
]);

test('a failed attended transfer is not reported as a completed handover', function () {
    $connection = transferConnection();
    $frames = array_map(function (array $frame): array {
        if (($frame['Uniqueid'] ?? null) === 'agent-out') {
            $frame['Linkedid'] = 'caller';
        }
        if (($frame['Event'] ?? null) === 'AttendedTransfer') {
            $frame['Result'] = 'Fail';
        }

        return $frame;
    }, warmTransferFrames());

    correlateCallFrames($connection, $frames);

    $attempt = TransferAttempt::sole();
    expect($attempt->status)->toBe('transfer_failed')
        ->and($attempt->failure_reason)->toBe('Fail')
        ->and($attempt->acceptance_confidence)->toBe('network_only');
});

test('retries link to the previous mobile leg and keep their own observed outcome', function () {
    $connection = transferConnection();

    correlateCallFrames($connection, transferRetryFrames());

    $attempts = TransferAttempt::query()->orderBy('sequence')->get();
    expect($attempts)->toHaveCount(2)
        ->and($attempts[0]->status)->toBe('unanswered')
        ->and($attempts[0]->failure_reason)->toBe('NOANSWER')
        ->and($attempts[0]->retry_of_id)->toBeNull()
        ->and($attempts[1]->status)->toBe('network_answered')
        ->and($attempts[1]->retry_of_id)->toBe($attempts[0]->id);
});

test('operator evidence distinguishes a confirmed consultation from a voicemail or declined bridge', function (string $outcome, string $status, string $sessionStatus) {
    $user = transferUser();
    $connection = transferConnection();
    transferDentistNumber();

    correlateCallFrames($connection, warmTransferFrames());
    $attempt = TransferAttempt::sole();
    CallerCase::factory()->create(['interaction_id' => $attempt->interaction_id, 'assigned_to' => $user->id]);

    $this->actingAs($user)->post(route('transfers.record', $attempt), [
        'revision' => $attempt->revision,
        'kind' => 'operator_outcome',
        'outcome' => $outcome,
        'occurred_at' => $attempt->answered_at->addSecond()->toIso8601String(),
        'note' => 'Operator confirmed the observed outcome.',
        'attested' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $attempt->refresh();
    expect($attempt->status)->toBe($status)
        ->and($attempt->human_outcome)->toBe($outcome)
        ->and($attempt->acceptance_confidence)->toBe($outcome === 'accepted' ? 'agent_confirmed' : 'network_only')
        ->and($attempt->human_accepted_at !== null)->toBe($outcome === 'accepted')
        ->and($attempt->session->status)->toBe($sessionStatus);
})->with([
    'accepted' => ['accepted', 'completed', 'human_confirmed'],
    'voicemail' => ['voicemail', 'voicemail', 'voicemail_bridge'],
    'declined' => ['declined', 'declined', 'unconfirmed'],
]);

test('fallback intent is recorded without changing the observed transfer outcome', function () {
    $user = transferUser();
    $connection = transferConnection();

    correlateCallFrames($connection, transferRetryFrames());
    $attempt = TransferAttempt::query()->orderByDesc('sequence')->firstOrFail();
    CallerCase::factory()->create(['interaction_id' => $attempt->interaction_id, 'assigned_to' => $user->id]);

    $this->actingAs($user)->post(route('transfers.record', $attempt), [
        'revision' => $attempt->revision,
        'kind' => 'fallback',
        'outcome' => 'retry_requested',
        'occurred_at' => $attempt->dial_started_at->addSecond()->toIso8601String(),
        'note' => 'Caller agreed to another attempt.',
        'attested' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $attempt->refresh();
    expect($attempt->fallback_status)->toBe('retry_requested')
        ->and($attempt->status)->toBe('network_answered')
        ->and($attempt->human_outcome)->toBe('unknown');
    $this->assertDatabaseHas('transfer_events', ['transfer_attempt_id' => $attempt->id, 'kind' => 'fallback', 'outcome' => 'retry_requested']);
});

test('emergency cases cannot wait for a retry or callback', function () {
    $user = transferUser();
    $connection = transferConnection();

    correlateCallFrames($connection, transferRetryFrames());
    $attempt = TransferAttempt::query()->orderByDesc('sequence')->firstOrFail();
    CallerCase::factory()->create(['interaction_id' => $attempt->interaction_id, 'assigned_to' => $user->id, 'priority' => 'P1']);

    $this->actingAs($user)->post(route('transfers.record', $attempt), [
        'revision' => $attempt->revision,
        'kind' => 'fallback',
        'outcome' => 'retry_requested',
        'occurred_at' => $attempt->dial_started_at->addSecond()->toIso8601String(),
        'note' => 'Request retry.',
        'attested' => true,
    ])->assertSessionHasErrors(['outcome' => 'Emergency care must not wait for another dentist attempt or callback.']);

    expect($attempt->fresh()->fallback_status)->toBe('pending');
    $this->assertDatabaseMissing('transfer_events', ['transfer_attempt_id' => $attempt->id, 'kind' => 'fallback']);
});

test('operator evidence cannot be recorded outside the visible interaction case', function () {
    $user = transferUser();
    $connection = transferConnection();

    correlateCallFrames($connection, warmTransferFrames());
    $attempt = TransferAttempt::sole();
    CallerCase::factory()->create(['interaction_id' => $attempt->interaction_id]);

    $this->actingAs($user)->post(route('transfers.record', $attempt), [
        'revision' => $attempt->revision,
        'kind' => 'operator_outcome',
        'outcome' => 'unknown',
        'occurred_at' => $attempt->answered_at->addSecond()->toIso8601String(),
        'note' => 'Not authorised for this case.',
        'attested' => true,
    ])->assertNotFound();

    $this->assertDatabaseMissing('transfer_events', ['transfer_attempt_id' => $attempt->id, 'kind' => 'operator_outcome']);
});

test('stale evidence revisions are rejected without appending an event', function () {
    $user = transferUser();
    $connection = transferConnection();

    correlateCallFrames($connection, warmTransferFrames());
    $attempt = TransferAttempt::sole();
    CallerCase::factory()->create(['interaction_id' => $attempt->interaction_id, 'assigned_to' => $user->id]);

    $this->actingAs($user)->post(route('transfers.record', $attempt), [
        'revision' => $attempt->revision + 1,
        'kind' => 'operator_outcome',
        'outcome' => 'unknown',
        'occurred_at' => $attempt->answered_at->addSecond()->toIso8601String(),
        'note' => 'Stale evidence.',
        'attested' => true,
    ])->assertSessionHasErrors(['revision' => 'Transfer evidence changed. Reload before recording a confirmation.']);

    $this->assertDatabaseMissing('transfer_events', ['transfer_attempt_id' => $attempt->id, 'kind' => 'operator_outcome']);
});

test('transfer evidence pages redact case notes without case permission', function () {
    $owner = transferUser();
    $viewer = transferUser(['view transfers']);
    $connection = transferConnection();

    correlateCallFrames($connection, warmTransferFrames());
    $attempt = TransferAttempt::sole();
    CallerCase::factory()->create(['interaction_id' => $attempt->interaction_id, 'assigned_to' => $owner->id]);

    $this->actingAs($owner)->post(route('transfers.record', $attempt), [
        'revision' => $attempt->revision,
        'kind' => 'operator_outcome',
        'outcome' => 'unknown',
        'occurred_at' => $attempt->answered_at->addSecond()->toIso8601String(),
        'note' => 'Sensitive case detail.',
        'attested' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($viewer)->get(route('transfers.show', $attempt))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Transfers/Show')
            ->where('caseId', null)
            ->where('canRecord', false)
            ->where('events.data', fn ($events) => collect($events)->contains(fn (array $event): bool => $event['kind'] === 'operator_outcome' && $event['note'] === null)));
});

test('analytics groups outcomes by matched dentist without treating network answers as acceptance', function () {
    $user = transferUser();
    $dentist = Dentist::factory()->create(['name' => 'Dr Ama Mensah']);
    $other = Dentist::factory()->create(['name' => 'Dr Kofi Boateng']);

    TransferAttempt::factory()->create([
        'dentist_id' => $dentist->id, 'match_status' => 'approved', 'status' => 'completed', 'sequence' => 1,
        'dial_started_at' => now()->subMinutes(20), 'answered_at' => now()->subMinutes(20), 'human_accepted_at' => now()->subMinutes(19),
        'acceptance_confidence' => 'agent_confirmed', 'dial_to_answer_seconds' => 5, 'ringing_seconds' => 3,
        'mobile_connected_seconds' => 51, 'consultation_seconds' => 36,
    ]);
    TransferAttempt::factory()->create([
        'dentist_id' => $dentist->id, 'match_status' => 'approved', 'status' => 'network_answered', 'sequence' => 2,
        'dial_started_at' => now()->subMinutes(15), 'answered_at' => now()->subMinutes(15),
        'acceptance_confidence' => 'network_only', 'dial_to_answer_seconds' => 7, 'mobile_connected_seconds' => 20,
    ]);
    TransferAttempt::factory()->create([
        'dentist_id' => $other->id, 'match_status' => 'pending', 'status' => 'unanswered', 'sequence' => 1,
        'dial_started_at' => now()->subMinutes(10), 'dial_status' => 'NOANSWER', 'failure_reason' => 'NOANSWER',
    ]);
    TransferAttempt::factory()->create([
        'dentist_id' => null, 'match_status' => 'unknown', 'status' => 'network_answered', 'sequence' => 1,
        'dial_started_at' => now()->subMinutes(5), 'answered_at' => now()->subMinutes(5),
        'acceptance_confidence' => 'network_only', 'dial_to_answer_seconds' => 4,
    ]);

    $this->actingAs($user)->get(route('transfers.index', ['view' => 'analytics', 'days' => 7]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Transfers/Index')
            ->where('filters.view', 'analytics')
            ->where('matches', TransferAttempt::MATCH_STATUSES)
            ->where('analytics.dentists.0.dentist_name', 'Dr Ama Mensah')
            ->where('analytics.dentists.0.attempts', 2)
            ->where('analytics.dentists.0.network_answers', 2)
            ->where('analytics.dentists.0.human_acceptances', 1)
            ->where('analytics.dentists.0.avg_dial_to_answer_seconds', 6)
            ->where('analytics.dentists.0.avg_ringing_seconds', 3)
            ->where('analytics.dentists.0.mobile_connected_seconds', 71)
            ->where('analytics.dentists.0.consultation_seconds', 36)
            ->where('analytics.dentists', fn ($rows) => collect($rows)->contains(fn (array $row): bool => $row['dentist_name'] === 'Dr Kofi Boateng' && $row['human_acceptances'] === 0)
                && collect($rows)->contains(fn (array $row): bool => $row['dentist_name'] === 'Unmatched destination' && $row['attempts'] === 1 && $row['network_answers'] === 1))
            ->where('analytics.failures.0.status', 'unanswered')
            ->where('analytics.failures.0.failure_reason', 'NOANSWER')
            ->where('analytics.failures.0.attempts', 1));

    $this->actingAs($user)->get(route('transfers.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('analytics', null));
});

test('retired numbers are rejected as a transfer directory filter', function () {
    $this->actingAs(transferUser());

    $this->get(route('transfers.index', ['match' => 'retired']))->assertSessionHasErrors('match');
});
