<?php

namespace App\Services;

use App\Models\CallBridge;
use App\Models\CallLeg;
use App\Models\Dentist;
use App\Models\DentistAvailability;
use App\Models\DentistCredential;
use App\Models\DentistNumber;
use App\Models\DentistRoster;
use App\Models\TransferAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds roster coverage, unmatched destinations and confirmed call activity.
 *
 * Coverage is derived from published rosters and timestamped overrides only.
 * Call activity is matched from observed EXP mobile legs; it never infers
 * whether a dentist's personal mobile is busy.
 */
class DentistDirectory
{
    public const COVERAGE_STATES = ['rostered', 'on_break', 'on_leave', 'unavailable', 'not_rostered', 'inactive'];

    /**
     * @return array{
     *     as_of: string,
     *     dentists: array<int, array<string, mixed>>,
     *     summary: array<string, int>,
     *     unmatched: list<array<string, mixed>>,
     *     activity_window: array<string, mixed>
     * }
     */
    public function coverage(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $activity = $this->activity($at);

        $dentists = Dentist::query()
            ->with([
                'numbers' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('number'),
                'credentials',
                'rosters' => fn ($query) => $query->where('ends_at', '>', $at->subDay())->orderBy('starts_at'),
                'availability' => fn ($query) => $query->where('ends_at', '>', $at)->orderBy('starts_at'),
            ])
            ->orderBy('name')->get();

        $rows = $dentists
            ->map(fn (Dentist $dentist): array => $this->row($dentist, $at, $activity['by_dentist'][$dentist->id] ?? []))
            ->values();

        return [
            'as_of' => $at->toIso8601String(),
            'dentists' => $rows->all(),
            'summary' => [
                'total' => $rows->count(),
                'active' => $rows->where('active', true)->count(),
                'rostered' => $rows->where('coverage_state', 'rostered')->count(),
                'on_break' => $rows->where('coverage_state', 'on_break')->count(),
                'on_leave' => $rows->where('coverage_state', 'on_leave')->count(),
                'unavailable' => $rows->where('coverage_state', 'unavailable')->count(),
                'not_rostered' => $rows->where('coverage_state', 'not_rostered')->count(),
                'inactive' => $rows->where('coverage_state', 'inactive')->count(),
                'selectable' => $rows->where('selectable', true)->count(),
                'unmatched' => count($activity['unmatched']),
            ],
            'unmatched' => $activity['unmatched'],
            'activity_window' => $activity['window'],
        ];
    }

    /**
     * Active dentists with at least one approved mobile number, for transfer selection.
     *
     * Only approved numbers are returned and availability is reported exactly as
     * manually recorded; it is never inferred from PBX or AMI activity.
     *
     * @return array{as_of: string, dentists: list<array<string, mixed>>}
     */
    public function selectable(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();

        $dentists = Dentist::query()
            ->active()
            ->with([
                'numbers' => fn ($query) => $query->approved()->orderByDesc('is_primary')->orderBy('number'),
                'availability' => fn ($query) => $query->where('ends_at', '>', $at)->orderBy('starts_at'),
            ])
            ->whereHas('numbers', fn ($query) => $query->approved())
            ->orderBy('name')
            ->get();

        return [
            'as_of' => $at->toIso8601String(),
            'dentists' => $dentists->map(function (Dentist $dentist) use ($at): array {
                $override = $dentist->availability->first(fn (DentistAvailability $row): bool => $row->starts_at->lte($at) && $row->ends_at->gt($at));

                return [
                    'id' => $dentist->id,
                    'name' => $dentist->name,
                    'availability_status' => $this->manualAvailability($override),
                    'availability_until' => $override?->ends_at->toIso8601String(),
                    'numbers' => $dentist->numbers->map(fn (DentistNumber $number): array => [
                        'id' => $number->id,
                        'masked' => $number->masked(),
                        'label' => $number->label,
                        'is_primary' => (bool) $number->is_primary,
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    private function manualAvailability(?DentistAvailability $override): string
    {
        return match ($override?->status) {
            'busy' => 'busy',
            'break', 'leave', 'unavailable' => 'unavailable',
            default => 'available',
        };
    }

    /**
     * Confirmed EXP mobile-leg activity grouped by normalised dentist number.
     *
     * @return array{by_dentist: array<int, array<string, mixed>>, unmatched: list<array<string, mixed>>, window: array<string, mixed>}
     */
    public function activity(?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $lookbackDays = max(1, (int) config('services.exp_pbx.dentist_unmatched_lookback_days', 7));
        $from = $at->subDays($lookbackDays)->startOfDay();
        $recent = $at->subDay();
        // SQL bindings format timestamps without microseconds, so an inclusive
        // bound of "now" would drop a leg dialled earlier in the current second.
        $windowEnd = $at->addSecond();

        $numbers = DentistNumber::query()->contactable()->get(['number', 'dentist_id'])->keyBy(fn (DentistNumber $number): string => GhanaPhoneNumber::fingerprint($number->number) ?? '');

        $legs = CallLeg::query()
            ->where('role', 'mobile')
            ->whereNotNull('dial_started_at')
            ->where('dial_started_at', '>=', $from)
            ->where('dial_started_at', '<', $windowEnd)
            ->orderByDesc('dial_started_at')
            ->limit(5000)
            ->get(['id', 'interaction_id', 'uniqueid', 'channel', 'destination_hash', 'destination_masked', 'dial_status', 'dial_started_at', 'ringing_at', 'answered_at', 'ended_at']);

        $answered = $this->answeredLegIds($legs);

        /** @var array<int, array<string, mixed>> $byDentist */
        $byDentist = [];
        /** @var array<string, array<string, mixed>> $unmatched */
        $unmatched = [];

        foreach ($legs as $leg) {
            $number = GhanaPhoneNumber::fromChannel($leg->channel);
            $hash = $leg->destination_hash ?? GhanaPhoneNumber::fingerprint($number);
            $dentistId = $hash === null ? null : $numbers->get($hash)?->dentist_id;

            if ($dentistId !== null) {
                $entry = $byDentist[$dentistId] ?? [
                    'attempts_24h' => 0,
                    'answered_24h' => 0,
                    'last_attempt_at' => null,
                ];

                if ($leg->dial_started_at !== null && $leg->dial_started_at->gte($recent)) {
                    $entry['attempts_24h']++;

                    if (isset($answered[$leg->id])) {
                        $entry['answered_24h']++;
                    }
                }

                $entry['last_attempt_at'] ??= $leg->dial_started_at?->toIso8601String();
                $byDentist[$dentistId] = $entry;

                continue;
            }

            $key = $hash ?? 'channel:'.$leg->channel;
            $entry = $unmatched[$key] ?? [
                'number' => $number,
                'channel' => $this->maskChannel($leg->channel),
                'display' => $leg->destination_masked ?? ($number === null ? $this->maskChannel($leg->channel) : GhanaPhoneNumber::mask($number)),
                'reason' => $hash === null ? 'unparseable_channel' : 'unknown_number',
                'attempts' => 0,
                'last_seen_at' => null,
            ];

            $entry['attempts']++;
            $entry['last_seen_at'] ??= $leg->dial_started_at?->toIso8601String();
            $unmatched[$key] = $entry;
        }

        $rows = array_values($unmatched);
        usort($rows, fn (array $left, array $right): int => [$right['attempts'], $right['last_seen_at']] <=> [$left['attempts'], $left['last_seen_at']]);

        return [
            'by_dentist' => $byDentist,
            'unmatched' => $rows,
            'window' => ['from' => $from->toIso8601String(), 'to' => $at->toIso8601String(), 'days' => $lookbackDays],
        ];
    }

    /**
     * Legs whose dentist call provably reached the network.
     *
     * The ANSWER frame can be missing from a projection (retention, an
     * out-of-order frame filed against another journey, or a replay that no
     * longer carries it). A leg therefore still counts as answered when the
     * retained evidence proves the channel connected: an ANSWER dial status, a
     * tracker attempt in an answered state, or membership of a private handover
     * or consultation bridge. Attempts are never inflated by this check.
     *
     * @param  Collection<int, CallLeg>  $legs
     * @return array<int, true>
     */
    private function answeredLegIds(Collection $legs): array
    {
        $answered = [];
        $pending = [];

        foreach ($legs as $leg) {
            if ($leg->answered_at !== null || $leg->dial_status === 'ANSWER') {
                $answered[$leg->id] = true;
            } else {
                $pending[$leg->id] = $leg;
            }
        }

        if ($pending === []) {
            return $answered;
        }

        foreach (array_chunk(array_keys($pending), 500) as $ids) {
            $attempts = TransferAttempt::query()
                ->whereIn('call_leg_id', $ids)
                ->get(['call_leg_id', 'answered_at', 'status']);

            foreach ($attempts as $attempt) {
                if ($attempt->answered_at !== null || in_array($attempt->status, TransferAttempt::ANSWERED_STATUSES, true)) {
                    $answered[$attempt->call_leg_id] = true;
                }
            }
        }

        $remaining = array_diff_key($pending, $answered);

        if ($remaining === []) {
            return $answered;
        }

        $interactionIds = collect($remaining)->pluck('interaction_id')->filter()->unique()->values()->all();
        $bridged = [];

        foreach (array_chunk($interactionIds, 500) as $ids) {
            $bridges = CallBridge::query()
                ->whereIn('interaction_id', $ids)
                ->whereIn('stage', ['private_handover', 'consultation'])
                ->get(['participants']);

            foreach ($bridges as $bridge) {
                foreach (is_array($bridge->participants) ? $bridge->participants : [] as $uniqueid) {
                    $bridged[(string) $uniqueid] = true;
                }
            }
        }

        foreach ($remaining as $leg) {
            if (isset($bridged[(string) $leg->uniqueid])) {
                $answered[$leg->id] = true;
            }
        }

        return $answered;
    }

    /**
     * Matching mobile-leg outcomes per dentist over a rolling window.
     *
     * @return array{window_days: int, as_of: string, rows: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function performance(int $days): array
    {
        $at = CarbonImmutable::now();
        $windowDays = max(1, min($days, 365));
        $from = $at->subDays($windowDays);
        // Match the inclusive "now" of the activity window so a leg dialled
        // earlier in the current second is not lost to microsecond truncation.
        $windowEnd = $at->addSecond();

        $numbers = DentistNumber::query()->contactable()->get(['number', 'dentist_id'])->keyBy(fn (DentistNumber $number): string => GhanaPhoneNumber::fingerprint($number->number) ?? '');
        $dentists = Dentist::query()->orderBy('name')->get(['id', 'name', 'active']);

        $legs = CallLeg::query()
            ->where('role', 'mobile')
            ->whereNotNull('dial_started_at')
            ->where('dial_started_at', '>=', $from)
            ->where('dial_started_at', '<', $windowEnd)
            ->orderByDesc('dial_started_at')
            ->limit(20000)
            ->get(['id', 'interaction_id', 'uniqueid', 'channel', 'destination_hash', 'destination_masked', 'dial_status', 'dial_started_at', 'ringing_at', 'answered_at', 'ended_at']);

        $answeredLegs = $this->answeredLegIds($legs);

        /** @var array<int, array<string, mixed>> $stats */
        $stats = [];
        $unmatchedAttempts = 0;

        foreach ($legs as $leg) {
            $number = GhanaPhoneNumber::fromChannel($leg->channel);
            $hash = $leg->destination_hash ?? GhanaPhoneNumber::fingerprint($number);
            $dentistId = $hash === null ? null : $numbers->get($hash)?->dentist_id;

            if ($dentistId === null) {
                $unmatchedAttempts++;

                continue;
            }

            $entry = $stats[$dentistId] ?? [
                'attempts' => 0,
                'answered' => 0,
                'failed' => 0,
                'dial_to_answer_total' => 0.0,
                'dial_to_answer_samples' => 0,
                'ringing_total' => 0.0,
                'ringing_samples' => 0,
                'last_attempt_at' => null,
            ];

            $entry['attempts']++;
            $entry['last_attempt_at'] ??= $leg->dial_started_at?->toIso8601String();

            if (isset($answeredLegs[$leg->id])) {
                $entry['answered']++;

                if ($leg->answered_at !== null) {
                    $entry['dial_to_answer_total'] += max(0, $leg->answered_at->getTimestamp() - $leg->dial_started_at->getTimestamp());
                    $entry['dial_to_answer_samples']++;
                }
            } elseif ($leg->dial_status !== null && in_array($leg->dial_status, AlertEvaluator::FAILED_DIAL_STATUSES, true)) {
                $entry['failed']++;
            }

            if ($leg->ringing_at !== null) {
                $end = $leg->answered_at ?? $leg->ended_at ?? $at;
                $entry['ringing_total'] += max(0, $end->getTimestamp() - $leg->ringing_at->getTimestamp());
                $entry['ringing_samples']++;
            }

            $stats[$dentistId] = $entry;
        }

        $rows = $dentists->map(function (Dentist $dentist) use ($stats): array {
            $entry = $stats[$dentist->id] ?? null;
            $attempts = (int) ($entry['attempts'] ?? 0);
            $answered = (int) ($entry['answered'] ?? 0);

            return [
                'id' => $dentist->id,
                'name' => $dentist->name,
                'active' => (bool) $dentist->active,
                'attempts' => $attempts,
                'answered' => $answered,
                'failed' => (int) ($entry['failed'] ?? 0),
                'answer_rate' => $attempts === 0 ? null : round($answered / $attempts, 3),
                'avg_dial_to_answer_seconds' => $this->average((float) ($entry['dial_to_answer_total'] ?? 0), (int) ($entry['dial_to_answer_samples'] ?? 0)),
                'avg_ringing_seconds' => $this->average((float) ($entry['ringing_total'] ?? 0), (int) ($entry['ringing_samples'] ?? 0)),
                'last_attempt_at' => $entry['last_attempt_at'] ?? null,
            ];
        })->values()->all();

        $attempts = array_sum(array_column($rows, 'attempts'));
        $answered = array_sum(array_column($rows, 'answered'));

        return [
            'window_days' => $windowDays,
            'as_of' => $at->toIso8601String(),
            'rows' => $rows,
            'summary' => [
                'attempts' => $attempts,
                'answered' => $answered,
                'failed' => array_sum(array_column($rows, 'failed')),
                'unmatched_attempts' => $unmatchedAttempts,
                'answer_rate' => $attempts === 0 ? null : round($answered / $attempts, 3),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $activity
     * @return array<string, mixed>
     */
    private function row(Dentist $dentist, CarbonImmutable $at, array $activity): array
    {
        $currentShift = $dentist->rosters->first(fn (DentistRoster $roster): bool => in_array($roster->kind, DentistRoster::COVERING_KINDS, true)
            && $roster->starts_at->lte($at) && $roster->ends_at->gt($at));
        $currentLeave = $dentist->rosters->first(fn (DentistRoster $roster): bool => $roster->kind === 'leave'
            && $roster->starts_at->lte($at) && $roster->ends_at->gt($at));
        $nextShift = $dentist->rosters->first(fn (DentistRoster $roster): bool => in_array($roster->kind, DentistRoster::COVERING_KINDS, true)
            && $roster->starts_at->gt($at));

        $override = $dentist->availability->first(fn (DentistAvailability $row): bool => $row->starts_at->lte($at) && $row->ends_at->gt($at));

        $state = match (true) {
            ! $dentist->active => 'inactive',
            $override !== null && $override->status === 'break' => 'on_break',
            $override !== null && $override->status === 'leave' => 'on_leave',
            $override !== null && in_array($override->status, ['unavailable', 'busy'], true) => 'unavailable',
            $currentLeave !== null => 'on_leave',
            $currentShift !== null => 'rostered',
            default => 'not_rostered',
        };

        $approvedNumbers = $dentist->numbers->where('status', 'approved');

        return [
            'id' => $dentist->id,
            'name' => $dentist->name,
            'email' => $dentist->email,
            'notes' => $dentist->notes,
            'active' => (bool) $dentist->active,
            'coverage_state' => $state,
            'on_duty_until' => $currentShift?->ends_at->toIso8601String(),
            'next_shift_starts_at' => $nextShift?->starts_at->toIso8601String(),
            'override' => $override === null ? null : [
                'status' => $override->status,
                'starts_at' => $override->starts_at->toIso8601String(),
                'ends_at' => $override->ends_at->toIso8601String(),
                'reason' => $override->reason,
            ],
            'selectable' => $state === 'rostered' && $approvedNumbers->isNotEmpty(),
            'numbers' => $dentist->numbers->map(fn (DentistNumber $number): array => [
                'id' => $number->id,
                'number' => $number->number,
                'original' => $number->original,
                'formatted' => GhanaPhoneNumber::formatLocal($number->number),
                'label' => $number->label,
                'is_primary' => (bool) $number->is_primary,
                'status' => $number->status,
                'verified_at' => $number->verified_at?->toIso8601String(),
            ])->values()->all(),
            'credential_state' => $this->credentialState($dentist, $at),
            'credentials_expired' => $dentist->credentials->filter(fn (DentistCredential $credential): bool => $credential->validity($at) === 'expired')->count(),
            'credentials_expiring' => $dentist->credentials->filter(fn (DentistCredential $credential): bool => $credential->validity($at) === 'expiring')->count(),
            'call_activity' => [
                'attempts_24h' => (int) ($activity['attempts_24h'] ?? 0),
                'answered_24h' => (int) ($activity['answered_24h'] ?? 0),
                'last_attempt_at' => $activity['last_attempt_at'] ?? null,
            ],
        ];
    }

    private function credentialState(Dentist $dentist, CarbonImmutable $at): string
    {
        if ($dentist->credentials->isEmpty()) {
            return 'missing';
        }

        $states = $dentist->credentials->map(fn (DentistCredential $credential): string => $credential->validity($at));

        return match (true) {
            $states->contains('expired') => 'expired',
            $states->contains('expiring') => 'expiring',
            default => 'valid',
        };
    }

    private function average(float $total, int $samples): ?float
    {
        return $samples === 0 ? null : round($total / $samples, 1);
    }

    private function maskChannel(?string $channel): string
    {
        if ($channel === null) {
            return '—';
        }

        return preg_replace_callback('/[0-9]{4,}/', function (array $matches): string {
            $digits = $matches[0];

            return substr($digits, 0, 3).str_repeat('*', max(strlen($digits) - 5, 1)).substr($digits, -2);
        }, $channel) ?? $channel;
    }
}
