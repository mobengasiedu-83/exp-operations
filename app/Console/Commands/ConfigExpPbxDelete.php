<?php

namespace App\Console\Commands;

use App\Models\PbxConnection;
use Illuminate\Console\Command;

class ConfigExpPbxDelete extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'config:exp-pbx:delete {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove an EXP PBX connection configuration entry.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        if (PbxConnection::where('name', $name)->where(fn ($query) => $query->whereHas('interactions')->orWhereHas('telemetryGaps'))->exists()) {
            $this->error('This connection has call or telemetry history. Update its configuration instead of deleting it.');

            return self::FAILURE;
        }

        $count = PbxConnection::query()
            ->where('name', $name)
            ->delete();

        if ($count === 0) {
            $this->error(\sprintf('PBX connection "%s" not found.', $name));

            return self::FAILURE;
        }

        $this->info(\sprintf('PBX connection "%s" deleted successfully.', $name));

        return self::SUCCESS;
    }
}
