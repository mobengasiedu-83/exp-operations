<?php

use App\Models\AmiEvent;
use App\Models\CallBridge;
use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CallTimingMetric;
use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Services\CallCorrelator;
use App\Services\GhanaPhoneNumber;
use App\Services\PbxTelemetry;

test('attended transfer joins inbound and outbound roots and separates verified bridge timings', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    expect(Interaction::count())->toBe(1);
    $interaction = Interaction::firstOrFail();
    expect($interaction->status)->toBe('completed');
    expect($interaction->is_exp)->toBeTrue();
    expect($interaction->legs()->count())->toBe(4);
    $metric = $interaction->metrics()->firstOrFail();
    expect($metric->dial_to_answer_seconds)->toBe(5.0);
    expect($metric->ringing_seconds)->toBe(3.0);
    expect($metric->mobile_connected_seconds)->toBe(51.0);
    expect($metric->private_handover_seconds)->toBe(8.0);
    expect($metric->consultation_seconds)->toBe(36.0);
    expect($metric->human_accepted_at)->toBeNull();
    expect($metric->acceptance_status)->toBe('unconfirmed');
    expect($metric->outbound_setup_seconds)->toBeNull();
    expect($interaction->exceptions()->count())->toBe(0);
});

test('caller evidence keeps only a masked caller number and a keyed fingerprint', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller-1', 'Linkedid' => 'caller-1', 'Channel' => 'PJSIP/trunk-1', 'Context' => 'exp-inbound', 'CallerIDNum' => '0241234567'],
        ['t' => 1, 'Event' => 'Newchannel', 'Uniqueid' => 'agent-1', 'Linkedid' => 'caller-1', 'Channel' => 'PJSIP/204-in'],
    ]);

    $leg = CallLeg::where('uniqueid', 'caller-1')->firstOrFail();

    expect($leg->role)->toBe('caller')
        ->and($leg->caller_masked)->toBe('+233241*****67')
        ->and($leg->caller_hash)->toBe(GhanaPhoneNumber::fingerprint('0241234567'));

    expect(json_encode(CallLeg::all()->toArray()))->not->toContain('0241234567')
        ->and(json_encode(CallEvent::all()->toArray()))->not->toContain('0241234567');
});

test('out of order delivery and replay produce the same timing results without duplicate evidence', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, array_reverse(warmTransferFrames()));
    foreach (AmiEvent::orderBy('id')->get() as $event) {
        app(CallCorrelator::class)->process($event);
    }
    expect(Interaction::count())->toBe(1);
    expect(CallEvent::count())->toBe(count(warmTransferFrames()));
    expect(CallLeg::count())->toBe(4);
    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBe(36.0);
    expect(CallTimingMetric::firstOrFail()->private_handover_seconds)->toBe(8.0);
});

test('absent ringing signals remain unknown and a network answer is never human acceptance', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $frames = array_values(array_filter(warmTransferFrames(), fn (array $frame): bool => ($frame['ChannelStateDesc'] ?? '') !== 'Ringing'));
    correlateCallFrames($connection, $frames);
    expect(CallTimingMetric::firstOrFail()->ringing_seconds)->toBeNull();
    expect(CallTimingMetric::firstOrFail()->dial_to_answer_seconds)->toBe(5.0);
    expect(CallTimingMetric::firstOrFail()->human_accepted_at)->toBeNull();
});

test('raw event retention does not destroy replayable derived call evidence', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    AmiEvent::query()->delete();
    $this->artisan('exp:correlate-calls', ['--rebuild' => true])->assertSuccessful();
    expect(CallEvent::count())->toBe(count(warmTransferFrames()));
    expect(CallEvent::whereNotNull('ami_event_id')->count())->toBe(0);
    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBe(36.0);
});

test('identical channel IDs on different PBX connections remain isolated', function () {
    foreach (['First', 'Second'] as $name) {
        $connection = PbxConnection::create(['name' => $name, 'host' => '127.0.0.1']);
        correlateCallFrames($connection, warmTransferFrames());
    }
    expect(Interaction::count())->toBe(2);
    expect(CallLeg::count())->toBe(8);
    expect(CallTimingMetric::count())->toBe(2);
});

test('events without channel identity are visible as unmatched diagnostics', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [['Event' => 'Hangup', 't' => 1]]);
    expect(CallEvent::whereNull('interaction_id')->count())->toBe(1);
    expect(CorrelationException::firstOrFail()->code)->toBe('missing_identity');
    expect(Interaction::count())->toBe(0);
});

test('three-party bridge time is flagged and excluded from private handover and consultation', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $frames = warmTransferFrames();
    $frames[] = ['t' => 30, 'Event' => 'BridgeEnter', 'Uniqueid' => 'agent-in', 'Linkedid' => 'caller', 'BridgeUniqueid' => 'consultation'];
    $frames[] = ['t' => 40, 'Event' => 'BridgeLeave', 'Uniqueid' => 'agent-in', 'Linkedid' => 'caller', 'BridgeUniqueid' => 'consultation'];
    correlateCallFrames($connection, $frames);
    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBe(26.0);
    expect(CorrelationException::where('code', 'ambiguous_bridge')->exists())->toBeTrue();
    expect(CallBridge::where('stage', 'ambiguous')->count())->toBe(1);
});

test('a busy mobile attempt has ringing duration but no invented answer or consultation', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'Newchannel', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-test'],
        ['t' => 2, 'Event' => 'Newchannel', 'Uniqueid' => 'busy', 'Linkedid' => 'caller'],
        ['t' => 2, 'Event' => 'DialBegin', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'busy', 'DestExten' => '+233241234567'],
        ['t' => 4, 'Event' => 'Newstate', 'Uniqueid' => 'busy', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Ringing'],
        ['t' => 9, 'Event' => 'DialEnd', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'busy', 'DialStatus' => 'BUSY'],
        ['t' => 10, 'Event' => 'Hangup', 'Uniqueid' => 'busy', 'Linkedid' => 'caller'],
    ]);
    $metric = CallTimingMetric::firstOrFail();
    expect($metric->ringing_seconds)->toBe(5.0);
    expect($metric->dial_to_answer_seconds)->toBeNull();
    expect($metric->mobile_connected_seconds)->toBeNull();
    expect($metric->consultation_seconds)->toBeNull();
});

test('missing bridge termination keeps consultation duration unknown', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, array_values(array_filter(warmTransferFrames(), fn (array $frame): bool => $frame['t'] < 60)));
    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBeNull();
    expect(CallTimingMetric::firstOrFail()->mobile_connected_seconds)->toBeNull();
    expect(CorrelationException::where('code', 'open_bridge_interval')->exists())->toBeTrue();
    expect(Interaction::firstOrFail()->status)->toBe('active');
});

test('held time is excluded from caller mobile bridge duration', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $frames = warmTransferFrames();
    $frames[] = ['t' => 30, 'Event' => 'Hold', 'Uniqueid' => 'caller', 'Linkedid' => 'caller'];
    $frames[] = ['t' => 40, 'Event' => 'Unhold', 'Uniqueid' => 'caller', 'Linkedid' => 'caller'];
    correlateCallFrames($connection, $frames);
    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBe(26.0);
    expect(CallBridge::where('stage', 'held')->sum('duration_seconds'))->toEqual(10);
});

test('live ingestion preserves route and mobile roles while redacting caller and dialled numbers', function () {
    config(['services.exp_pbx.inbound_contexts' => ['exp-inbound']]);
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $telemetry = app(PbxTelemetry::class);
    $telemetry->event($connection, ['Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Context' => 'exp-inbound', 'CallerIDNum' => '0241234567']);
    $telemetry->event($connection, ['Event' => 'DialBegin', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'DestUniqueid' => 'mobile', 'DialString' => '0241234567', 'Secret' => 'private']);
    expect(CallLeg::where('uniqueid', 'caller')->firstOrFail()->role)->toBe('caller');
    expect(CallLeg::where('uniqueid', 'mobile')->firstOrFail()->role)->toBe('mobile');
    expect(AmiEvent::all()->toJson())->not->toContain('0241234567', 'private');
    expect(CallEvent::all()->toJson())->not->toContain('0241234567', 'private');
});

test('received timestamps are labelled and invalid PBX clocks produce diagnostics', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    app(PbxTelemetry::class)->event($connection, ['Event' => 'Newchannel', 'Uniqueid' => 'one', 'Timestamp' => 'invalid']);
    expect(Interaction::firstOrFail()->timing_quality)->toBe('received');
    expect(CorrelationException::where('code', 'invalid_timestamp')->exists())->toBeTrue();
});

test('failed attended transfers cannot merge two distinct journeys', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'first', 'Linkedid' => 'first'],
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'second', 'Linkedid' => 'second'],
        ['t' => 1, 'Event' => 'AttendedTransfer', 'Result' => 'Fail', 'OrigTransfererUniqueid' => 'first', 'SecondTransfererUniqueid' => 'second'],
    ]);
    expect(Interaction::count())->toBe(2);
    expect(CorrelationException::where('code', 'failed_transfer_crosses_interactions')->exists())->toBeTrue();
});

test('the confirmed EXP DID identifies inbound entry in national and international forms', function (string $did) {
    config(['services.exp_pbx.inbound_dids' => ['0596921095']]);
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    app(PbxTelemetry::class)->event($connection, ['Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Exten' => $did]);
    expect(CallLeg::firstOrFail()->entry_kind)->toBe('did');
    expect(CallLeg::firstOrFail()->role)->toBe('caller');
    expect(Interaction::firstOrFail()->is_exp)->toBeTrue();
    expect(CallEvent::firstOrFail()->toJson())->not->toContain('0596921095', '233596921095');
})->with(['0596921095', '+233596921095', '233596921095']);

test('overlapping membership in separate bridges leaves bridge timings unknown', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $frames = warmTransferFrames();
    $frames[] = ['t' => 30, 'Event' => 'BridgeEnter', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'BridgeUniqueid' => 'overlap'];
    $frames[] = ['t' => 31, 'Event' => 'BridgeEnter', 'Uniqueid' => 'mobile', 'Linkedid' => 'caller', 'BridgeUniqueid' => 'overlap'];
    correlateCallFrames($connection, $frames);
    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBeNull();
    expect(CorrelationException::where('code', 'overlapping_bridge_membership')->exists())->toBeTrue();
});

test('blind transfer headers correlate the transferer without implying an answered destination', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'Newchannel', 'Uniqueid' => 'agent', 'Channel' => 'PJSIP/204-one'],
        ['t' => 2, 'Event' => 'BlindTransfer', 'Result' => 'Success', 'TransfererUniqueid' => 'agent', 'TransfererChannel' => 'PJSIP/204-one', 'TransfereeUniqueid' => 'caller'],
    ]);
    expect(Interaction::count())->toBe(1);
    expect(CallLeg::where('uniqueid', 'agent')->firstOrFail()->role)->toBe('agent');
    expect(CallTimingMetric::count())->toBe(0);
    expect(CallEvent::where('event_name', 'BlindTransfer')->whereNull('interaction_id')->count())->toBe(0);
});

test('mobile retries keep separate attempt metrics and missing answer ordering is flagged', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'Newchannel', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-one'],
        ['t' => 2, 'Event' => 'Newchannel', 'Uniqueid' => 'first', 'Linkedid' => 'caller'],
        ['t' => 2, 'Event' => 'DialBegin', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'first', 'DestExten' => '0241234567'],
        ['t' => 5, 'Event' => 'DialEnd', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'first', 'DialStatus' => 'NOANSWER'],
        ['t' => 5, 'Event' => 'Hangup', 'Uniqueid' => 'first', 'Linkedid' => 'caller'],
        ['t' => 6, 'Event' => 'Newchannel', 'Uniqueid' => 'second', 'Linkedid' => 'caller'],
        ['t' => 6, 'Event' => 'DialBegin', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'second', 'DestExten' => '0541234567'],
        ['t' => 7, 'Event' => 'Hangup', 'Uniqueid' => 'second', 'Linkedid' => 'caller'],
        ['t' => 8, 'Event' => 'DialEnd', 'Uniqueid' => 'agent', 'Linkedid' => 'caller', 'DestUniqueid' => 'second', 'DialStatus' => 'ANSWER'],
    ]);
    expect(Interaction::count())->toBe(1);
    expect(CallTimingMetric::count())->toBe(2);
    expect(CorrelationException::where('code', 'invalid_time_sequence')->exists())->toBeTrue();
    $second = CallLeg::where('uniqueid', 'second')->firstOrFail();
    expect(CallTimingMetric::where('call_leg_id', $second->id)->firstOrFail()->mobile_connected_seconds)->toBeNull();
});

test('events received within one clock second are preserved when no PBX timestamp exists', function () {
    $this->freezeTime();
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $frame = ['Event' => 'Hold', 'Uniqueid' => 'caller', 'Linkedid' => 'caller'];
    foreach ([1, 2] as $copy) {
        $source = AmiEvent::create(['pbx_connection_id' => $connection->id, 'event_name' => 'Hold', 'payload' => $frame, 'received_at' => now()]);
        app(CallCorrelator::class)->process($source);
        app(CallCorrelator::class)->process($source);
    }
    expect(CallEvent::count())->toBe(2);
});

test('caller number captured before inbound classification remains visible in call history', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'early-caller', 'Linkedid' => 'early-caller', 'Channel' => 'PJSIP/trunk-1', 'CallerIDNum' => '0241234567'],
        ['t' => 1, 'Event' => 'Newexten', 'Uniqueid' => 'early-caller', 'Linkedid' => 'early-caller', 'Context' => 'exp-inbound'],
    ]);

    $interaction = Interaction::firstOrFail();
    expect($interaction->caller_number)->toBe('+233241*****67');
    expect($interaction->legs()->firstOrFail()->role)->toBe('caller');
    expect(CallEvent::all()->toJson())->not->toContain('0241234567');
});

test('late caller ID events update an already identified inbound call', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'late-caller', 'Linkedid' => 'late-caller', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'NewCallerid', 'Uniqueid' => 'late-caller', 'Linkedid' => 'late-caller', 'CallerIDNum' => '0241234567'],
    ]);

    expect(Interaction::firstOrFail()->caller_number)->toBe('+233241*****67');
    expect(CallEvent::all()->toJson())->not->toContain('0241234567');
});

test('caller number can come from a linked caller leg without replacing the inbound line', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'inbound-leg', 'Linkedid' => 'inbound-leg', 'Context' => 'exp-inbound', 'Exten' => '0596921095'],
        ['t' => 1, 'Event' => 'Newchannel', 'Uniqueid' => 'linked-caller', 'Linkedid' => 'inbound-leg', 'Context' => 'exp-inbound', 'CallerIDNum' => '0241234567'],
    ]);

    $interaction = Interaction::firstOrFail();
    expect($interaction->caller_number)->toBe('+233241*****67');
    expect($interaction->inbound_line)->toBe('059*****95');
});
