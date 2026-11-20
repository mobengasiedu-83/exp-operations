<?php

namespace App\Services;

use App\Models\CallerCase;
use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds the read-only call evidence shown to an EXP agent: which inbound calls
 * are active, what the PBX reported (or did not report) about them, and whether
 * the same evidence is contradictory enough to require an explicit confirmation
 * before a case is opened.
 */
class AgentCallContext
{
    /** Calls older than this are treated as possibly missing a hangup event. */
    public const STALE_CALL_MINUTES = 120;

    public const AMBIGUITY_MULTIPLE_CALLS = 'multiple_active_calls';

    public const AMBIGUITY_CORRELATION = 'correlation_exceptions';

    public const AMBIGUITY_MISSING_HANGUP = 'possible_missing_hangup';

    /** @var array<int, int> */
    private array $activeCounts = [];

    public function __construct(private LiveOperationsSnapshot $snapshot) {}

    /**
     * @return array<string, mixed>
     */
    public function build(?Interaction $focus, User $user): array
    {
        $connection = $this->connection();
        $active = $connection === null ? collect() : $this->activeCalls($connection);
        $recent = $connection === null ? collect() : $this->recentJourneys($connection);
        $caseIds = $this->caseIds($active->pluck('id')->all(), $recent->pluck('id')->all(), $focus?->id, $user);

        return [
            'generated_at' => now()->toIso8601String(),
            'agent_extension' => $this->extension(),
            'connection' => $connection?->only(['id', 'name', 'status', 'last_event_at', 'last_heartbeat_at', 'last_error']),
            'freshness' => $this->snapshot->connectionFreshness($connection),
            'calls' => $active->map(fn (Interaction $interaction): array => $this->detail($interaction, $caseIds))->values()->all(),
            'recent' => $recent->map(fn (Interaction $interaction): array => $this->detail($interaction, $caseIds))->values()->all(),
            'interaction' => $focus === null ? null : $this->detail($focus, $caseIds),
        ];
    }

    /**
     * Contradictory evidence that the operator must acknowledge before a case is
     * bound to an interaction.
     *
     * @return array{requires_confirmation: bool, reasons: list<string>}
     */
    public function ambiguityFor(Interaction $interaction): array
    {
        $interaction->loadCount('exceptions');

        return $this->ambiguity(
            $interaction,
            $this->activeCount((int) $interaction->pbx_connection_id),
            (int) $interaction->getAttribute('exceptions_count'),
        );
    }

    private function connection(): ?PbxConnection
    {
        return PbxConnection::query()
            ->where('name', (string) config('services.exp_pbx.connection_name'))
            ->first();
    }

    /** @return Collection<int, Interaction> */
    private function activeCalls(PbxConnection $connection): Collection
    {
        return Interaction::query()
            ->where('pbx_connection_id', $connection->id)
            ->where('status', 'active')
            ->agentSelected()
            ->where('started_at', '>=', now()->subMinutes(self::STALE_CALL_MINUTES))
            ->with(['legs', 'bridges'])
            ->withCount('exceptions')
            ->orderByDesc('started_at')->orderByDesc('id')
            ->limit(10)->get();
    }

    /** @return Collection<int, Interaction> */
    private function recentJourneys(PbxConnection $connection): Collection
    {
        return Interaction::query()
            ->where('pbx_connection_id', $connection->id)
            ->agentSelected()
            ->with(['legs', 'bridges'])
            ->where('started_at', '>=', now()->subDay())
            ->withCount('exceptions')
            ->orderByDesc('started_at')->orderByDesc('id')
            ->limit(50)->get();
    }

    /**
     * Case identifiers are scoped to records the requesting agent may open, so
     * call evidence never reveals that another operator's case exists.
     *
     * @param  array<int, int>  $activeIds
     * @param  array<int, int>  $recentIds
     * @return array<int, int>
     */
    private function caseIds(array $activeIds, array $recentIds, ?int $focusId, User $user): array
    {
        $ids = array_values(array_unique([...$activeIds, ...$recentIds, ...($focusId === null ? [] : [$focusId])]));

        return $ids === [] ? [] : CallerCase::query()
            ->whereIn('interaction_id', $ids)
            ->visibleTo($user)
            ->pluck('id', 'interaction_id')->all();
    }

    /**
     * @param  array<int, int>  $caseIds
     * @return array<string, mixed>
     */
    private function detail(Interaction $interaction, array $caseIds = []): array
    {
        $interaction->loadMissing(['legs', 'bridges']);

        if ($interaction->getAttribute('exceptions_count') === null) {
            $interaction->loadCount('exceptions');
        }

        $caller = $this->leg($interaction, 'caller');
        $agent = $this->leg($interaction, 'agent');
        $ringingFrom = $agent?->ringing_at ?? $caller?->ringing_at ?? $interaction->started_at;
        $answered = $agent?->answered_at ?? $caller?->answered_at;

        return [
            'id' => $interaction->id,
            'correlation_key' => $interaction->correlation_key,
            'status' => $interaction->status,
            'is_exp' => (bool) $interaction->is_exp,
            'started_at' => $interaction->started_at?->toIso8601String(),
            'ended_at' => $interaction->ended_at?->toIso8601String(),
            'answered_at' => $answered?->toIso8601String(),
            'ringing_at' => $ringingFrom?->toIso8601String(),
            'duration_seconds' => $this->secondsBetween($interaction->started_at, $interaction->ended_at ?? now()),
            'ringing_for_seconds' => $answered === null && $ringingFrom !== null
                ? $this->secondsBetween($ringingFrom, now())
                : null,
            'waiting_for_seconds' => $answered === null && $interaction->started_at !== null
                ? $this->secondsBetween($interaction->started_at, now())
                : null,
            'stage' => $this->snapshot->stageFor($interaction),
            'caller_number' => $caller?->caller_masked ?? $this->dialableNumber($caller?->channel),
            'inbound_did' => $caller?->inbound_masked,
            'agent_extension' => $this->extension(),
            'agent_channel' => $this->maskReference($agent?->channel),
            'timing_quality' => $interaction->timing_quality,
            'exceptions' => (int) $interaction->getAttribute('exceptions_count'),
            'case_id' => $caseIds[$interaction->id] ?? null,
            'has_case' => isset($caseIds[$interaction->id]),
            'ambiguity' => $this->ambiguity(
                $interaction,
                $this->activeCount((int) $interaction->pbx_connection_id),
                (int) $interaction->getAttribute('exceptions_count'),
            ),
        ];
    }

    /**
     * @return array{requires_confirmation: bool, reasons: list<string>}
     */
    private function ambiguity(Interaction $interaction, int $activeCount, int $exceptions): array
    {
        $reasons = [];

        if ($activeCount > 1) {
            $reasons[] = self::AMBIGUITY_MULTIPLE_CALLS;
        }

        if ($exceptions > 0) {
            $reasons[] = self::AMBIGUITY_CORRELATION;
        }

        if ($interaction->status === 'active' && $interaction->started_at !== null
            && $interaction->started_at->lt(now()->subMinutes(self::STALE_CALL_MINUTES))) {
            $reasons[] = self::AMBIGUITY_MISSING_HANGUP;
        }

        return ['requires_confirmation' => $reasons !== [], 'reasons' => $reasons];
    }

    private function activeCount(int $connectionId): int
    {
        return $this->activeCounts[$connectionId] ??= Interaction::query()
            ->where('pbx_connection_id', $connectionId)
            ->where('status', 'active')
            ->agentSelected()
            ->where('started_at', '>=', now()->subMinutes(self::STALE_CALL_MINUTES))
            ->count();
    }

    private function leg(Interaction $interaction, string $role): ?CallLeg
    {
        return $interaction->legs->first(fn (CallLeg $leg): bool => $leg->role === $role);
    }

    /**
     * Caller IDs are never stored in full, so the masked caller number comes
     * from the listener's caller evidence, with the channel as a legacy
     * fallback for calls captured before caller evidence was retained.
     */
    private function dialableNumber(?string $channel): ?string
    {
        if ($channel === null) {
            return null;
        }

        $withoutSuffix = preg_replace('/-[0-9a-f]{1,12}$/i', '', $channel) ?? $channel;
        $position = strrpos($withoutSuffix, '/');
        $segment = $position === false ? $withoutSuffix : substr($withoutSuffix, $position + 1);
        $digits = preg_replace('/\D+/', '', $segment);

        return is_string($digits) && strlen($digits) >= 7 ? $this->maskDigits($digits) : null;
    }

    private function maskReference(?string $value): ?string
    {
        return $value === null ? null : ($this->maskDigits($value) ?? $value);
    }

    private function maskDigits(string $value): ?string
    {
        return preg_replace_callback('/[0-9]{4,}/', function (array $matches): string {
            $digits = $matches[0];

            return substr($digits, 0, 3).str_repeat('*', max(strlen($digits) - 5, 1)).substr($digits, -2);
        }, $value);
    }

    private function secondsBetween(?CarbonInterface $from, CarbonInterface $to): ?int
    {
        return $from === null ? null : max(0, $to->getTimestamp() - $from->getTimestamp());
    }

    private function extension(): string
    {
        return (string) config('services.exp_pbx.screening_extension', '204');
    }
}
