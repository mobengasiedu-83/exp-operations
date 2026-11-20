<?php

namespace App\Http\Controllers;

use App\Models\Certification;
use App\Models\TrainingAssessment;
use App\Models\TrainingModule;
use App\Models\User;
use App\Services\Training;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TrainingController extends Controller
{
    public function index(Request $request, Training $training): Response
    {
        $this->authorize('view training');
        $user = $this->user($request);
        $canManage = $user->can('manage training');
        $canAuthorise = $user->can('authorise live calls');
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['curriculum', 'assessment', 'certification'])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $tab = $filters['tab'] ?? 'curriculum';
        $term = trim((string) ($filters['q'] ?? ''));
        $candidate = isset($filters['user_id'])
            ? User::query()->find((int) $filters['user_id'])
            : $user;
        $modules = TrainingModule::query()
            ->with('author:id,name')
            ->orderBy('day')
            ->orderByDesc('id')
            ->get()
            ->map(fn (TrainingModule $module): array => [
                'id' => $module->id,
                'day' => $module->day,
                'title' => $module->title,
                'objectives' => $module->objectives,
                'content' => $module->content,
                'requires_role_play' => $module->requires_role_play,
                'pass_mark' => $module->pass_mark,
                'status' => $module->status,
                'author' => $module->author?->name,
                'published_at' => $module->published_at?->toIso8601String(),
                'revision' => $module->revision,
            ])
            ->values();
        $assessments = TrainingAssessment::query()
            ->with(['module:id,day,title', 'candidate:id,name', 'assessor:id,name'])
            ->when($term !== '', fn (Builder $query) => $query->whereHas(
                'candidate',
                fn (Builder $candidateQuery) => $candidateQuery->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'),
            ))
            ->orderByDesc('assessed_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'assessment_page')
            ->withQueryString()
            ->through(fn (TrainingAssessment $assessment): array => [
                'id' => $assessment->id,
                'day' => $assessment->module?->day,
                'module' => $assessment->module?->title,
                'candidate' => $assessment->candidate?->name,
                'kind' => $assessment->kind,
                'score' => $assessment->score,
                'pass_mark' => $assessment->pass_mark,
                'result' => $assessment->result,
                'assessor' => $assessment->assessor?->name,
                'assessed_at' => $assessment->assessed_at?->toIso8601String(),
            ]);
        $certifications = Certification::query()
            ->with(['user:id,name', 'authoriser:id,name', 'revoker:id,name'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'certification_page')
            ->withQueryString()
            ->through(fn (Certification $certification): array => [
                'id' => $certification->id,
                'candidate' => $certification->user?->name,
                'status' => $certification->status,
                'effective_status' => $certification->status === 'revoked'
                    ? 'revoked'
                    : ($certification->hasExpired() ? 'expired' : 'active'),
                'issued_at' => $certification->issued_at?->toDateString(),
                'expires_at' => $certification->expires_at?->toDateString(),
                'authoriser' => $certification->authoriser?->name,
                'reference' => $certification->reference,
                'revoked_at' => $certification->revoked_at?->toIso8601String(),
                'revoked_by' => $certification->revoker?->name,
                'revocation_reason' => $certification->revocation_reason,
                'revision' => $certification->revision,
            ]);

        return Inertia::render('Training/Index', [
            'modules' => $modules,
            'assessments' => $assessments,
            'certifications' => $certifications,
            'progress' => $candidate instanceof User ? $training->curriculumProgress($candidate) : [],
            'candidate' => $candidate instanceof User ? ['id' => $candidate->id, 'name' => $candidate->name] : null,
            'candidates' => User::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $person): array => ['id' => $person->id, 'name' => $person->name])->values(),
            'authorisedForLiveCalls' => $training->isAuthorisedForLiveCalls($user),
            'myCertification' => $training->activeCertification($user)?->only(['id', 'reference', 'issued_at', 'expires_at']),
            'days' => Training::DAYS,
            'kinds' => Training::ASSESSMENT_KINDS,
            'statuses' => TrainingModule::STATUSES,
            'filters' => ['tab' => $tab, 'user_id' => $candidate?->id, 'q' => $term],
            'canManage' => $canManage,
            'canAuthorise' => $canAuthorise,
            'defaultPassMark' => Training::DEFAULT_PASS_MARK,
            'asOf' => now()->toIso8601String(),
        ]);
    }

    public function storeModule(Request $request, Training $training): RedirectResponse
    {
        $this->authorize('view training');
        $this->authorize('manage training');
        $training->createModule($this->user($request), $this->moduleData($request));

        return back()->with('success', 'Draft curriculum module created.');
    }

    public function updateModule(Request $request, TrainingModule $module, Training $training): RedirectResponse
    {
        $this->authorize('view training');
        $this->authorize('manage training');
        $training->updateModule($this->user($request), $module, $this->moduleData($request) + [
            'revision' => $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'],
        ]);

        return back()->with('success', 'Draft curriculum module updated.');
    }

    public function publishModule(Request $request, TrainingModule $module, Training $training): RedirectResponse
    {
        $this->authorize('view training');
        $this->authorize('manage training');
        $training->publishModule($this->user($request), $module);

        return back()->with('success', 'Curriculum module published and available for assessment.');
    }

    public function retireModule(Request $request, TrainingModule $module, Training $training): RedirectResponse
    {
        $this->authorize('view training');
        $this->authorize('manage training');
        $training->retireModule($this->user($request), $module);

        return back()->with('success', 'Curriculum module retired.');
    }

    public function storeAssessment(Request $request, Training $training): RedirectResponse
    {
        $this->authorize('view training');
        $this->authorize('manage training');
        $data = $request->validate([
            'training_module_id' => ['required', 'integer', 'exists:training_modules,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'kind' => ['required', Rule::in(Training::ASSESSMENT_KINDS)],
            'score' => ['required', 'integer', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $training->recordAssessment($this->user($request), $data);

        return back()->with('success', 'Assessment result recorded against the curriculum day.');
    }

    public function storeCertification(Request $request, Training $training): RedirectResponse
    {
        $this->authorize('view training');
        $this->authorize('authorise live calls');
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'reference' => ['required', 'string', 'max:255'],
            'issued_at' => ['nullable', 'date', 'before_or_equal:today'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'attested' => ['required', 'accepted'],
        ]);
        $candidate = User::query()->findOrFail((int) $data['user_id']);
        $training->certify($this->user($request), $candidate, $data);

        return back()->with('success', 'Live-call certification issued with the curriculum snapshot.');
    }

    public function revokeCertification(Request $request, Certification $certification, Training $training): RedirectResponse
    {
        $this->authorize('view training');
        $this->authorize('authorise live calls');
        $data = $request->validate(['revocation_reason' => ['required', 'string', 'max:2000']]);
        $training->revoke($this->user($request), $certification, $data['revocation_reason']);

        return back()->with('success', 'Certification revoked. The operator can no longer take live calls.');
    }

    /** @return array<string, mixed> */
    private function moduleData(Request $request): array
    {
        return $request->validate([
            'day' => ['required', 'integer', Rule::in(Training::DAYS)],
            'title' => ['required', 'string', 'max:255'],
            'objectives' => ['required', 'string', 'max:3000'],
            'content' => ['required', 'string', 'max:20000'],
            'requires_role_play' => ['required', 'boolean'],
            'pass_mark' => ['required', 'integer', 'between:50,100'],
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
