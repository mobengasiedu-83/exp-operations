<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AmiEvent;
use App\Models\CallTimingMetric;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\PbxTelemetryGap;
use App\Models\TransferAttempt;
use App\Models\User;
use App\Services\CallReconciler;
use App\Services\CallTimelineProjector;
use App\Services\TelemetryCoverage;
use Spatie\Permission\Models\Permission;

test('a listener outage invalidates durations and survives reconnect replay and raw retention', function () {
    $this->travelTo(now()->setDate(2026, 1, 1)->setTime(10, 0, 40));
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1', 'status' => 'connected', 'last_heartbeat_at' => '2026-01-01 10:00:30']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    $coverage = app(TelemetryCoverage::class);

    $coverage->disconnected($connection);
    $coverage->disconnected($connection);

    expect(PbxTelemetryGap::count())->toBe(1);
    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBeNull();
    expect(TransferAttempt::firstOrFail()->consultation_seconds)->toBeNull();
    expect(TransferAttempt::firstOrFail()->session->intervals[0]['duration_seconds'])->toBeNull();
    expect($interaction->exceptions()->where('code', 'telemetry_gap')->count())->toBe(1);
    $this->travel(10)->seconds();
    $coverage->connected($connection);
    expect(PbxTelemetryGap::firstOrFail()->ended_at->format('H:i:s'))->toBe('10:00:50');
    AmiEvent::query()->delete();
    $this->artisan('exp:correlate-calls', ['--rebuild' => true])->assertSuccessful();
    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBeNull();
    expect($interaction->bridges()->whereNotNull('duration_seconds')->count())->toBe(0);
    expect($interaction->exceptions()->where('code', 'telemetry_gap')->count())->toBe(1);
    app(CallReconciler::class)->reconcile($interaction);
    expect($interaction->fresh()->reconciliation_status)->toBe('insufficient');
});

test('outages outside the call interval and other connections do not invalidate durations', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    correlateCallFrames($connection, warmTransferFrames());
    $interaction = Interaction::firstOrFail();
    PbxTelemetryGap::factory()->create(['pbx_connection_id' => $connection->id, 'started_at' => '2026-01-01 09:00:00', 'ended_at' => '2026-01-01 10:00:00']);
    PbxTelemetryGap::factory()->create(['pbx_connection_id' => $connection->id, 'started_at' => '2026-01-01 10:01:01', 'ended_at' => '2026-01-01 10:02:00']);
    PbxTelemetryGap::factory()->create(['started_at' => '2026-01-01 10:00:30', 'ended_at' => '2026-01-01 10:00:40']);

    app(CallTimelineProjector::class)->rebuild($interaction);

    expect(CallTimingMetric::firstOrFail()->consultation_seconds)->toBe(36.0);
    expect($interaction->exceptions()->where('code', 'telemetry_gap')->exists())->toBeFalse();
});

test('restart after an abrupt listener loss records the unobserved interval', function () {
    $this->travelTo(now()->startOfSecond());
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1', 'status' => 'connected', 'last_heartbeat_at' => now()->subMinutes(3)]);

    app(TelemetryCoverage::class)->connected($connection);

    $gap = PbxTelemetryGap::sole();
    expect($gap->reason)->toBe('listener_restart');
    expect($gap->started_at->diffInSeconds($gap->ended_at))->toBe(180.0);
});

test('detail refresh exposes stale telemetry and historical gaps without exposing case data', function () {
    Permission::findOrCreate('view pbx');
    $user = User::factory()->create();
    $user->givePermissionTo('view pbx');
    $interaction = Interaction::factory()->create(['started_at' => now()->subMinutes(2)]);
    PbxConnection::whereKey($interaction->pbx_connection_id)->update(['status' => 'connected', 'last_heartbeat_at' => now()->subMinutes(3)]);
    PbxTelemetryGap::factory()->create(['pbx_connection_id' => $interaction->pbx_connection_id]);

    $this->actingAs($user)->get(route('calls.show', $interaction), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => (string) (new HandleInertiaRequests)->version(request()), 'X-Inertia-Partial-Component' => 'Calls/Show', 'X-Inertia-Partial-Data' => 'telemetry',
    ])->assertOk()->assertJsonPath('component', 'Calls/Show')
        ->assertJsonPath('props.telemetry.freshness.state', 'stale')
        ->assertJsonCount(1, 'props.telemetry.gaps')->assertJsonMissingPath('props.record');
});

test('agent exit preserves an ongoing consultation until the caller and dentist hang up', function () {
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $frames = warmTransferFrames();
    correlateCallFrames($connection, array_values(array_filter($frames, fn (array $frame): bool => $frame['t'] <= 24)));
    $interaction = Interaction::firstOrFail();

    expect($interaction->status)->toBe('active');
    expect($interaction->legs()->where('role', 'agent')->whereNull('ended_at')->count())->toBe(0);
    expect($interaction->bridges()->where('stage', 'consultation')->whereNull('ended_at')->count())->toBe(1);
    expect($interaction->metrics()->firstOrFail()->consultation_seconds)->toBeNull();
    correlateCallFrames($connection, array_values(array_filter($frames, fn (array $frame): bool => $frame['t'] > 24)));
    expect($interaction->fresh()->status)->toBe('completed');
    expect($interaction->metrics()->firstOrFail()->consultation_seconds)->toBe(36.0);
});

test('a boundary call does not stop outage invalidation of subsequent overlapping calls', function () {
    $this->travelTo(now()->setDate(2026, 1, 1)->setTime(10, 0, 50));
    $connection = PbxConnection::create(['name' => 'PBX', 'host' => '127.0.0.1']);
    $boundary = Interaction::factory()->create(['pbx_connection_id' => $connection->id, 'started_at' => '2026-01-01 10:00:50', 'ended_at' => '2026-01-01 10:01:00']);
    correlateCallFrames($connection, warmTransferFrames());
    $overlapping = Interaction::whereKeyNot($boundary->id)->firstOrFail();
    PbxTelemetryGap::factory()->create(['pbx_connection_id' => $connection->id, 'started_at' => '2026-01-01 10:00:30', 'ended_at' => null]);

    app(TelemetryCoverage::class)->connected($connection);

    expect($boundary->exceptions()->where('code', 'telemetry_gap')->exists())->toBeFalse();
    expect($overlapping->exceptions()->where('code', 'telemetry_gap')->exists())->toBeTrue();
    expect($overlapping->metrics()->firstOrFail()->consultation_seconds)->toBeNull();
});

test('PBX configuration cleanup preserves outage evidence even without calls', function () {
    $gap = PbxTelemetryGap::factory()->create();
    $connection = PbxConnection::findOrFail($gap->pbx_connection_id);
    Permission::findOrCreate('edit settings');
    $user = User::factory()->create();
    $user->givePermissionTo('edit settings');

    $this->artisan('config:exp-pbx:delete', ['name' => $connection->name])->assertFailed();
    $this->artisan('config:exp-pbx:clear')->assertFailed();
    $this->actingAs($user)->delete(route('admin.pbx-connections.destroy', $connection))->assertSessionHasErrors('connection');

    $this->assertModelExists($gap);
    $this->assertModelExists($connection);
});
