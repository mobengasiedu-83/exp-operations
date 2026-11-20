<?php

namespace App\Services;

use App\Models\Callback;
use App\Models\CallBridge;
use App\Models\CallerCase;
use App\Models\CdrRecord;
use App\Models\CorrectiveAction;
use App\Models\CorrelationException;
use App\Models\DentistRoster;
use App\Models\Incident;
use App\Models\Interaction;
use App\Models\QaReview;
use App\Models\TransferAttempt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Computes the raw value, numerator and denominator for every published KPI.
 *
 * The catalogue in {@see Reporting::KPI_CATALOGUE} explains each figure to the
 * operator; this class only applies those definitions to the telemetry,
 * transfer, case, follow-up and governance records already stored by earlier
 * phases. Nothing here guesses a value: missing evidence yields null.
 */
class ReportMetrics
{
    /**
     * @return array<string, array{value: float|int|null, numerator: float|int|null, denominator: float|int|null}>
     */
    public function values(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $now = CarbonImmutable::now();
        $percent = fn (int|float $numerator, int|float $denominator): ?float => $denominator > 0
            ? round($numerator / $denominator * 100, 2)
            : null;

        $answeredAgentLeg = fn (Builder $legs): Builder => $legs->where('role', 'agent')->whereNotNull('answered_at');

        $inbound = Interaction::query()->where('is_exp', true)->whereBetween('started_at', [$from, $to]);
        $inboundCount = (clone $inbound)->count();
        $answeredCount = (clone $inbound)->whereHas('legs', $answeredAgentLeg)->count();
        $abandonedCount = (clone $inbound)->whereDoesntHave('legs', $answeredAgentLeg)->count();
        $withinThirty = $this->answeredWithinSeconds($from, $to, 30);

        $cases = CallerCase::query()->whereBetween('created_at', [$from, $to]);
        $casesOpened = (clone $cases)->count();
        $casesClosed = CallerCase::query()->whereBetween('closed_at', [$from, $to])->count();
        $priority = fn (string $value): int => (clone $cases)->where('priority', $value)->count();
        $contactKind = fn (string $value): int => (clone $cases)->where('contact_kind', $value)->count();

        $attempts = TransferAttempt::query()->whereBetween('dial_started_at', [$from, $to]);
        $attemptCount = (clone $attempts)->count();
        $networkAnswered = (clone $attempts)->whereNotNull('answered_at')->count();
        $humanAccepted = (clone $attempts)->whereNotNull('human_accepted_at')->count();
        $failedAttempts = (clone $attempts)->where('status', 'transfer_failed')->count();
        $dialToAnswer = (clone $attempts)->whereNotNull('dial_to_answer_seconds');
        $ringing = (clone $attempts)->whereNotNull('ringing_seconds');
        $privateHandover = (clone $attempts)->whereNotNull('private_handover_seconds');
        $consultations = (clone $attempts)->whereNotNull('consultation_seconds');
        $consultationMinutes = round((float) $this->decimal((clone $attempts)->sum('consultation_seconds')) / 60, 2);
        $tariff = (float) config('reporting.consultation_tariff_per_minute', 0);
        $bridges = CallBridge::query()
            ->where('stage', 'consultation')
            ->whereBetween('started_at', [$from, $to])
            ->whereNotNull('duration_seconds');

        $rosterSeconds = DentistRoster::query()
            ->whereIn('kind', DentistRoster::COVERING_KINDS)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from)
            ->get(['starts_at', 'ends_at'])
            ->sum(fn (DentistRoster $roster): int => max(
                0,
                min(CarbonImmutable::parse($roster->ends_at)->getTimestamp(), $to->getTimestamp())
                    - max(CarbonImmutable::parse($roster->starts_at)->getTimestamp(), $from->getTimestamp()),
            ));

        $completedCallbacks = Callback::query()->whereBetween('completed_at', [$from, $to]);
        $completedCount = (clone $completedCallbacks)->count();
        $onTimeCount = (clone $completedCallbacks)->whereColumn('completed_at', '<=', 'due_at')->count();

        $finalisedReviews = QaReview::query()->where('status', 'finalised')->whereBetween('reviewed_at', [$from, $to]);
        $finalisedCount = (clone $finalisedReviews)->count();
        $scoredReviews = (clone $finalisedReviews)->whereNotNull('total');
        $averageScore = $this->decimal($scoredReviews->avg('total'));

        $incidents = Incident::query()->whereBetween('occurred_at', [$from, $to]);
        $overdueIncidents = Incident::query()->whereNotIn('status', ['resolved', 'closed'])
            ->whereNotNull('escalation_due_at')->where('escalation_due_at', '<', $now);
        $openActions = CorrectiveAction::query()->where('status', '!=', 'verified');

        $cdrJourneys = CdrRecord::query()->whereBetween('start', [$from, $to])->distinct()->count('uniqueid');
        $caseRevisions = Activity::query()->where('log_name', 'case-workflow')->whereBetween('created_at', [$from, $to]);

        $averageDial = $this->decimal((clone $dialToAnswer)->avg('dial_to_answer_seconds'));
        $averageRinging = $this->decimal((clone $ringing)->avg('ringing_seconds'));
        $averageHandover = $this->decimal((clone $privateHandover)->avg('private_handover_seconds'));
        $averageConsultation = $this->decimal((clone $consultations)->avg('consultation_seconds'));
        $averageBridge = $this->decimal((clone $bridges)->avg('duration_seconds'));
        $sumDial = $this->decimal((clone $dialToAnswer)->sum('dial_to_answer_seconds'));
        $sumRinging = $this->decimal((clone $ringing)->sum('ringing_seconds'));
        $sumHandover = $this->decimal((clone $privateHandover)->sum('private_handover_seconds'));
        $sumConsultation = $this->decimal((clone $consultations)->sum('consultation_seconds'));
        $sumBridge = $this->decimal((clone $bridges)->sum('duration_seconds'));
        $bookedCallbacks = Callback::query()->whereBetween('created_at', [$from, $to])->count();
        $overdueCallbacks = Callback::query()->whereIn('status', ['pending', 'in_progress'])
            ->whereNotNull('due_at')->where('due_at', '<', $now)->count();
        $criticalFails = (clone $finalisedReviews)->where('critical_fail', true)->count();
        $coachingOutstanding = (clone $finalisedReviews)->where('coaching_required', true)
            ->whereNull('coaching_completed_at')->count();
        $incidentCount = (clone $incidents)->count();
        $overdueIncidentCount = (clone $overdueIncidents)->count();
        $openActionCount = (clone $openActions)->count();
        $verifiedActionCount = CorrectiveAction::query()->whereBetween('verified_at', [$from, $to])->count();
        $exceptionCount = CorrelationException::query()->whereBetween('created_at', [$from, $to])->count();
        $revisionCount = (clone $caseRevisions)->count();

        return [
            'inbound_interactions' => ['value' => $inboundCount, 'numerator' => $inboundCount, 'denominator' => null],
            'answered_interactions' => ['value' => $answeredCount, 'numerator' => $answeredCount, 'denominator' => null],
            'answer_rate' => ['value' => $percent($answeredCount, $inboundCount), 'numerator' => $answeredCount, 'denominator' => $inboundCount],
            'answered_within_30_seconds' => ['value' => $percent($withinThirty, $answeredCount), 'numerator' => $withinThirty, 'denominator' => $answeredCount],
            'abandoned_interactions' => ['value' => $abandonedCount, 'numerator' => $abandonedCount, 'denominator' => null],
            'ultra_short_contacts' => ['value' => $contactKind('ultra_short'), 'numerator' => $contactKind('ultra_short'), 'denominator' => null],
            'nuisance_contacts' => ['value' => $contactKind('nuisance'), 'numerator' => $contactKind('nuisance'), 'denominator' => null],
            'silent_contacts' => ['value' => $contactKind('silent'), 'numerator' => $contactKind('silent'), 'denominator' => null],
            'cases_opened' => ['value' => $casesOpened, 'numerator' => $casesOpened, 'denominator' => null],
            'cases_closed' => ['value' => $casesClosed, 'numerator' => $casesClosed, 'denominator' => null],
            'priority_p1' => ['value' => $priority('P1'), 'numerator' => $priority('P1'), 'denominator' => null],
            'priority_p2' => ['value' => $priority('P2'), 'numerator' => $priority('P2'), 'denominator' => null],
            'priority_p3' => ['value' => $priority('P3'), 'numerator' => $priority('P3'), 'denominator' => null],
            'priority_s' => ['value' => $priority('S'), 'numerator' => $priority('S'), 'denominator' => null],
            'priority_review' => ['value' => $priority('Review'), 'numerator' => $priority('Review'), 'denominator' => null],
            'transfer_attempts' => ['value' => $attemptCount, 'numerator' => $attemptCount, 'denominator' => null],
            'transfer_answer_rate' => ['value' => $percent($networkAnswered, $attemptCount), 'numerator' => $networkAnswered, 'denominator' => $attemptCount],
            'transfer_acceptance_rate' => ['value' => $percent($humanAccepted, $attemptCount), 'numerator' => $humanAccepted, 'denominator' => $attemptCount],
            'transfer_failures' => ['value' => $failedAttempts, 'numerator' => $failedAttempts, 'denominator' => null],
            'average_dial_to_answer_seconds' => ['value' => $averageDial, 'numerator' => $sumDial, 'denominator' => (clone $dialToAnswer)->count()],
            'average_private_handover_seconds' => ['value' => $averageHandover, 'numerator' => $sumHandover, 'denominator' => (clone $privateHandover)->count()],
            'average_consultation_seconds' => ['value' => $averageConsultation, 'numerator' => $sumConsultation, 'denominator' => (clone $consultations)->count()],
            'average_ringing_seconds' => ['value' => $averageRinging, 'numerator' => $sumRinging, 'denominator' => (clone $ringing)->count()],
            'average_verified_bridge_seconds' => ['value' => $averageBridge, 'numerator' => $sumBridge, 'denominator' => (clone $bridges)->count()],
            'consultation_minutes' => ['value' => $consultationMinutes, 'numerator' => $consultationMinutes, 'denominator' => null],
            'estimated_consultation_cost' => ['value' => $tariff > 0 ? round($consultationMinutes * $tariff, 2) : null, 'numerator' => $tariff > 0 ? $consultationMinutes : null, 'denominator' => $tariff > 0 ? $tariff : null],
            'dentist_roster_hours' => ['value' => round($rosterSeconds / 3600, 2), 'numerator' => $rosterSeconds, 'denominator' => null],
            'callbacks_booked' => ['value' => $bookedCallbacks, 'numerator' => $bookedCallbacks, 'denominator' => null],
            'callbacks_completed' => ['value' => $completedCount, 'numerator' => $completedCount, 'denominator' => null],
            'callbacks_on_time' => ['value' => $percent($onTimeCount, $completedCount), 'numerator' => $onTimeCount, 'denominator' => $completedCount],
            'callbacks_overdue_open' => ['value' => $overdueCallbacks, 'numerator' => $overdueCallbacks, 'denominator' => null],
            'qa_reviews_finalised' => ['value' => $finalisedCount, 'numerator' => $finalisedCount, 'denominator' => null],
            'qa_average_score' => ['value' => $averageScore === null ? null : round((float) $averageScore, 2), 'numerator' => $averageScore, 'denominator' => (clone $scoredReviews)->count()],
            'qa_critical_fails' => ['value' => $criticalFails, 'numerator' => $criticalFails, 'denominator' => null],
            'coaching_outstanding' => ['value' => $coachingOutstanding, 'numerator' => $coachingOutstanding, 'denominator' => null],
            'incidents_opened' => ['value' => $incidentCount, 'numerator' => $incidentCount, 'denominator' => null],
            'incidents_overdue' => ['value' => $overdueIncidentCount, 'numerator' => $overdueIncidentCount, 'denominator' => null],
            'corrective_actions_open' => ['value' => $openActionCount, 'numerator' => $openActionCount, 'denominator' => null],
            'corrective_actions_verified' => ['value' => $verifiedActionCount, 'numerator' => $verifiedActionCount, 'denominator' => null],
            'reconciliation_exceptions' => ['value' => $exceptionCount, 'numerator' => $exceptionCount, 'denominator' => null],
            'cdr_journeys' => ['value' => $cdrJourneys, 'numerator' => $cdrJourneys, 'denominator' => null],
            'reconciliation_variance' => ['value' => $inboundCount - $cdrJourneys, 'numerator' => $inboundCount, 'denominator' => $cdrJourneys],
            'recorded_case_revisions' => ['value' => $revisionCount, 'numerator' => $revisionCount, 'denominator' => null],
        ];
    }

    /**
     * Normalise a database aggregate, which may arrive as a numeric string.
     */
    private function decimal(float|int|string|null $value): float|int|null
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? (float) $value : $value;
    }

    /**
     * Count interactions whose first agent answer landed inside the threshold.
     */
    private function answeredWithinSeconds(CarbonImmutable $from, CarbonImmutable $to, int $seconds): int
    {
        return DB::table('call_legs')
            ->join('interactions', 'interactions.id', '=', 'call_legs.interaction_id')
            ->where('interactions.is_exp', true)
            ->whereNotNull('interactions.started_at')
            ->whereBetween('interactions.started_at', [$from, $to])
            ->where('call_legs.role', 'agent')
            ->whereNotNull('call_legs.answered_at')
            ->groupBy('interactions.id', 'interactions.started_at')
            ->selectRaw('interactions.started_at as started_at, MIN(call_legs.answered_at) as answered_at')
            ->get()
            ->filter(function (object $row) use ($seconds): bool {
                $elapsed = CarbonImmutable::parse($row->answered_at)->getTimestamp()
                    - CarbonImmutable::parse($row->started_at)->getTimestamp();

                return $elapsed >= 0 && $elapsed <= $seconds;
            })
            ->count();
    }
}
