<?php

namespace App\Services;

use App\Models\Interaction;
use App\Models\TransferAttempt;

/** Publishes the key call milestones from durable PBX and operator evidence. */
class CallLifecycle
{
    /**
     * @return array{arrived_at: string|null, selected_agent_at: string|null, agent_answered_at: string|null, ended_at: string|null, status: string, disposition: string|null, attempts: list<array<string, mixed>>}
     */
    public function for(?Interaction $interaction): array
    {
        if ($interaction === null) {
            return ['arrived_at' => null, 'selected_agent_at' => null, 'agent_answered_at' => null, 'ended_at' => null, 'status' => 'unknown', 'disposition' => null, 'attempts' => []];
        }
        $interaction->loadMissing(['legs', 'ivrSteps', 'attempts.dentist', 'callerCase']);
        $arrival = $interaction->legs->where('role', 'caller')->pluck('started_at')->filter()->sort()->first()
            ?? $interaction->started_at;
        $answered = $interaction->legs->where('role', 'agent')->pluck('answered_at')->filter()->sort()->first();
        $disposition = $interaction->callerCase?->disposition;

        return [
            'arrived_at' => $arrival?->toIso8601String(),
            'selected_agent_at' => $interaction->ivrSteps->firstWhere('option', '3')?->occurred_at?->toIso8601String(),
            'agent_answered_at' => $answered?->toIso8601String(),
            'ended_at' => $interaction->ended_at?->toIso8601String(),
            'status' => $interaction->status,
            'disposition' => $disposition === null ? null : (CaseWorkflow::DISPOSITIONS[$disposition]['label'] ?? $disposition),
            'attempts' => $interaction->attempts->map(function (TransferAttempt $attempt): array {
                $label = match ($attempt->status) {
                    'dialling' => 'Dialling', 'ringing' => 'Ringing',
                    'network_answered' => 'Network answered · human acceptance unconfirmed',
                    'unanswered' => 'No answer', 'busy' => 'Busy', 'cancelled' => 'Cancelled',
                    'failed' => 'Failed', 'transfer_failed' => 'Transfer failed',
                    'voicemail' => 'Voicemail', 'declined' => 'Declined',
                    'accepted' => 'Dentist accepted', 'completed' => 'Completed',
                    'consultation_observed' => 'Caller–dentist bridge observed',
                    default => 'Outcome not recorded',
                };

                return [
                    'id' => $attempt->id, 'sequence' => $attempt->sequence,
                    'dentist' => $attempt->dentist?->name ?? 'Dentist not identified',
                    'destination' => $attempt->destination_masked,
                    'dialled_at' => $attempt->dial_started_at?->toIso8601String(),
                    'ringing_at' => $attempt->ringing_at?->toIso8601String(),
                    'answered_at' => $attempt->answered_at?->toIso8601String(),
                    'human_accepted_at' => $attempt->human_accepted_at?->toIso8601String(),
                    'transferred_at' => $attempt->transfer_completed_at?->toIso8601String(),
                    'ended_at' => $attempt->ended_at?->toIso8601String(),
                    'status' => $attempt->status, 'outcome' => $label, 'dial_status' => $attempt->dial_status,
                    'failure_reason' => $attempt->failure_reason,
                ];
            })->values()->all(),
        ];
    }
}
