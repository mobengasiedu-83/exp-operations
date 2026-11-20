<?php

namespace App\Services;

use App\Models\CallLeg;
use App\Models\Interaction;
use Carbon\CarbonInterface;
use Closure;

/**
 * Derives the ordered caller–dentist journey narrative from retained evidence.
 *
 * Every entry carries its source and confidence so a network answer, a transfer
 * event or a bridge interval can never be presented as proof that a dentist
 * accepted the call. Evidence that was never observed stays absent rather than
 * being inferred.
 */
class CallJourneyTimeline
{
    /** Confidence vocabulary rendered by the call detail screen. */
    public const CONFIDENCE = [
        'observed' => 'Observed PBX event',
        'network_only' => 'Network signal only — human acceptance unconfirmed',
        'operator_confirmed' => 'Confirmed by the agent with the dentist',
        'unknown' => 'Evidence incomplete',
    ];

    /** Hard cap so an unusually noisy journey cannot overwhelm the page. */
    private const LIMIT = 300;

    /**
     * @return list<array{at: string|null, stage: string, label: string, detail: string|null, source: string, confidence: string, confidence_label: string}>
     */
    public function for(Interaction $interaction): array
    {
        $interaction->loadMissing(['legs', 'ivrSteps', 'bridges', 'events', 'attempts.dentist', 'attempts.session', 'attempts.events']);

        /** @var list<array{at: CarbonInterface|null, stage: string, label: string, detail: string|null, source: string, confidence: string, confidence_label: string}> $entries */
        $entries = [];

        $add = function (?CarbonInterface $at, string $stage, string $label, string $source, string $confidence, ?string $detail = null) use (&$entries): void {
            $entries[] = [
                'at' => $at,
                'stage' => $stage,
                'label' => $label,
                'detail' => filled($detail) ? $detail : null,
                'source' => $source,
                'confidence' => $confidence,
                'confidence_label' => self::CONFIDENCE[$confidence] ?? $confidence,
            ];
        };

        $this->addLegs($add, $interaction);
        $this->addIvr($add, $interaction);
        $this->addEvents($add, $interaction);
        $this->addBridges($add, $interaction);
        $this->addAttempts($add, $interaction);
        $this->addConsultations($add, $interaction);

        usort($entries, fn (array $left, array $right): int => $this->compare($left['at'], $right['at']));

        return array_map(function (array $entry): array {
            $at = $entry['at'];
            $entry['at'] = $at instanceof CarbonInterface ? $at->toIso8601String() : null;

            return $entry;
        }, array_slice($entries, 0, self::LIMIT));
    }

    /**
     * Menu key presses are evidence from the dialplan, so they are shown with
     * the same confidence as any other observed PBX event.
     *
     * @param  Closure(CarbonInterface|null, string, string, string, string, string|null=): void  $add
     */
    private function addIvr(Closure $add, Interaction $interaction): void
    {
        $journey = app(IvrJourney::class);

        foreach ($interaction->ivrSteps as $step) {
            $add(
                $step->occurred_at,
                'ivr_selection',
                'Caller pressed '.$step->option.' on the IVR menu',
                'ami',
                'observed',
                $journey->optionLabel($step->option),
            );
        }
    }

    /**
     * @param  Closure(CarbonInterface|null, string, string, string, string, string|null=): void  $add
     */
    private function addLegs(Closure $add, Interaction $interaction): void
    {
        $legs = $interaction->legs->sortBy(fn (CallLeg $leg): string => (string) $leg->started_at?->format('Y-m-d H:i:s.u'));

        foreach ($legs as $leg) {
            $line = $leg->inbound_masked ?? $leg->destination_masked ?? $leg->caller_masked;
            $detail = $line === null ? null : 'Line '.$line;

            if ($leg->role === 'caller') {
                $add($leg->started_at, 'inbound_start', 'Caller reached the inbound line', 'ami', 'observed', $detail);
                $add($leg->ringing_at, 'inbound_ring', 'Inbound line ringing', 'ami', 'observed');
                $add($leg->answered_at, 'agent_answer', 'Agent answered the caller', 'ami', 'observed');
                $add($leg->ended_at, 'caller_end', 'Caller line ended', 'ami', 'observed');

                continue;
            }

            if ($leg->role === 'agent') {
                $add($leg->started_at, 'agent_channel', 'Assistant channel created', 'ami', 'observed');
                $add($leg->answered_at, 'agent_channel_answer', 'Assistant channel connected', 'ami', 'observed');
                $add($leg->ended_at, 'agent_exit', 'Assistant channel ended', 'ami', 'observed');

                continue;
            }

            if ($leg->role === 'mobile') {
                $add($leg->dial_started_at ?? $leg->started_at, 'dentist_dial', 'Dentist dial started', 'ami', 'observed', $detail);
                $add($leg->ringing_at, 'dentist_ring', 'Dentist line ringing', 'ami', 'observed');
                $add($leg->answered_at, 'dentist_answer', 'Dentist network answered', 'ami', 'network_only', 'A network answer does not prove a dentist accepted the call.');
                $add($leg->ended_at, 'dentist_hangup', 'Dentist line ended', 'ami', 'observed');
            }
        }
    }

    /**
     * Hold and transfer evidence only appears when the listener retained it.
     *
     * @param  Closure(CarbonInterface|null, string, string, string, string, string|null=): void  $add
     */
    private function addEvents(Closure $add, Interaction $interaction): void
    {
        foreach ($interaction->events->sortBy('occurred_at') as $event) {
            $source = 'ami:'.$event->event_name;

            if ($event->event_name === 'Hold') {
                $add($event->occurred_at, 'hold_start', 'Caller placed on hold', $source, 'observed');

                continue;
            }

            if ($event->event_name === 'Unhold') {
                $add($event->occurred_at, 'hold_end', 'Caller taken off hold', $source, 'observed');

                continue;
            }

            if (in_array($event->event_name, ['AttendedTransfer', 'BlindTransfer'], true)) {
                $result = (string) (data_get($event->data, 'transfer_result') ?? 'unknown');
                $add($event->occurred_at, 'transfer_result', 'Transfer result: '.$result, $source, $result === 'Success' ? 'observed' : 'unknown', 'A transfer event alone does not confirm that a dentist accepted the call.');
            }
        }
    }

    /**
     * @param  Closure(CarbonInterface|null, string, string, string, string, string|null=): void  $add
     */
    private function addBridges(Closure $add, Interaction $interaction): void
    {
        foreach ($interaction->bridges->sortBy('started_at') as $bridge) {
            [$stage, $label] = match ($bridge->stage) {
                'private_handover' => ['private_briefing', 'Private agent–dentist briefing'],
                'consultation' => ['consultation_bridge', 'Caller–dentist consultation bridge'],
                'ambiguous' => ['ambiguous_bridge', 'Bridge participants ambiguous'],
                default => ['bridge', 'Bridge interval'],
            };

            $add($bridge->started_at, $stage, $label.' started', 'bridge', 'observed', 'Bridge '.$bridge->bridge_uniqueid);
            $add(
                $bridge->ended_at,
                $stage.'_end',
                $label.' ended',
                'bridge',
                $bridge->duration_seconds === null ? 'unknown' : 'observed',
                $bridge->duration_seconds === null ? 'End time not retained; duration unknown.' : 'Duration '.$bridge->duration_seconds.' seconds',
            );
        }
    }

    /**
     * @param  Closure(CarbonInterface|null, string, string, string, string, string|null=): void  $add
     */
    private function addAttempts(Closure $add, Interaction $interaction): void
    {
        foreach ($interaction->attempts as $attempt) {
            $dentist = $attempt->dentist?->name ?? 'Unassigned dentist';
            $detail = sprintf(
                'Attempt %d · %s%s',
                $attempt->sequence,
                $dentist,
                $attempt->destination_masked === null ? '' : ' · '.$attempt->destination_masked,
            );

            $add($attempt->dial_started_at, 'attempt_dial', 'Attempt '.$attempt->sequence.' dial started', 'transfer', 'observed', $detail);
            $add($attempt->ringing_at, 'attempt_ring', 'Attempt '.$attempt->sequence.' ringing', 'transfer', 'observed', $detail);
            $add($attempt->answered_at, 'attempt_answer', 'Attempt '.$attempt->sequence.' network answered', 'transfer', 'network_only', $detail);
            $add(
                $attempt->transfer_completed_at,
                'attempt_transfer',
                'Attempt '.$attempt->sequence.' transfer completed',
                'transfer',
                $attempt->human_outcome === 'accepted' ? 'operator_confirmed' : 'unknown',
                $detail,
            );
            $add(
                $attempt->human_accepted_at,
                'attempt_acceptance',
                'Attempt '.$attempt->sequence.' human acceptance recorded',
                'operator',
                'operator_confirmed',
                $detail.' · outcome '.($attempt->human_outcome ?? 'unknown'),
            );
            $add(
                $attempt->ended_at,
                'attempt_end',
                'Attempt '.$attempt->sequence.' ended as '.$attempt->status,
                'transfer',
                'observed',
                $attempt->failure_reason,
            );

            foreach ($attempt->events->where('kind', '!=', 'telemetry')->sortBy('occurred_at') as $event) {
                $add(
                    $event->occurred_at,
                    'operator_evidence',
                    'Attempt '.$attempt->sequence.' operator recorded '.($event->outcome ?? $event->kind),
                    'operator',
                    'operator_confirmed',
                    $detail.($event->note === null ? '' : ' · '.$event->note),
                );
            }
        }
    }

    /**
     * @param  Closure(CarbonInterface|null, string, string, string, string, string|null=): void  $add
     */
    private function addConsultations(Closure $add, Interaction $interaction): void
    {
        foreach ($interaction->attempts as $attempt) {
            $session = $attempt->session;

            if ($session === null) {
                continue;
            }

            $confidence = $session->status === 'human_confirmed' ? 'operator_confirmed' : 'unknown';
            $detail = 'Attempt '.$attempt->sequence.($session->consultation_seconds === null
                ? ' · duration unknown'
                : ' · '.$session->consultation_seconds.' seconds measured');

            $add($session->started_at, 'consultation_start', 'Consultation bridge started', 'consultation_session', $confidence, $detail);
            $add(
                $session->ended_at,
                'consultation_end',
                'Consultation bridge ended',
                'consultation_session',
                $session->ended_at === null ? 'unknown' : $confidence,
                $detail,
            );
        }
    }

    private function compare(?CarbonInterface $left, ?CarbonInterface $right): int
    {
        if ($left === null || $right === null) {
            return ($left === null ? 1 : 0) <=> ($right === null ? 1 : 0);
        }

        return strcmp($left->format('Y-m-d H:i:s.u'), $right->format('Y-m-d H:i:s.u'));
    }
}
