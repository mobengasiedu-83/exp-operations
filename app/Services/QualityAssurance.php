<?php

namespace App\Services;

use App\Models\QaCalibration;
use App\Models\QaReview;
use App\Models\QaScore;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class QualityAssurance
{
    /**
     * Weighted playbook scorecard. Weights total 100 points.
     *
     * @var array<string, array{label: string, weight: int, critical: bool}>
     */
    public const CRITERIA = [
        'opening' => ['label' => 'Opening', 'weight' => 10, 'critical' => false],
        'empathy' => ['label' => 'Empathy', 'weight' => 15, 'critical' => false],
        'privacy' => ['label' => 'Privacy', 'weight' => 10, 'critical' => true],
        'safety' => ['label' => 'Safety', 'weight' => 25, 'critical' => true],
        'capture' => ['label' => 'Capture', 'weight' => 15, 'critical' => false],
        'routing' => ['label' => 'Routing', 'weight' => 15, 'critical' => false],
        'closure' => ['label' => 'Closure', 'weight' => 10, 'critical' => false],
    ];

    /** @var list<int> */
    public const RATINGS = [0, 1, 2, 3, 4, 5];

    public const PASS_MARK = 80;

    public const CRITICAL_FAIL_RATING = 1;

    public const CALIBRATION_TOLERANCE = 10;

    public function __construct(private readonly IncidentRegister $incidents) {}

    /** @param array<string, mixed> $data */
    public function create(User $reviewer, array $data): QaReview
    {
        return DB::transaction(function () use ($reviewer, $data): QaReview {
            $reference = Str::slug((string) $data['reference']);
            $this->require($reference !== '', 'reference', 'Enter a review reference containing letters or numbers.');
            $this->require(
                ! QaReview::query()->where('reference', $reference)->exists(),
                'reference',
                'This review reference is already recorded.',
            );
            $agent = User::query()->active()->find((int) $data['agent_id']);
            $this->require($agent instanceof User, 'agent_id', 'Choose an active agent to review.');
            /** @var array<string, array{rating: int, note: string|null}> $scores */
            $scores = $data['scores'];
            $normalised = $this->normaliseScores($scores);
            $totals = $this->totals($normalised);
            $this->requireCriticalNote($totals['critical_fail'], $data['critical_note'] ?? null);
            $review = QaReview::query()->create([
                'reference' => $reference,
                'case_id' => $data['case_id'] ?? null,
                'agent_id' => $agent->id,
                'reviewer_id' => $reviewer->id,
                'reviewed_at' => CarbonImmutable::parse((string) $data['reviewed_at'])->startOfDay(),
                'status' => 'draft',
                'total' => $totals['total'],
                'outcome' => $totals['outcome'],
                'critical_fail' => $totals['critical_fail'],
                'critical_note' => $data['critical_note'] ?? null,
                'notes' => $data['notes'] ?? null,
                'revision' => 1,
            ]);
            $this->writeScores($review, $normalised);
            activity('quality-assurance')->causedBy($reviewer)->performedOn($review)->withProperties([
                'operation' => 'review_drafted', 'agent_id' => $agent->id, 'total' => $totals['total'],
                'critical_fail' => $totals['critical_fail'],
            ])->log('QA review drafted');

            return $review->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $reviewer, QaReview $review, array $data): QaReview
    {
        return DB::transaction(function () use ($reviewer, $review, $data): QaReview {
            $review = QaReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->require($review->isDraft(), 'revision', 'Only draft reviews can be edited.');
            $this->require((int) $data['revision'] === $review->revision, 'revision', 'This review changed. Reload before saving.');
            $agent = User::query()->active()->find((int) $data['agent_id']);
            $this->require($agent instanceof User, 'agent_id', 'Choose an active agent to review.');
            /** @var array<string, array{rating: int, note: string|null}> $scores */
            $scores = $data['scores'];
            $normalised = $this->normaliseScores($scores);
            $totals = $this->totals($normalised);
            $this->requireCriticalNote($totals['critical_fail'], $data['critical_note'] ?? null);
            $review->update([
                'case_id' => $data['case_id'] ?? null,
                'agent_id' => $agent->id,
                'reviewed_at' => CarbonImmutable::parse((string) $data['reviewed_at'])->startOfDay(),
                'total' => $totals['total'],
                'outcome' => $totals['outcome'],
                'critical_fail' => $totals['critical_fail'],
                'critical_note' => $data['critical_note'] ?? null,
                'notes' => $data['notes'] ?? null,
                'revision' => $review->revision + 1,
            ]);
            $this->writeScores($review, $normalised);
            activity('quality-assurance')->causedBy($reviewer)->performedOn($review)->withProperties([
                'operation' => 'review_updated', 'total' => $totals['total'], 'revision' => $review->revision,
            ])->log('QA review updated');

            return $review->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function finalise(User $reviewer, QaReview $review, array $data): QaReview
    {
        return DB::transaction(function () use ($reviewer, $review, $data): QaReview {
            $review = QaReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->require($review->isDraft(), 'status', 'Only draft reviews can be finalised.');
            $this->require((int) $data['revision'] === $review->revision, 'revision', 'This review changed. Reload before finalising.');
            $scores = [];
            foreach ($review->scores()->get() as $score) {
                $scores[$score->criterion] = ['rating' => $score->rating, 'note' => $score->note];
            }
            $totals = $this->totals($this->normaliseScores($scores));
            $this->requireCriticalNote($totals['critical_fail'], $review->critical_note);
            $requiresCoaching = $totals['outcome'] !== 'pass';
            $due = $data['coaching_due_at'] ?? null;
            $this->require(! $requiresCoaching || ($due !== null && $due !== ''), 'coaching_due_at', 'Remediation and coaching need a due date.');
            $review->update([
                'status' => 'finalised',
                'total' => $totals['total'],
                'outcome' => $totals['outcome'],
                'critical_fail' => $totals['critical_fail'],
                'coaching_required' => $requiresCoaching,
                'coaching_due_at' => $requiresCoaching && $due !== null ? CarbonImmutable::parse((string) $due) : null,
                'finalised_at' => now(),
                'revision' => $review->revision + 1,
            ]);
            activity('quality-assurance')->causedBy($reviewer)->performedOn($review)->withProperties([
                'operation' => 'review_finalised', 'total' => $totals['total'], 'outcome' => $totals['outcome'],
            ])->log('QA review finalised');
            if ($totals['critical_fail']) {
                $this->raiseCriticalFailIncident($reviewer, $review);
            }

            return $review->refresh();
        });
    }

    public function recordCoaching(User $coach, QaReview $review, string $note): QaReview
    {
        return DB::transaction(function () use ($coach, $review, $note): QaReview {
            $review = QaReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->require($review->status === 'finalised', 'status', 'Finalise the review before recording coaching.');
            $this->require($review->coaching_required, 'coaching_required', 'This review does not require coaching.');
            $this->require($review->coaching_completed_at === null, 'coaching_completed_at', 'Coaching is already recorded for this review.');
            $this->require(trim($note) !== '', 'coaching_note', 'Record the coaching conversation and agreed actions.');
            $review->update([
                'coaching_completed_at' => now(),
                'coaching_note' => $note,
                'coached_by' => $coach->id,
                'revision' => $review->revision + 1,
            ]);
            activity('quality-assurance')->causedBy($coach)->performedOn($review)->withProperties([
                'operation' => 'coaching_recorded', 'agent_id' => $review->agent_id,
            ])->log('QA coaching recorded');

            return $review->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function addCalibration(User $reviewer, QaReview $review, array $data): QaCalibration
    {
        return DB::transaction(function () use ($reviewer, $review, $data): QaCalibration {
            $review = QaReview::query()->lockForUpdate()->findOrFail($review->id);
            $this->require($review->status === 'finalised', 'qa_review_id', 'Only finalised reviews can be calibrated.');
            $this->require($review->reviewer_id !== $reviewer->id, 'reviewer_id', 'A calibration needs a second reviewer.');
            $this->require(
                ! $review->calibrations()->where('reviewer_id', $reviewer->id)->exists(),
                'reviewer_id',
                'This reviewer already submitted a calibration score.',
            );
            $secondary = (int) $data['secondary_total'];
            $this->require($secondary >= 0 && $secondary <= 100, 'secondary_total', 'Enter a calibration score between 0 and 100.');
            $variance = abs((int) $review->total - $secondary);
            $note = trim((string) ($data['note'] ?? ''));
            $this->require(
                $variance <= self::CALIBRATION_TOLERANCE || $note !== '',
                'note',
                'Explain differences larger than '.self::CALIBRATION_TOLERANCE.' points.',
            );
            $calibration = $review->calibrations()->create([
                'reviewer_id' => $reviewer->id,
                'secondary_total' => $secondary,
                'variance' => $variance,
                'status' => 'open',
                'note' => $data['note'] ?? null,
            ]);
            activity('quality-assurance')->causedBy($reviewer)->performedOn($review)->withProperties([
                'operation' => 'calibration_recorded', 'calibration_id' => $calibration->id,
                'variance' => $variance,
            ])->log('QA calibration recorded');

            return $calibration->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function agreeCalibration(User $approver, QaCalibration $calibration, array $data): QaCalibration
    {
        return DB::transaction(function () use ($approver, $calibration, $data): QaCalibration {
            $calibration = QaCalibration::query()->lockForUpdate()->findOrFail($calibration->id);
            $this->require($calibration->status === 'open', 'status', 'This calibration is already agreed.');
            $agreed = (int) $data['agreed_total'];
            $this->require($agreed >= 0 && $agreed <= 100, 'agreed_total', 'Enter the agreed score between 0 and 100.');
            $note = trim((string) ($data['note'] ?? ''));
            $this->require(
                $calibration->variance <= self::CALIBRATION_TOLERANCE || $note !== '',
                'note',
                'Record the rationale for a variance above '.self::CALIBRATION_TOLERANCE.' points.',
            );
            $calibration->update([
                'status' => 'agreed',
                'agreed_total' => $agreed,
                'agreed_by' => $approver->id,
                'agreed_at' => now(),
                'note' => $note !== '' ? $note : $calibration->note,
            ]);
            activity('quality-assurance')->causedBy($approver)->performedOn($calibration->review)->withProperties([
                'operation' => 'calibration_agreed', 'calibration_id' => $calibration->id, 'agreed_total' => $agreed,
            ])->log('QA calibration agreed');

            return $calibration->refresh();
        });
    }

    /**
     * @param  array<string, array{rating: int, note: string|null}>  $scores
     * @return array<string, array{rating: int, note: string|null}>
     */
    private function normaliseScores(array $scores): array
    {
        $unknown = array_diff(array_keys($scores), array_keys(self::CRITERIA));
        $this->require($unknown === [], 'scores', 'The scorecard contains an unknown criterion.');
        $normalised = [];
        foreach (self::CRITERIA as $key => $definition) {
            $this->require(array_key_exists($key, $scores), 'scores', 'Score every weighted criterion before saving.');
            $rating = (int) $scores[$key]['rating'];
            $this->require(in_array($rating, self::RATINGS, true), 'scores', 'Rate every criterion from 0 to 5.');
            $note = $scores[$key]['note'];
            $normalised[$key] = ['rating' => $rating, 'note' => is_string($note) ? $note : null];
        }

        return $normalised;
    }

    /**
     * @param  array<string, array{rating: int, note: string|null}>  $scores
     * @return array{total: int, critical_fail: bool, outcome: string}
     */
    private function totals(array $scores): array
    {
        $total = 0;
        $critical = false;
        foreach (self::CRITERIA as $key => $definition) {
            $total += (int) round($definition['weight'] * $scores[$key]['rating'] / 5);
            if ($definition['critical'] && $scores[$key]['rating'] <= self::CRITICAL_FAIL_RATING) {
                $critical = true;
            }
        }

        return [
            'total' => $total,
            'critical_fail' => $critical,
            'outcome' => $critical ? 'critical_fail' : ($total >= self::PASS_MARK ? 'pass' : 'remediate'),
        ];
    }

    private function raiseCriticalFailIncident(User $reviewer, QaReview $review): void
    {
        $critical = $review->scores()->where('critical', true)->orderBy('rating')->first();
        $criterion = $critical instanceof QaScore ? $critical->criterion : 'safety';
        $incident = $this->incidents->register($reviewer, [
            'reference' => 'qa-'.$review->reference,
            'category' => $criterion === 'safety' ? 'safety' : 'privacy',
            'severity' => 'critical',
            'case_id' => $review->case_id,
            'qa_review_id' => $review->id,
            'occurred_at' => $review->reviewed_at?->toDateTimeString() ?? now()->toDateTimeString(),
            'summary' => 'Critical QA failure on review '.$review->reference,
            'description' => 'The weighted scorecard recorded a critical '.$criterion.' failure. Immediate clinical review, coaching and a verified corrective action are required.',
            'immediate_action' => 'Reviewing supervisor notified automatically; corrective action assigned.',
        ]);
        $incident = $this->incidents->escalate($reviewer, $incident, [
            'revision' => $incident->revision,
            'severity' => 'critical',
            'assigned_to' => $reviewer->id,
            'note' => 'Automatic escalation from a critical QA failure.',
        ]);
        $this->incidents->addAction($reviewer, $incident, [
            'owner_id' => $reviewer->id,
            'description' => 'Immediate clinical review and coaching for QA review '.$review->reference.'.',
            'due_at' => now()->addDay()->toDateTimeString(),
        ]);
    }

    /** @param array<string, array{rating: int, note: string|null}> $scores */
    private function writeScores(QaReview $review, array $scores): void
    {
        $review->scores()->delete();
        foreach (self::CRITERIA as $key => $definition) {
            $review->scores()->create([
                'criterion' => $key,
                'weight' => $definition['weight'],
                'rating' => $scores[$key]['rating'],
                'points' => (int) round($definition['weight'] * $scores[$key]['rating'] / 5),
                'critical' => $definition['critical'],
                'note' => $scores[$key]['note'],
            ]);
        }
    }

    private function requireCriticalNote(bool $critical, ?string $note): void
    {
        $this->require(
            ! $critical || ($note !== null && trim($note) !== ''),
            'critical_note',
            'Record why a critical safety or privacy criterion failed.',
        );
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
