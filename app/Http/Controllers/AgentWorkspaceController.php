<?php

namespace App\Http\Controllers;

use App\Models\CallerCase;
use App\Models\Interaction;
use App\Models\ScreeningDraft;
use App\Models\ScreeningProtocol;
use App\Models\User;
use App\Services\AgentCallContext;
use App\Services\CallLifecycle;
use App\Services\CaseWorkflow;
use App\Services\DentistDirectory;
use App\Services\Training;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AgentWorkspaceController extends Controller
{
    public function index(Request $request, Training $training, AgentCallContext $calls): Response
    {
        $this->authorize('view cases');
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['open', 'clinical_review', 'closed'])],
            'priority' => ['nullable', Rule::in(CaseWorkflow::PRIORITIES)],
            'mine' => ['nullable', 'boolean'],
        ]);
        $user = $this->user($request);

        return Inertia::render('Workspace/Index', [
            'cases' => CallerCase::query()->visibleTo($user)
                ->whereHas('interaction', fn ($query) => $query->agentSelected())
                ->when($request->boolean('mine'), fn ($query) => $query->where('assigned_to', $user->id))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($filters['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
                ->orderByDesc('updated_at')->orderByDesc('id')
                ->paginate(25, ['id', 'interaction_id', 'assigned_to', 'status', 'priority', 'contact_kind', 'disposition', 'updated_at'])->withQueryString(),
            'callContext' => $user->can('edit cases') ? $calls->build(null, $user) : null,
            'filters' => $filters,
            'canEdit' => $user->can('edit cases'),
            'canProtocols' => $user->can('manage screening protocols'),
            'canOpenCase' => $this->canOpenCase($user, $training),
        ]);
    }

    public function store(Request $request, Training $training, AgentCallContext $calls): RedirectResponse
    {
        $this->authorize('view cases');
        $this->authorize('edit cases');
        abort_unless(
            $this->canOpenCase($this->user($request), $training),
            403,
            'Opening a case requires the create cases permission.',
        );
        $request->validate([
            'interaction_id' => ['required', 'integer', 'exists:interactions,id'],
            'association_confirmed' => ['required', 'accepted'],
            'ambiguity_acknowledged' => ['sometimes', 'boolean'],
        ]);
        $case = DB::transaction(function () use ($request, $calls): CallerCase {
            $interaction = Interaction::query()->lockForUpdate()->findOrFail($request->integer('interaction_id'));
            abort_unless(Interaction::query()->whereKey($interaction->id)->agentSelected()->exists(), 422, 'Only calls with a recorded IVR option 3 selection can have an agent case.');
            $existing = CallerCase::query()->where('interaction_id', $interaction->id)->first();
            if ($existing !== null) {
                abort_unless(CallerCase::query()->visibleTo($this->user($request))->whereKey($existing->id)->exists(), 404);

                return $existing;
            }

            $ambiguity = $calls->ambiguityFor($interaction);
            if ($ambiguity['requires_confirmation'] && ! $request->boolean('ambiguity_acknowledged')) {
                throw ValidationException::withMessages([
                    'ambiguity_acknowledged' => 'More than one call is active or the PBX evidence conflicts. Confirm the exact interaction before opening this case.',
                ]);
            }

            $case = CallerCase::query()->create([
                'interaction_id' => $interaction->id, 'assigned_to' => $this->user($request)->id,
                'association_verified_by' => $this->user($request)->id, 'association_verified_at' => now(),
            ]);
            activity('case-workflow')->causedBy($this->user($request))->performedOn($case)
                ->withProperties(['interaction_id' => $interaction->id, 'association_ambiguity' => $ambiguity['reasons']])
                ->log('Case association verified');

            return $case;
        });

        return to_route('workspace.show', $case);
    }

    public function show(Request $request, CallerCase $case, AgentCallContext $calls, CaseWorkflow $workflow, CallLifecycle $lifecycle): Response
    {
        $this->authorize('view cases');
        $this->visible($request, $case);
        $user = $this->user($request);
        $consent = $case->consents()->latest('id')->first();
        $screenings = $case->screenings()->with('answers')->orderByDesc('id')->get();
        $protocol = $workflow->recordProtocol($case);
        $protocols = ScreeningProtocol::query()->with('questions')->whereIn('id', $screenings->pluck('screening_protocol_id'))->get();
        $interaction = $case->interaction;
        $draft = $protocol === null ? null : $this->draftFor($case, $protocol);

        return Inertia::render('Workspace/Show', [
            'callerCase' => $case,
            'interaction' => $interaction?->only(['id', 'correlation_key', 'status', 'started_at', 'ended_at', 'is_exp']),
            'callContext' => $calls->build($interaction, $user),
            'lifecycle' => $lifecycle->for($interaction),
            'protocol' => $protocol,
            'historyProtocols' => $protocols,
            'draft' => $draft === null ? null : $this->draftPayload($draft),
            'consent' => $consent,
            'consentHistory' => $case->consents()->latest('id')->get(),
            'screenings' => $screenings,
            'notes' => $case->notes()->latest('id')->get(),
            'dispositions' => collect(CaseWorkflow::DISPOSITIONS)->map(fn (array $row, string $code): array => ['code' => $code, ...$row])->values(),
            'canEdit' => $user->can('edit cases') && $case->status !== 'closed',
            'canWithdrawConsent' => $user->can('edit cases') && $consent?->status === 'granted',
            'canReview' => $user->can('review cases'),
            'canControl' => $user->can('control calls') && $case->status !== 'closed'
                && ($case->assigned_to === $user->id || $user->hasRole('Super Administrator')),
            'selectableDentists' => $user->can('view dentists')
                ? app(DentistDirectory::class)->selectable()
                : null,
        ]);
    }

    /**
     * Autosave questionnaire answers. Confirmed screening records stay a separate
     * explicit action so a draft can never be mistaken for a completed screen.
     */
    public function saveDraft(Request $request, CallerCase $case, CaseWorkflow $workflow): JsonResponse
    {
        $this->authorize('view cases');
        $this->authorize('edit cases');
        $this->visible($request, $case);
        $data = $request->validate([
            'protocol_id' => ['required', 'integer', 'exists:screening_protocols,id'],
            'priority' => ['required', Rule::in(CaseWorkflow::PRIORITIES)],
            'revision' => ['nullable', 'integer', 'min:1'],
            'answers' => ['required', 'array', 'list', 'max:100'],
            'answers.*' => ['required', 'array:question_id,answer'],
            'answers.*.question_id' => ['required', 'integer', 'distinct'],
            'answers.*.answer' => ['present', 'nullable', 'string', 'max:2000'],
        ]);
        $draft = $workflow->saveDraft($case, $this->user($request), $data);

        return response()->json(['draft' => $this->draftPayload($draft)]);
    }

    public function update(Request $request, CallerCase $case, CaseWorkflow $workflow): RedirectResponse
    {
        $this->authorize('view cases');
        $this->authorize('edit cases');
        $this->visible($request, $case);
        $base = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'operation' => ['required', Rule::in(['consent', 'profile', 'screening', 'note', 'close'])],
        ]);
        $rules = match ($base['operation']) {
            'consent' => [
                'protocol_id' => ['required', 'integer', 'exists:screening_protocols,id'],
                'status' => ['required', Rule::in(['granted', 'declined', 'unable', 'withdrawn'])],
                'callback_allowed' => ['required', 'boolean'],
            ],
            'profile' => [
                'preferred_name' => ['nullable', 'string', 'max:100'],
                'callback_number' => ['nullable', 'string', 'max:40'],
                'age_band' => ['required', Rule::in(['adult', 'child', 'unknown'])],
                'guardian_name' => ['nullable', 'string', 'max:100'],
                'guardian_relationship' => ['nullable', 'string', 'max:100'],
                'guardian_confirmed' => ['required', 'boolean'],
                'contact_kind' => ['required', Rule::in(['genuine', 'unclear', 'abandoned', 'silent', 'nuisance', 'ultra_short'])],
                'concern_type' => ['required', Rule::in(['clinical', 'service_information'])],
                'concern' => ['nullable', 'string', 'max:2000'],
                'onset' => ['nullable', 'string', 'max:500'], 'impact' => ['nullable', 'string', 'max:1000'],
            ],
            'screening' => [
                'protocol_id' => ['required', 'integer', 'exists:screening_protocols,id'],
                'priority' => ['required', Rule::in(CaseWorkflow::PRIORITIES)],
                'answers' => ['required', 'array', 'list', 'min:1', 'max:50'],
                'answers.*' => ['required', 'array:question_id,answer'],
                'answers.*.question_id' => ['required', 'integer', 'distinct'],
                'answers.*.answer' => ['required', 'string', 'max:2000'],
                'review_reason' => ['nullable', 'string', 'max:2000'],
            ],
            'note' => ['body' => ['required', 'string', 'max:2000']],
            'close' => [
                'disposition' => ['required', Rule::in(array_keys(CaseWorkflow::DISPOSITIONS))],
                'next_step' => ['required', 'string', 'max:2000'],
                'emergency_action_confirmed' => ['sometimes', 'boolean'], 'supervisor_notified' => ['sometimes', 'boolean'], 'faq_confirmed' => ['sometimes', 'boolean'],
            ],
            default => throw new \InvalidArgumentException('Unknown case operation.'),
        };
        $data = $request->validate($rules);
        $workflow->change($case, $this->user($request), [...$base, ...$data], $base['operation']);

        return back()->with('success', 'Case record saved.');
    }

    private function draftFor(CallerCase $case, ScreeningProtocol $protocol): ?ScreeningDraft
    {
        return ScreeningDraft::query()
            ->where('case_id', $case->id)
            ->where('screening_protocol_id', $protocol->id)
            ->first();
    }

    /** @return array<string, mixed> */
    private function draftPayload(ScreeningDraft $draft): array
    {
        return [
            'id' => $draft->id,
            'screening_protocol_id' => $draft->screening_protocol_id,
            'priority' => $draft->priority,
            'revision' => $draft->revision,
            'recorded_by' => $draft->recorded_by,
            'draft_saved_at' => $draft->draft_saved_at?->toIso8601String(),
            'answers' => $draft->answers,
        ];
    }

    /**
     * Agents open cases with the `create cases` permission. A current live-call
     * certification still authorises creation for operators who hold one.
     */
    private function canOpenCase(User $user, Training $training): bool
    {
        return $user->can('create cases') || $training->isAuthorisedForLiveCalls($user);
    }

    private function visible(Request $request, CallerCase $case): void
    {
        abort_unless(CallerCase::query()->visibleTo($this->user($request))
            ->whereHas('interaction', fn ($query) => $query->agentSelected())
            ->whereKey($case->id)->exists(), 404);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
