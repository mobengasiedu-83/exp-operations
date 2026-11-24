<?php

namespace App\Console\Commands;

use App\Models\ConnectionHealth;
use App\Models\IntegrationHealth;
use App\Models\PbxConnection;
use App\Models\Setting;
use App\Services\AmiClient;
use App\Services\PbxTelemetry;
use App\Services\TelemetryCoverage;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

class ExpAmiListener extends Command
{
    protected $signature = 'exp:ami-listen {--connection=} {--once : Exit after one connection attempt}';

    protected $description = 'Run the single-instance read-only AMI listener.';

    private bool $quit = false;

    public function handle(AmiClient $client, PbxTelemetry $telemetry, TelemetryCoverage $coverage): int
    {
        if (! Setting::get('app.feature_flags.ami_listener', true)) {
            $this->error('The AMI listener feature flag is disabled.');

            return self::FAILURE;
        }
        $name = $this->option('connection') ?: config('services.exp_pbx.connection_name');
        $lock = fopen($this->lockPath(), 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->error('The EXP AMI listener is already running.');

            return self::FAILURE;
        }

        $connection = null;
        $result = self::SUCCESS;
        $attempts = 0;
        $this->quit = false;

        try {
            $connection = PbxConnection::firstOrCreate(['name' => $name], [
                'host' => config('services.exp_pbx.host') ?? '',
                'ami_port' => config('services.exp_pbx.port', 5038),
            ]);
            if (function_exists('pcntl_async_signals')) {
                $this->trap([SIGTERM, SIGINT], function (): void {
                    $this->quit = true;
                });
            }

            while (! $this->shouldStop() && $connection->refresh()->listener_enabled) {
                try {
                    $configuration = $connection->configuration();
                    if (blank($configuration['host']) || blank($configuration['username']) || blank($configuration['password'])) {
                        throw new RuntimeException('Configure AMI host and read-only credentials before starting the listener.');
                    }
                    $generation = $connection->reconnect_generation;
                    $client->connect($configuration);
                    $coverage->connected($connection);
                    $result = self::SUCCESS;
                    IntegrationHealth::record('ami_listener', 'healthy');
                    $this->info('Connected to VitalPBX AMI (read-only).');
                    $connection->update(['status' => 'connected', 'last_connected_at' => now(), 'last_heartbeat_at' => now(), 'last_error' => null]);
                    $telemetry->health($connection, ['status' => 'connected', 'backoff_seconds' => 0, 'last_heartbeat_at' => now(), 'last_error' => null]);
                    $telemetry->log($connection, 'info', 'AMI connected.');
                    $lastPing = now()->getTimestamp();
                    $lastPrune = now()->getTimestamp();
                    $pendingPing = null;
                    $heartbeatInterval = max(5, (int) config('services.exp_pbx.heartbeat_interval_seconds', 20));
                    $heartbeatTimeout = max(10, (int) config('services.exp_pbx.heartbeat_timeout_seconds', 45));
                    $pruneInterval = max(10, (int) config('services.exp_pbx.prune_interval_seconds', 60));

                    while (! $this->shouldStop() && $connection->refresh()->listener_enabled) {
                        if ($connection->reconnect_generation !== $generation) {
                            break;
                        }
                        $frame = $client->nextFrame();
                        if (isset($frame['Event'])) {
                            $telemetry->event($connection, $frame);
                        }
                        // Any inbound frame proves the socket is alive. PBX builds answer `Ping`
                        // with `Response: Pong` (and some omit the ActionID entirely), so a busy
                        // feed must never be mistaken for a dead heartbeat and forced to reconnect.
                        if ($frame !== null && $pendingPing !== null) {
                            if (($frame['ActionID'] ?? null) === 'exp-heartbeat'
                                && ! in_array(strtolower((string) ($frame['Response'] ?? '')), ['success', 'pong'], true)) {
                                throw new RuntimeException('AMI heartbeat rejected.');
                            }
                            $pendingPing = null;
                            $attempts = 0;
                            $connection->update(['last_heartbeat_at' => now()]);
                            $telemetry->health($connection, ['status' => 'connected', 'last_heartbeat_at' => now()]);
                        }
                        if ($pendingPing !== null && now()->getTimestamp() - $pendingPing >= $heartbeatTimeout) {
                            throw new RuntimeException('AMI heartbeat timed out.');
                        }
                        if ($pendingPing === null && now()->getTimestamp() - $lastPing >= $heartbeatInterval) {
                            $client->sendAction(['Action' => 'Ping', 'ActionID' => 'exp-heartbeat']);
                            $lastPing = $pendingPing = now()->getTimestamp();
                        }
                        if (now()->getTimestamp() - $lastPrune >= $pruneInterval) {
                            $telemetry->prune();
                            $lastPrune = now()->getTimestamp();
                        }
                        if ($this->option('once')) {
                            break;
                        }
                    }
                } catch (Throwable $exception) {
                    $coverage->disconnected($connection);
                    $attempts++;
                    $backoff = min(60, 2 ** min($attempts, 6));
                    $reason = $this->redactReason($connection, $exception);
                    $error = mb_substr(sprintf('AMI connection or event processing failed (%s)%s', $exception::class, $reason === '' ? '.' : ': '.$reason.'.'), 0, 300);
                    $connection->update(['status' => 'disconnected', 'last_error' => $error]);
                    $telemetry->health($connection, ['status' => 'disconnected', 'backoff_seconds' => $backoff, 'last_error' => $error]);
                    ConnectionHealth::where('connection_id', $connection->id)->increment('reconnect_attempts');
                    $telemetry->log($connection, 'warning', $error, ['backoff_seconds' => $backoff]);
                    IntegrationHealth::record('ami_listener', 'unhealthy', $error);
                    $this->warn($error);
                    $result = self::FAILURE;
                    $client->disconnect();
                    if (! $this->option('once')) {
                        for ($second = 0; $second < $backoff && ! $this->shouldStop() && $connection->refresh()->listener_enabled; $second++) {
                            Sleep::for(1)->seconds();
                        }
                    }
                } finally {
                    $coverage->disconnected($connection, 'listener_reconnecting');
                    $client->disconnect();
                }
                if ($this->option('once')) {
                    break;
                }
            }
        } finally {
            $client->disconnect();
            if ($connection !== null) {
                $coverage->disconnected($connection, 'listener_stopped');
                $connection->update(['status' => 'disconnected']);
                $telemetry->health($connection, ['status' => 'disconnected', 'backoff_seconds' => 0]);
                IntegrationHealth::record('ami_listener', 'unhealthy', 'AMI listener is disconnected.');
            }
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->untrap();
        }

        return $result;
    }

    /**
     * Resolve the single-instance lock file, allowing deployments and tests to relocate it.
     */
    private function lockPath(): string
    {
        $path = config('services.exp_pbx.listener_lock_path');

        return is_string($path) && $path !== '' ? $path : storage_path('framework/exp-ami-listener.lock');
    }

    /**
     * Build a single-line failure reason with the configured AMI credentials stripped.
     */
    private function redactReason(PbxConnection $connection, Throwable $exception): string
    {
        $reason = trim((string) preg_replace('/\s+/', ' ', $exception->getMessage()));

        if ($reason === '') {
            return '';
        }

        try {
            $configuration = $connection->configuration();
        } catch (Throwable) {
            return '';
        }

        $secrets = array_values(array_filter(
            [$configuration['username'], $configuration['password']],
            fn (string $secret): bool => $secret !== '',
        ));
        usort($secrets, fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        return $secrets === [] ? $reason : str_replace($secrets, '[REDACTED]', $reason);
    }

    /**
     * Signal callbacks can change this value between calls.
     *
     * @phpstan-impure
     */
    private function shouldStop(): bool
    {
        return $this->quit;
    }
}
