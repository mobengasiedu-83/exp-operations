<?php

namespace App\Services;

use App\Models\CallerCase;
use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\Screening;
use App\Models\ScreeningAnswer;
use App\Models\TransferAttempt;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CallHistoryRecord
{
    /** Human labels for the three attribution states shown in history and detail. */
    public const CONNECTION_LABELS = [
        'verified' => 'Connected',
        'unverified' => 'Unverified',
        'none' => 'No connected dentist',
    ];

    /**
     * Row-level record shared by the history table and the call detail header.
     * Attribution is derived from evidence only: a network answer is never
     * promoted to a connected dentist, and incomplete bridge evidence is
     * reported as unverified rather than as a consultation.
     *
     * @return array<string, mixed>
     */
    public function summary(Interaction $interaction, User $user): array
    {
        $case = $interaction->callerCase;
        $canRead = $case !== null && $user->can('view cases') && ($user->can('view all cases') || $case->assigned_to === $user->id);
        $attempts = $interaction->attempts;
        $connected = $attempts->last(fn (TransferAttempt $attempt): bool => $attempt->isVerifiedConnection());
        $unverified = $connected === null
            ? $attempts->last(fn (TransferAttempt $attempt): bool => $attempt->hasUnverifiedBridgeEvidence())
            : null;
        $state = match (true) {
            $connected !== null => 'verified',
            $unverified !== null => 'unverified',
            default => 'none',
        };
        $last = $attempts->last();
        $answers = $canRead ? $this->latestAnswers($case) : collect();

        return [
            'caller_name' => $canRead ? $case->preferred_name : null,
            'agent' => $canRead ? $case->agent?->name : null,
            'connected_dentist' => $connected?->dentist?->name ?? self::CONNECTION_LABELS['none'],
            'connected_destination' => $connected?->destination_masked,
            'connection_state' => $state,
            'connection_label' => self::CONNECTION_LABELS[$state],
            'unverified_dentist' => $unverified?->dentist?->name,
            'attempted_dentists' => $attempts->map(fn (TransferAttempt $attempt): ?string => $attempt->dentist?->name)
                ->filter()->unique()->values()->all(),
            'attempts_count' => $attempts->count(),
            'outcome' => $connected !== null ? 'verified_connection' : ($last?->status ?? 'no_attempt'),
            'outcome_label' => $this->outcomeLabel($connected, $last, $state),
            'transfer_completed_at' => $connected?->transfer_completed_at?->toIso8601String(),
            'duration_seconds' => $interaction->ended_at !== null && $interaction->started_at !== null
                ? $interaction->started_at->diffInSeconds($interaction->ended_at) : null,
            'answered_questions' => $answers->where('answer_status', 'answered')->count(),
            'question_count' => $answers->count(),
            'case_record' => $canRead ? $case : null,
        ];
    }

    /**
     * Durations for the call detail page. Every metric is measured from
     * retained evidence; a metric the PBX never supplied stays null so the
     * screen can print "Not available" instead of inventing a value.
     *
     * @return array<string, float|null>
     */
    public function durations(Interaction $interaction): array
    {
        $interaction->loadMissing(['legs', 'attempts.session']);
        $caller = $interaction->legs->first(fn (CallLeg $leg): bool => $leg->role === 'caller');
        $attempts = $interaction->attempts;
        $connected = $attempts->last(fn (TransferAttempt $attempt): bool => $attempt->isVerifiedConnection());
        $measured = $connected ?? $attempts->last();
        $firstDial = $attempts->first(fn (TransferAttempt $attempt): bool => $attempt->dial_started_at !== null);
        $bridgeStart = $connected?->transfer_completed_at ?? $connected?->human_accepted_at;

        return [
            'inbound_wait_seconds' => $this->elapsed($caller?->started_at, $caller?->answered_at),
            'agent_talk_seconds' => $this->elapsed($caller?->answered_at, $firstDial?->dial_started_at),
            'hold_seconds' => $this->elapsed($firstDial?->dial_started_at, $bridgeStart),
            'dial_to_answer_seconds' => $measured?->dial_to_answer_seconds,
            'ringing_seconds' => $measured?->ringing_seconds,
            'private_handover_seconds' => $measured?->private_handover_seconds,
            'dentist_connected_seconds' => $measured?->mobile_connected_seconds,
            'verified_bridge_seconds' => $connected?->session?->consultation_seconds,
            'total_seconds' => $this->elapsed($interaction->started_at, $interaction->ended_at),
        ];
    }

    private function outcomeLabel(?TransferAttempt $connected, ?TransferAttempt $last, string $state): string
    {
        if ($connected !== null) {
            return 'Connected';
        }

        if ($state === 'unverified') {
            return 'Unverified bridge · human acceptance not confirmed';
        }

        return match ($last?->status) {
            null => 'Not transferred',
            'network_answered' => 'Network answered · human acceptance unconfirmed',
            'unanswered' => 'No answer',
            'transfer_failed' => 'Transfer failed',
            default => Str::headline($last->status),
        };
    }

    /** @return Collection<int, ScreeningAnswer> */
    private function latestAnswers(?CallerCase $case): Collection
    {
        $screening = $case?->screenings->sortByDesc('id')->first();

        return $screening instanceof Screening ? $screening->answers : collect();
    }

    private function elapsed(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        return (float) $from->diffInSeconds($to);
    }
}
