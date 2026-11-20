<?php

namespace App\Console\Commands;

use App\Services\PbxTelemetry;
use Illuminate\Console\Command;

class ExpPruneTelemetry extends Command
{
    protected $signature = 'exp:prune-telemetry';

    protected $description = 'Apply AMI event and listener log retention and row limits.';

    public function handle(PbxTelemetry $telemetry): int
    {
        $telemetry->prune();

        return self::SUCCESS;
    }
}
