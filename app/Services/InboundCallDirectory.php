<?php

namespace App\Services;

use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the two inbound call lists from retained PBX evidence.
 *
 * "Agent calls" is deliberately narrow: a call appears only when the caller
 * pressed the agent option, or the PBX delivered the caller to the agent leg.
 * A call that never reached the agent is never counted as an agent call, no
 * matter how many screening answers a case happens to have.
 */
class InboundCallDirectory
{
    public function __construct(private IvrJourney $journey, private CallHistoryRecord $history, private ServiceCalendar $calendar) {}

    /**
     * @param  array{from?: string|null, to?: string|null, basis?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function agentCalls(array $filters, User $user): LengthAwarePaginator
    {
        $agentOption = $this->journey->agentOption();
        $includeCaseOnly = ($filters['basis'] ?? null) === 'all';

        return Interaction::query()
            ->visibleInHistory($user)
            ->where(fn (Builder $query) => $query
                ->whereHas('ivrSteps', fn (Builder $steps) => $steps->where('option', $agentOption))
                ->orWhereHas('legs', fn (Builder $legs) => $legs->where('role', 'agent'))
                ->when($includeCaseOnly, fn (Builder $inner) => $inner->orWhereHas('callerCase')))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('started_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('started_at', '<=', $to))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->whereHas('legs', fn (Builder $legs) => $legs->where('caller_masked', 'like', '%'.$search.'%')))
            ->with($this->relations())
            ->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(20)->withQueryString()
            ->through(fn (Interaction $interaction): array => $this->row($interaction, $user));
    }

    /**
     * @param  array{from?: string|null, to?: string|null, option?: string|null, agent?: string|null, search?: string|null}  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function didCalls(array $filters, User $user): LengthAwarePaginator
    {
        $scoped = $this->hasInboundScope();

        return Interaction::query()
            ->visibleInHistory($user)
            ->when(
                $scoped,
                fn (Builder $query) => $query->whereHas('legs', fn (Builder $legs) => $legs->whereIn('entry_kind', ['did', 'ivr', 'agent_route'])),
                fn (Builder $query) => $query->where('is_exp', true)->whereHas('legs', fn (Builder $legs) => $legs->where('role', 'caller')),
            )
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->whereDate('started_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->whereDate('started_at', '<=', $to))
            ->when($filters['option'] ?? null, fn (Builder $query, string $option) => $query
                ->whereHas('ivrSteps', fn (Builder $steps) => $steps->where('option', $option)))
            ->when(($filters['agent'] ?? null) === 'yes', fn (Builder $query) => $query
                ->where(fn (Builder $inner) => $inner
                    ->whereHas('ivrSteps', fn (Builder $steps) => $steps->where('option', $this->journey->agentOption()))
                    ->orWhereHas('legs', fn (Builder $legs) => $legs->where('role', 'agent'))))
            ->when(($filters['agent'] ?? null) === 'no', fn (Builder $query) => $query
                ->whereDoesntHave('ivrSteps', fn (Builder $steps) => $steps->where('option', $this->journey->agentOption()))
                ->whereDoesntHave('legs', fn (Builder $legs) => $legs->where('role', 'agent')))
            ->when($filters['search'] ?? null, fn (Builder $query, string $search) => $query
                ->whereHas('legs', fn (Builder $legs) => $legs->where('caller_masked', 'like', '%'.$search.'%')))
            ->with($this->relations())
            ->orderByDesc('started_at')->orderByDesc('id')
            ->paginate(20)->withQueryString()
            ->through(fn (Interaction $interaction): array => $this->row($interaction, $user));
    }

    /**
     * Today's totals on the Ghana service day.
     *
     * @return array{total: int, reached_agent: int, screened: int, connected: int, self_service: int, open: int}
     */
    public function stats(User $user): array
    {
        $day = $this->calendar->now()->startOfDay()->utc();
        $base = Interaction::query()->visibleInHistory($user)->where('started_at', '>=', $day);

        return [
            'total' => (clone $base)->count(),
            'reached_agent' => (clone $base)->where(fn (Builder $query) => $query
                ->whereHas('ivrSteps', fn (Builder $steps) => $steps->where('option', $this->journey->agentOption()))
                ->orWhereHas('legs', fn (Builder $legs) => $legs->where('role', 'agent')))->count(),
            'screened' => (clone $base)->whereHas('callerCase')->count(),
            'connected' => (clone $base)->whereHas('attempts', fn (Builder $attempts) => $attempts->verifiedConnection())->count(),
            'self_service' => (clone $base)->whereDoesntHave('legs', fn (Builder $legs) => $legs->where('role', 'agent'))->count(),
            'open' => (clone $base)->where('status', 'active')->count(),
        ];
    }

    public function today(): CarbonImmutable
    {
        return $this->calendar->now();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Interaction $interaction, User $user): array
    {
        $summary = $this->history->summary($interaction, $user);
        $caller = $interaction->legs->first(fn (CallLeg $leg): bool => $leg->role === 'caller');
        $basis = $this->basis($interaction);

        return [
            'id' => $interaction->id,
            'started_at' => $interaction->started_at?->toIso8601String(),
            'ended_at' => $interaction->ended_at?->toIso8601String(),
            'status' => $interaction->status,
            'caller_number' => $caller?->caller_masked,
            'inbound_line' => $this->journey->inboundLine($interaction),
            'basis' => $basis['key'],
            'basis_label' => $basis['label'],
            'basis_detail' => $basis['detail'],
            'selections' => $this->journey->selections($interaction),
            'chips' => $this->journey->chips($interaction, $summary),
            'track' => $this->journey->track($interaction, $summary),
            'outcome_label' => $summary['outcome_label'] ?? null,
            'outcome' => $this->journey->outcome($interaction, $summary),
            'connection_state' => $summary['connection_state'] ?? 'none',
            'connection_label' => $summary['connection_label'] ?? null,
            'transfer_to' => $summary['connected_dentist'] ?? null,
            'agent' => $summary['agent'] ?? null,
            'answered_questions' => $summary['answered_questions'] ?? 0,
            'question_count' => $summary['question_count'] ?? 0,
            'attempts_count' => $summary['attempts_count'] ?? 0,
            'duration_seconds' => $summary['duration_seconds'] ?? null,
            'href' => route('calls.show', $interaction),
        ];
    }

    /** @return array{key: string, label: string, detail: string} */
    private function basis(Interaction $interaction): array
    {
        if ($this->journey->selectedAgentOption($interaction)) {
            return [
                'key' => 'ivr',
                'label' => 'IVR option '.$this->journey->agentOption(),
                'detail' => 'The caller pressed the agent option on the menu.',
            ];
        }

        if ($this->journey->reachedAgent($interaction)) {
            return [
                'key' => 'agent_route',
                'label' => 'Agent leg',
                'detail' => 'The PBX delivered the caller to the agent; no menu step was retained.',
            ];
        }

        if ($interaction->callerCase !== null) {
            return [
                'key' => 'case',
                'label' => 'Screening case',
                'detail' => 'A screening case exists but neither the menu step nor the agent leg was retained.',
            ];
        }

        return [
            'key' => 'ivr_unrecorded',
            'label' => 'Menu not recorded',
            'detail' => 'No menu step was retained, so the caller\'s choice is unknown for this call.',
        ];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function relations(): array
    {
        return [
            'ivrSteps',
            'legs' => fn ($legs) => $legs->select(['id', 'interaction_id', 'role', 'entry_kind', 'caller_masked', 'inbound_masked', 'started_at', 'answered_at', 'ended_at']),
            'callerCase.agent:id,name',
            'callerCase.screenings.answers' => fn ($answers) => $answers->select('id', 'screening_id', 'answer_status'),
            'attempts.dentist:id,name',
            'attempts.session',
        ];
    }

    private function hasInboundScope(): bool
    {
        return config('services.exp_pbx.inbound_dids', []) !== []
            || config('services.exp_pbx.ivr_contexts', []) !== [];
    }
}
