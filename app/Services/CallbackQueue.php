<?php

namespace App\Services;

use App\Models\Callback;
use App\Models\CallbackAttempt;
use App\Models\CallerCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CallbackQueue
{
    /** @param array<string, mixed> $data */
    public function book(CallerCase $case, User $user, array $data): Callback
    {
        return DB::transaction(function () use ($case, $user, $data): Callback {
            $case = CallerCase::query()->visibleTo($user)->lockForUpdate()->findOrFail($case->id);
            $this->require($case->status !== 'closed', 'case', 'A closed case cannot receive a new callback.');
            $this->require($case->priority !== 'P1', 'case', 'Emergency (P1) cases never wait in a callback queue. Confirm the approved emergency action and supervisor escalation instead.');
            $this->require(
                ! $case->referrals()->open()->where('route', 'urgent')->exists(),
                'case',
                'An urgent referral is outstanding. Priority clinician review must not be replaced by a routine callback.',
            );
            $consent = $case->consents()->latest('id')->first();
            $this->require(
                $consent !== null && $consent->status === 'granted' && $consent->callback_allowed,
                'consent_record_id',
                'A current granted consent record with callback permission is required before a callback can be booked.',
            );
            $this->require(
                ! $case->callbacks()->open()->exists(),
                'case',
                'This case already has an open callback. Complete or cancel it before booking another.',
            );
            $due = CarbonImmutable::parse((string) $data['due_at']);
            $this->require($due->greaterThan(now()), 'due_at', 'Book a callback due in the future within the approved contact window.');
            $this->withinWindow($due, (string) $data['window_start'], (string) $data['window_end']);
            $owner = User::query()->whereKey((int) $data['assigned_to'])->where('active', true)->first();
            $this->require($owner !== null, 'assigned_to', 'Choose an active owner for the callback.');

            $callback = $case->callbacks()->create([
                'consent_record_id' => $consent->id,
                'assigned_to' => $owner->id,
                'booked_by' => $user->id,
                'status' => 'pending',
                'priority' => $data['priority'],
                'reason' => $data['reason'],
                'due_at' => $due,
                'window_start' => $data['window_start'],
                'window_end' => $data['window_end'],
            ]);
            activity('callbacks')->causedBy($user)->performedOn($callback)->withProperties([
                'operation' => 'booked', 'priority' => $callback->priority, 'owner_id' => $owner->id,
                'due_at' => $due->toIso8601String(), 'window' => $data['window_start'].'–'.$data['window_end'],
            ])->log('Callback booked');
            activity('case-workflow')->causedBy($user)->performedOn($case)
                ->withProperties(['operation' => 'callback_booked', 'callback_id' => $callback->id, 'due_at' => $due->toIso8601String()])
                ->log('Case callback booked');

            return $callback;
        });
    }

    /** @param array<string, mixed> $data */
    public function recordAttempt(Callback $callback, User $user, array $data): CallbackAttempt
    {
        return DB::transaction(function () use ($callback, $user, $data): CallbackAttempt {
            $callback = Callback::query()->visibleTo($user)->lockForUpdate()->findOrFail($callback->id);
            $this->require($callback->isOpen(), 'callback', 'This callback is closed. Attempts can no longer be recorded against it.');
            $voicemail = $data['outcome'] === 'voicemail';
            $this->require(
                ! $voicemail || (bool) ($data['neutral_voicemail_confirmed'] ?? false),
                'neutral_voicemail_confirmed',
                'Confirm the voicemail message carried no clinical or personal detail. Free-text notes are not stored for voicemail attempts.',
            );

            $attempt = $callback->attempts()->create([
                'attempted_by' => $user->id,
                'outcome' => $data['outcome'],
                'neutral_voicemail_confirmed' => $voicemail,
                'note' => $voicemail ? null : ($data['note'] ?? null),
                'attempted_at' => $data['attempted_at'] ?? now(),
            ]);
            if ($data['outcome'] === 'answered') {
                $callback->update(['status' => 'in_progress', 'escalated_at' => null]);
            }
            activity('callbacks')->causedBy($user)->performedOn($callback)->withProperties([
                'operation' => 'attempt', 'outcome' => $data['outcome'], 'revision' => $callback->revision,
            ])->log('Callback attempt recorded');

            return $attempt;
        });
    }

    /** @param array<string, mixed> $data */
    public function complete(Callback $callback, User $user, array $data): Callback
    {
        return DB::transaction(function () use ($callback, $user, $data): Callback {
            $callback = Callback::query()->visibleTo($user)->lockForUpdate()->findOrFail($callback->id);
            $this->require($callback->isOpen(), 'callback', 'This callback is already closed.');
            $this->require($callback->revision === (int) $data['revision'], 'revision', 'This callback changed. Reload it before saving to avoid overwriting another operator.');
            $this->require($callback->attempts()->exists(), 'outcome', 'Record at least one attempt before completing a callback.');

            $callback->update([
                'status' => 'completed', 'outcome' => $data['outcome'], 'outcome_note' => $data['outcome_note'] ?? null,
                'completed_at' => now(), 'completed_by' => $user->id,
            ]);
            $callback->increment('revision');
            activity('callbacks')->causedBy($user)->performedOn($callback)->withProperties([
                'operation' => 'completed', 'outcome' => $data['outcome'], 'revision' => $callback->revision,
            ])->log('Callback completed');

            return $callback;
        });
    }

    /** @param array<string, mixed> $data */
    public function cancel(Callback $callback, User $user, array $data): Callback
    {
        return DB::transaction(function () use ($callback, $user, $data): Callback {
            $callback = Callback::query()->visibleTo($user)->lockForUpdate()->findOrFail($callback->id);
            $this->require($callback->isOpen(), 'callback', 'This callback is already closed.');
            $this->require($callback->revision === (int) $data['revision'], 'revision', 'This callback changed. Reload it before saving to avoid overwriting another operator.');

            $callback->update([
                'status' => 'cancelled', 'outcome' => 'caller_cancelled', 'outcome_note' => $data['outcome_note'],
                'completed_at' => now(), 'completed_by' => $user->id,
            ]);
            $callback->increment('revision');
            activity('callbacks')->causedBy($user)->performedOn($callback)->withProperties([
                'operation' => 'cancelled', 'revision' => $callback->revision,
            ])->log('Callback cancelled');

            return $callback;
        });
    }

    /**
     * Mark callbacks that passed the follow-up SLA without completion for escalation.
     *
     * @return int Number of callbacks escalated by this run.
     */
    public function escalateMissedSlas(): int
    {
        $escalated = 0;
        Callback::query()->overdue()->whereNull('escalated_at')->chunkById(100, function ($callbacks) use (&$escalated): void {
            foreach ($callbacks as $callback) {
                $callback->forceFill(['escalated_at' => now()])->save();
                activity('callbacks')->performedOn($callback)->withProperties([
                    'operation' => 'sla_escalated', 'due_at' => $callback->due_at?->toIso8601String(),
                ])->log('Callback SLA missed; escalated for supervisor follow-up');
                $escalated++;
            }
        });

        return $escalated;
    }

    private function withinWindow(CarbonImmutable $due, string $start, string $end): void
    {
        $time = $due->format('H:i:s');
        $inside = $start <= $end
            ? $time >= $start && $time <= $end
            : $time >= $start || $time <= $end;

        $this->require($inside, 'due_at', 'The due time must fall inside the approved contact window ('.$start.'–'.$end.').');
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
