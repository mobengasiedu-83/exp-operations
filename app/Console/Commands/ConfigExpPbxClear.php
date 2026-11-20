<?php

namespace App\Console\Commands;

use App\Models\PbxConnection;
use Illuminate\Console\Command;

class ConfigExpPbxClear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config:exp-pbx:clear';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove all EXP PBX connection configuration entries.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $count = PbxConnection::query()->count();

        if ($count === 0) {
            $this->info('No PBX connection configurations to clear.');

            return self::SUCCESS;
        }

        if (PbxConnection::where(fn ($query) => $query->whereHas('interactions')->orWhereHas('telemetryGaps'))->exists()) {
            $this->error('Connections with call or telemetry history cannot be cleared. Update their configuration instead.');

            return self::FAILURE;
        }
        PbxConnection::query()->delete();

        $this->info(\sprintf('Cleared %d connection configuration(s).', $count));

        return self::SUCCESS;
    }
}
