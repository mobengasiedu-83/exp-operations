<?php

namespace App\Console\Commands;

use App\Models\PbxConnection;
use Illuminate\Console\Command;

class ConfigExpPbxGet extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config:exp-pbx:get {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display one EXP PBX connection configuration by name.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        $connection = PbxConnection::query()
            ->where('name', $name)
            ->first();

        if ($connection === null) {
            $this->error(\sprintf('PBX connection "%s" not found.', $name));

            return self::FAILURE;
        }

        $this->table(
            ['Attribute', 'Value'],
            [
                ['Name', $connection->name],
                ['Host', $connection->host],
                ['Port', (string) $connection->ami_port],
                ['Status', $connection->status],
                ['Last Connected', $connection->last_connected_at?->toIso8601String() ?? '—'],
                ['Last Event', $connection->last_event_at?->toIso8601String() ?? '—'],
                ['Last Error', $connection->last_error ?? 'None'],
            ]
        );

        return self::SUCCESS;
    }
}
