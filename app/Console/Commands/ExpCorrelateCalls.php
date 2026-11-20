<?php

namespace App\Console\Commands;

use App\Models\AmiEvent;
use App\Models\CorrelationException;
use App\Models\IntegrationHealth;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Services\CallCorrelator;
use App\Services\CallTimelineProjector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ExpCorrelateCalls extends Command
{
    protected $signature = 'exp:correlate-calls {--connection=} {--after=0} {--since= : Replay AMI events received at or after this local timestamp} {--limit=500} {--rebuild : Reproject existing durable events instead of raw AMI rows}';

    protected $description = 'Replay a bounded batch of AMI events or rebuild durable call timelines.';

    public function handle(CallCorrelator $correlator, CallTimelineProjector $projector): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $after = max(0, (int) $this->option('after'));
        $connection = $this->option('connection');
        $connectionId = $connection === null ? null : PbxConnection::where('name', $connection)->value('id');
        if ($connection !== null && $connectionId === null) {
            $this->error('Unknown PBX connection.');

            return self::FAILURE;
        }
        $since = null;
        if ($this->option('since') !== null) {
            try {
                $since = CarbonImmutable::parse((string) $this->option('since'))->utc();
            } catch (Throwable) {
                $this->error('Use an absolute local timestamp for --since.');

                return self::FAILURE;
            }
        }
        if ($this->option('rebuild')) {
            $rows = Interaction::where('id', '>', $after)->when($connectionId, fn ($query) => $query->where('pbx_connection_id', $connectionId))->orderBy('id')->limit($limit)->get();
            foreach ($rows as $interaction) {
                DB::transaction(function () use ($interaction, $projector): void {
                    PbxConnection::whereKey($interaction->pbx_connection_id)->lockForUpdate()->firstOrFail();
                    $projector->rebuild($interaction);
                });
                $after = $interaction->id;
            }
        } else {
            $rows = AmiEvent::where('id', '>', $after)->when($connectionId, fn ($query) => $query->where('pbx_connection_id', $connectionId))->when($since, fn ($query) => $query->where('received_at', '>=', $since))->orderBy('id')->limit($limit)->get();
            foreach ($rows as $event) {
                $correlator->process($event);
                $after = $event->id;
            }
        }
        if (! $this->option('rebuild') && $rows->isNotEmpty() && ! CorrelationException::where('code', 'event_processing_failed')->exists()) {
            IntegrationHealth::record('call_correlation', 'healthy');
        }
        $this->info('Processed '.$rows->count().' rows. Continue with --after='.$after.'.');

        return self::SUCCESS;
    }
}
