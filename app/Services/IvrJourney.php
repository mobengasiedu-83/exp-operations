<?php

namespace App\Services;

use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\IvrStep;

/**
 * Reads the inbound IVR journey from projected evidence.
 *
 * The service never infers a key press. Where the dialplan was not captured the
 * journey says "option not recorded" and the screen shows that wording, so a
 * self-service call can never be presented as an agent call (or the reverse).
 */
class IvrJourney
{
    public function agentOption(): string
    {
        return (string) config('exp_ivr.agent_option', '3');
    }

    /** @return array<string, array{label: string, kind: string}> */
    public function options(): array
    {
        /** @var array<string, array{label: string, kind: string}> $options */
        $options = (array) config('exp_ivr.options', []);

        return $options;
    }

    public function optionLabel(?string $option): string
    {
        if ($option === null || $option === '') {
            return 'Menu option not recorded';
        }

        $label = $this->options()[$option]['label'] ?? null;

        return $label === null ? 'Option '.$option.' · unlabelled menu option' : 'Option '.$option.' · '.$label;
    }

    public function optionKind(?string $option): string
    {
        return $option === null ? 'unknown' : ($this->options()[$option]['kind'] ?? 'unknown');
    }

    /**
     * Was the agent option pressed on this call?
     */
    public function selectedAgentOption(Interaction $interaction): bool
    {
        return $interaction->ivrSteps->contains(fn (IvrStep $step): bool => $step->option === $this->agentOption());
    }

    /**
     * Ordered key presses, oldest first.
     *
     * @return list<array{option: string|null, label: string, kind: string, at: string|null}>
     */
    public function selections(Interaction $interaction): array
    {
        return array_values($interaction->ivrSteps
            ->map(fn (IvrStep $step): array => [
                'option' => $step->option,
                'label' => $this->optionLabel($step->option),
                'kind' => $this->optionKind($step->option),
                'at' => $step->occurred_at?->toIso8601String(),
            ])
            ->all());
    }

    /**
     * Compact journey chips for the DID list.
     *
     * @param  array<string, mixed>  $summary
     * @return list<array{label: string, tone: string, title: string}>
     */
    public function chips(Interaction $interaction, array $summary): array
    {
        $selections = $this->selections($interaction);
        $chips = [[
            'label' => 'DID',
            'tone' => 'muted',
            'title' => 'Caller reached '.($this->inboundLine($interaction) ?? 'an assigned DID'),
        ]];

        foreach ($selections as $selection) {
            $chips[] = [
                'label' => 'Option '.($selection['option'] ?? '?'),
                'tone' => $this->tone($selection['kind']),
                'title' => $selection['label'],
            ];
        }

        if ($selections === [] && $this->reachedAgent($interaction)) {
            $chips[] = ['label' => 'Agent route', 'tone' => 'brand', 'title' => 'The PBX routed the caller to the agent extension without a recorded menu step'];
        } elseif ($selections === []) {
            $chips[] = ['label' => 'Menu not recorded', 'tone' => 'muted', 'title' => 'No IVR key press was received for this call'];
        }

        $chips[] = [
            'label' => $this->shortOutcome($summary),
            'tone' => $this->outcomeTone($summary),
            'title' => (string) ($summary['outcome_label'] ?? ''),
        ];

        return $chips;
    }

    /**
     * Full journey for the timeline component.
     *
     * @param  array<string, mixed>  $summary
     * @return list<array{key: string, label: string, detail: string|null, at: string|null, tone: string}>
     */
    public function track(Interaction $interaction, array $summary): array
    {
        $steps = [];
        $caller = $interaction->legs->first(fn (CallLeg $leg): bool => $leg->role === 'caller');
        $selections = $this->selections($interaction);

        $steps[] = [
            'key' => 'inbound',
            'label' => 'Caller reached the assigned DID',
            'detail' => $this->inboundLine($interaction) === null ? null : 'Line '.$this->inboundLine($interaction),
            'at' => $caller?->started_at?->toIso8601String() ?? $interaction->started_at?->toIso8601String(),
            'tone' => 'brand',
        ];

        foreach ($selections as $selection) {
            $steps[] = [
                'key' => 'ivr-'.($selection['option'] ?? 'unknown'),
                'label' => 'Caller pressed '.($selection['option'] ?? '?').' on the menu',
                'detail' => $selection['label'],
                'at' => $selection['at'],
                'tone' => $this->tone($selection['kind']),
            ];
        }

        if ($selections === [] && $this->reachedAgent($interaction)) {
            $steps[] = [
                'key' => 'agent-route',
                'label' => 'Routed to the agent queue',
                'detail' => 'No menu step was captured; the route is taken from the agent leg.',
                'at' => $caller?->answered_at?->toIso8601String(),
                'tone' => 'brand',
            ];
        }

        if ($interaction->callerCase !== null) {
            $answered = (int) ($summary['answered_questions'] ?? 0);
            $questions = (int) ($summary['question_count'] ?? 0);
            $steps[] = [
                'key' => 'screening',
                'label' => 'Agent screening recorded',
                'detail' => $questions === 0 ? 'No questionnaire answers stored' : $answered.' of '.$questions.' answers recorded',
                'at' => $caller?->answered_at?->toIso8601String(),
                'tone' => $answered > 0 ? 'success' : 'muted',
            ];
        }

        if ((int) ($summary['attempts_count'] ?? 0) > 0) {
            $steps[] = [
                'key' => 'transfer',
                'label' => 'Dentist transfer attempted',
                'detail' => (string) ($summary['outcome_label'] ?? 'Attempt recorded'),
                'at' => $summary['transfer_completed_at'] ?? null,
                'tone' => $this->outcomeTone($summary),
            ];
        }

        $steps[] = [
            'key' => 'end',
            'label' => $interaction->ended_at === null ? 'Call still active' : 'Call ended',
            'detail' => $this->outcome($interaction, $summary),
            'at' => $interaction->ended_at?->toIso8601String(),
            'tone' => $interaction->ended_at === null ? 'warning' : 'muted',
        ];

        return $steps;
    }

    /**
     * The published result of the journey, in caller-facing wording.
     *
     * @param  array<string, mixed>  $summary
     */
    public function outcome(Interaction $interaction, array $summary): string
    {
        if ($interaction->callerCase !== null || $this->reachedAgent($interaction) || $this->selectedAgentOption($interaction)) {
            return (string) ($summary['outcome_label'] ?? 'Agent call');
        }

        $last = $interaction->ivrSteps->last();

        return match ($this->optionKind($last?->option)) {
            'recording' => 'Recorded message option · caller did not request an agent',
            'unknown' => $last === null ? 'IVR option not recorded for this call' : 'Self-service option recorded',
            default => 'Self-service · no agent contact',
        };
    }

    public function reachedAgent(Interaction $interaction): bool
    {
        return $interaction->legs->contains(fn (CallLeg $leg): bool => $leg->role === 'agent');
    }

    public function inboundLine(Interaction $interaction): ?string
    {
        return $interaction->legs
            ->first(fn (CallLeg $leg): bool => $leg->role === 'caller' && $leg->inbound_masked !== null)
            ?->inbound_masked;
    }

    private function tone(string $kind): string
    {
        return match ($kind) {
            'agent' => 'brand',
            'recording' => 'info',
            'callback' => 'warning',
            default => 'muted',
        };
    }

    /** @param array<string, mixed> $summary */
    private function outcomeTone(array $summary): string
    {
        return match ($summary['connection_state'] ?? 'none') {
            'verified' => 'success',
            'unverified' => 'warning',
            default => ((int) ($summary['attempts_count'] ?? 0)) > 0 ? 'danger' : 'muted',
        };
    }

    /** @param array<string, mixed> $summary */
    private function shortOutcome(array $summary): string
    {
        return match ($summary['connection_state'] ?? 'none') {
            'verified' => 'Connected',
            'unverified' => 'Unverified',
            default => ((int) ($summary['attempts_count'] ?? 0)) > 0 ? 'Transfer failed' : 'No agent',
        };
    }
}
