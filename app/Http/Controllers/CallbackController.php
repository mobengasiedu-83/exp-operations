<?php

namespace App\Http\Controllers;

use App\Models\Callback;
use App\Models\CallbackAttempt;
use App\Models\CallerCase;
use App\Models\User;
use App\Services\CallbackQueue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CallbackController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view callbacks');
        $user = $this->user($request);
        $filters = $request->validate([
            'view' => ['nullable', Rule::in(['pending', 'due', 'overdue', 'attempts', 'completed'])],
            'mine' => ['nullable', 'boolean'],
            'case_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $view = $filters['view'] ?? 'pending';
        $base = Callback::query()->visibleTo($user)
            ->when($request->boolean('mine'), fn ($query) => $query->where('assigned_to', $user->id))
            ->when($filters['case_id'] ?? null, fn ($query, int $caseId) => $query->where('case_id', $caseId));
        $counts = [
            'pending' => (clone $base)->open()->where('due_at', '>', now())->count(),
            'due' => (clone $base)->due()->count(),
            'overdue' => (clone $base)->overdue()->count(),
            'attempts' => (clone $base)->whereHas('attempts')->count(),
            'completed' => (clone $base)->whereIn('status', ['completed', 'cancelled'])->count(),
        ];
        $callbacks = match ($view) {
            'pending' => (clone $base)->open()->where('due_at', '>', now()),
            'due' => (clone $base)->due(),
            'overdue' => (clone $base)->overdue(),
            'completed' => (clone $base)->whereIn('status', ['completed', 'cancelled']),
            default => (clone $base)->open(),
        };

        return Inertia::render('Callbacks/Index', [
            'callbacks' => $view === 'attempts' ? null : $callbacks->with(['case:id,priority,status', 'assignee:id,name'])
                ->withCount('attempts')->orderBy('due_at')->orderBy('id')->paginate(25)->withQueryString()
                ->through(fn (Callback $callback): array => [
                    'id' => $callback->id,
                    'case_id' => $callback->case_id,
                    'case_priority' => $callback->case?->priority,
                    'case_status' => $callback->case?->status,
                    'priority' => $callback->priority,
                    'status' => $callback->status,
                    'owner' => $callback->assignee?->name,
                    'window' => substr((string) $callback->window_start, 0, 5).'–'.substr((string) $callback->window_end, 0, 5),
                    'due_at' => $callback->due_at?->toIso8601String(),
                    'escalated_at' => $callback->escalated_at?->toIso8601String(),
                    'attempts' => (int) $callback->getAttribute('attempts_count'),
                    'outcome' => $callback->outcome,
                    'revision' => $callback->revision,
                ]),
            'attempts' => $view === 'attempts' ? CallbackAttempt::query()
                ->whereHas('callback', fn ($query) => $query->visibleTo($user))
                ->with(['callback:id,case_id,status,revision', 'attemptedBy:id,name'])
                ->orderByDesc('attempted_at')->orderByDesc('id')->paginate(25)->withQueryString()
                ->through(fn (CallbackAttempt $attempt): array => [
                    'id' => $attempt->id,
                    'callback_id' => $attempt->callback_id,
                    'case_id' => $attempt->callback?->case_id,
                    'outcome' => $attempt->outcome,
                    'note' => $attempt->note,
                    'neutral_voicemail_confirmed' => $attempt->neutral_voicemail_confirmed,
                    'attempted_at' => $attempt->attempted_at?->toIso8601String(),
                    'recorded_by' => $attempt->attemptedBy?->name,
                    'callback_status' => $attempt->callback?->status,
                ]) : null,
            'counts' => $counts,
            'filters' => [...$filters, 'view' => $view, 'mine' => $request->boolean('mine')],
            'cases' => $user->can('edit callbacks') ? CallerCase::query()->visibleTo($user)->where('status', '!=', 'closed')
                ->orderByDesc('updated_at')->orderByDesc('id')->limit(50)->get(['id', 'priority', 'status']) : [],
            'owners' => $user->can('edit callbacks') ? User::query()->permission('view cases')->where('active', true)
                ->orderBy('name')->get(['id', 'name']) : [],
            'attemptOutcomes' => Callback::ATTEMPT_OUTCOMES,
            'outcomes' => Callback::OUTCOMES,
            'voicemailStatement' => CallbackAttempt::VOICEMAIL_SAFE_STATEMENT,
            'slaMinutes' => Callback::SLA_MINUTES,
            'canEdit' => $user->can('edit callbacks'),
            'asOf' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request, CallbackQueue $queue): RedirectResponse
    {
        $this->authorize('view callbacks');
        $this->authorize('edit callbacks');
        $data = $request->validate([
            'case_id' => ['required', 'integer', 'exists:cases,id'],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'priority' => ['required', Rule::in(Callback::PRIORITIES)],
            'reason' => ['required', 'string', 'max:1000'],
            'due_at' => ['required', 'date'],
            'window_start' => ['required', 'date_format:H:i'],
            'window_end' => ['required', 'date_format:H:i'],
        ]);
        $queue->book(CallerCase::query()->findOrFail((int) $data['case_id']), $this->user($request), [
            ...$data,
            'assigned_to' => (int) $data['assigned_to'],
            'window_start' => $data['window_start'].':00',
            'window_end' => $data['window_end'].':00',
        ]);

        return back()->with('success', 'Callback booked inside the approved contact window.');
    }

    public function attempt(Request $request, Callback $callback, CallbackQueue $queue): RedirectResponse
    {
        $this->authorize('view callbacks');
        $this->authorize('edit callbacks');
        $data = $request->validate([
            'outcome' => ['required', Rule::in(Callback::ATTEMPT_OUTCOMES)],
            'note' => ['nullable', 'string', 'max:1000'],
            'attempted_at' => ['nullable', 'date'],
            'neutral_voicemail_confirmed' => ['sometimes', 'boolean'],
        ]);
        $queue->recordAttempt($callback, $this->user($request), $data);

        return back()->with('success', 'Callback attempt recorded.');
    }

    public function update(Request $request, Callback $callback, CallbackQueue $queue): RedirectResponse
    {
        $this->authorize('view callbacks');
        $this->authorize('edit callbacks');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'outcome' => ['required', Rule::in(Callback::OUTCOMES)],
            'outcome_note' => ['nullable', 'string', 'max:1000'],
        ]);
        $queue->complete($callback, $this->user($request), $data);

        return back()->with('success', 'Callback completed.');
    }

    public function cancel(Request $request, Callback $callback, CallbackQueue $queue): RedirectResponse
    {
        $this->authorize('view callbacks');
        $this->authorize('edit callbacks');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'outcome_note' => ['required', 'string', 'max:1000'],
        ]);
        $queue->cancel($callback, $this->user($request), $data);

        return back()->with('success', 'Callback cancelled and retained in history.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
