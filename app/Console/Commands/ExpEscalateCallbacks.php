<?php

namespace App\Console\Commands;

use App\Services\CallbackQueue;
use Illuminate\Console\Command;

class ExpEscalateCallbacks extends Command
{
    protected $signature = 'exp:escalate-callbacks';

    protected $description = 'Escalate callbacks that missed their follow-up SLA without a completed outcome.';

    public function handle(CallbackQueue $queue): int
    {
        $escalated = $queue->escalateMissedSlas();
        $this->info(sprintf('Callbacks escalated: %d.', $escalated));

        return self::SUCCESS;
    }
}
