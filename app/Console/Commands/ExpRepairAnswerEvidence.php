<?php

namespace App\Console\Commands;

use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CallTimingMetric;
use App\Models\CdrRecord;
use App\Models\CelRecord;
use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Services\TransferTracker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpRepairAnswerEvidence extends Command
{
    /**
     * Tie-breaker when two sources report the same answer instant: the direct
     * channel state beats a bridge, which beats imported CEL/CDR rows.
     *
     * @var array<string, int>
     */
    private const SOURCE_STRENGTH = ['Newstate' => 4, 'DialEnd' => 3, 'BridgeEnter' => 2, 'CEL' => 1, 'CDR' => 0];

    protected $signature = 'exp:repair-answer-evidence
        {--connection= : Limit the repair to one saved PBX connection name}
        {--days=7 : Look back this many days of dial starts, falling back to the leg start}
        {--dry-run : List the legs that would be repaired without writing them}';

    protected $description = 'Backfill missing dentist answer timestamps on mobile legs from retained PBX frames and CDR/CEL rows.';

    public function handle(TransferTracker $transfers): int
    {
        $connectionName = $this->option('connection');
        $connectionId = null;

        if ($connectionName !== null) {
            $connectionId = PbxConnection::query()->where('name', $connectionName)->value('id');

            if ($connectionId === null) {
                $this->error('Unknown PBX connection.');

                return self::FAILURE;
            }
        }

        $days = max(1, min(90, (int) $this->option('days')));
        $windowStart = CarbonImmutable::now()->subDays($days);
        $legs = CallLeg::query()
            ->where('role', 'mobile')
            ->whereNull('answered_at')
            ->where(fn ($query) => $query->where('dial_started_at', '>=', $windowStart)
                ->orWhere(fn ($inner) => $inner->whereNull('dial_started_at')->where('started_at', '>=', $windowStart)))
            ->when($connectionId !== null, fn ($query) => $query->where('pbx_connection_id', $connectionId))
            ->orderByRaw('coalesce(dial_started_at, started_at)')
            ->orderBy('id')
            ->get(['id', 'interaction_id', 'pbx_connection_id', 'uniqueid', 'started_at', 'dial_started_at', 'ringing_at', 'ended_at', 'answered_at', 'dial_status']);

        if ($legs->isEmpty()) {
            $this->info('No dentist mobile leg in the window is missing answer evidence.');

            return self::SUCCESS;
        }

        $evidence = $this->evidence($legs);
        $dryRun = (bool) $this->option('dry-run');
        /** @var Collection<int, CallLeg> $repaired */
        $repaired = new Collection;
        /** @var array<int, string> $sources */
        $sources = [];
        $replayed = [];
        $withoutEvidence = 0;
        $outOfInterval = 0;
        $unidentified = 0;

        foreach ($legs as $leg) {
            if ($leg->uniqueid === null || $leg->uniqueid === '') {
                $unidentified++;

                continue;
            }

            $entry = $evidence[$leg->uniqueid] ?? null;

            if ($entry === null) {
                $withoutEvidence++;

                continue;
            }

            $answeredAt = $entry['at'];
            $earliest = $leg->dial_started_at ?? $leg->started_at;

            if ($earliest === null || $answeredAt->lt($earliest)
                || ($leg->ended_at !== null && $answeredAt->gt($leg->ended_at))) {
                $outOfInterval++;
                $this->warn("Leg {$leg->id} ({$leg->uniqueid}) has answer evidence outside the dial window; left unchanged.");

                continue;
            }

            $this->line(sprintf(
                '%s leg %d (%s): answer %s from %s%s',
                $dryRun ? 'Would repair' : 'Repair',
                $leg->id,
                $leg->uniqueid,
                $answeredAt->toIso8601String(),
                $entry['source'],
                $leg->dial_status === null ? ', dial status ANSWER' : '',
            ));

            $repaired->push($leg);
            $sources[$leg->id] = $entry['source'];
            $replayed[$leg->interaction_id] = true;

            if ($dryRun) {
                continue;
            }

            $leg->update([
                'answered_at' => $answeredAt,
                'dial_status' => $leg->dial_status ?? 'ANSWER',
            ]);
            $this->refreshMetric($leg);
        }

        $failures = 0;

        if (! $dryRun && $repaired->isNotEmpty()) {
            foreach (array_keys($replayed) as $interactionId) {
                $interaction = Interaction::query()->find($interactionId);

                if ($interaction === null) {
                    continue;
                }

                try {
                    $transfers->sync($interaction);
                } catch (Throwable $exception) {
                    $failures++;
                    $this->error("Interaction {$interactionId} could not be re-synced: {$exception->getMessage()}");
                    report($exception);
                }
            }

            $this->clearResolvedExceptions($repaired);
            Log::info('exp:repair-answer-evidence applied retained answer evidence.', [
                'legs' => $repaired->map(fn (CallLeg $leg): array => [
                    'leg_id' => $leg->id,
                    'interaction_id' => $leg->interaction_id,
                    'uniqueid' => $leg->uniqueid,
                    'answered_at' => $leg->answered_at?->toIso8601String(),
                    'source' => $sources[$leg->id] ?? null,
                ])->all(),
            ]);
        }

        $this->info(sprintf(
            '%s %d of %d legs; %d without usable evidence; %d with evidence outside the dial window.',
            $dryRun ? 'Would repair' : 'Repaired',
            $repaired->count(),
            $legs->count(),
            $withoutEvidence,
            $outOfInterval,
        ));

        if ($unidentified > 0) {
            $this->warn(sprintf('%d legs have no PBX unique ID, so their answer state cannot be resolved.', $unidentified));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Earliest retained proof that each mobile leg reached the network.
     *
     * Only exact PBX leg identifiers are used: an Up state on the channel, a
     * DialEnd ANSWER for the dialled leg, a bridge entry (a channel can only be
     * bridged after it answered), or the CEL/CDR rows for that unique ID. Every
     * lookup is bounded to the legs' own lifetime so a long event history is
     * never replayed for a handful of legs, and ties keep the strongest source.
     *
     * @param  Collection<int, CallLeg>  $legs
     * @return array<string, array{at: CarbonImmutable, source: string}>
     */
    private function evidence(Collection $legs): array
    {
        /** @var array<string, array{at: CarbonImmutable, source: string}> $evidence */
        $evidence = [];

        foreach ($legs->groupBy(fn (CallLeg $leg): string => (string) $leg->pbx_connection_id) as $group) {
            $uniqueids = $group->pluck('uniqueid')->filter()->unique()->values()->all();

            if ($uniqueids === []) {
                continue;
            }

            $connection = $group->first()->pbx_connection_id;
            $from = $this->earliestStart($group)->subDay();
            $to = $this->latestEnd($group);

            foreach (array_chunk($uniqueids, 500) as $chunk) {
                $this->readEventAnswers($connection, $chunk, $from, $to, $evidence);
                $this->readCelAnswers($connection, $chunk, $evidence);
                $this->readCdrAnswers($connection, $chunk, $evidence);
            }
        }

        return $evidence;
    }

    /**
     * @param  list<string>  $uniqueids
     * @param  array<string, array{at: CarbonImmutable, source: string}>  $evidence
     */
    private function readEventAnswers(?int $connection, array $uniqueids, CarbonImmutable $from, CarbonImmutable $to, array &$evidence): void
    {
        $events = CallEvent::query()
            ->where('pbx_connection_id', $connection)
            ->whereIn('event_name', ['Newstate', 'DialEnd', 'BridgeEnter'])
            ->whereBetween('occurred_at', [$from, $to])
            ->where(fn ($query) => $query->whereIn('data->primary_id', $uniqueids)
                ->orWhereIn('data->destination_id', $uniqueids))
            ->orderBy('occurred_at')
            ->get(['event_name', 'occurred_at', 'data']);

        foreach ($events as $event) {
            $data = $event->data;
            $primary = is_string($data['primary_id'] ?? null) ? $data['primary_id'] : null;
            $destination = is_string($data['destination_id'] ?? null) ? $data['destination_id'] : null;

            $uniqueid = match ($event->event_name) {
                'Newstate' => ($data['state'] ?? null) === 'Up' ? $primary : null,
                'DialEnd' => ($data['dial_status'] ?? null) === 'ANSWER' ? $destination : null,
                'BridgeEnter' => $primary,
                default => null,
            };

            if ($uniqueid !== null && $event->occurred_at !== null) {
                $this->remember($evidence, $uniqueid, $event->occurred_at, $event->event_name);
            }
        }
    }

    /**
     * @param  list<string>  $uniqueids
     * @param  array<string, array{at: CarbonImmutable, source: string}>  $evidence
     */
    private function readCelAnswers(?int $connection, array $uniqueids, array &$evidence): void
    {
        $records = CelRecord::query()
            ->where('pbx_connection_id', $connection)
            ->whereIn('uniqueid', $uniqueids)
            ->whereRaw('upper(event_type) = ?', ['ANSWER'])
            ->get(['uniqueid', 'event_time']);

        foreach ($records as $record) {
            $answeredAt = $this->parseSourceTime($record->event_time);

            if ($record->uniqueid !== null && $answeredAt !== null) {
                $this->remember($evidence, $record->uniqueid, $answeredAt, 'CEL');
            }
        }
    }

    /**
     * @param  list<string>  $uniqueids
     * @param  array<string, array{at: CarbonImmutable, source: string}>  $evidence
     */
    private function readCdrAnswers(?int $connection, array $uniqueids, array &$evidence): void
    {
        $records = CdrRecord::query()
            ->where('pbx_connection_id', $connection)
            ->whereIn('uniqueid', $uniqueids)
            ->whereNotNull('answer')
            ->get(['uniqueid', 'answer', 'disposition']);

        foreach ($records as $record) {
            if ($record->uniqueid === null || $record->answer === null) {
                continue;
            }

            if (filled($record->disposition) && strtoupper((string) $record->disposition) !== 'ANSWERED') {
                continue;
            }

            $this->remember($evidence, $record->uniqueid, CarbonImmutable::instance($record->answer)->utc(), 'CDR');
        }
    }

    /**
     * Keep the earliest proof, preferring the direct channel state on a tie.
     *
     * @param  array<string, array{at: CarbonImmutable, source: string}>  $evidence
     */
    private function remember(array &$evidence, string $uniqueid, CarbonImmutable $answeredAt, string $source): void
    {
        $answeredAt = $answeredAt->utc();
        $existing = $evidence[$uniqueid] ?? null;

        if ($existing === null || $answeredAt->lt($existing['at'])
            || ($answeredAt->equalTo($existing['at'])
                && self::SOURCE_STRENGTH[$source] > self::SOURCE_STRENGTH[$existing['source']])) {
            $evidence[$uniqueid] = ['at' => $answeredAt, 'source' => $source];
        }
    }

    private function parseSourceTime(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return is_numeric($value)
                ? CarbonImmutable::createFromTimestamp((int) $value)->utc()
                : CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Recompute the answer-dependent timings the projector derives from a leg.
     *
     * The transfer tracker copies these from the metric row, so a repaired leg
     * would otherwise count as answered while contributing no dial-to-answer or
     * connected time to the call time analytics.
     */
    private function refreshMetric(CallLeg $leg): void
    {
        $metric = CallTimingMetric::query()->firstOrNew(['call_leg_id' => $leg->id]);

        $metric->interaction_id = $leg->interaction_id;
        $metric->dial_to_answer_seconds = $this->seconds($leg->dial_started_at, $leg->answered_at) ?? $metric->dial_to_answer_seconds;
        $metric->ringing_seconds = $this->seconds($leg->ringing_at, $leg->answered_at) ?? $metric->ringing_seconds;
        $metric->mobile_connected_seconds = $this->seconds($leg->answered_at, $leg->ended_at) ?? $metric->mobile_connected_seconds;
        $metric->save();
    }

    private function seconds(?CarbonImmutable $from, ?CarbonImmutable $to): ?float
    {
        if ($from === null || $to === null || $to->lt($from)) {
            return null;
        }

        return $from->diffInMicroseconds($to) / 1000000;
    }

    /** @param Collection<int, CallLeg> $group */
    private function earliestStart(Collection $group): CarbonImmutable
    {
        return $group
            ->map(fn (CallLeg $leg): ?CarbonImmutable => $leg->dial_started_at ?? $leg->started_at)
            ->filter()
            ->sort()
            ->first() ?? CarbonImmutable::now();
    }

    /** @param Collection<int, CallLeg> $group */
    private function latestEnd(Collection $group): CarbonImmutable
    {
        return $group->pluck('ended_at')->filter()->sortDesc()->first() ?? CarbonImmutable::now()->addDay();
    }

    /**
     * Drop the correlation exceptions the repair actually proves resolved.
     *
     * A mismatch code is only cleared when the disagreeing source now agrees;
     * a genuine CDR/CEL conflict stays visible until the reconciler runs again.
     *
     * @param  Collection<int, CallLeg>  $legs
     */
    private function clearResolvedExceptions(Collection $legs): void
    {
        foreach ($legs->groupBy(fn (CallLeg $leg): string => (string) $leg->pbx_connection_id) as $group) {
            $subjects = $group->pluck('uniqueid')->filter()->unique()->values()->all();

            if ($subjects === []) {
                continue;
            }

            $connection = $group->first()->pbx_connection_id;
            $this->deleteExceptions($subjects, ['missing_answer']);
            $this->deleteExceptions($this->answeredBy($connection, $subjects, 'cel'), ['cel_answer_state_mismatch']);
            $this->deleteExceptions($this->answeredBy($connection, $subjects, 'cdr'), ['cdr_answer_state_mismatch']);
        }
    }

    /**
     * Imported rows that now prove the leg answered, per source.
     *
     * @param  list<string>  $subjects
     * @return list<string>
     */
    private function answeredBy(?int $connection, array $subjects, string $source): array
    {
        $answered = [];

        foreach (array_chunk($subjects, 500) as $chunk) {
            $found = $source === 'cel'
                ? CelRecord::query()
                    ->where('pbx_connection_id', $connection)
                    ->whereIn('uniqueid', $chunk)
                    ->whereRaw('upper(event_type) = ?', ['ANSWER'])
                    ->pluck('uniqueid')
                : CdrRecord::query()
                    ->where('pbx_connection_id', $connection)
                    ->whereIn('uniqueid', $chunk)
                    ->whereNotNull('answer')
                    ->where(fn ($query) => $query->whereNull('disposition')
                        ->orWhere('disposition', '')
                        ->orWhere('disposition', 'ANSWERED'))
                    ->pluck('uniqueid');

            $answered = [...$answered, ...$found->all()];
        }

        return array_values(array_unique(array_filter($answered)));
    }

    /**
     * @param  list<string>  $subjects
     * @param  list<string>  $codes
     */
    private function deleteExceptions(array $subjects, array $codes): void
    {
        if ($subjects === []) {
            return;
        }

        foreach (array_chunk($subjects, 500) as $chunk) {
            CorrelationException::query()
                ->whereIn('code', $codes)
                ->whereIn('subject', $chunk)
                ->delete();
        }
    }
}
