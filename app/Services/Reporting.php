<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Computes attributable, period-bounded EXP operating metrics.
 *
 * Every value carries its numerator, denominator and exclusion list so a
 * reviewer can reproduce the figure from the documented definition instead of
 * trusting a dashboard number.
 */
class Reporting
{
    /**
     * KPI catalogue. The numerator, denominator and exclusion wording is
     * published to operators on the reports screen and in every export.
     *
     * @var array<string, array<string, mixed>>
     */
    public const KPI_CATALOGUE = [
        'inbound_interactions' => [
            'label' => 'Inbound EXP interactions', 'category' => 'volume', 'unit' => 'count',
            'definition' => 'Count of EXP interactions whose observed start time falls inside the reporting window.',
            'numerator' => 'Interactions with is_exp = true and a start timestamp in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Interactions not marked as EXP', 'Interactions without an observed start time'],
        ],
        'answered_interactions' => [
            'label' => 'Interactions answered by an agent', 'category' => 'volume', 'unit' => 'count',
            'definition' => 'Count of inbound EXP interactions with at least one agent leg that has an answer timestamp.',
            'numerator' => 'Interactions with an agent leg answered in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Mobile or dentist legs answered without an agent answer', 'Interactions answered after the window closed'],
        ],
        'answer_rate' => [
            'label' => 'Agent answer rate', 'category' => 'volume', 'unit' => 'percent',
            'definition' => 'Agent-answered interactions divided by inbound EXP interactions in the same window.',
            'numerator' => 'answered_interactions',
            'denominator' => 'inbound_interactions',
            'exclusions' => ['A zero denominator reports no value rather than zero percent'],
        ],
        'answered_within_30_seconds' => [
            'label' => 'Answered within 30 seconds', 'category' => 'volume', 'unit' => 'percent',
            'definition' => 'Interactions whose first agent answer occurred at most 30 seconds after the observed interaction start.',
            'numerator' => 'Answered interactions with first agent answer <= 30 seconds after start',
            'denominator' => 'answered_interactions',
            'exclusions' => ['Interactions with missing start or answer timestamps'],
        ],
        'abandoned_interactions' => [
            'label' => 'Abandoned interactions', 'category' => 'relief', 'unit' => 'count',
            'definition' => 'Inbound EXP interactions with no agent answer recorded in the window.',
            'numerator' => 'Interactions without an answered agent leg',
            'denominator' => 'None (count)',
            'exclusions' => ['Interactions still active when the report was generated', 'Interactions with no agent leg at all'],
        ],
        'ultra_short_contacts' => [
            'label' => 'Ultra-short contacts', 'category' => 'relief', 'unit' => 'count',
            'definition' => 'Cases opened in the window with the factual contact type ultra_short.',
            'numerator' => 'Cases with contact_kind = ultra_short',
            'denominator' => 'None (count)',
            'exclusions' => ['Calls without a case record', 'Reclassified calls keep their latest recorded type'],
        ],
        'cases_opened' => [
            'label' => 'Cases opened', 'category' => 'cases', 'unit' => 'count',
            'definition' => 'Caller cases created in the window.',
            'numerator' => 'Cases with created_at inside the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Consent-only records without a case'],
        ],
        'cases_closed' => [
            'label' => 'Cases closed', 'category' => 'cases', 'unit' => 'count',
            'definition' => 'Caller cases closed with a recorded disposition in the window.',
            'numerator' => 'Cases with closed_at inside the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Cases closed before the window', 'Reopened cases count again only if closed again inside the window'],
        ],
        'priority_p1' => [
            'label' => 'P1 emergency cases', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Cases opened in the window whose latest recorded safety priority is P1.',
            'numerator' => 'Cases created in the window with priority = P1',
            'denominator' => 'None (count)',
            'exclusions' => ['Cases still awaiting screening'],
        ],
        'priority_p2' => [
            'label' => 'P2 urgent cases', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Cases opened in the window whose latest recorded safety priority is P2.',
            'numerator' => 'Cases created in the window with priority = P2',
            'denominator' => 'None (count)',
            'exclusions' => ['Cases still awaiting screening'],
        ],
        'priority_p3' => [
            'label' => 'P3 routine cases', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Cases opened in the window whose latest recorded safety priority is P3.',
            'numerator' => 'Cases created in the window with priority = P3',
            'denominator' => 'None (count)',
            'exclusions' => ['Cases still awaiting screening'],
        ],
        'priority_s' => [
            'label' => 'S approved service resolutions', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Cases opened in the window resolved against approved non-clinical service wording.',
            'numerator' => 'Cases created in the window with priority = S',
            'denominator' => 'None (count)',
            'exclusions' => ['Personal clinical concerns closed as service FAQs'],
        ],
        'priority_review' => [
            'label' => 'Cases held for review', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Cases opened in the window held for clinical or supervisor review, including unclear contact.',
            'numerator' => 'Cases created in the window with priority = Review',
            'denominator' => 'None (count)',
            'exclusions' => ['Cases with a recorded disposition other than clinical_review'],
        ],
        'transfer_attempts' => [
            'label' => 'Warm transfer attempts', 'category' => 'transfers', 'unit' => 'count',
            'definition' => 'Observed dentist dial attempts whose dial start falls inside the window.',
            'numerator' => 'Transfer attempts with dial_started_at in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Retries are counted individually', 'Attempts without an observed dial start'],
        ],
        'transfer_answer_rate' => [
            'label' => 'Dentist network answer rate', 'category' => 'transfers', 'unit' => 'percent',
            'definition' => 'Attempts with a network answer timestamp divided by all attempts in the window. A network answer is not human acceptance.',
            'numerator' => 'Attempts with answered_at recorded',
            'denominator' => 'transfer_attempts',
            'exclusions' => ['Operator-recorded acceptance without a network answer timestamp'],
        ],
        'transfer_acceptance_rate' => [
            'label' => 'Human acceptance rate', 'category' => 'transfers', 'unit' => 'percent',
            'definition' => 'Attempts with a recorded human acceptance divided by all attempts in the window.',
            'numerator' => 'Attempts with human_accepted_at recorded',
            'denominator' => 'transfer_attempts',
            'exclusions' => ['Network answers awaiting operator confirmation'],
        ],
        'transfer_failures' => [
            'label' => 'Failed attended transfers', 'category' => 'transfers', 'unit' => 'count',
            'definition' => 'Attempts in the window whose recorded status is transfer_failed.',
            'numerator' => 'Attempts with status = transfer_failed',
            'denominator' => 'None (count)',
            'exclusions' => ['Busy, unanswered, voicemail and declined outcomes are reported as separate statuses'],
        ],

        'nuisance_contacts' => [
            'label' => 'Nuisance contacts', 'category' => 'relief', 'unit' => 'count',
            'definition' => 'Cases opened in the window with the factual contact type nuisance.',
            'numerator' => 'Cases with contact_kind = nuisance',
            'denominator' => 'None (count)',
            'exclusions' => ['Calls without a case record', 'Clinical outcome is never inferred from this classification'],
        ],
        'silent_contacts' => [
            'label' => 'Silent contacts', 'category' => 'relief', 'unit' => 'count',
            'definition' => 'Cases opened in the window with the factual contact type silent.',
            'numerator' => 'Cases with contact_kind = silent',
            'denominator' => 'None (count)',
            'exclusions' => ['Calls without a case record'],
        ],
        'average_dial_to_answer_seconds' => [
            'label' => 'Average dial to answer', 'category' => 'transfers', 'unit' => 'seconds',
            'definition' => 'Mean observed seconds between dial start and network answer for attempts in the window.',
            'numerator' => 'Sum of dial_to_answer_seconds',
            'denominator' => 'Attempts with a recorded dial_to_answer_seconds',
            'exclusions' => ['Attempts with incomplete timing evidence'],
        ],
        'average_private_handover_seconds' => [
            'label' => 'Average private handover', 'category' => 'transfers', 'unit' => 'seconds',
            'definition' => 'Mean observed seconds of private operator-to-dentist handover before the caller is bridged.',
            'numerator' => 'Sum of private_handover_seconds',
            'denominator' => 'Attempts with a recorded private_handover_seconds',
            'exclusions' => ['Attempts where no private handover was observed'],
        ],
        'average_consultation_seconds' => [
            'label' => 'Average consultation', 'category' => 'transfers', 'unit' => 'seconds',
            'definition' => 'Mean observed caller-dentist consultation duration in the window.',
            'numerator' => 'Sum of consultation_seconds',
            'denominator' => 'Attempts with a recorded consultation_seconds',
            'exclusions' => ['Consultations without a confirmed start and end'],
        ],
        'average_ringing_seconds' => [
            'label' => 'Average dentist ringing', 'category' => 'transfers', 'unit' => 'seconds',
            'definition' => 'Mean observed seconds a dentist mobile leg rang before it was answered or the attempt ended.',
            'numerator' => 'Sum of ringing_seconds',
            'denominator' => 'Attempts with a recorded ringing_seconds',
            'exclusions' => ['Attempts where the PBX did not measure ringing', 'Ring time is not proof that a person answered'],
        ],
        'average_verified_bridge_seconds' => [
            'label' => 'Average verified caller–dentist bridge', 'category' => 'transfers', 'unit' => 'seconds',
            'definition' => 'Mean duration of closed caller–dentist consultation bridges observed in the window.',
            'numerator' => 'Sum of consultation bridge duration_seconds',
            'denominator' => 'Consultation bridges with a recorded duration',
            'exclusions' => ['Bridges still open when the report was generated', 'Private handover intervals are a different stage', 'A projected bridge is not human acceptance'],
        ],
        'consultation_minutes' => [
            'label' => 'Consultation minutes', 'category' => 'transfers', 'unit' => 'minutes',
            'definition' => 'Total observed caller-dentist consultation time for attempts in the window.',
            'numerator' => 'Sum of consultation_seconds / 60',
            'denominator' => 'None (total)',
            'exclusions' => ['Unconfirmed consultation intervals'],
        ],
        'estimated_consultation_cost' => [
            'label' => 'Estimated consultation cost', 'category' => 'dentists', 'unit' => 'currency',
            'definition' => 'Consultation minutes multiplied by the configured reporting.consultation_tariff_per_minute setting.',
            'numerator' => 'consultation_minutes * configured tariff',
            'denominator' => 'None (total)',
            'exclusions' => ['No value is reported until a tariff is configured', 'Travel, materials and procedure costs are out of scope'],
        ],
        'dentist_roster_hours' => [
            'label' => 'Dentist roster hours', 'category' => 'dentists', 'unit' => 'hours',
            'definition' => 'Rostered dentist time overlapping the reporting window.',
            'numerator' => 'Sum of roster overlap seconds / 3600',
            'denominator' => 'None (total)',
            'exclusions' => ['Unrostered availability entries', 'Roster time outside the window is clipped'],
        ],
        'callbacks_booked' => [
            'label' => 'Callbacks booked', 'category' => 'follow_up', 'unit' => 'count',
            'definition' => 'Callback records created in the window against a recorded consent.',
            'numerator' => 'Callbacks with created_at in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Callbacks booked before the window'],
        ],
        'callbacks_completed' => [
            'label' => 'Callbacks completed', 'category' => 'follow_up', 'unit' => 'count',
            'definition' => 'Callbacks completed in the window.',
            'numerator' => 'Callbacks with completed_at in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Callbacks completed before or after the window'],
        ],
        'callbacks_on_time' => [
            'label' => 'Callbacks completed on time', 'category' => 'follow_up', 'unit' => 'percent',
            'definition' => 'Callbacks completed at or before their recorded due time, divided by callbacks completed in the window.',
            'numerator' => 'Completed callbacks with completed_at <= due_at',
            'denominator' => 'callbacks_completed',
            'exclusions' => ['Completed callbacks without a due time', 'Escalations keep their original due time'],
        ],
        'callbacks_overdue_open' => [
            'label' => 'Open overdue callbacks', 'category' => 'follow_up', 'unit' => 'count',
            'definition' => 'Callbacks still pending or in progress past their due time when the report was generated.',
            'numerator' => 'Open callbacks with due_at before generation time',
            'denominator' => 'None (count)',
            'exclusions' => ['Cancelled and completed callbacks'],
        ],
        'qa_reviews_finalised' => [
            'label' => 'QA reviews finalised', 'category' => 'quality', 'unit' => 'count',
            'definition' => 'Quality reviews with status finalised and a review date inside the window.',
            'numerator' => 'Finalised QA reviews with reviewed_at in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Draft reviews', 'Calibration variance is reported separately'],
        ],
        'qa_average_score' => [
            'label' => 'Average QA score', 'category' => 'quality', 'unit' => 'score',
            'definition' => 'Mean weighted QA score of finalised reviews in the window.',
            'numerator' => 'Sum of finalised review totals',
            'denominator' => 'Finalised reviews with a recorded total',
            'exclusions' => ['Draft reviews', 'Reviews without a computed total'],
        ],
        'qa_critical_fails' => [
            'label' => 'QA critical fails', 'category' => 'quality', 'unit' => 'count',
            'definition' => 'Finalised reviews in the window with a critical fail outcome.',
            'numerator' => 'Finalised reviews with critical_fail = true',
            'denominator' => 'None (count)',
            'exclusions' => ['Draft reviews'],
        ],
        'coaching_outstanding' => [
            'label' => 'Coaching outstanding', 'category' => 'quality', 'unit' => 'count',
            'definition' => 'Finalised reviews requiring coaching without a recorded coaching completion.',
            'numerator' => 'Finalised reviews with coaching_required and no coaching_completed_at',
            'denominator' => 'None (count)',
            'exclusions' => ['Reviews not marked as requiring coaching'],
        ],
        'incidents_opened' => [
            'label' => 'Incidents recorded', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Safety, privacy, complaint and safeguarding incidents whose occurrence time falls inside the window.',
            'numerator' => 'Incidents with occurred_at in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Late-recorded incidents keep their original occurrence period'],
        ],
        'incidents_overdue' => [
            'label' => 'Incidents past escalation due', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Incidents not resolved or closed whose escalation due time has passed when the report was generated.',
            'numerator' => 'Open incidents with escalation_due_at in the past',
            'denominator' => 'None (count)',
            'exclusions' => ['Resolved and closed incidents'],
        ],
        'corrective_actions_open' => [
            'label' => 'Corrective actions open', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Corrective actions that are not yet verified when the report was generated.',
            'numerator' => 'Corrective actions with status open or completed',
            'denominator' => 'None (count)',
            'exclusions' => ['Verified actions'],
        ],
        'corrective_actions_verified' => [
            'label' => 'Corrective actions verified', 'category' => 'safety', 'unit' => 'count',
            'definition' => 'Corrective actions with recorded verification evidence in the window.',
            'numerator' => 'Corrective actions with verified_at in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Actions completed but not independently verified'],
        ],
        'reconciliation_exceptions' => [
            'label' => 'Call reconciliation exceptions', 'category' => 'governance', 'unit' => 'count',
            'definition' => 'Correlation exceptions recorded in the window across ingestion and reconciliation categories.',
            'numerator' => 'Correlation exceptions with created_at in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Exceptions later resolved by a CDR match are still counted when raised'],
        ],
        'cdr_journeys' => [
            'label' => 'CDR journeys observed', 'category' => 'governance', 'unit' => 'count',
            'definition' => 'Distinct CDR unique identifiers whose start time falls inside the window.',
            'numerator' => 'Distinct cdr_records.uniqueid in the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Multi-leg records sharing a unique identifier count once', 'Records without a start timestamp'],
        ],
        'reconciliation_variance' => [
            'label' => 'Interaction / CDR variance', 'category' => 'governance', 'unit' => 'count',
            'definition' => 'Inbound EXP interactions minus distinct CDR journeys in the window. A non-zero value is a count-level flag, not proof of a missing call.',
            'numerator' => 'inbound_interactions - cdr_journeys',
            'denominator' => 'None (signed count)',
            'exclusions' => ['Records still open when the report was generated', 'Interactions correlated without a CDR record'],
        ],
        'recorded_case_revisions' => [
            'label' => 'Recorded case revisions', 'category' => 'governance', 'unit' => 'count',
            'definition' => 'Append-only case workflow revision entries recorded in the window, used to audit corrections without exposing content.',
            'numerator' => 'Activity log entries for case-workflow inside the window',
            'denominator' => 'None (count)',
            'exclusions' => ['Note and concern content is never exported', 'Reads and views are not counted'],
        ],
    ];

    /**
     * Report catalogue seeded into report_definitions.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $operations = [
            'inbound_interactions', 'answered_interactions', 'answer_rate', 'answered_within_30_seconds',
            'abandoned_interactions', 'ultra_short_contacts', 'nuisance_contacts', 'silent_contacts',
            'cases_opened', 'cases_closed', 'priority_p1', 'priority_p2', 'priority_p3', 'priority_s',
            'priority_review', 'reconciliation_variance', 'recorded_case_revisions',
        ];
        $transfers = [
            'transfer_attempts', 'transfer_answer_rate', 'transfer_acceptance_rate', 'transfer_failures',
            'average_dial_to_answer_seconds', 'average_ringing_seconds', 'average_private_handover_seconds',
            'average_consultation_seconds', 'average_verified_bridge_seconds',
        ];

        return [
            [
                'key' => 'daily-operations', 'name' => 'Daily operations', 'category' => 'operations', 'grain' => 'daily',
                'description' => 'Daily contact volume, answer performance, safety priorities and reconciliation flags for the duty supervisor.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => $operations,
            ],
            [
                'key' => 'weekly-operations', 'name' => 'Weekly operations', 'category' => 'operations', 'grain' => 'weekly',
                'description' => 'Weekly operating review combining contact handling with warm transfer evidence.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => [...$operations, ...$transfers],
            ],
            [
                'key' => 'monthly-management', 'name' => 'Monthly management', 'category' => 'operations', 'grain' => 'monthly',
                'description' => 'Monthly trend report for service managers, including relief classifications and safety outcomes.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => [
                    'answer_rate', 'abandoned_interactions', 'ultra_short_contacts', 'nuisance_contacts', 'silent_contacts',
                    ...$transfers, 'qa_average_score', 'incidents_opened', 'callbacks_on_time', 'reconciliation_exceptions',
                ],
            ],
            [
                'key' => 'warm-transfer-quality', 'name' => 'Warm transfer quality', 'category' => 'transfers', 'grain' => 'daily',
                'description' => 'Dentist ring, answer, handover and consultation evidence with per-dentist attribution.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => [...$transfers, 'consultation_minutes', 'dentist_roster_hours'],
            ],
            [
                'key' => 'callback-follow-up', 'name' => 'Callback follow-up', 'category' => 'follow_up', 'grain' => 'daily',
                'description' => 'Callback demand, completion and on-time performance against the recorded due time.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => ['callbacks_booked', 'callbacks_completed', 'callbacks_on_time', 'callbacks_overdue_open'],
            ],
            [
                'key' => 'quality-assurance', 'name' => 'Quality assurance', 'category' => 'quality', 'grain' => 'weekly',
                'description' => 'Finalised QA scoring, critical fails and outstanding coaching by agent.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => ['qa_reviews_finalised', 'qa_average_score', 'qa_critical_fails', 'coaching_outstanding'],
            ],
            [
                'key' => 'dentist-engagement', 'name' => 'Dentist engagement and cost', 'category' => 'dentists', 'grain' => 'daily',
                'description' => 'Rostered dentist time, transfer volume and consultation cost when a tariff is configured.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => [
                    'dentist_roster_hours', 'transfer_attempts', 'transfer_answer_rate', 'transfer_acceptance_rate',
                    'average_consultation_seconds', 'consultation_minutes', 'estimated_consultation_cost',
                ],
            ],
            [
                'key' => 'safety-incidents', 'name' => 'Safety and incidents', 'category' => 'safety', 'grain' => 'weekly',
                'description' => 'Emergency and urgent demand, incident escalation and corrective action verification.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => [
                    'priority_p1', 'priority_p2', 'incidents_opened', 'incidents_overdue',
                    'corrective_actions_open', 'corrective_actions_verified', 'reconciliation_exceptions',
                ],
            ],
            [
                'key' => 'short-call-dispositions', 'name' => 'Short and nuisance call dispositions', 'category' => 'safety', 'grain' => 'daily',
                'description' => 'Factual abandoned, silent, nuisance and ultra-short classification with supervisor review demand.',
                'permission' => 'view reports', 'sensitive' => false, 'kpis' => [
                    'abandoned_interactions', 'ultra_short_contacts', 'nuisance_contacts', 'silent_contacts',
                    'priority_review', 'recorded_case_revisions',
                ],
            ],
            [
                'key' => 'executive-summary', 'name' => 'Executive summary', 'category' => 'executive', 'grain' => 'monthly',
                'description' => 'Aggregated service-level summary without per-agent or per-dentist identities.',
                'permission' => 'view executive reports', 'sensitive' => true, 'kpis' => [
                    'inbound_interactions', 'answer_rate', 'abandoned_interactions', 'priority_p1', 'priority_p2',
                    'transfer_acceptance_rate', 'qa_average_score', 'incidents_opened', 'reconciliation_variance',
                ],
            ],
        ];
    }

    /**
     * Compute the requested KPIs for a reporting window.
     *
     * Rows carry their catalogue definition, numerator, denominator and
     * exclusions so a reviewer can reproduce every published figure.
     *
     * @param  list<string>  $keys
     * @return list<array{key: string, label: string, category: string, unit: string, value: float|int|null, numerator: float|int|null, denominator: float|int|null, definition: string, exclusions: list<string>}>
     */
    public function compute(array $keys, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $values = app(ReportMetrics::class)->values($from, $to);
        $rows = [];

        foreach ($keys as $key) {
            if (! isset(self::KPI_CATALOGUE[$key], $values[$key])) {
                continue;
            }

            $meta = self::KPI_CATALOGUE[$key];

            $rows[] = [
                'key' => $key,
                'label' => $meta['label'],
                'category' => $meta['category'],
                'unit' => $meta['unit'],
                'value' => $values[$key]['value'],
                'numerator' => $values[$key]['numerator'],
                'denominator' => $values[$key]['denominator'],
                'definition' => $meta['definition'],
                'exclusions' => $meta['exclusions'],
            ];
        }

        return $rows;
    }
}
