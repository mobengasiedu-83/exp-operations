<?php

use App\Models\AmiEvent;
use App\Models\ConnectionHealth;
use App\Models\CorrelationException;
use App\Models\IntegrationHealth;
use App\Models\PbxConnection;
use App\Models\PbxTelemetryGap;
use App\Models\Setting;
use App\Models\StructuredListenerLog;
use App\Services\AmiClient;
use App\Services\AmiEventNormalizer;
use App\Services\CallCorrelator;
use App\Services\PbxTelemetry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

function savedPbx(): PbxConnection
{
    return PbxConnection::create(['name' => config('services.exp_pbx.connection_name'), 'host' => '127.0.0.1', 'ami_port' => 5038, 'ami_username' => 'readonly', 'ami_password' => 'private-secret']);
}

/**
 * Keep the listener lock inside a per-process temp file so a live AMI listener on the
 * developer machine cannot take the lock the tests need to exercise the guard.
 */
function expListenerLockPath(): string
{
    static $path = null;

    return $path ??= sys_get_temp_dir().DIRECTORY_SEPARATOR.'exp-ami-listener-'.getmypid().'.lock';
}

beforeEach(function () {
    config(['services.exp_pbx.listener_lock_path' => expListenerLockPath()]);
});

afterEach(function () {
    if (is_file(expListenerLockPath())) {
        unlink(expListenerLockPath());
    }
});

test('configuration CLI saves encrypted credentials without exposing them', function () {
    $this->artisan('config:exp-pbx:set', ['name' => 'Test', 'host' => '127.0.0.1', 'port' => 5038, 'username' => 'readonly', 'password' => 'private-secret'])->assertSuccessful();
    $connection = PbxConnection::firstOrFail();
    expect($connection->ami_password)->toBe('private-secret');
    expect(DB::table('pbx_connections')->value('ami_password'))->not->toBe('private-secret');
    expect($connection->toJson())->not->toContain('private-secret', 'readonly');
    $this->artisan('config:exp-pbx:set', ['name' => 'Bad', 'host' => '127.0.0.1', 'port' => 0, 'username' => 'readonly', 'password' => 'private-secret'])->assertFailed();
    $this->assertDatabaseMissing('pbx_connections', ['name' => 'Bad']);
});

test('disconnect and reconnect signal the persistent listener without fabricating a connection', function () {
    $connection = savedPbx();
    $this->artisan('pbx:disconnect')->assertSuccessful();
    expect($connection->fresh()->listener_enabled)->toBeFalse();
    $this->artisan('pbx:reconnect')->assertSuccessful();
    expect($connection->fresh()->listener_enabled)->toBeTrue();
    expect($connection->fresh()->reconnect_generation)->toBe(1);
    expect($connection->fresh()->status)->toBe('disconnected');
});

test('listener persists redacted events and health and closes the socket', function () {
    $connection = savedPbx();
    $client = $this->mock(AmiClient::class);
    $client->shouldReceive('connect')->once()->withArgs(fn ($configuration) => $configuration['username'] === 'readonly' && $configuration['password'] === 'private-secret');
    $client->shouldReceive('nextFrame')->once()->andReturn(['Event' => 'Newchannel', 'Uniqueid' => 'call-1', 'CallerIDNum' => '0241234567', 'Secret' => 'hidden']);
    $client->shouldReceive('disconnect')->atLeast()->once();
    $this->artisan('exp:ami-listen', ['--once' => true])->assertSuccessful();
    $event = AmiEvent::firstOrFail();
    expect($event->payload['CallerIDNum'])->toBe('[REDACTED]');
    expect($event->payload['Secret'])->toBe('[REDACTED]');
    expect($connection->fresh()->last_heartbeat_at)->not->toBeNull();
    expect($connection->fresh()->status)->toBe('disconnected');
    expect(ConnectionHealth::firstOrFail()->connection->id)->toBe($connection->id);
    expect(StructuredListenerLog::count())->toBe(1);
});

test('dialplan noise is dropped before storage so the heartbeat keeps up', function () {
    savedPbx();
    $client = $this->mock(AmiClient::class);
    $client->shouldReceive('connect')->once();
    $client->shouldReceive('nextFrame')->once()->andReturn(['Event' => 'VarSet', 'Uniqueid' => 'call-1', 'Variable' => 'SECRET', 'Value' => 'hidden']);
    $client->shouldReceive('disconnect')->atLeast()->once();
    $this->artisan('exp:ami-listen', ['--once' => true])->assertSuccessful();
    expect(AmiEvent::count())->toBe(0);
    expect(StructuredListenerLog::count())->toBe(1);
});

test('listener records failed connection telemetry without leaking credentials', function () {
    savedPbx();
    $client = $this->mock(AmiClient::class);
    $client->shouldReceive('connect')->once()->andThrow(new RuntimeException('private-secret'));
    $client->shouldReceive('disconnect')->atLeast()->once();
    $this->artisan('exp:ami-listen', ['--once' => true])->assertFailed();
    $health = ConnectionHealth::firstOrFail();
    expect($health->reconnect_attempts)->toBe(1);
    expect($health->last_error)->not->toContain('private-secret');
    expect($health->last_error)->toContain('RuntimeException')->toContain('[REDACTED]');
    expect(StructuredListenerLog::firstOrFail()->level)->toBe('warning');
});

test('listener rejects a duplicate process before creating connection state', function () {
    $lock = fopen(expListenerLockPath(), 'c');
    flock($lock, LOCK_EX);
    try {
        $this->artisan('exp:ami-listen', ['--once' => true])->assertFailed();
        expect(PbxConnection::count())->toBe(0);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

test('telemetry retention enforces age and row limits when it runs', function () {
    $this->freezeTime();
    config(['services.exp_pbx.max_records' => 2, 'services.exp_pbx.retention_days' => 7]);
    $connection = savedPbx();
    AmiEvent::create(['pbx_connection_id' => $connection->id, 'event_name' => 'Old', 'payload' => [], 'received_at' => now()->subDays(8)]);
    $telemetry = app(PbxTelemetry::class);
    foreach (['Newchannel', 'DialBegin', 'Hangup'] as $event) {
        $telemetry->event($connection, ['Event' => $event]);
    }
    $telemetry->prune();
    expect(AmiEvent::orderBy('id')->pluck('event_name')->all())->toBe(['DialBegin', 'Hangup']);
    expect(StructuredListenerLog::count())->toBe(0);
});

test('AMI client rejects call control and header injection', function () {
    $client = new AmiClient;
    $socket = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    $property = new ReflectionProperty($client, 'socket');
    $property->setValue($client, $socket[0]);
    try {
        expect(fn () => $client->sendAction(['Action' => 'Originate']))->toThrow(RuntimeException::class, 'Only read-only');
        expect(fn () => $client->sendAction(['Action' => 'Ping', 'ActionID' => "x\r\nAction: Originate"]))->toThrow(RuntimeException::class, 'Invalid AMI');
    } finally {
        $client->disconnect();
        fclose($socket[1]);
    }
});

test('a missing heartbeat response disconnects the listener and records an error', function () {
    $this->freezeTime();
    $connection = savedPbx();
    $client = $this->mock(AmiClient::class);
    $client->shouldReceive('connect')->once();
    $client->shouldReceive('nextFrame')->once()->ordered()->andReturnUsing(function () {
        $this->travel(26)->seconds();

        return null;
    });
    $client->shouldReceive('sendAction')->once()->with(['Action' => 'Ping', 'ActionID' => 'exp-heartbeat']);
    $client->shouldReceive('nextFrame')->once()->ordered()->andReturnUsing(function () use ($connection) {
        $this->travel(46)->seconds();
        $connection->update(['listener_enabled' => false]);

        return null;
    });
    $client->shouldReceive('disconnect')->atLeast()->once();
    $this->artisan('exp:ami-listen')->assertFailed();
    expect(ConnectionHealth::firstOrFail()->reconnect_attempts)->toBe(1);
    expect($connection->fresh()->status)->toBe('disconnected');
});

test('AMI parsing retains partial lines across read timeouts', function () {
    $client = new AmiClient;
    $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
    stream_set_blocking($sockets[0], false);
    (new ReflectionProperty($client, 'socket'))->setValue($client, $sockets[0]);
    try {
        fwrite($sockets[1], 'Event: New');
        expect($client->readFrame(1))->toBeNull();
        fwrite($sockets[1], "channel\r\nUniqueid: one\r\n\r\n");
        expect($client->readFrame(1))->toBe(['Event' => 'Newchannel', 'Uniqueid' => 'one']);
    } finally {
        $client->disconnect();
        fclose($sockets[1]);
    }
});

test('disabling the AMI feature flag prevents any network connection', function () {
    Setting::create(['key' => 'app.feature_flags.ami_listener', 'group' => 'feature_flags', 'value' => 'false', 'cast' => 'boolean', 'is_feature_flag' => true]);
    $client = $this->mock(AmiClient::class);
    $client->shouldNotReceive('connect');
    $this->artisan('exp:ami-listen', ['--once' => true])->assertFailed();
    expect(PbxConnection::count())->toBe(0);
});

test('listener reconnect closes a coverage gap and recovers its command result', function () {
    $this->travelTo(now()->startOfSecond());
    Sleep::fake();
    $connection = savedPbx();
    $connection->update(['last_heartbeat_at' => now()->subMinute()]);
    $client = $this->mock(AmiClient::class);
    $client->shouldReceive('connect')->once()->ordered()->andThrow(new RuntimeException('Connection lost'));
    $client->shouldReceive('connect')->once()->ordered()->andReturnUsing(function () {
        $this->travel(2)->seconds();
    });
    $client->shouldReceive('nextFrame')->once()->andReturnUsing(function () use ($connection) {
        $connection->update(['listener_enabled' => false]);

        return null;
    });
    $client->shouldReceive('disconnect')->atLeast()->once();

    $this->artisan('exp:ami-listen')->assertSuccessful();

    $gap = PbxTelemetryGap::whereNotNull('ended_at')->sole();
    expect($gap->started_at->diffInSeconds($gap->ended_at))->toBe(62.0);
    expect(ConnectionHealth::firstOrFail()->reconnect_attempts)->toBe(1);
    expect($connection->fresh()->status)->toBe('disconnected');
});

test('failed event processing remains visible until replay and channel references are sanitised', function () {
    $connection = savedPbx();
    $correlator = Mockery::mock(CallCorrelator::class);
    $correlator->shouldReceive('process')->once()->andThrow(new RuntimeException('private clinical content'));
    $telemetry = new PbxTelemetry(app(AmiEventNormalizer::class), $correlator);

    $telemetry->event($connection, ['Event' => 'Newchannel', 'Uniqueid' => 'failed-frame', 'Context' => 'exp-inbound', 'Channel' => 'PJSIP/0241234567-00000001', 'CallerIDNum' => '0241234567']);

    expect(CorrelationException::where('code', 'event_processing_failed')->count())->toBe(1);
    expect(AmiEvent::firstOrFail()->toJson())->not->toContain('0241234567');
    expect(CorrelationException::firstOrFail()->toJson())->not->toContain('private clinical content');
    app(PbxTelemetry::class)->event($connection, ['Event' => 'Newchannel', 'Uniqueid' => 'other-frame']);
    expect(IntegrationHealth::where('component', 'call_correlation')->value('status'))->toBe('unhealthy');
    $this->artisan('exp:correlate-calls')->assertSuccessful();
    expect(CorrelationException::where('code', 'event_processing_failed')->exists())->toBeFalse();
    expect(IntegrationHealth::where('component', 'call_correlation')->value('status'))->toBe('healthy');
});

test('retained channel sanitisation preserves identity and supports repeat runs', function () {
    $connection = savedPbx();
    $event = AmiEvent::create([
        'pbx_connection_id' => $connection->id, 'event_name' => 'Newchannel', 'uniqueid' => '1770000000.1',
        'channel' => 'PJSIP/0241234567-00000001', 'payload' => ['Channel' => 'PJSIP/0241234567-00000001', 'Uniqueid' => '1770000000.1'], 'received_at' => now(),
    ]);
    $migration = require database_path('migrations/2026_11_19_220001_sanitize_retained_ami_channel_references.php');

    $migration->up();
    $migration->up();

    expect($event->fresh()->toJson())->not->toContain('0241234567');
    expect($event->fresh()->uniqueid)->toBe('1770000000.1');
    expect($event->fresh()->payload['Uniqueid'])->toBe('1770000000.1');
});
