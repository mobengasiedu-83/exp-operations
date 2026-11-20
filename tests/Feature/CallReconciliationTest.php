<?php

use App\Models\CdrRecord;
use App\Models\CelRecord;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\PbxTelemetryGap;
use App\Services\CallReconciler;

function matchingCallExports(Interaction $interaction): void
{
    foreach ($interaction->legs()->get() as $leg) {
        $record = CdrRecord::create([
            'uniqueid' => $leg->uniqueid, 'linkedid' => 'caller',
            'start' => $leg->started_at, 'answer' => $leg->answered_at, 'end' => $leg->ended_at,
            'disposition' => $leg->answered_at === null ? 'NO ANSWER' : 'ANSWERED',
            'billsec' => $leg->answered_at === null ? 0 : (int) $leg->answered_at->diffInSeconds($leg->ended_at),
        ]);
        $record->forceFill(['pbx_connection_id' => $interaction->pbx_connection_id])->save();
        CelRecord::create(['uniqueid' => $leg->uniqueid, 'event_type' => 'CHAN_START', 'event_time' => $leg->started_at->format('Y-m-d H:i:s')])->forceFill(['pbx_connection_id' => $interaction->pbx_connection_id])->save();
        CelRecord::create(['uniqueid' => $leg->uniqueid, 'event_type' => 'HANGUP', 'event_time' => $leg->ended_at->format('Y-m-d H:i:s')])
            ->forceFill(['pbx_connection_id' => $interaction->pbx_connection_id])->save();
        if ($leg->answered_at !== null) {
            CelRecord::create(['uniqueid' => $leg->uniqueid, 'event_type' => 'ANSWER', 'event_time' => $leg->answered_at->format('Y-m-d H:i:s')])->forceFill(['pbx_connection_id' => $interaction->pbx_connection_id])->save();
        }
        foreach ($interaction->events()->where('event_name', 'BridgeEnter')->get() as $event) {
            if ($event->data['primary_id'] !== $leg->uniqueid) {
                continue;
            }
            $leave = $interaction->events()->where('event_name', 'BridgeLeave')->get()->first(fn ($row): bool => $row->data['primary_id'] === $leg->uniqueid && $row->data['bridge_id'] === $event->data['bridge_id'] && $row->occurred_at->gte($event->occurred_at));
            foreach (['BRIDGE_ENTER' => $event->occurred_at, 'BRIDGE_EXIT' => $leave?->occurred_at ?? $leg->ended_at] as $type => $at) {
                CelRecord::create(['uniqueid' => $leg->uniqueid, 'event_type' => $type, 'event_time' => $at->format('Y-m-d H:i:s')])->forceFill(['pbx_connection_id' => $interaction->pbx_connection_id])->save();
            }
        }
    }
}

test('completed channel evidence reconciles with scoped CDR and CEL without treating billsec as consultation', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->fresh()->reconciliation_status)->toBe('matched');
    expect($interaction->metrics()->firstOrFail()->consultation_seconds)->toBe(36.0);
    expect($interaction->exceptions()->count())->toBe(0);
});

test('missing and unscoped exports cannot produce a matched reconciliation', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    CdrRecord::query()->update(['pbx_connection_id' => null]);
    CelRecord::query()->update(['pbx_connection_id' => null]);
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->fresh()->reconciliation_status)->toBe('insufficient');
    expect($interaction->exceptions()->where('code', 'missing_cdr')->count())->toBe(4);
    expect($interaction->exceptions()->where('code', 'missing_cel')->count())->toBe(4);
});

test('a wrong CDR answer and CEL hangup are reported and reruns replace obsolete discrepancies', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    CdrRecord::where('uniqueid', 'mobile')->update(['answer' => '2026-01-01 10:00:20']);
    CelRecord::where('uniqueid', 'mobile')->where('event_type', 'HANGUP')->update(['event_time' => '2026-01-01 10:01:10']);
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->fresh()->reconciliation_status)->toBe('discrepancy');
    expect($interaction->exceptions()->whereIn('code', ['cdr_answer_mismatch', 'cel_hangup_mismatch'])->count())->toBe(2);
    CdrRecord::where('uniqueid', 'mobile')->update(['answer' => '2026-01-01 10:00:10']);
    CelRecord::where('uniqueid', 'mobile')->where('event_type', 'HANGUP')->update(['event_time' => '2026-01-01 10:01:01']);
    $this->artisan('exp:reconcile-calls', ['--interaction' => $interaction->id])->assertSuccessful();
    expect($interaction->fresh()->reconciliation_status)->toBe('matched');
    expect($interaction->exceptions()->where('category', 'reconciliation')->count())->toBe(0);
});

test('an active call remains pending during reconciliation', function () {
    $interaction = Interaction::factory()->create(['status' => 'active']);
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->reconciliation_status)->toBe('pending');
    expect($interaction->reconciled_at)->toBeNull();
});

test('new scoped imports return completed interactions to pending reconciliation', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $interaction = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'status' => 'completed', 'reconciliation_status' => 'insufficient']);
    $path = tempnam(sys_get_temp_dir(), 'exp-scope-');
    file_put_contents($path, json_encode(['uniqueid' => 'source', 'start' => '2026-01-01 10:00:00']));
    try {
        $this->artisan('exp:import-call-records', ['type' => 'cdr', 'file' => $path, '--connection' => 'PBX', '--timezone' => 'Africa/Accra'])->assertSuccessful();
        expect(CdrRecord::firstOrFail()->pbx_connection_id)->toBe($connection->id);
        expect($interaction->fresh()->reconciliation_status)->toBe('pending');
    } finally {
        unlink($path);
    }
});

test('missing CEL bridge evidence is reported even when CDR durations agree', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    CelRecord::where('uniqueid', 'mobile')->where('event_type', 'BRIDGE_ENTER')->delete();
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->fresh()->reconciliation_status)->toBe('discrepancy');
    expect($interaction->exceptions()->where('code', 'cel_bridge_evidence_mismatch')->exists())->toBeTrue();
});

test('missing source answer evidence cannot be reported as a matched call', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    CdrRecord::where('uniqueid', 'mobile')->update(['answer' => null]);
    CelRecord::where('uniqueid', 'mobile')->where('event_type', 'ANSWER')->delete();
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->fresh()->reconciliation_status)->toBe('insufficient');
    expect($interaction->exceptions()->whereIn('code', ['missing_cdr_answer', 'missing_cel_answer'])->count())->toBe(2);
});

test('CEL bridge timestamp discrepancies are detected despite matching counts and hangups', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    CelRecord::where('uniqueid', 'mobile')->where('event_type', 'BRIDGE_ENTER')->orderBy('id')->firstOrFail()->update(['event_time' => '2026-01-01 10:00:18']);
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->fresh()->reconciliation_status)->toBe('discrepancy');
    expect($interaction->exceptions()->where('code', 'cel_bridge_time_mismatch')->exists())->toBeTrue();
});

test('missing CEL creation and out of tolerance creation prevent a match', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    $start = CelRecord::where('uniqueid', 'mobile')->where('event_type', 'CHAN_START')->firstOrFail();
    $start->update(['event_time' => null]);

    app(CallReconciler::class)->reconcile($interaction);

    expect($interaction->fresh()->reconciliation_status)->toBe('insufficient');
    expect($interaction->exceptions()->where('code', 'missing_cel_start')->exists())->toBeTrue();
    $start->update(['event_time' => '2026-01-01 10:00:20']);
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->fresh()->reconciliation_status)->toBe('discrepancy');
    expect($interaction->exceptions()->where('code', 'cel_start_mismatch')->exists())->toBeTrue();
});

test('reconciliation accepts timestamp differences at the configured tolerance boundary', function () {
    config(['services.exp_pbx.reconciliation_tolerance_seconds' => 2]);
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    CelRecord::where('uniqueid', 'mobile')->where('event_type', 'CHAN_START')->update(['event_time' => '2026-01-01 10:00:07']);

    app(CallReconciler::class)->reconcile($interaction);

    expect($interaction->fresh()->reconciliation_status)->toBe('matched');
});

test('matching exports cannot erase a known listener coverage gap', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    matchingCallExports($interaction);
    PbxTelemetryGap::factory()->create([
        'pbx_connection_id' => $connection->id, 'started_at' => '2026-01-01 10:00:30', 'ended_at' => '2026-01-01 10:00:40',
    ]);

    app(CallReconciler::class)->reconcile($interaction);

    expect($interaction->fresh()->reconciliation_status)->toBe('insufficient');
    expect($interaction->metrics()->firstOrFail()->consultation_seconds)->toBeNull();
    expect($interaction->exceptions()->where('code', 'telemetry_gap')->exists())->toBeTrue();
});
