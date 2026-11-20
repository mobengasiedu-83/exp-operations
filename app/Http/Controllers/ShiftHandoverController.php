<?php

namespace App\Http\Controllers;

use App\Models\CallerCase;
use App\Models\ShiftHandover as ShiftHandoverRecord;
use App\Models\User;
use App\Services\ShiftHandover;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShiftHandoverController extends Controller
{
    public function assignments(Request $request): Response
    {
        $this->authorize('view cases');
        $user = $this->user($request);
        $filters = $request->validate([
            'owner' => ['nullable', 'integer', 'min:1'],
            'mine' => ['nullable', 'boolean'],
        ]);
        $ownerId = $request->boolean('mine') ? $user->id : ($filters['owner'] ?? null);

        return Inertia::render('Cases/Assignments', [
            'cases' => CallerCase::query()->visibleTo($user)->where('status', '!=', 'closed')
                ->when($ownerId, fn ($query, int $id) => $query->where('assigned_to', $id))
                ->with(['assignments' => fn ($query) => $query->active()->with('assignee:id,name')->orderByDesc('id')])
                ->orderBy('priority')->orderBy('id')->paginate(25)->withQueryString()
                ->through(function (CallerCase $case): array {
                    $assignment = $case->assignments->first();

                    return [
                        'id' => $case->id,
                        'priority' => $case->priority,
                        'status' => $case->status,
                        'owner_id' => $case->assigned_to,
                        'owner' => $assignment?->assignee?->name,
                        'next_action' => $assignment?->next_action,
                        'due_at' => $assignment?->due_at?->toIso8601String(),
                        'overdue' => $assignment?->isDue() ?? false,
                    ];
                }),
            'filters' => [...$filters, 'mine' => $request->boolean('mine')],
            'owners' => User::query()->permission('view cases')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canEdit' => $user->can('edit cases'),
        ]);
    }

    public function handover(Request $request, ShiftHandover $service): Response
    {
        $this->authorize('view cases');
        $user = $this->user($request);
        $unresolved = $service->unresolved($user);

        return Inertia::render('Cases/Handover', [
            'unresolved' => $unresolved->map(function (CallerCase $case): array {
                $assignment = $case->assignments->first();

                return [
                    'id' => $case->id,
                    'priority' => $case->priority,
                    'status' => $case->status,
                    'owner_id' => $case->assigned_to,
                    'next_action' => $assignment?->next_action,
                    'due_at' => $assignment?->due_at?->toIso8601String(),
                    'overdue' => $assignment?->isDue() ?? false,
                ];
            })->values()->all(),
            'ready' => $unresolved->every(fn (CallerCase $case): bool => $case->assignments->isNotEmpty()),
            'handovers' => ShiftHandoverRecord::query()
                ->with(['outgoing:id,name', 'incoming:id,name'])
                ->when(! $user->can('view all cases'), fn ($query) => $query->where('outgoing_user_id', $user->id))
                ->orderByDesc('shift_ended_at')->orderByDesc('id')->limit(20)->get()
                ->map(fn (ShiftHandoverRecord $handover): array => [
                    'id' => $handover->id,
                    'outgoing' => $handover->outgoing?->name,
                    'incoming' => $handover->incoming?->name,
                    'shift_started_at' => $handover->shift_started_at?->toIso8601String(),
                    'shift_ended_at' => $handover->shift_ended_at?->toIso8601String(),
                    'unresolved_cases' => $handover->unresolved_cases ?? [],
                    'notes' => $handover->notes,
                    'acknowledged_at' => $handover->acknowledged_at?->toIso8601String(),
                ])->values()->all(),
            'owners' => User::query()->permission('view cases')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'canEdit' => $user->can('edit cases'),
            'asOf' => now()->toIso8601String(),
        ]);
    }

    public function storeAssignment(Request $request, CallerCase $case, ShiftHandover $service): RedirectResponse
    {
        $this->authorize('view cases');
        $this->authorize('edit cases');
        $data = $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'next_action' => ['required', 'string', 'max:1000'],
            'due_at' => ['required', 'date'],
        ]);
        $service->assign($case, $this->user($request), $data);

        return back()->with('success', 'Case owner and next action recorded.');
    }

    public function storeHandover(Request $request, ShiftHandover $service): RedirectResponse
    {
        $this->authorize('view cases');
        $this->authorize('edit cases');
        $data = $request->validate([
            'shift_started_at' => ['required', 'date'],
            'shift_ended_at' => ['required', 'date'],
            'incoming_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'acknowledged' => ['sometimes', 'boolean'],
        ]);
        $service->handover($this->user($request), $data);

        return back()->with('success', 'Shift handover recorded with owner and due action for every unresolved case.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
