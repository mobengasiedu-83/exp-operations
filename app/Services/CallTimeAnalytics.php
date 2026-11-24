<?php

namespace App\Services;

use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\TransferAttempt;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Aggregates observed call time for the Call time menu.
 *
 * Every figure is measured from retained AMI evidence and follows the same
 * definitions as the call detail page ({@see CallHistoryRecord::durations()}):
 *
 * - total time: journey start to the last hangup;
 * - wait time: the caller's channel start to the moment it was answered
 *   (queue and IVR time included);
 * - agent time: the answer to the first dentist dial, or to the end of the
 *   journey when no transfer was attempted;
 * - dentist time: the connected time of each answered mobile leg;
 * - consultation and private-handover time: the bridge intervals the projector
 *   measured for the answered dentist leg.
 *
 * A metric the PBX never supplied stays null so the pages can print a dash
 * instead of inventing a value. Journeys whose agent or dentist cannot be
 * identified are kept in explicit "unattributed" buckets rather than dropped.
 */
class CallTimeAnalytics
{
    public const DEFAULT_DAYS = 7;

    public const MAX_DAYS = 365;

    public const UNATTRIBUTED_AGENT = 'unattributed-agent';

    public const UNATTRIBUTED_DENTIST = 'unattributed-dentist';

    /** Hard ceiling so an unfiltered window can never exhaust memory. */
    private const MAX_ROWS = 20000;

    public function __construct(private readonly ?CarbonImmutable $now = null) {}

    /**
     * The rolling window the pages report on.
     *
     * @return array{days: int, from: CarbonImmutable, to: CarbonImmutable, from_iso: string, to_iso: string}
     */
    public function window(int $days): array
    {
        $windowDays = max(1, min($days, self::MAX_DAYS));
        $to = $this->now();
        $from = $to->subDays($windowDays);

        return [
            'days' => $windowDays,
            'from' => $from,
            'to' => $to,
            'from_iso' => $from->toIso8601String(),
            'to_iso' => $to->toIso8601String(),
        ];
    }

    /**
     * Totals, averages and a per-day trend for the overview screen.
     *
     * @return array<string, mixed>
     */
    public function overview(int $days): array
    {
        $window = $this->window($days);
        $timings = $this->timings($window['from'], $window['to']);

        return [
            'window' => $this->serialiseWindow($window),
            'calls' => [
                'total' => $timings->count(),
                'active' => $timings->where('status', 'active')->count(),
                'completed' => $timings->where('status', 'completed')->count(),
                'answered' => $timings->where('answered', true)->count(),
                'transferred' => $timings->whereNotNull('dentist_seconds')->count(),
                'abandoned' => $timings->where('answered', false)->count(),
            ],
            'totals' => [
                'total_seconds' => $this->sum($timings, 'total_seconds'),
                'wait_seconds' => $this->sum($timings, 'wait_seconds'),
                'agent_seconds' => $this->sum($timings, 'agent_seconds'),
                'dentist_seconds' => $this->sum($timings, 'dentist_seconds'),
                'consultation_seconds' => $this->sum($timings, 'consultation_seconds'),
                'handover_seconds' => $this->sum($timings, 'handover_seconds'),
                'hold_seconds' => $this->sum($timings, 'hold_seconds'),
                'ringing_seconds' => $this->sum($timings, 'ringing_seconds'),
                'dial_to_answer_seconds' => $this->sum($timings, 'dial_to_answer_seconds'),
            ],
            'averages' => [
                'total_seconds' => $this->average($timings, 'total_seconds'),
                'wait_seconds' => $this->average($timings, 'wait_seconds'),
                'agent_seconds' => $this->average($timings, 'agent_seconds'),
                'dentist_seconds' => $this->average($timings, 'dentist_seconds'),
                'consultation_seconds' => $this->average($timings, 'consultation_seconds'),
                'ringing_seconds' => $this->average($timings, 'ringing_seconds'),
            ],
            'samples' => [
                'wait' => $timings->whereNotNull('wait_seconds')->count(),
                'agent' => $timings->whereNotNull('agent_seconds')->count(),
                'dentist' => $timings->whereNotNull('dentist_seconds')->count(),
            ],
            'daily' => $this->daily($timings),
        ];
    }

    /**
     * Talk time grouped by the PBX extension that handled the call.
     *
     * @return array<string, mixed>
     */
    public function agents(int $days): array
    {
        $window = $this->window($days);
        $timings = $this->timings($window['from'], $window['to']);

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($timings as $timing) {
            $key = $timing['extension'] ?? self::UNATTRIBUTED_AGENT;
            $row = $rows[$key] ?? [
                'key' => $key,
                'extension' => $timing['extension'],
                'calls' => 0,
                'answered' => 0,
                'talk_seconds' => 0.0,
                'talk_samples' => 0,
                'wait_seconds' => 0.0,
                'wait_samples' => 0,
                'dentist_seconds' => 0.0,
                'dentist_samples' => 0,
                'longest_talk_seconds' => null,
                'last_call_at' => null,
            ];

            $row['calls']++;

            if ($timing['answered']) {
                $row['answered']++;
            }

            if ($timing['agent_seconds'] !== null) {
                $row['talk_seconds'] += $timing['agent_seconds'];
                $row['talk_samples']++;
                $row['longest_talk_seconds'] = max((float) ($row['longest_talk_seconds'] ?? 0.0), $timing['agent_seconds']);
            }

            if ($timing['wait_seconds'] !== null) {
                $row['wait_seconds'] += $timing['wait_seconds'];
                $row['wait_samples']++;
            }

            if ($timing['dentist_seconds'] !== null) {
                $row['dentist_seconds'] += $timing['dentist_seconds'];
                $row['dentist_samples']++;
            }

            if ($row['last_call_at'] === null || $timing['started_at']->gt($row['last_call_at'])) {
                $row['last_call_at'] = $timing['started_at'];
            }

            $rows[$key] = $row;
        }

        $rows = collect($rows)
            ->map(fn (array $row): array => [
                ...$row,
                'avg_talk_seconds' => $row['talk_samples'] === 0 ? null : round($row['talk_seconds'] / $row['talk_samples'], 3),
                'avg_wait_seconds' => $row['wait_samples'] === 0 ? null : round($row['wait_seconds'] / $row['wait_samples'], 3),
                'longest_talk_seconds' => $row['longest_talk_seconds'] === null ? null : round((float) $row['longest_talk_seconds'], 3),
                'last_call_at' => $row['last_call_at']?->toIso8601String(),
            ])
            ->sortByDesc('talk_seconds')
            ->values()
            ->all();

        return [
            'window' => $this->serialiseWindow($window),
            'rows' => $rows,
            'summary' => [
                'agents' => collect($rows)->whereNotNull('extension')->count(),
                'calls' => $timings->count(),
                'answered' => $timings->where('answered', true)->count(),
                'talk_seconds' => $this->sum($timings, 'agent_seconds'),
                'unattributed_calls' => collect($rows)->firstWhere('key', self::UNATTRIBUTED_AGENT)['calls'] ?? 0,
            ],
        ];
    }

    /**
     * Connected time grouped by the dentist each mobile leg was dialled for.
     *
     * Attempts are matched to the directory by the transfer tracker, which
     * fingerprints the dialled number. An attempt that never matched a
     * directory number is kept so the page shows how much time is unattributed.
     *
     * @return array<string, mixed>
     */
    public function dentists(int $days): array
    {
        $window = $this->window($days);

        $attempts = TransferAttempt::query()
            ->whereNotNull('dial_started_at')
            ->where('dial_started_at', '>=', $window['from'])
            ->where('dial_started_at', '<', $window['to'])
            ->with('dentist:id,name')
            ->orderBy('dial_started_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get([
                'id', 'dentist_id', 'dial_started_at', 'answered_at', 'ended_at',
                'dial_to_answer_seconds', 'ringing_seconds', 'mobile_connected_seconds',
                'private_handover_seconds', 'consultation_seconds', 'status',
            ]);

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];

        foreach ($attempts as $attempt) {
            $key = $attempt->dentist_id === null ? self::UNATTRIBUTED_DENTIST : (string) $attempt->dentist_id;
            $row = $rows[$key] ?? [
                'key' => $key,
                'dentist_id' => $attempt->dentist_id,
                'name' => $attempt->dentist?->name,
                'matcher' => $attempt->dentist_id === null ? 'unmatched' : 'directory',
                'attempts' => 0,
                'answered' => 0,
                'connected_seconds' => 0.0,
                'connected_samples' => 0,
                'consultation_seconds' => 0.0,
                'handover_seconds' => 0.0,
                'dial_to_answer_seconds' => 0.0,
                'dial_to_answer_samples' => 0,
                'ringing_seconds' => 0.0,
                'ringing_samples' => 0,
                'last_attempt_at' => null,
            ];

            $row['attempts']++;

            if ($attempt->answered_at !== null) {
                $row['answered']++;
            }

            if ($attempt->mobile_connected_seconds !== null) {
                $row['connected_seconds'] += $attempt->mobile_connected_seconds;
                $row['connected_samples']++;
            }

            $row['consultation_seconds'] += (float) ($attempt->consultation_seconds ?? 0);
            $row['handover_seconds'] += (float) ($attempt->private_handover_seconds ?? 0);

            if ($attempt->dial_to_answer_seconds !== null) {
                $row['dial_to_answer_seconds'] += $attempt->dial_to_answer_seconds;
                $row['dial_to_answer_samples']++;
            }

            if ($attempt->ringing_seconds !== null) {
                $row['ringing_seconds'] += $attempt->ringing_seconds;
                $row['ringing_samples']++;
            }

            if ($row['last_attempt_at'] === null || $attempt->dial_started_at->gt($row['last_attempt_at'])) {
                $row['last_attempt_at'] = $attempt->dial_started_at;
            }

            $rows[$key] = $row;
        }

        $rows = collect($rows)
            ->map(fn (array $row): array => [
                ...$row,
                'answer_rate' => $row['attempts'] === 0 ? null : round($row['answered'] / $row['attempts'], 4),
                'avg_connected_seconds' => $row['connected_samples'] === 0 ? null : round($row['connected_seconds'] / $row['connected_samples'], 3),
                'avg_dial_to_answer_seconds' => $row['dial_to_answer_samples'] === 0 ? null : round($row['dial_to_answer_seconds'] / $row['dial_to_answer_samples'], 3),
                'avg_ringing_seconds' => $row['ringing_samples'] === 0 ? null : round($row['ringing_seconds'] / $row['ringing_samples'], 3),
                'last_attempt_at' => $row['last_attempt_at']?->toIso8601String(),
            ])
            ->sortByDesc('connected_seconds')
            ->values()
            ->all();

        $matched = $attempts->whereNotNull('dentist_id')->count();
        $answered = $attempts->whereNotNull('answered_at')->count();

        return [
            'window' => $this->serialiseWindow($window),
            'rows' => $rows,
            'summary' => [
                'attempts' => $attempts->count(),
                'answered' => $answered,
                'matched_attempts' => $matched,
                'unmatched_attempts' => $attempts->count() - $matched,
                'match_rate' => $attempts->isEmpty() ? null : round($matched / $attempts->count(), 4),
                'answer_rate' => $attempts->isEmpty() ? null : round($answered / $attempts->count(), 4),
                'connected_seconds' => (float) $attempts->sum(fn (TransferAttempt $attempt): float => (float) ($attempt->mobile_connected_seconds ?? 0)),
                'consultation_seconds' => (float) $attempts->sum(fn (TransferAttempt $attempt): float => (float) ($attempt->consultation_seconds ?? 0)),
            ],
        ];
    }

    /**
     * One normalised timing row per journey in the window.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function timings(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Interaction::query()
            ->whereNotNull('started_at')
            ->where('started_at', '>=', $from)
            ->where('started_at', '<', $to)
            ->with([
                'legs:id,interaction_id,role,channel,started_at,answered_at,ended_at',
                'attempts:id,interaction_id,call_leg_id,dentist_id,sequence,status,human_outcome,human_accepted_at,transfer_completed_at,dial_started_at,answered_at,ended_at,dial_to_answer_seconds,ringing_seconds,mobile_connected_seconds,private_handover_seconds,consultation_seconds',
            ])
            ->orderBy('started_at')
            ->orderBy('id')
            ->limit(self::MAX_ROWS)
            ->get(['id', 'started_at', 'ended_at', 'status'])
            ->map(fn (Interaction $interaction): array => $this->timing($interaction));
    }

    /**
     * Measure one journey using the documented Call time definitions.
     *
     * @return array<string, mixed>
     */
    private function timing(Interaction $interaction): array
    {
        $longest = $interaction->ended_at ?? $this->now();
        $caller = $interaction->legs->first(fn (CallLeg $leg): bool => $leg->role === 'caller');
        $agentLeg = $interaction->legs->first(fn (CallLeg $leg): bool => $leg->role === 'agent');
        $attempts = $interaction->attempts;
        $firstDial = $attempts->first(fn (TransferAttempt $attempt): bool => $attempt->dial_started_at !== null);
        $connected = $attempts->last(fn (TransferAttempt $attempt): bool => $attempt->human_accepted_at !== null && $attempt->human_outcome === 'accepted');
        $bridgeStart = $connected?->transfer_completed_at ?? $connected?->human_accepted_at;
        $answeredAt = $caller?->answered_at ?? $agentLeg?->answered_at;
        $agentEnd = $firstDial?->dial_started_at ?? $longest;

        return [
            'id' => $interaction->id,
            'status' => $interaction->status,
            'started_at' => $interaction->started_at,
            'extension' => $this->extension($agentLeg?->channel),
            'answered' => $answeredAt !== null,
            'total_seconds' => $this->seconds($interaction->started_at, $longest),
            'wait_seconds' => $this->seconds($caller?->started_at, $answeredAt),
            'agent_seconds' => $answeredAt === null ? null : $this->seconds($answeredAt, $agentEnd),
            'hold_seconds' => $this->seconds($firstDial?->dial_started_at, $bridgeStart),
            'dentist_seconds' => $this->metricSum($attempts, 'mobile_connected_seconds'),
            'consultation_seconds' => $this->metricSum($attempts, 'consultation_seconds'),
            'handover_seconds' => $this->metricSum($attempts, 'private_handover_seconds'),
            'ringing_seconds' => $this->metricSum($attempts, 'ringing_seconds'),
            'dial_to_answer_seconds' => $this->metricSum($attempts, 'dial_to_answer_seconds'),
        ];
    }

    /**
     * Bucket the window by calendar day so the overview can show a trend.
     *
     * @param  Collection<int, array<string, mixed>>  $timings
     * @return list<array<string, mixed>>
     */
    private function daily(Collection $timings): array
    {
        /** @var array<string, array<string, mixed>> $days */
        $days = [];

        foreach ($timings as $timing) {
            $date = $timing['started_at']->toDateString();
            $entry = $days[$date] ?? [
                'date' => $date,
                'calls' => 0,
                'answered' => 0,
                'total_seconds' => 0.0,
                'wait_seconds' => 0.0,
                'agent_seconds' => 0.0,
                'dentist_seconds' => 0.0,
            ];

            $entry['calls']++;

            if ($timing['answered']) {
                $entry['answered']++;
            }

            foreach (['total_seconds', 'wait_seconds', 'agent_seconds', 'dentist_seconds'] as $metric) {
                $entry[$metric] += (float) ($timing[$metric] ?? 0);
            }

            $days[$date] = $entry;
        }

        ksort($days);

        return array_values($days);
    }

    /** The PBX extension embedded in an agent channel, e.g. `PJSIP/204-0000074a` → `204`. */
    private function extension(?string $channel): ?string
    {
        if ($channel === null || $channel === '') {
            return null;
        }

        if (preg_match('#^(?:PJSIP|SIP|IAX2|DAHDI|Local)/(?:[^/@;]*/)?([0-9]{2,8})(?:[-@;/]|$)#', $channel, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Sum a nullable attempt metric across a journey's mobile legs.
     *
     * @param  Collection<int, TransferAttempt>  $attempts
     */
    private function metricSum(Collection $attempts, string $metric): ?float
    {
        $values = $attempts->pluck($metric)->filter(fn (mixed $value): bool => $value !== null);

        return $values->isEmpty() ? null : round((float) $values->sum(), 3);
    }

    /** @param Collection<int, array<string, mixed>> $timings */
    private function sum(Collection $timings, string $metric): float
    {
        return round((float) $timings->sum(fn (array $timing): float => (float) ($timing[$metric] ?? 0)), 3);
    }

    /** @param Collection<int, array<string, mixed>> $timings */
    private function average(Collection $timings, string $metric): ?float
    {
        $values = $timings->pluck($metric)->filter(fn (mixed $value): bool => $value !== null);

        return $values->isEmpty() ? null : round((float) $values->avg(), 3);
    }

    private function seconds(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        return round(max(0.0, (float) $from->diffInSeconds($to)), 3);
    }

    private function now(): CarbonImmutable
    {
        return $this->now ?? CarbonImmutable::now();
    }

    /**
     * @param  array{days: int, from: CarbonImmutable, to: CarbonImmutable, from_iso: string, to_iso: string}  $window
     * @return array{days: int, from: string, to: string}
     */
    private function serialiseWindow(array $window): array
    {
        return [
            'days' => $window['days'],
            'from' => $window['from_iso'],
            'to' => $window['to_iso'],
        ];
    }
}
