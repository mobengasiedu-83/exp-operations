<?php

namespace App\Console\Commands;

use App\Models\PbxConnection;
use App\Services\AlertEvaluator;
use Illuminate\Console\Command;

class ExpEvaluateAlerts extends Command
{
    protected $signature = 'exp:evaluate-alerts {--connection= : Saved connection name; omit to evaluate every connection}';

    protected $description = 'Evaluate live-operations alert rules and reconcile open alert events.';

    public function handle(AlertEvaluator $evaluator): int
    {
        $connection = null;
        $name = $this->option('connection');

        if (is_string($name) && $name !== '') {
            $connection = PbxConnection::query()->where('name', $name)->first();
            if ($connection === null) {
                $this->error('Unknown PBX connection: '.$name);

                return self::FAILURE;
            }
        }

        $counts = $evaluator->evaluate($connection);

        $this->info(sprintf(
            'Alerts evaluated: %d created, %d updated, %d resolved.',
            $counts['created'],
            $counts['updated'],
            $counts['resolved'],
        ));

        return self::SUCCESS;
    }
}
