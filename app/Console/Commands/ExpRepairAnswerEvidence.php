<?php

namespace App\Console\Commands;

use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CdrRecord;
use App\Models\CelRecord;
use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Services\TransferTracker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class ExpRepairAnswerEvidence extends Command
{
    protected $signature = 'exp:repair-answer-evidence
        {--connection= : Limit the repair to one saved PBX connection name}
        {--days=7 : Look back this many days of dial starts}
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
        $legs = CallLeg::query()
            ->where('role', 'mobile')
            ->whereNull('answered_at')
            ->whereNotNull('dial_started_at')
            ->where('dial_started_at', '>=', CarbonImmutable::now()->subDays($days))
            ->when($connectionId !== null, fn ($query) => $query->where('pbx_connection_id', $connectionId))
            ->orderBy('dial_started_at')
            ->orderBy('id')
            ->get(['id', 'interaction_id', 'pbx_connection_id', 'uniqueid', 'dial_started_at', 'ended_at', 'answered_at', 'dial_status']);

        if ($legs->isEmpty()) {
            $this->info('Every dentist mobile leg in the window already carries answer evidence.');

            return self::SUCCESS;
        }

        $evidence = $this->evidence($legs);
        $dryRun = (bool) $this->option('dry-run');
        $repaired = [];
        $replayed = [];
        $withoutEvidence = 0;
        $outOfInterval = 0;

        foreach ($legs as $leg) {
            $answeredAt = $evidence[$leg->uniqueid] ?? null;

            if ($answeredAt === null) {
                $withoutEvidence++;

                continue;
            }

            if ($leg->dial_started_at === null || $answeredAt->lt($leg->dial_started_at)
                || ($leg->ended_at !== null && $answeredAt->gt($leg->ended_at))) {
                $outOfInterval++;
                $this->warn("Leg {$leg->id} ({$leg->uniqueid}) has answer evidence outside the dial window; left unchanged.");

                continue;
            }

            $this->line(sprintf(
                '%s leg %d (%s): answer %s%s',
                $dryRun ? 'Would repair' : 'Repair',
                $leg->id,
                $leg->uniqueid,
                $answeredAt->toIso8601String(),
                $leg->dial_status === null ? ', dial status ANSWER' : '',
            ));

            $repaired[] = $leg;
            $replayed[$leg->interaction_id] = true;

            if ($dryRun) {
                continue;
            }

            $leg->update([
                'answered_at' => $answeredAt,
                'dial_status' => $leg->dial_status ?? 'ANSWER',
            ]);
        }

        if (! $dryRun && $replayed !== []) {
            foreach (array_keys($replayed) as $interactionId) {
                $interaction = Interaction::query()->find($interactionId);

                if ($interaction !== null) {
                    $transfers->sync($interaction);
                }
            }

            $this->clearResolvedExceptions($repaired);
        }

        $this->info(sprintf(
            '%s %d of %d legs; %d without usable evidence; %d with evidence outside the dial window.',
            $dryRun ? 'Would repair' : 'Repaired',
            count($repaired),
            $legs->count(),
            $withoutEvidence,
            $outOfInterval,
        ));

        return self::SUCCESS;
    }

    /**
     * Earliest retained proof that each mobile leg reached the network.
     *
     * Only exact PBX leg identifiers are used: an Up state on the channel, a
     * DialEnd ANSWER for the dialled leg, a bridge entry (a channel can only be
     * bridged after it answered), or the CEL/CDR rows for that unique ID.
     *
     * @param  Collection<int, CallLeg>  $legs
     * @return array<string, CarbonImmutable>
     */
    private function evidence(Collection $legs): array
    {
        /** @var array<string, CarbonImmutable> $evidence */
        $evidence = [];

        foreach ($legs->groupBy(fn (CallLeg $leg): string => (string) $leg->pbx_connection_id) as $group) {
            $uniqueids = $group->pluck('uniqueid')->filter()->unique()->values()->all();
            $connection = $group->first()->pbx_connection_id;

            $events = CallEvent::query()
                ->where('pbx_connection_id', $connection)
                ->whereIn('event_name', ['Newstate', 'DialEnd', 'BridgeEnter'])
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
                    $this->remember($evidence, $uniqueid, $event->occurred_at);
                }
            }

            $this->readCelAnswers($connection, $uniqueids, $evidence);
            $this->readCdrAnswers($connection, $uniqueids, $evidence);
        }

        return $evidence;
    }

    /**
     * @param  list<string>  $uniqueids
     * @param  array<string, CarbonImmutable>  $evidence
     */
    private function readCelAnswers(?int $connection, array $uniqueids, array &$evidence): void
    {
        foreach (array_chunk($uniqueids, 500) as $chunk) {
            $records = CelRecord::query()
                ->where('pbx_connection_id', $connection)
                ->whereIn('uniqueid', $chunk)
                ->where('event_type', 'ANSWER')
                ->get(['uniqueid', 'event_time']);

            foreach ($records as $record) {
                $answeredAt = $this->parseSourceTime($record->event_time);

                if ($record->uniqueid !== null && $answeredAt !== null) {
                    $this->remember($evidence, $record->uniqueid, $answeredAt);
                }
            }
        }
    }

    /**
     * @param  list<string>  $uniqueids
     * @param  array<string, CarbonImmutable>  $evidence
     */
    private function readCdrAnswers(?int $connection, array $uniqueids, array &$evidence): void
    {
        foreach (array_chunk($uniqueids, 500) as $chunk) {
            $records = CdrRecord::query()
                ->where('pbx_connection_id', $connection)
                ->whereIn('uniqueid', $chunk)
                ->whereNotNull('answer')
                ->get(['uniqueid', 'answer', 'disposition']);

            foreach ($records as $record) {
                if ($record->uniqueid === null || $record->answer === null) {
                    continue;
                }

                if (filled($record->disposition) && strtoupper((string) $record->disposition) !== 'ANSWERED') {
                    continue;
                }

                $this->remember($evidence, $record->uniqueid, CarbonImmutable::instance($record->answer)->utc());
            }
        }
    }

    /** @param array<string, CarbonImmutable> $evidence */
    private function remember(array &$evidence, string $uniqueid, CarbonImmutable $answeredAt): void
    {
        $existing = $evidence[$uniqueid] ?? null;

        if ($existing === null || $answeredAt->lt($existing)) {
            $evidence[$uniqueid] = $answeredAt->utc();
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

    /** @param list<CallLeg> $legs */
    private function clearResolvedExceptions(array $legs): void
    {
        $subjects = array_values(array_unique(array_map(fn (CallLeg $leg): string => (string) $leg->uniqueid, $legs)));

        foreach (array_chunk($subjects, 500) as $chunk) {
            CorrelationException::query()
                ->whereIn('code', ['missing_answer', 'cdr_answer_state_mismatch', 'cel_answer_state_mismatch'])
                ->whereIn('subject', $chunk)
                ->delete();
        }
    }
}
