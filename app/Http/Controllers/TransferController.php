<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecordTransferEventRequest;
use App\Models\CallerCase;
use App\Models\Dentist;
use App\Models\TransferAttempt;
use App\Models\User;
use App\Services\TransferTracker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TransferController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view transfers');
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['live', 'attempts', 'consultations', 'failures', 'analytics'])],
            'status' => ['nullable', Rule::in(TransferAttempt::STATUSES)],
            'days' => ['nullable', 'integer', 'between:1,365'],
            'match' => ['nullable', Rule::in(TransferAttempt::MATCH_STATUSES)],
            'interaction_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $view = $filters['view'] ?? 'attempts';
        $days = (int) ($filters['days'] ?? 7);
        $query = TransferAttempt::query()->where('dial_started_at', '>=', now()->subDays($days))->where('dial_started_at', '<=', now())
            ->when($view === 'live', fn ($query) => $query->whereNull('ended_at')->whereNotIn('status', ['busy', 'unanswered', 'failed', 'cancelled', 'transfer_failed']))
            ->when($view === 'consultations', fn ($query) => $query->whereHas('session', fn ($sessions) => $sessions->where('status', '!=', 'evidence_withdrawn')))
            ->when($view === 'failures', fn ($query) => $query->whereIn('status', TransferAttempt::FAILURE_STATUSES))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['match'] ?? null, fn ($query, string $match) => $query->where('match_status', $match))
            ->when($filters['interaction_id'] ?? null, fn ($query, $id) => $query->where('interaction_id', $id));
        $totals = (clone $query)->selectRaw('COUNT(*) as attempts, SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) as network_answers, SUM(CASE WHEN acceptance_confidence = ? THEN 1 ELSE 0 END) as human_acceptances, AVG(dial_to_answer_seconds) as avg_dial_to_answer_seconds, AVG(ringing_seconds) as avg_ringing_seconds, SUM(mobile_connected_seconds) as mobile_connected_seconds, SUM(consultation_seconds) as observed_consultation_seconds', ['agent_confirmed'])->first();
        $analytics = $view === 'analytics' ? $this->analytics($query) : null;

        return Inertia::render('Transfers/Index', [
            'attempts' => (clone $query)->with('dentist:id,name')->orderByDesc('dial_started_at')->orderByDesc('id')->paginate(25)->withQueryString(),
            'summary' => $totals?->getAttributes(),
            'analytics' => $analytics,
            'filters' => [...$filters, 'view' => $view, 'days' => $days],
            'asOf' => now()->toIso8601String(),
            'statuses' => TransferAttempt::STATUSES,
            'matches' => TransferAttempt::MATCH_STATUSES,
        ]);
    }

    /**
     * @param  Builder<TransferAttempt>  $query
     * @return array{dentists: array<int, array<string, mixed>>, failures: array<int, array<string, mixed>>}
     */
    private function analytics(Builder $query): array
    {
        $names = Dentist::query()
            ->whereKey((clone $query)->whereNotNull('dentist_id')->distinct()->pluck('dentist_id'))
            ->pluck('name', 'id');

        $dentists = (clone $query)
            ->selectRaw('dentist_id, COUNT(*) as attempts, SUM(CASE WHEN answered_at IS NOT NULL THEN 1 ELSE 0 END) as network_answers, SUM(CASE WHEN acceptance_confidence = ? THEN 1 ELSE 0 END) as human_acceptances, AVG(dial_to_answer_seconds) as avg_dial_to_answer_seconds, AVG(ringing_seconds) as avg_ringing_seconds, SUM(mobile_connected_seconds) as mobile_connected_seconds, SUM(consultation_seconds) as consultation_seconds', ['agent_confirmed'])
            ->groupBy('dentist_id')
            ->orderByDesc('attempts')
            ->get()
            ->map(function (TransferAttempt $row) use ($names): array {
                $row = $row->getAttributes();
                $dentistId = $row['dentist_id'] === null ? null : (int) $row['dentist_id'];

                return [
                    'dentist_id' => $dentistId,
                    'dentist_name' => $dentistId === null ? 'Unmatched destination' : ($names[$dentistId] ?? 'Unknown dentist'),
                    'attempts' => (int) $row['attempts'],
                    'network_answers' => (int) $row['network_answers'],
                    'human_acceptances' => (int) $row['human_acceptances'],
                    'avg_dial_to_answer_seconds' => $row['avg_dial_to_answer_seconds'] === null ? null : round((float) $row['avg_dial_to_answer_seconds'], 1),
                    'avg_ringing_seconds' => $row['avg_ringing_seconds'] === null ? null : round((float) $row['avg_ringing_seconds'], 1),
                    'mobile_connected_seconds' => $row['mobile_connected_seconds'] === null ? null : round((float) $row['mobile_connected_seconds'], 1),
                    'consultation_seconds' => $row['consultation_seconds'] === null ? null : round((float) $row['consultation_seconds'], 1),
                ];
            })->values()->all();

        $failures = (clone $query)
            ->whereIn('status', TransferAttempt::FAILURE_STATUSES)
            ->selectRaw('status, failure_reason, COUNT(*) as attempts')
            ->groupBy('status', 'failure_reason')
            ->orderByDesc('attempts')
            ->get()
            ->map(function (TransferAttempt $row): array {
                $row = $row->getAttributes();

                return [
                    'status' => (string) $row['status'],
                    'failure_reason' => $row['failure_reason'] === null ? 'not recorded' : (string) $row['failure_reason'],
                    'attempts' => (int) $row['attempts'],
                ];
            })->values()->all();

        return ['dentists' => $dentists, 'failures' => $failures];
    }

    public function show(Request $request, TransferAttempt $attempt): Response
    {
        $this->authorize('view transfers');
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $case = $user->can('view cases') ? CallerCase::query()->visibleTo($user)->where('interaction_id', $attempt->interaction_id)->first() : null;
        $attempt->load(['dentist:id,name', 'session']);

        return Inertia::render('Transfers/Show', [
            'attempt' => $attempt,
            'events' => $attempt->events()->orderByDesc('occurred_at')->orderByDesc('id')->paginate(50)->through(fn ($event): array => [
                'id' => $event->id, 'kind' => $event->kind, 'outcome' => $event->outcome,
                'occurred_at' => $event->occurred_at, 'recorded_at' => $event->created_at, 'recorded_by' => $event->recorded_by,
                'note' => $case === null ? null : $event->note,
            ])->withQueryString(),
            'caseId' => $case?->id,
            'canRecord' => $case !== null && $user->can('edit transfers'),
            'outcomes' => TransferTracker::OUTCOMES,
            'fallbacks' => TransferTracker::FALLBACKS,
            'related' => TransferAttempt::query()->where('interaction_id', $attempt->interaction_id)->orderBy('sequence')->get(['id', 'sequence', 'status', 'retry_of_id']),
            'diagnostics' => $attempt->interaction?->exceptions()->orderBy('id')->get(['code', 'category', 'subject']) ?? [],
        ]);
    }

    public function record(RecordTransferEventRequest $request, TransferAttempt $attempt, TransferTracker $tracker): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $tracker->record($attempt, $user, $request->validated());

        return back()->with('success', 'Operator evidence recorded.');
    }
}
