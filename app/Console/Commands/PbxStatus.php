<?php

namespace App\Console\Commands;

use App\Models\ConnectionHealth;
use App\Models\PbxConnection;
use Illuminate\Console\Command;

class PbxStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'pbx:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show connection status, heartbeat, event count and health.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $configuration = config('services.exp_pbx');
        $connection = PbxConnection::query()
            ->where('name', $configuration['connection_name'])
            ->first();

        if ($connection === null) {
            $this->error('No PBX connection named "'.($configuration['connection_name'] ?? 'unknown').'".');

            return self::FAILURE;
        }

        $health = ConnectionHealth::query()
            ->where('connection_id', $connection->id)
            ->first();

        $this->table(
            ['Attribute', 'Value'],
            [
                ['Connection Name', $connection->name],
                ['Host', $connection->host],
                ['Port', (string) $connection->ami_port],
                ['Status', $connection->status === 'connected' && ($connection->last_heartbeat_at === null || $connection->last_heartbeat_at->lt(now()->subSeconds(config('services.exp_pbx.heartbeat_stale_seconds', 90)))) ? 'stale' : $connection->status],
                ['Event Count', $connection->events()->count()],
                ['Last Connected', $connection->last_connected_at?->toIso8601String() ?? '—'],
                ['Last Event', $connection->last_event_at?->toIso8601String() ?? '—'],
                ['Last Heartbeat', $connection->last_heartbeat_at?->toIso8601String() ?? '—'],
                ['Last Error', $connection->last_error ?? 'None'],
                ['Reconnect Attempts', $health->reconnect_attempts ?? 0],
                ['Backoff Seconds', $health->backoff_seconds ?? 0],
                ['Last Heartbeat At', $health?->last_heartbeat_at?->toIso8601String() ?? '—'],
                ['Last Event At', $health?->last_event_at?->toIso8601String() ?? '—'],
                ['Lag Seconds', $health->lag_seconds ?? '—'],
                ['Last Error', $health->last_error ?? 'None'],
            ]
        );

        return self::SUCCESS;
    }
}
