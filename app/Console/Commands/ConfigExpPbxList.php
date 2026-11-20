<?php

namespace App\Console\Commands;

use App\Models\PbxConnection;
use Illuminate\Console\Command;

class ConfigExpPbxList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config:exp-pbx:list';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List saved EXP PBX connection configuration rows.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connections = PbxConnection::query()
            ->orderBy('name')
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No EXP PBX connection configurations saved.');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Host', 'Port', 'Username (masked)', 'Status'],
            $connections->map(function (PbxConnection $connection): array {
                return [
                    $connection->name,
                    $connection->host,
                    $connection->ami_port,
                    $connection->ami_username ? '********' : '(environment)',
                    $connection->status,
                ];
            })
        );

        $this->info(\count($connections).' connection configuration(s) listed.');

        return self::SUCCESS;
    }
}
