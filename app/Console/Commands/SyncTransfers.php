<?php

namespace App\Console\Commands;

use App\Models\Interaction;
use App\Services\TransferTracker;
use Illuminate\Console\Command;

class SyncTransfers extends Command
{
    protected $signature = 'exp:sync-transfers {--interaction= : Sync one interaction ID}';

    protected $description = 'Rebuild transfer tracking from existing projected telemetry, preserving operator evidence';

    public function handle(TransferTracker $tracker): int
    {
        $id = $this->option('interaction');
        if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
            $this->error('Use a positive interaction ID.');

            return self::FAILURE;
        }
        $count = 0;
        Interaction::query()->when($id !== null, fn ($query) => $query->whereKey($id))->chunkById(100, function ($rows) use ($tracker, &$count): void {
            foreach ($rows as $interaction) {
                $tracker->sync($interaction);
                $count++;
            }
        });
        $this->info('Synced '.$count.' interactions.');

        return self::SUCCESS;
    }
}
