<?php

use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CallTimingMetric;
use App\Models\CdrRecord;
use App\Models\CelRecord;
use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\TransferAttempt;

function repairConnection(): PbxConnection
{
    return PbxConnection::create(['name' => 'repair', 'host' => '127.0.0.1']);
}

function repairLeg(PbxConnection $connection, string $uniqueid, array $attributes = []): CallLeg
{
    $interaction = Interaction::create([
        'pbx_connection_id' => $connection->id,
        'correlation_key' => fake()->uuid(),
        'started_at' => now()->subMinutes(30),
    ]);

    return CallLeg::create(array_merge([
        'interaction_id' => $interaction->id,
        'pbx_connection_id' => $connection->id,
        'uniqueid' => $uniqueid,
        'channel' => 'PJSIP/trunk-1',
        'role' => 'mobile',
        'dial_started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(15),
    ], $attributes));
}

test('missing answer timestamps are rebuilt from retained exact-leg evidence', function () {
    $connection = repairConnection();
    $leg = repairLeg($connection, 'mobile-1');
    $unanswered = repairLeg($connection, 'mobile-2', [
        'dial_status' => 'NOANSWER',
        'dial_started_at' => now()->subMinutes(10),
        'ended_at' => now()->subMinutes(9),
    ]);
    $answeredAt = now()->subMinutes(19)->addSeconds(9);

    // The ANSWER state was filed against another journey, so the leg lost it.
    CallEvent::create([
        'pbx_connection_id' => $connection->id,
        'fingerprint' => hash('sha256', 'repair-answer-frame'),
        'event_name' => 'Newstate',
        'occurred_at' => $answeredAt,
        'timing_quality' => 'pbx',
        'data' => ['primary_id' => 'mobile-1', 'state' => 'Up'],
    ]);

    CorrelationException::create([
        'pbx_connection_id' => $connection->id,
        'interaction_id' => $leg->interaction_id,
        'fingerprint' => hash('sha256', 'repair-missing-answer'),
        'category' => 'projection',
        'code' => 'missing_answer',
        'subject' => 'mobile-1',
    ]);

    $this->artisan('exp:repair-answer-evidence', ['--days' => 1])->assertSuccessful();

    $leg->refresh();

    expect($leg->answered_at?->format('Y-m-d H:i:s'))->toBe($answeredAt->format('Y-m-d H:i:s'))
        ->and($leg->dial_status)->toBe('ANSWER')
        ->and($unanswered->refresh()->answered_at)->toBeNull()
        ->and(CorrelationException::where('code', 'missing_answer')->where('subject', 'mobile-1')->exists())->toBeFalse()
        ->and(TransferAttempt::where('call_leg_id', $leg->id)->value('status'))->toBe('network_answered');
});

test('imported CDR answer evidence heals the leg and dry runs change nothing', function () {
    $connection = repairConnection();
    $leg = repairLeg($connection, 'mobile-cdr');
    $answeredAt = now()->subMinutes(20)->addSeconds(12);

    $cdr = new CdrRecord([
        'uniqueid' => 'mobile-cdr',
        'start' => now()->subMinutes(20),
        'answer' => $answeredAt,
        'end' => now()->subMinutes(15),
        'disposition' => 'ANSWERED',
    ]);
    $cdr->setAttribute('pbx_connection_id', $connection->id);
    $cdr->setAttribute('source_fingerprint', hash('sha256', 'repair-cdr:mobile-cdr'));
    $cdr->save();

    $this->artisan('exp:repair-answer-evidence', ['--days' => 1, '--dry-run' => true])
        ->expectsOutputToContain('Would repair 1 of 1 legs')
        ->assertSuccessful();

    expect($leg->refresh()->answered_at)->toBeNull()
        ->and(CallTimingMetric::where('call_leg_id', $leg->id)->exists())->toBeFalse();

    $this->artisan('exp:repair-answer-evidence', ['--days' => 1])->assertSuccessful();

    expect($leg->refresh()->answered_at?->format('Y-m-d H:i:s'))
        ->toBe($answeredAt->format('Y-m-d H:i:s'));
});

test('answer evidence outside the dial window is never applied', function () {
    $connection = repairConnection();
    $leg = repairLeg($connection, 'mobile-late', [
        'dial_started_at' => now()->subMinutes(20),
        'ended_at' => now()->subMinutes(15),
    ]);

    CallEvent::create([
        'pbx_connection_id' => $connection->id,
        'fingerprint' => hash('sha256', 'repair-outside-window'),
        'event_name' => 'DialEnd',
        'occurred_at' => now()->subMinutes(2),
        'timing_quality' => 'pbx',
        'data' => ['destination_id' => 'mobile-late', 'dial_status' => 'ANSWER'],
    ]);

    $this->artisan('exp:repair-answer-evidence', ['--days' => 1])->assertSuccessful();

    expect($leg->refresh()->answered_at)->toBeNull()
        ->and($leg->dial_status)->toBeNull();
});

test('imported CEL answers are matched regardless of case and restore timing parity', function () {
    $connection = repairConnection();
    $dialled = now()->startOfSecond()->subMinutes(20);
    $leg = repairLeg($connection, 'mobile-cel', [
        'dial_started_at' => $dialled,
        'ringing_at' => $dialled->addSeconds(2),
        'ended_at' => $dialled->addSeconds(300),
    ]);
    $answeredAt = $dialled->addSeconds(14);

    $cel = new CelRecord([
        'uniqueid' => 'mobile-cel',
        'event_type' => 'answer',
        'event_time' => $answeredAt->toDateTimeString(),
    ]);
    $cel->setAttribute('pbx_connection_id', $connection->id);
    $cel->setAttribute('source_fingerprint', hash('sha256', 'repair-cel:mobile-cel'));
    $cel->save();

    $this->artisan('exp:repair-answer-evidence', ['--days' => 1])->assertSuccessful();

    $metric = CallTimingMetric::query()->where('call_leg_id', $leg->id)->first();
    $attempt = TransferAttempt::query()->where('call_leg_id', $leg->id)->first();

    expect($leg->refresh()->answered_at?->format('Y-m-d H:i:s'))->toBe($answeredAt->format('Y-m-d H:i:s'))
        ->and($metric?->dial_to_answer_seconds)->toBe(14.0)
        ->and($metric?->ringing_seconds)->toBe(12.0)
        ->and($metric?->mobile_connected_seconds)->toBe(286.0)
        ->and($attempt?->dial_to_answer_seconds)->toBe(14.0)
        ->and($attempt?->mobile_connected_seconds)->toBe(286.0);
});

test('a leg that lost its dial start is still repaired from its channel start', function () {
    $connection = repairConnection();
    $started = now()->startOfSecond()->subMinutes(30);
    $leg = repairLeg($connection, 'mobile-no-start', [
        'started_at' => $started,
        'dial_started_at' => null,
        'ended_at' => $started->addMinutes(4),
    ]);
    $answeredAt = $started->addSeconds(20);

    CallEvent::create([
        'pbx_connection_id' => $connection->id,
        'fingerprint' => hash('sha256', 'repair-no-start'),
        'event_name' => 'Newstate',
        'occurred_at' => $answeredAt,
        'timing_quality' => 'pbx',
        'data' => ['primary_id' => 'mobile-no-start', 'state' => 'Up'],
    ]);

    $this->artisan('exp:repair-answer-evidence', ['--days' => 1])
        ->expectsOutputToContain('Repaired 1 of 1 legs')
        ->assertSuccessful();

    expect($leg->refresh()->answered_at?->format('Y-m-d H:i:s'))->toBe($answeredAt->format('Y-m-d H:i:s'))
        ->and($leg->dial_status)->toBe('ANSWER');
});

test('a CDR that still disagrees keeps its mismatch exception after the repair', function () {
    $connection = repairConnection();
    $leg = repairLeg($connection, 'mobile-conflict');
    $answeredAt = now()->subMinutes(19)->addSeconds(9);

    CallEvent::create([
        'pbx_connection_id' => $connection->id,
        'fingerprint' => hash('sha256', 'repair-conflict-frame'),
        'event_name' => 'Newstate',
        'occurred_at' => $answeredAt,
        'timing_quality' => 'pbx',
        'data' => ['primary_id' => 'mobile-conflict', 'state' => 'Up'],
    ]);

    $cdr = new CdrRecord([
        'uniqueid' => 'mobile-conflict',
        'start' => now()->subMinutes(20),
        'answer' => null,
        'end' => now()->subMinutes(15),
        'disposition' => 'NO ANSWER',
        'duration' => 300,
        'billsec' => 0,
    ]);
    $cdr->setAttribute('pbx_connection_id', $connection->id);
    $cdr->setAttribute('source_fingerprint', hash('sha256', 'repair-conflict-cdr'));
    $cdr->save();

    foreach (['missing_answer', 'cdr_answer_state_mismatch'] as $code) {
        CorrelationException::create([
            'pbx_connection_id' => $connection->id,
            'interaction_id' => $leg->interaction_id,
            'fingerprint' => hash('sha256', 'repair-conflict:'.$code),
            'category' => 'reconciliation',
            'code' => $code,
            'subject' => 'mobile-conflict',
        ]);
    }

    $this->artisan('exp:repair-answer-evidence', ['--days' => 1])->assertSuccessful();

    expect($leg->refresh()->answered_at)->not->toBeNull()
        ->and(CorrelationException::query()->where('code', 'missing_answer')->where('subject', 'mobile-conflict')->exists())->toBeFalse()
        ->and(CorrelationException::query()->where('code', 'cdr_answer_state_mismatch')->where('subject', 'mobile-conflict')->exists())->toBeTrue();
});
