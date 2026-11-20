<?php

namespace App\Services;

use App\Models\CallerCase;
use App\Models\CallEvent;
use App\Models\DentistNumber;
use App\Models\Interaction;
use App\Models\TransferAttempt;
use App\Models\TransferEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TransferTracker
{
    public const OUTCOMES = ['accepted', 'declined', 'voicemail', 'failed_transfer', 'unknown'];

    public const FALLBACKS = ['pending', 'retry_requested', 'callback_requested', 'urgent_referral', 'no_further_action'];

    public function sync(Interaction $interaction): void
    {
        DB::transaction(function () use ($interaction): void {
            $interaction = Interaction::query()->lockForUpdate()->findOrFail($interaction->id);
            $legs = $interaction->legs()->where('role', 'mobile')->whereNotNull('dial_started_at')->orderBy('dial_started_at')->orderBy('id')->get();
            $metrics = $interaction->metrics()->get()->keyBy('call_leg_id');
            $bridges = $interaction->bridges()->where('stage', 'consultation')->orderBy('started_at')->get();
            $events = $interaction->events()->orderBy('occurred_at')->orderBy('id')->get();
            $numbers = DentistNumber::query()->contactable()->get()->keyBy(fn (DentistNumber $number): string => GhanaPhoneNumber::fingerprint($number->number) ?? '');
            $previous = null;
            foreach ($legs as $position => $leg) {
                $hash = $leg->destination_hash ?? GhanaPhoneNumber::fingerprint(GhanaPhoneNumber::fromChannel($leg->channel));
                $number = $hash === null ? null : $numbers->get($hash);
                $attempt = TransferAttempt::query()->firstOrNew(['call_leg_id' => $leg->id]);
                $human = $attempt->exists ? $attempt->events()->where('kind', 'operator_outcome')->latest('id')->first() : null;
                $fallback = $attempt->exists ? $attempt->events()->where('kind', 'fallback')->latest('id')->value('outcome') : null;
                $humanOutcome = $human instanceof TransferEvent ? $human->outcome : 'unknown';
                $metric = $metrics->get($leg->id);
                $relevant = $events->filter(fn (CallEvent $event): bool => collect($this->participants($event))->contains('uniqueid', $leg->uniqueid));
                $transferEvents = $relevant->filter(function (CallEvent $event) use ($legs, $leg): bool {
                    if (! in_array($event->event_name, ['AttendedTransfer', 'BlindTransfer'], true)) {
                        return false;
                    }
                    $mobileParticipants = collect($this->participants($event))->pluck('uniqueid')->intersect($legs->pluck('uniqueid'));

                    return $mobileParticipants->count() === 1 && $mobileParticipants->contains($leg->uniqueid);
                });
                $completed = $transferEvents->first(fn (CallEvent $event): bool => ($event->data['transfer_result'] ?? null) === 'Success');
                $failed = $transferEvents->first(fn (CallEvent $event): bool => in_array($event->data['transfer_result'] ?? null, ['Fail', 'Invalid', 'Not Permitted'], true));
                $intervals = $bridges->filter(fn ($bridge): bool => in_array($leg->uniqueid, $bridge->participants, true))->values();
                $hasConsultation = $intervals->isNotEmpty();
                $humanValid = $human !== null && $leg->answered_at !== null && $human->occurred_at->gte($leg->answered_at)
                    && ($leg->ended_at === null || $human->occurred_at->lte($leg->ended_at));
                $accepted = $humanOutcome === 'accepted' && $humanValid;
                $status = match (true) {
                    $humanOutcome === 'voicemail' && $humanValid => 'voicemail',
                    $humanOutcome === 'declined' && $humanValid => 'declined',
                    $humanOutcome === 'failed_transfer', $failed !== null => 'transfer_failed',
                    $accepted && $completed !== null && $hasConsultation && $leg->ended_at !== null => 'completed',
                    $accepted => 'accepted',
                    $hasConsultation => 'consultation_observed',
                    $leg->answered_at !== null => 'network_answered',
                    $leg->dial_status === 'BUSY' => 'busy',
                    $leg->dial_status === 'NOANSWER' => 'unanswered',
                    $leg->dial_status === 'CANCEL' => 'cancelled',
                    in_array($leg->dial_status, ['CONGESTION', 'CHANUNAVAIL', 'DONTCALL', 'TORTURE', 'INVALIDARGS'], true) => 'failed',
                    $leg->ended_at !== null => 'failed',
                    $leg->ringing_at !== null => 'ringing',
                    default => 'dialling',
                };
                $attempt->fill([
                    'interaction_id' => $interaction->id, 'dentist_id' => $number?->dentist_id,
                    'retry_of_id' => $previous?->id, 'sequence' => $position + 1,
                    'destination_masked' => $leg->destination_masked ?? GhanaPhoneNumber::mask(GhanaPhoneNumber::fromChannel($leg->channel)),
                    'match_status' => $number instanceof DentistNumber ? $number->status : 'unknown', 'dial_status' => $leg->dial_status,
                    'status' => $status,
                    'failure_reason' => match ($status) {
                        'busy', 'unanswered', 'cancelled', 'failed' => $leg->dial_status ?? 'incomplete_telemetry',
                        'transfer_failed' => $failed?->data['transfer_result'] ?? 'operator_reported',
                        'voicemail', 'declined' => 'operator_confirmed',
                        default => null,
                    },
                    'human_outcome' => $humanOutcome, 'acceptance_confidence' => $accepted ? 'agent_confirmed' : ($leg->answered_at !== null ? 'network_only' : 'none'),
                    'fallback_status' => $fallback ?? 'pending', 'timing_quality' => $interaction->timing_quality,
                    'dial_started_at' => $leg->dial_started_at, 'ringing_at' => $leg->ringing_at, 'answered_at' => $leg->answered_at, 'ended_at' => $leg->ended_at,
                    'transfer_completed_at' => $completed?->occurred_at, 'human_accepted_at' => $accepted ? $human->occurred_at : null,
                    'dial_to_answer_seconds' => $metric?->dial_to_answer_seconds, 'ringing_seconds' => $metric?->ringing_seconds,
                    'mobile_connected_seconds' => $metric?->mobile_connected_seconds, 'private_handover_seconds' => $metric?->private_handover_seconds, 'consultation_seconds' => $metric?->consultation_seconds,
                ]);
                if ($attempt->exists && $attempt->isDirty()) {
                    $attempt->revision++;
                }
                $attempt->save();
                foreach ($relevant as $event) {
                    $attempt->events()->updateOrCreate(['fingerprint' => hash('sha256', $attempt->id.':'.$event->id)], [
                        'call_event_id' => $event->id, 'kind' => 'telemetry', 'outcome' => $event->event_name, 'occurred_at' => $event->occurred_at,
                    ]);
                }
                if ($hasConsultation) {
                    $attempt->session()->updateOrCreate([], [
                        'status' => $humanOutcome === 'voicemail' && $humanValid ? 'voicemail_bridge' : ($accepted ? 'human_confirmed' : 'unconfirmed'),
                        'started_at' => $intervals->first()->started_at,
                        'ended_at' => $intervals->contains(fn ($interval): bool => $interval->ended_at === null) ? null : $intervals->last()->ended_at,
                        'consultation_seconds' => $metric?->consultation_seconds,
                        'intervals' => $intervals->map(fn ($interval): array => $interval->only(['bridge_uniqueid', 'started_at', 'ended_at', 'duration_seconds']))->all(),
                    ]);
                } elseif ($attempt->session()->exists()) {
                    $attempt->session()->update(['status' => 'evidence_withdrawn', 'started_at' => null, 'ended_at' => null, 'consultation_seconds' => null, 'intervals' => []]);
                }
                $previous = $attempt;
            }
        });
    }

    /** @param array<string, mixed> $data */
    public function record(TransferAttempt $attempt, User $user, array $data): void
    {
        DB::transaction(function () use ($attempt, $user, $data): void {
            $interaction = Interaction::query()->lockForUpdate()->findOrFail($attempt->interaction_id);
            $attempt = TransferAttempt::query()->lockForUpdate()->findOrFail($attempt->id);
            abort_unless(CallerCase::query()->visibleTo($user)->where('interaction_id', $interaction->id)->exists(), 404);
            $this->require($attempt->revision === (int) $data['revision'], 'revision', 'Transfer evidence changed. Reload before recording a confirmation.');
            $at = CarbonImmutable::parse($data['occurred_at']);
            $this->require($attempt->dial_started_at !== null && $at->gte($attempt->dial_started_at) && $at->lte(CarbonImmutable::now()), 'occurred_at', 'Use the actual action time between dial start and now.');
            if ($data['kind'] === 'operator_outcome') {
                $this->require($attempt->ended_at === null || $at->lte($attempt->ended_at), 'occurred_at', 'The reported call outcome must occur before the mobile leg ended.');
                if (in_array($data['outcome'], ['accepted', 'declined', 'voicemail'], true)) {
                    $this->require($attempt->answered_at !== null && $at->gte($attempt->answered_at), 'outcome', 'Network answer evidence is required before confirming a person or voicemail answered.');
                }
                if ($data['outcome'] === 'accepted') {
                    $this->require($attempt->dentist_id !== null && $attempt->match_status === 'approved', 'outcome', 'Confirm the destination against an approved directory number before recording dentist acceptance.');
                }
            } else {
                $case = CallerCase::query()->where('interaction_id', $interaction->id)->firstOrFail();
                if ($data['outcome'] === 'callback_requested') {
                    $consent = $case->consents()->latest('id')->first();
                    $this->require($case->priority === 'P3' && $consent?->status === 'granted' && $consent->callback_allowed && filled($case->callback_number), 'outcome', 'Routine callback intent requires P3 classification, current callback permission and a confirmed number. Emergency and urgent cases must use their approved escalation route.');
                }
                $this->require($case->priority !== 'P1' || ! in_array($data['outcome'], ['retry_requested', 'callback_requested'], true), 'outcome', 'Emergency care must not wait for another dentist attempt or callback.');
            }
            $event = $attempt->events()->create([
                'kind' => $data['kind'], 'outcome' => $data['outcome'], 'note' => $data['note'], 'occurred_at' => $at, 'recorded_by' => $user->id,
            ]);
            $attempt->increment('revision');
            $this->sync($interaction);
            activity('transfer-tracking')->causedBy($user)->performedOn($attempt)->withProperties(['event_id' => $event->id, 'kind' => $data['kind'], 'outcome' => $data['outcome']])->log('Operator transfer evidence recorded');
        });
    }

    /**
     * Extract the participant list from an AMI event payload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function participants(CallEvent $event): array
    {
        $participants = $event->data['participants'] ?? [];

        return is_array($participants) ? array_values(array_filter($participants, is_array(...))) : [];
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
