<?php

namespace App\Console\Commands;

use App\Models\PbxConnection;
use Illuminate\Console\Command;

class PbxDisconnect extends Command
{
    protected $signature = 'pbx:disconnect';

    protected $description = 'Control the persistent read-only AMI listener.';

    public function handle(): int
    {
        $connection = PbxConnection::where('name', config('services.exp_pbx.connection_name'))->first();
        if ($connection === null) {
            $this->error('Configure a PBX connection first with config:exp-pbx:set.');

            return self::FAILURE;
        }
        $connection->update(['listener_enabled' => false]);
        $this->info('Disconnect requested. The listener will close its socket and exit.');

        return self::SUCCESS;
    }
}
