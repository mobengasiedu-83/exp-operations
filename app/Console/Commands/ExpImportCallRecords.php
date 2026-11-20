<?php

namespace App\Console\Commands;

use App\Models\CdrRecord;
use App\Models\CelRecord;
use App\Models\IntegrationHealth;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Services\PbxTelemetry;
use Carbon\CarbonImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ExpImportCallRecords extends Command
{
    protected $signature = 'exp:import-call-records {type : cdr or cel} {file : Local JSON Lines export} {--timezone= : Required source timezone} {--connection= : Saved PBX connection name}';

    protected $description = 'Import a read-only CDR/CEL export with timezone validation and replay protection.';

    public function handle(PbxTelemetry $telemetry): int
    {
        $type = $this->argument('type');
        $timezone = $this->option('timezone');
        $file = $this->argument('file');
        $component = 'import_'.(in_array($type, ['cdr', 'cel'], true) ? $type : 'invalid');
        $handle = null;
        try {
            if (! in_array($type, ['cdr', 'cel'], true) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
                throw new RuntimeException('Use cdr or cel and an explicit IANA source timezone.');
            }
            if (! is_file($file) || ! is_readable($file)) {
                throw new RuntimeException('The local export file is not readable.');
            }
            $connectionName = $this->option('connection') ?? config('services.exp_pbx.connection_name');
            $connection = PbxConnection::where('name', $connectionName)->first();
            if ($this->option('connection') !== null && $connection === null) {
                throw new RuntimeException('Unknown PBX connection.');
            }
            $handle = fopen($file, 'r');
            if ($handle === false) {
                throw new RuntimeException('Unable to open the export file.');
            }
            $count = DB::transaction(function () use ($handle, $type, $timezone, $telemetry, $connection): int {
                $count = 0;
                while (($line = fgets($handle, 262145)) !== false) {
                    if (strlen($line) > 262144) {
                        throw new RuntimeException('Export row exceeds the size limit.');
                    }
                    if (trim($line) === '') {
                        continue;
                    }
                    $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                    if (! is_array($row) || empty($row['uniqueid']) || ! is_string($row['uniqueid'])) {
                        throw new RuntimeException('Every row needs a uniqueid.');
                    }
                    $model = $type === 'cdr' ? new CdrRecord : new CelRecord;
                    if ($type === 'cel') {
                        $row['event_type'] = $row['event_type'] ?? $row['eventtype'] ?? null;
                        $row['event_time'] = $row['event_time'] ?? $row['eventtime'] ?? null;
                        $row['channel'] = $row['channel'] ?? $row['channame'] ?? null;
                    } else {
                        $row['start'] = $row['start'] ?? $row['calldate'] ?? null;
                        $row['clrd'] = $row['clrd'] ?? $row['clid'] ?? null;
                    }
                    $requiredTime = $type === 'cdr' ? 'start' : 'event_time';
                    if (empty($row[$requiredTime]) || ($type === 'cel' && empty($row['event_type']))) {
                        throw new RuntimeException('Missing event type or source timestamp.');
                    }
                    foreach ($type === 'cdr' ? ['start', 'answer', 'end'] : ['event_time'] as $field) {
                        if (! empty($row[$field])) {
                            $date = CarbonImmutable::createFromFormat('!Y-m-d H:i:s', $row[$field], $timezone);
                            if ($date === null || $date->format('Y-m-d H:i:s') !== $row[$field] || $date->utc()->gt(now()->addMinutes(5))) {
                                throw new RuntimeException('Invalid source timestamp or PBX clock is ahead.');
                            }
                            $row[$field] = $date->utc()->format('Y-m-d H:i:s');
                        }
                    }
                    if ($type === 'cdr') {
                        foreach (['duration', 'billsec'] as $field) {
                            if (isset($row[$field]) && (filter_var($row[$field], FILTER_VALIDATE_INT) === false || $row[$field] < 0)) {
                                throw new RuntimeException('Invalid call duration.');
                            }
                        }
                        if (isset($row['billsec'], $row['duration']) && $row['billsec'] > $row['duration']) {
                            throw new RuntimeException('Billable duration exceeds total duration.');
                        }
                        if (isset($row['answer']) && ($row['answer'] < $row['start'] || (isset($row['end']) && $row['answer'] > $row['end']))) {
                            throw new RuntimeException('Answer timestamp is outside the call interval.');
                        }
                        if (isset($row['start'], $row['end']) && $row['end'] < $row['start']) {
                            throw new RuntimeException('Call ends before it starts.');
                        }
                    }
                    $values = array_intersect_key($row, array_flip($model->getFillable()));
                    $values['raw'] = $telemetry->redact($row);
                    $fingerprint = hash('sha256', json_encode([$connection?->id, $type, $row['uniqueid'], $row[$requiredTime], $row['event_type'] ?? null, $row['channel'] ?? null, $row['dstchannel'] ?? null], JSON_THROW_ON_ERROR));
                    if (! $model->newQuery()->where('source_fingerprint', $fingerprint)->exists()) {
                        $record = $model->newInstance($values);
                        $record->setAttribute('source_fingerprint', $fingerprint);
                        $record->setAttribute('pbx_connection_id', $connection?->id);
                        $record->save();
                        $count++;
                    }
                }

                return $count;
            });
            if ($connection !== null) {
                Interaction::where('pbx_connection_id', $connection->id)->where('status', 'completed')->update(['reconciliation_status' => 'pending']);
            }
            IntegrationHealth::record($component, 'healthy', null, ['imported' => $count, 'source_timezone' => $timezone, 'stored_timezone' => 'UTC']);
            $this->info("Imported {$count} {$type} records (UTC).");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $error = $exception::class === RuntimeException::class ? $exception->getMessage() : 'Import failed. Check the export schema and source timestamps.';
            IntegrationHealth::record($component, 'unhealthy', $error);
            $this->error($error);

            return self::FAILURE;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }
}
