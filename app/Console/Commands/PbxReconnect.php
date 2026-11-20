<?php

namespace App\Console\Commands;

use App\Models\PbxConnection;
use Illuminate\Console\Command;

class PbxReconnect extends Command
{
    protected $signature = 'pbx:reconnect';

    protected $description = 'Control the persistent read-only AMI listener.';

    public function handle(): int
    {
        $connection = PbxConnection::where('name', config('services.exp_pbx.connection_name'))->first();
        if ($connection === null) {
            $this->error('Configure a PBX connection first with config:exp-pbx:set.');

            return self::FAILURE;
        }
        $connection->update(['listener_enabled' => true]);
        $connection->increment('reconnect_generation');
        $this->info('Reconnect requested. A running listener will reconnect; start pbx:connect if it is stopped.');

        return self::SUCCESS;
    }
}
