<?php

namespace App\Http\Controllers;

use App\Models\CallerCase;
use App\Models\QaCalibration;
use App\Models\QaReview;
use App\Models\User;
use App\Services\QualityAssurance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class QualityAssuranceController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('view quality assurance');
        $user = $this->user($request);
        $canManage = $user->can('manage quality assurance');
        $canApprove = $user->can('approve quality assurance');
        $filters = $request->validate([
            'tab' => ['nullable', Rule::in(['reviews', 'scorecards', 'calibration', 'critical_fails'])],
            'q' => ['nullable', 'string', 'max:100'],
            'outcome' => ['nullable', Rule::in(QaReview::OUTCOMES)],
        ]);
        $tab = $filters['tab'] ?? 'reviews';
        $term = trim((string) ($filters['q'] ?? ''));
        $reviews = QaReview::query()
            ->with(['agent:id,name', 'reviewer:id,name', 'scores'])
            ->when($tab === 'critical_fails', fn (Builder $query) => $query->where('critical_fail', true))
            ->when($filters['outcome'] ?? null, fn (Builder $query, string $outcome) => $query->where('outcome', $outcome))
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $inner) use ($term): void {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $inner->where('reference', 'like', $like)
                    ->orWhereHas('agent', fn (Builder $agent) => $agent->where('name', 'like', $like));
            }))
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString()
            ->through(fn (QaReview $review): array => $this->reviewRow($review));
        $calibrations = QaCalibration::query()
            ->with(['review:id,reference,total,reviewer_id', 'reviewer:id,name', 'agreedBy:id,name'])
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'calibration_page')
            ->withQueryString()
            ->through(fn (QaCalibration $calibration): array => [
                'id' => $calibration->id,
                'review_id' => $calibration->qa_review_id,
                'review' => $calibration->review?->reference,
                'primary_total' => $calibration->review?->total,
                'reviewer' => $calibration->reviewer?->name,
                'secondary_total' => $calibration->secondary_total,
                'variance' => $calibration->variance,
                'agreed_total' => $calibration->agreed_total,
                'status' => $calibration->status,
                'agreed_by' => $calibration->agreedBy?->name,
                'agreed_at' => $calibration->agreed_at?->toIso8601String(),
                'note' => $calibration->note,
            ]);

        return Inertia::render('QualityAssurance/Index', [
            'reviews' => $reviews,
            'calibrations' => $calibrations,
            'scorecard' => $this->scorecard(),
            'criteria' => collect(QualityAssurance::CRITERIA)->map(fn (array $criterion, string $key): array => ['criterion' => $key, ...$criterion])->values(),
            'ratings' => QualityAssurance::RATINGS,
            'outcomes' => QaReview::OUTCOMES,
            'statuses' => QaReview::STATUSES,
            'agents' => User::query()->active()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $agent): array => ['id' => $agent->id, 'name' => $agent->name])->values(),
            'cases' => CallerCase::query()->latest('id')->limit(50)->get(['id', 'priority'])
                ->map(fn (CallerCase $case): array => ['id' => $case->id, 'priority' => $case->priority])->values(),
            'filters' => ['tab' => $tab, 'q' => $term, 'outcome' => $filters['outcome'] ?? null],
            'canManage' => $canManage,
            'canApprove' => $canApprove,
            'passMark' => QualityAssurance::PASS_MARK,
            'tolerance' => QualityAssurance::CALIBRATION_TOLERANCE,
            'asOf' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    private function reviewRow(QaReview $review): array
    {
        $scores = [];
        foreach ($review->scores as $score) {
            $scores[] = [
                'criterion' => $score->criterion,
                'rating' => $score->rating,
                'weight' => $score->weight,
                'points' => $score->points,
                'critical' => $score->critical,
                'note' => $score->note,
            ];
        }

        return [
            'id' => $review->id,
            'reference' => $review->reference,
            'agent' => $review->agent?->name,
            'agent_id' => $review->agent_id,
            'reviewer' => $review->reviewer?->name,
            'case_id' => $review->case_id,
            'reviewed_at' => $review->reviewed_at?->toDateString(),
            'status' => $review->status,
            'total' => $review->total,
            'outcome' => $review->outcome,
            'critical_fail' => $review->critical_fail,
            'critical_note' => $review->critical_note,
            'notes' => $review->notes,
            'coaching_required' => $review->coaching_required,
            'coaching_due_at' => $review->coaching_due_at?->toIso8601String(),
            'coaching_completed_at' => $review->coaching_completed_at?->toIso8601String(),
            'finalised_at' => $review->finalised_at?->toIso8601String(),
            'revision' => $review->revision,
            'scores' => $scores,
        ];
    }

    /**
     * @return list<array{criterion: string, label: string, weight: int, critical: bool, average: int|null, count: int}>
     */
    private function scorecard(): array
    {
        $points = [];
        $finalised = QaReview::query()->finalised()->with('scores')->orderByDesc('reviewed_at')->limit(200)->get();
        foreach ($finalised as $review) {
            foreach ($review->scores as $score) {
                $points[$score->criterion][] = $score->points;
            }
        }
        $rows = [];
        foreach (QualityAssurance::CRITERIA as $key => $criterion) {
            $values = $points[$key] ?? [];
            $rows[] = [
                'criterion' => $key,
                'label' => $criterion['label'],
                'weight' => $criterion['weight'],
                'critical' => $criterion['critical'],
                'average' => $values === [] ? null : (int) round(array_sum($values) / count($values)),
                'count' => count($values),
            ];
        }

        return $rows;
    }

    public function store(Request $request, QualityAssurance $qualityAssurance): RedirectResponse
    {
        $this->authorize('view quality assurance');
        $this->authorize('manage quality assurance');
        $qualityAssurance->create($this->user($request), $this->reviewData($request));

        return back()->with('success', 'Draft QA review saved with the weighted scorecard.');
    }

    public function update(Request $request, QaReview $review, QualityAssurance $qualityAssurance): RedirectResponse
    {
        $this->authorize('view quality assurance');
        $this->authorize('manage quality assurance');
        $qualityAssurance->update($this->user($request), $review, [
            ...$this->reviewData($request),
            'revision' => $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'],
        ]);

        return back()->with('success', 'Draft QA review updated.');
    }

    public function finalise(Request $request, QaReview $review, QualityAssurance $qualityAssurance): RedirectResponse
    {
        $this->authorize('view quality assurance');
        $this->authorize('manage quality assurance');
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'coaching_due_at' => ['nullable', 'date'],
        ]);
        $qualityAssurance->finalise($this->user($request), $review, $data);

        return back()->with('success', 'QA review finalised. Critical failures raise an immediate escalated incident.');
    }

    public function coaching(Request $request, QaReview $review, QualityAssurance $qualityAssurance): RedirectResponse
    {
        $this->authorize('view quality assurance');
        $this->authorize('manage quality assurance');
        $data = $request->validate(['coaching_note' => ['required', 'string', 'max:2000']]);
        $qualityAssurance->recordCoaching($this->user($request), $review, $data['coaching_note']);

        return back()->with('success', 'Coaching conversation and agreed actions recorded.');
    }

    public function storeCalibration(Request $request, QaReview $review, QualityAssurance $qualityAssurance): RedirectResponse
    {
        $this->authorize('view quality assurance');
        $this->authorize('manage quality assurance');
        $data = $request->validate([
            'secondary_total' => ['required', 'integer', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $qualityAssurance->addCalibration($this->user($request), $review, $data);

        return back()->with('success', 'Second reviewer score recorded for calibration.');
    }

    public function agreeCalibration(Request $request, QaCalibration $calibration, QualityAssurance $qualityAssurance): RedirectResponse
    {
        $this->authorize('view quality assurance');
        $this->authorize('approve quality assurance');
        $data = $request->validate([
            'agreed_total' => ['required', 'integer', 'between:0,100'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $qualityAssurance->agreeCalibration($this->user($request), $calibration, $data);

        return back()->with('success', 'Calibration agreed and recorded against the review.');
    }

    /** @return array<string, mixed> */
    private function reviewData(Request $request): array
    {
        return $request->validate([
            'reference' => ['required', 'string', 'max:80'],
            'agent_id' => ['required', 'integer', 'exists:users,id'],
            'case_id' => ['nullable', 'integer', 'exists:cases,id'],
            'reviewed_at' => ['required', 'date', 'before_or_equal:today'],
            'critical_note' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'scores' => ['required', 'array', 'size:'.count(QualityAssurance::CRITERIA)],
            'scores.*.rating' => ['required', 'integer', 'between:0,5'],
            'scores.*.note' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
