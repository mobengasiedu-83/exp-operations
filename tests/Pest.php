<?php

use App\Models\AmiEvent;
use App\Models\PbxConnection;
use App\Services\AmiEventNormalizer;
use App\Services\CallCorrelator;
use App\Services\PbxTelemetry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/** @return list<array<string, mixed>> */
function warmTransferFrames(): array
{
    return [
        ['t' => 0, 'Event' => 'Newchannel', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/inbound-1', 'Context' => 'exp-inbound'],
        ['t' => 1, 'Event' => 'Newchannel', 'Uniqueid' => 'agent-in', 'Linkedid' => 'caller', 'Channel' => 'PJSIP/204-in'],
        ['t' => 2, 'Event' => 'Newstate', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Up'],
        ['t' => 2, 'Event' => 'Newstate', 'Uniqueid' => 'agent-in', 'Linkedid' => 'caller', 'ChannelStateDesc' => 'Up'],
        ['t' => 4, 'Event' => 'Newchannel', 'Uniqueid' => 'agent-out', 'Linkedid' => 'agent-out', 'Channel' => 'PJSIP/204-out'],
        ['t' => 4, 'Event' => 'Newstate', 'Uniqueid' => 'agent-out', 'Linkedid' => 'agent-out', 'ChannelStateDesc' => 'Up'],
        ['t' => 5, 'Event' => 'Newchannel', 'Uniqueid' => 'mobile', 'Linkedid' => 'agent-out', 'Channel' => 'PJSIP/trunk-2'],
        ['t' => 5, 'Event' => 'DialBegin', 'Uniqueid' => 'agent-out', 'Linkedid' => 'agent-out', 'Channel' => 'PJSIP/204-out', 'DestUniqueid' => 'mobile', 'DestLinkedid' => 'agent-out', 'DestChannel' => 'PJSIP/trunk-2', 'DialString' => 'PJSIP/trunk/0241234567'],
        ['t' => 7, 'Event' => 'Newstate', 'Uniqueid' => 'mobile', 'Linkedid' => 'agent-out', 'ChannelStateDesc' => 'Ringing'],
        ['t' => 10, 'Event' => 'Newstate', 'Uniqueid' => 'mobile', 'Linkedid' => 'agent-out', 'ChannelStateDesc' => 'Up'],
        ['t' => 10, 'Event' => 'DialEnd', 'Uniqueid' => 'agent-out', 'Linkedid' => 'agent-out', 'DestUniqueid' => 'mobile', 'DestLinkedid' => 'agent-out', 'DialStatus' => 'ANSWER'],
        ['t' => 11, 'Event' => 'BridgeEnter', 'Uniqueid' => 'agent-out', 'Linkedid' => 'agent-out', 'BridgeUniqueid' => 'handover'],
        ['t' => 12, 'Event' => 'BridgeEnter', 'Uniqueid' => 'mobile', 'Linkedid' => 'agent-out', 'BridgeUniqueid' => 'handover'],
        ['t' => 20, 'Event' => 'BridgeLeave', 'Uniqueid' => 'agent-out', 'Linkedid' => 'agent-out', 'BridgeUniqueid' => 'handover'],
        ['t' => 20, 'Event' => 'BridgeLeave', 'Uniqueid' => 'mobile', 'Linkedid' => 'agent-out', 'BridgeUniqueid' => 'handover'],
        ['t' => 21, 'Event' => 'AttendedTransfer', 'Result' => 'Success', 'OrigTransfererUniqueid' => 'agent-in', 'OrigTransfererLinkedid' => 'caller', 'OrigTransfererChannel' => 'PJSIP/204-in', 'SecondTransfererUniqueid' => 'agent-out', 'SecondTransfererLinkedid' => 'agent-out', 'SecondTransfererChannel' => 'PJSIP/204-out', 'TransfereeUniqueid' => 'caller', 'TransfereeLinkedid' => 'caller', 'TransferTargetUniqueid' => 'mobile', 'TransferTargetLinkedid' => 'agent-out'],
        ['t' => 22, 'Event' => 'Hangup', 'Uniqueid' => 'agent-in', 'Linkedid' => 'caller'],
        ['t' => 22, 'Event' => 'Hangup', 'Uniqueid' => 'agent-out', 'Linkedid' => 'caller'],
        ['t' => 23, 'Event' => 'BridgeEnter', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'BridgeUniqueid' => 'consultation'],
        ['t' => 24, 'Event' => 'BridgeEnter', 'Uniqueid' => 'mobile', 'Linkedid' => 'caller', 'BridgeUniqueid' => 'consultation'],
        ['t' => 60, 'Event' => 'BridgeLeave', 'Uniqueid' => 'caller', 'Linkedid' => 'caller', 'BridgeUniqueid' => 'consultation'],
        ['t' => 60, 'Event' => 'BridgeLeave', 'Uniqueid' => 'mobile', 'Linkedid' => 'caller', 'BridgeUniqueid' => 'consultation'],
        ['t' => 60, 'Event' => 'Hangup', 'Uniqueid' => 'caller', 'Linkedid' => 'caller'],
        ['t' => 61, 'Event' => 'Hangup', 'Uniqueid' => 'mobile', 'Linkedid' => 'caller'],
    ];
}

/** @param list<array<string, mixed>> $frames */
function correlateCallFrames(PbxConnection $connection, array $frames): void
{
    config(['services.exp_pbx.inbound_contexts' => ['exp-inbound']]);
    foreach ($frames as $frame) {
        $time = CarbonImmutable::parse('2026-01-01 10:00:00 UTC')->addSeconds($frame['t'] ?? 0);
        unset($frame['t']);
        $frame['Timestamp'] = (string) $time->getTimestamp();
        $source = AmiEvent::create([
            'pbx_connection_id' => $connection->id, 'event_name' => $frame['Event'],
            'payload' => app(PbxTelemetry::class)->redact($frame),
            'received_at' => now(), 'correlation_data' => app(AmiEventNormalizer::class)->normalize($frame, now()),
        ]);
        app(CallCorrelator::class)->process($source);
    }
}
