<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\CallBridge;
use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\PbxConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class LiveOperationsSnapshot
{
    public const MIN_REFRESH_SECONDS = 2;

    public const MAX_REFRESH_SECONDS = 5;

    /**
     * @param  array<string, string|int|null>  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters = []): array
    {
        $connection = $this->connection($filters);
        $window = $this->window($filters);

        if ($connection === null) {
            return [
                'generated_at' => now()->toIso8601String(),
                'window' => $window,
                'connection' => null,
                'freshness' => [
                    'state' => 'unconfigured',
                    'heartbeat_age_seconds' => null,
                    'last_event_age_seconds' => null,
                    'stale_after_seconds' => (int) config('services.exp_pbx.heartbeat_stale_seconds', 90),
                ],
                'agent_presence' => [
                    'state' => 'unknown',
                    'extension' => (string) config('services.exp_pbx.screening_extension', '204'),
                    'inferred' => true,
                    'as_of' => now()->toIso8601String(),
                ],
                'kpis' => $this->emptyKpis(),
                'active_calls' => [],
                'transfers' => [],
                'alerts' => [],
                'alert_counts' => ['open' => 0, 'acknowledged' => 0],
                'options' => ['agents' => [], 'destinations' => []],
            ];
        }

        $scope = $this->scope($connection, $filters, $window['from'], $window['to']);

        return [
            'generated_at' => now()->toIso8601String(),
            'window' => $window,
            'connection' => [
                'id' => $connection->id,
                'name' => $connection->name,
                'status' => $connection->status,
                'last_event_at' => $connection->last_event_at?->toIso8601String(),
                'last_heartbeat_at' => $connection->last_heartbeat_at?->toIso8601String(),
                'last_error' => $connection->last_error,
            ],
            'freshness' => $this->freshness($connection),
            'agent_presence' => $this->agentPresence($connection),
            'kpis' => $this->kpis($scope),
            'active_calls' => $this->activeCalls($scope),
            'transfers' => $this->transfers($scope),
            'alerts' => $this->alerts($connection),
            'alert_counts' => $this->alertCounts($connection),
            'options' => $this->options($connection),
        ];
    }

    /**
     * @param  array<string, string|int|null>  $filters
     */
    private function connection(array $filters): ?PbxConnection
    {
        $id = $filters['connection_id'] ?? null;
        if ($id !== null && $id !== '') {
            return PbxConnection::query()->find((int) $id);
        }

        return PbxConnection::query()->where('name', config('services.exp_pbx.connection_name'))->first();
    }

    /**
     * @param  array<string, string|int|null>  $filters
     * @return array<string, string>
     */
    private function window(array $filters): array
    {
        $timezone = (string) config('app.timezone');
        $from = $this->parse($filters['from'] ?? null, $timezone, CarbonImmutable::now($timezone)->startOfDay());
        $to = $this->parse($filters['to'] ?? null, $timezone, CarbonImmutable::now($timezone));

        return [
            'from' => $from->format('Y-m-d\TH:i'),
            'to' => $to->format('Y-m-d\TH:i'),
            'from_iso' => $from->toIso8601String(),
            'to_iso' => $to->endOfMinute()->toIso8601String(),
        ];
    }

    private function parse(mixed $value, string $timezone, CarbonImmutable $default): CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return $default;
        }

        $parsed = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $value, $timezone);

        return $parsed instanceof CarbonImmutable ? $parsed : $default;
    }

    /**
     * @param  array<string, string|int|null>  $filters
     * @return Builder<Interaction>
     */
    private function scope(PbxConnection $connection, array $filters, string $from, string $to): Builder
    {
        $timezone = (string) config('app.timezone');
        $start = CarbonImmutable::parse($from, $timezone);
        $end = CarbonImmutable::parse($to, $timezone)->endOfMinute();

        return Interaction::query()
            ->where('pbx_connection_id', $connection->id)
            ->whereBetween('started_at', [$start, $end])
            ->when($filters['agent'] ?? null, fn ($query, $agent) => $query->whereHas('legs', fn ($legs) => $legs->where('role', 'agent')->where('channel', 'like', '%'.$agent.'%')))
            ->when($filters['destination'] ?? null, fn ($query, $destination) => $query->whereHas('legs', fn ($legs) => $legs->where('role', 'mobile')->where('channel', 'like', '%'.$destination.'%')))
            ->when($filters['state'] ?? null, fn ($query, $state) => match ($state) {
                'active' => $query->recentlyActive(),
                'completed' => $query->where('status', 'completed'),
                'abandoned' => $query->where('status', 'completed')->whereDoesntHave('legs', fn ($legs) => $legs->where('role', 'agent')->whereNotNull('answered_at')),
                default => $query,
            });
    }

    /**
     * @param  Builder<Interaction>  $scope
     * @return array<string, int>
     */
    private function kpis(Builder $scope): array
    {
        return [
            'inbound' => (clone $scope)->count(),
            'answered' => (clone $scope)->whereHas('legs', fn ($legs) => $legs->where('role', 'agent')->whereNotNull('answered_at'))->count(),
            'abandoned' => (clone $scope)->where('status', 'completed')->whereDoesntHave('legs', fn ($legs) => $legs->where('role', 'agent')->whereNotNull('answered_at'))->count(),
            'active' => (clone $scope)->recentlyActive()->count(),
            'completed' => (clone $scope)->where('status', 'completed')->count(),
            'dentist_ringing' => (clone $scope)->whereHas('legs', fn ($legs) => $legs->where('role', 'mobile')->whereNotNull('ringing_at')->whereNull('answered_at')->whereNull('ended_at'))->count(),
            'consultations_active' => (clone $scope)->whereHas('bridges', fn ($bridges) => $bridges->where('stage', 'consultation')->whereNull('ended_at'))->count(),
            'private_handovers_active' => (clone $scope)->whereHas('bridges', fn ($bridges) => $bridges->where('stage', 'private_handover')->whereNull('ended_at'))->count(),
        ];
    }

    /** @return array<string, int> */
    private function emptyKpis(): array
    {
        return ['inbound' => 0, 'answered' => 0, 'abandoned' => 0, 'active' => 0, 'completed' => 0, 'dentist_ringing' => 0, 'consultations_active' => 0, 'private_handovers_active' => 0];
    }

    /**
     * @param  Builder<Interaction>  $scope
     * @return list<array<string, mixed>>
     */
    private function activeCalls(Builder $scope): array
    {
        $calls = (clone $scope)->recentlyActive()
            ->with(['legs', 'bridges'])
            ->withCount('exceptions')
            ->orderByDesc('started_at')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (Interaction $interaction): array => [
                'id' => $interaction->id,
                'started_at' => $interaction->started_at?->toIso8601String(),
                'duration_seconds' => $interaction->started_at === null ? null : max(0, now()->getTimestamp() - $interaction->started_at->getTimestamp()),
                'stage' => $this->stage($interaction),
                'agent_channel' => $this->mask($this->leg($interaction, 'agent')?->channel),
                'destination_channel' => $this->mask($this->leg($interaction, 'mobile')?->channel),
                'timing_quality' => $interaction->timing_quality,
                'is_exp' => (bool) $interaction->is_exp,
                'exceptions' => (int) $interaction->getAttribute('exceptions_count'),
            ])->all();

        return array_values($calls);
    }

    /**
     * @param  Builder<Interaction>  $scope
     * @return list<array<string, mixed>>
     */
    private function transfers(Builder $scope): array
    {
        $transfers = CallLeg::query()
            ->with(['interaction' => fn ($query) => $query->withExists(['exceptions as has_telemetry_gap' => fn ($exceptions) => $exceptions->where('code', 'telemetry_gap')])])
            ->whereIn('interaction_id', (clone $scope)->select('id'))
            ->where('role', 'mobile')
            ->orderByDesc('dial_started_at')->orderByDesc('id')
            ->limit(25)->get()
            ->map(fn (CallLeg $leg): array => [
                'id' => $leg->id,
                'interaction_id' => $leg->interaction_id,
                'channel' => $this->mask($leg->channel),
                'dial_status' => $leg->dial_status,
                'dial_started_at' => $leg->dial_started_at?->toIso8601String(),
                'ringing_at' => $leg->ringing_at?->toIso8601String(),
                'answered_at' => $leg->answered_at?->toIso8601String(),
                'ended_at' => $leg->ended_at?->toIso8601String(),
                'ringing_for_seconds' => $leg->interaction?->getAttribute('has_telemetry_gap') ? null : $this->secondsBetween($leg->ringing_at, $leg->answered_at ?? $leg->ended_at ?? now()),
                'dial_to_answer_seconds' => $leg->interaction?->getAttribute('has_telemetry_gap') ? null : $this->secondsBetween($leg->dial_started_at, $leg->answered_at),
                'active' => $leg->ended_at === null,
            ])->all();

        return array_values($transfers);
    }

    /** @return list<array<string, mixed>> */
    private function alerts(PbxConnection $connection): array
    {
        $alerts = $this->alertQuery($connection)
            ->whereIn('status', ['open', 'acknowledged'])
            ->orderByDesc('last_seen_at')
            ->limit(20)->get()
            ->sortBy(fn (AlertEvent $alert): int => $this->severityRank($alert->severity))
            ->values()
            ->map(fn (AlertEvent $alert): array => [
                'id' => $alert->id,
                'rule' => $alert->rule?->key,
                'name' => $alert->rule?->name,
                'severity' => $alert->severity,
                'status' => $alert->status,
                'subject' => $alert->subject,
                'details' => $alert->details,
                'first_seen_at' => $alert->first_seen_at?->toIso8601String(),
                'last_seen_at' => $alert->last_seen_at?->toIso8601String(),
                'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            ])->all();

        return array_values($alerts);
    }

    /** @return array{open: int, acknowledged: int} */
    private function alertCounts(PbxConnection $connection): array
    {
        return [
            'open' => $this->alertQuery($connection)->where('status', 'open')->count(),
            'acknowledged' => $this->alertQuery($connection)->where('status', 'acknowledged')->count(),
        ];
    }

    /** @return Builder<AlertEvent> */
    private function alertQuery(PbxConnection $connection): Builder
    {
        return AlertEvent::query()
            ->with('rule:id,key,name')
            ->where(function (Builder $query) use ($connection): void {
                $query->where('pbx_connection_id', $connection->id)
                    ->orWhereHas('interaction', fn ($interaction) => $interaction->where('pbx_connection_id', $connection->id));
            });
    }

    /** @return array<string, mixed> */
    private function freshness(PbxConnection $connection): array
    {
        return $this->connectionFreshness($connection);
    }

    /**
     * Explain whether AMI evidence is live. Callers must not present a stale or
     * offline connection as live.
     *
     * @return array{state: string, heartbeat_age_seconds: int|null, last_event_age_seconds: int|null, stale_after_seconds: int}
     */
    public function connectionFreshness(?PbxConnection $connection): array
    {
        $staleAfter = (int) config('services.exp_pbx.heartbeat_stale_seconds', 90);

        if ($connection === null) {
            return ['state' => 'unconfigured', 'heartbeat_age_seconds' => null, 'last_event_age_seconds' => null, 'stale_after_seconds' => $staleAfter];
        }

        $heartbeatAge = $connection->last_heartbeat_at === null ? null : max(0, now()->getTimestamp() - $connection->last_heartbeat_at->getTimestamp());
        $eventAge = $connection->last_event_at === null ? null : max(0, now()->getTimestamp() - $connection->last_event_at->getTimestamp());
        $state = match (true) {
            $connection->status !== 'connected' => 'offline',
            $heartbeatAge === null || $heartbeatAge > $staleAfter => 'stale',
            default => 'live',
        };

        return [
            'state' => $state,
            'heartbeat_age_seconds' => $heartbeatAge,
            'last_event_age_seconds' => $eventAge,
            'stale_after_seconds' => $staleAfter,
        ];
    }

    /** @return array<string, mixed> */
    private function agentPresence(PbxConnection $connection): array
    {
        $window = (int) config('services.exp_pbx.agent_presence_window_minutes', 10);
        $onCall = CallLeg::query()->where('pbx_connection_id', $connection->id)->where('role', 'agent')
            ->whereNotNull('answered_at')->whereNull('ended_at')->exists();
        $recent = CallLeg::query()->where('pbx_connection_id', $connection->id)->where('role', 'agent')
            ->where('answered_at', '>=', now()->subMinutes($window))->exists();

        return [
            'state' => $onCall ? 'on_call' : ($recent ? 'recently_active' : 'unknown'),
            'extension' => (string) config('services.exp_pbx.screening_extension', '204'),
            'inferred' => true,
            'as_of' => now()->toIso8601String(),
        ];
    }

    /** @return array{agents: list<string>, destinations: list<string>} */
    private function options(PbxConnection $connection): array
    {
        $legs = CallLeg::query()->where('pbx_connection_id', $connection->id)
            ->whereIn('role', ['agent', 'mobile'])->whereNotNull('channel')
            ->limit(500)->get(['role', 'channel']);

        $agents = $legs->where('role', 'agent')
            ->map(fn (CallLeg $leg): ?string => preg_match('/(?:PJSIP|SIP|Local)\/([0-9]{1,8})/', (string) $leg->channel, $matches) === 1 ? $matches[1] : null)
            ->filter()->unique()->sort()->values()->all();

        $destinations = $legs->where('role', 'mobile')
            ->map(fn (CallLeg $leg): ?string => $this->mask($leg->channel))
            ->filter()->unique()->sort()->values()->all();

        return ['agents' => array_values($agents), 'destinations' => array_values($destinations)];
    }

    private function stage(Interaction $interaction): string
    {
        $openBridge = fn (string $stage): bool => $interaction->bridges->contains(
            fn (CallBridge $bridge): bool => $bridge->stage === $stage && $bridge->ended_at === null
        );
        $openLeg = fn (string $role, bool $answered): bool => $interaction->legs->contains(
            fn (CallLeg $leg): bool => $leg->role === $role
                && $leg->ended_at === null
                && ($answered ? $leg->answered_at !== null : ($leg->ringing_at !== null && $leg->answered_at === null))
        );

        return match (true) {
            $openBridge('consultation') => 'consultation',
            $openBridge('private_handover') => 'private_handover',
            $openLeg('mobile', true) => 'dentist_connected',
            $openLeg('mobile', false) => 'dentist_ringing',
            $openLeg('agent', true) => 'agent_conversation',
            default => 'setup',
        };
    }

    /** Public projection of the furthest stage reached, used by the agent workspace. */
    public function stageFor(Interaction $interaction): string
    {
        return $this->stage($interaction);
    }

    private function leg(Interaction $interaction, string $role): ?CallLeg
    {
        return $interaction->legs->first(fn (CallLeg $leg): bool => $leg->role === $role);
    }

    private function secondsBetween(mixed $from, mixed $to): ?int
    {
        if (! $from instanceof \DateTimeInterface || ! $to instanceof \DateTimeInterface) {
            return null;
        }

        return max(0, $to->getTimestamp() - $from->getTimestamp());
    }

    private function severityRank(?string $severity): int
    {
        return match ($severity) {
            'critical' => 0,
            'warning' => 1,
            default => 2,
        };
    }

    private function mask(?string $channel): ?string
    {
        if ($channel === null) {
            return null;
        }

        return preg_replace_callback('/\+?[0-9]{7,}/', function (array $matches): string {
            $number = $matches[0];
            $prefix = str_starts_with($number, '+') ? '+' : '';
            $digits = ltrim($number, '+');

            return $prefix.substr($digits, 0, 3).str_repeat('*', max(strlen($digits) - 5, 1)).substr($digits, -2);
        }, $channel) ?? $channel;
    }
}
