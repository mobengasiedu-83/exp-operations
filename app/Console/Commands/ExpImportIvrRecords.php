<?php

namespace App\Console\Commands;

use App\Models\CallEvent;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Services\AmiEventNormalizer;
use App\Services\CallTimelineProjector;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ExpImportIvrRecords extends Command
{
    protected $signature = 'exp:import-ivr-records {file : Local JSON Lines export} {--connection= : Required saved PBX connection name} {--timezone= : Required IANA source timezone}';

    protected $description = 'Backfill IVR selections using exact PBX leg IDs without inferring call outcomes.';

    public function handle(AmiEventNormalizer $normalizer, CallTimelineProjector $projector): int
    {
        $handle = null;

        try {
            $timezone = $this->option('timezone');
            if (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new RuntimeException('Use an explicit IANA source timezone.');
            }
            $connection = PbxConnection::where('name', $this->option('connection'))->first();
            if (! filled($this->option('connection')) || $connection === null) {
                throw new RuntimeException('Specify a known PBX connection.');
            }
            $file = $this->argument('file');
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException('The local export file is not readable.');
            }
            $handle = fopen($file, 'r');
            if ($handle === false) {
                throw new RuntimeException('Unable to open the export file.');
            }

            $count = DB::transaction(function () use ($handle, $connection, $timezone, $normalizer, $projector): int {
                PbxConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
                $count = 0;
                $changed = [];
                while (($line = fgets($handle, 262145)) !== false) {
                    if (strlen($line) > 262144) {
                        throw new RuntimeException('Export row exceeds the size limit.');
                    }
                    if (trim($line) === '') {
                        continue;
                    }
                    $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($row)
                        || ! is_string($row['uniqueid'] ?? null)
                        || preg_match('/^[a-zA-Z0-9_.:@\/-]{1,255}$/D', $row['uniqueid']) !== 1
                        || ! is_string($row['option'] ?? null)
                        || preg_match('/^[0-9*#]{1,32}$/D', $row['option']) !== 1
                        || ! is_string($row['menu'] ?? null)
                        || preg_match('/^[a-zA-Z0-9_. -]{1,100}$/D', $row['menu']) !== 1
                        || ! is_string($row['occurred_at'] ?? null)) {
                        throw new RuntimeException('Every row needs uniqueid, menu, option and occurred_at.');
                    }
                    $at = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $row['occurred_at'], $timezone);
                    if ($at === null || $at->format('Y-m-d H:i:s') !== $row['occurred_at'] || $at->utc()->gt(now()->addMinutes(5))) {
                        throw new RuntimeException('Invalid source timestamp or PBX clock is ahead.');
                    }
                    $interaction = Interaction::where('pbx_connection_id', $connection->id)
                        ->whereHas('legs', fn ($query) => $query->where('uniqueid', $row['uniqueid'])->where('role', 'caller'))
                        ->first();
                    if ($interaction === null) {
                        throw new RuntimeException('No exact caller leg match; import retained call evidence first.');
                    }
                    if (($interaction->started_at !== null && $at->lt($interaction->started_at))
                        || ($interaction->ended_at !== null && $at->gt($interaction->ended_at))) {
                        throw new RuntimeException('Selection timestamp is outside the observed call interval.');
                    }
                    $normalized = $normalizer->normalize(['Event' => 'Newexten'], now());
                    $data = $normalized['data'];
                    $data['primary_id'] = $row['uniqueid'];
                    $data['source'] = 'pbx_ivr_export';
                    $data['ivr'] = ['menu' => $row['menu'], 'option' => $row['option'], 'application' => 'PBX IVR export', 'app_data' => null];
                    $event = CallEvent::firstOrCreate([
                        'fingerprint' => hash('sha256', json_encode([$connection->id, 'ivr_export', $row['uniqueid'], $at->utc()->format('Y-m-d H:i:s'), $row['menu'], $row['option']], JSON_THROW_ON_ERROR)),
                    ], [
                        'pbx_connection_id' => $connection->id, 'interaction_id' => $interaction->id,
                        'event_name' => 'IvrSelection', 'occurred_at' => $at->utc(), 'timing_quality' => 'pbx', 'data' => $data,
                    ]);
                    if ($event->wasRecentlyCreated) {
                        $count++;
                        $changed[$interaction->id] = $interaction;
                    }
                }
                foreach ($changed as $interaction) {
                    $projector->rebuild($interaction);
                }

                return $count;
            });
            $this->info("Imported {$count} IVR selections (UTC).");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof RuntimeException ? $exception->getMessage() : 'Import failed. Check the JSON schema and source timestamps.');

            return self::FAILURE;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
