<?php

namespace App\Http\Controllers;

use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\IncidentEvidenceAccess;
use App\Models\User;
use App\Services\IncidentRegister;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class IncidentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view incidents');
        $user = $this->user($request);
        $canManage = $user->can('manage incidents');
        $canClose = $user->can('close incidents');
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'safety', 'privacy', 'complaints', 'safeguarding'])],
            'status' => ['nullable', Rule::in(Incident::STATUSES)],
            'severity' => ['nullable', Rule::in(Incident::SEVERITIES)],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $tab = $filters['tab'] ?? 'all';
        $category = $tab === 'all' ? null : ($tab === 'complaints' ? 'complaint' : $tab);
        $term = trim((string) ($filters['q'] ?? ''));
        $incidents = Incident::query()
            ->with(['reporter:id,name', 'assignee:id,name'])
            ->when($category, fn (Builder $query, string $value) => $query->where('category', $value))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['severity'] ?? null, fn (Builder $query, string $severity) => $query->where('severity', $severity))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $inner->where('reference', 'like', $like)->orWhere('summary', 'like', $like);
            }))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (Incident $incident): array => [
                'id' => $incident->id,
                'reference' => $incident->reference,
                'category' => $incident->category,
                'severity' => $incident->severity,
                'status' => $incident->status,
                'summary' => $incident->summary,
                'case_id' => $incident->case_id,
                'reporter' => $incident->reporter?->name,
                'assigned_to' => $incident->assignee?->name,
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'escalated_at' => $incident->escalated_at?->toIso8601String(),
                'escalation_due_at' => $incident->escalation_due_at?->toIso8601String(),
                'overdue' => $incident->escalation_due_at !== null
                    && $incident->escalation_due_at->isPast()
                    && ! in_array($incident->status, ['resolved', 'closed'], true),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'closed_at' => $incident->closed_at?->toIso8601String(),
                'revision' => $incident->revision,
                'detail' => $canManage ? [
                    'description' => $incident->description,
                    'immediate_action' => $incident->immediate_action,
                    'escalation_note' => $incident->escalation_note,
                    'resolution_summary' => $incident->resolution_summary,
                ] : null,
            ]);

        $actions = CorrectiveAction::query()
            ->with(['incident:id,reference,status', 'owner:id,name', 'verifier:id,name'])
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderBy('due_at')
            ->paginate(15, ['*'], 'action_page')
            ->withQueryString()
            ->through(fn (CorrectiveAction $action): array => [
                'id' => $action->id,
                'incident_id' => $action->incident_id,
                'incident' => $action->incident?->reference,
                'incident_status' => $action->incident?->status,
                'owner' => $action->owner?->name,
                'description' => $canManage ? $action->description : null,
                'due_at' => $action->due_at?->toIso8601String(),
                'overdue' => $action->status === 'open' && $action->due_at !== null && $action->due_at->isPast(),
                'status' => $action->status,
                'completed_at' => $action->completed_at?->toIso8601String(),
                'verified_by' => $action->verifier?->name,
                'verified_at' => $action->verified_at?->toIso8601String(),
            ]);
        $evidence = $canManage ? IncidentEvidenceAccess::query()
            ->with(['incident:id,reference', 'user:id,name'])
            ->orderByDesc('accessed_at')
            ->paginate(15, ['*'], 'evidence_page')
            ->withQueryString()
            ->through(fn (IncidentEvidenceAccess $access): array => [
                'id' => $access->id,
                'incident' => $access->incident?->reference,
                'user' => $access->user?->name,
                'purpose' => $access->purpose,
                'accessed_at' => $access->accessed_at?->toIso8601String(),
            ]) : null;
        $counts = [];
        foreach (Incident::CATEGORIES as $incidentCategory) {
            $counts[$incidentCategory] = Incident::query()
                ->where('category', $incidentCategory)
                ->whereIn('status', ['open', 'investigating'])
                ->count();
        }

        return Inertia::render('Incidents/Index', [
            'incidents' => $incidents,
            'actions' => $actions,
            'evidence' => $evidence,
            'counts' => $counts,
            'tabs' => ['all', 'safety', 'privacy', 'complaints', 'safeguarding'],
            'categories' => Incident::CATEGORIES,
            'severities' => Incident::SEVERITIES,
            'statuses' => Incident::STATUSES,
            'slaHours' => IncidentRegister::SLA_HOURS,
            'owners' => User::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $owner): array => ['id' => $owner->id, 'name' => $owner->name])->values(),
            'filters' => ['tab' => $tab, 'status' => $filters['status'] ?? null, 'severity' => $filters['severity'] ?? null, 'q' => $term],
            'canManage' => $canManage,
            'canClose' => $canClose,
            'canReport' => $user->can('report incidents'),
            'asOf' => now()->toIso8601String(),
        ]);
    }

    public function store(Request $request, IncidentRegister $incidents): RedirectResponse
    {
        $this->authorize('view incidents');
        $this->authorize('report incidents');
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:80'],
            'category' => ['required', Rule::in(Incident::CATEGORIES)],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)],
            'case_id' => ['nullable', 'integer', 'exists:cases,id'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'summary' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'immediate_action' => ['nullable', 'string', 'max:5000'],
        ]);
        $incidents->register($this->user($request), $data);

        return back()->with('success', 'Incident recorded. Escalate it to a named owner within the severity SLA.');
    }

    public function escalate(Request $request, Incident $incident, IncidentRegister $incidents): RedirectResponse
    {
        $this->authorize('view incidents');
        $this->authorize('manage incidents');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'severity' => ['required', Rule::in(Incident::SEVERITIES)],
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $incidents->escalate($this->user($request), $incident, $data);

        return back()->with('success', 'Incident escalated with an SLA deadline and named owner.');
    }

    public function evidence(Request $request, Incident $incident, IncidentRegister $incidents): RedirectResponse
    {
        $this->authorize('view incidents');
        $this->authorize('manage incidents');
        $data = $request->validate([
            'purpose' => ['required', 'string', 'min:5', 'max:255'],
            'attested' => ['required', 'accepted'],
        ]);
        $incidents->recordEvidenceAccess($this->user($request), $incident, $data['purpose']);

        return back()->with('success', 'Evidence access recorded in the incident audit trail.');
    }

    public function storeAction(Request $request, Incident $incident, IncidentRegister $incidents): RedirectResponse
    {
        $this->authorize('view incidents');
        $this->authorize('manage incidents');
        $data = $request->validate([
            'description' => ['required', 'string', 'max:3000'],
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'due_at' => ['required', 'date'],
        ]);
        $incidents->addAction($this->user($request), $incident, $data);

        return back()->with('success', 'Corrective action assigned to a named owner.');
    }

    public function completeAction(Request $request, CorrectiveAction $action, IncidentRegister $incidents): RedirectResponse
    {
        $this->authorize('view incidents');
        $user = $this->user($request);
        abort_unless($user->can('manage incidents') || $action->owner_id === $user->id, 403);
        $data = $request->validate(['completion_note' => ['required', 'string', 'max:2000']]);
        $incidents->completeAction($user, $action, $data['completion_note']);

        return back()->with('success', 'Corrective action completed; independent verification is required.');
    }

    public function verifyAction(Request $request, CorrectiveAction $action, IncidentRegister $incidents): RedirectResponse
    {
        $this->authorize('view incidents');
        $this->authorize('manage incidents');
        $data = $request->validate(['verification_note' => ['required', 'string', 'max:2000']]);
        $incidents->verifyAction($this->user($request), $action, $data['verification_note']);

        return back()->with('success', 'Corrective action verified as effective.');
    }

    public function resolve(Request $request, Incident $incident, IncidentRegister $incidents): RedirectResponse
    {
        $this->authorize('view incidents');
        $this->authorize('manage incidents');
        $data = $request->validate(['resolution_summary' => ['required', 'string', 'min:10', 'max:5000']]);
        $incidents->resolve($this->user($request), $incident, $data['resolution_summary']);

        return back()->with('success', 'Incident resolved with corrective evidence recorded.');
    }

    public function close(Request $request, Incident $incident, IncidentRegister $incidents): RedirectResponse
    {
        $this->authorize('view incidents');
        $this->authorize('close incidents');
        $request->validate(['attested' => ['required', 'accepted']]);
        $incidents->close($this->user($request), $incident);

        return back()->with('success', 'Incident closed and retained in the audit record.');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
