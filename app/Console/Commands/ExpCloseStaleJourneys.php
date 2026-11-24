<?php

namespace App\Console\Commands;

use App\Services\StaleJourneyCloser;
use Illuminate\Console\Command;

class ExpCloseStaleJourneys extends Command
{
    protected $signature = 'exp:close-stale-journeys {--minutes= : Minutes without PBX events before a journey is closed} {--dry-run : Report stale journeys without closing them}';

    protected $description = 'Close active journeys whose PBX evidence stopped arriving mid-call.';

    public function handle(StaleJourneyCloser $closer): int
    {
        $minutes = max(1, (int) ($this->option('minutes') ?? config('services.exp_pbx.stale_journey_minutes', 15)));
        $dryRun = (bool) $this->option('dry-run');
        $result = $closer->close($minutes, $dryRun);

        if ($result['candidates'] === []) {
            $this->info(sprintf('No stale journeys older than %d minutes. Skipped connections with a stale AMI feed: %d.', $minutes, $result['skipped']));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d stale journey(s) older than %d minutes%s. Skipped connections with a stale AMI feed: %d.',
            $dryRun ? 'Would close' : 'Closed',
            $dryRun ? count($result['candidates']) : $result['closed'],
            $minutes,
            $dryRun ? ' ['.implode(', ', $result['candidates']).']' : '',
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
