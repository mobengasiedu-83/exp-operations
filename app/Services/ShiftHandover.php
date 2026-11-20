<?php

namespace App\Services;

use App\Models\CallerCase;
use App\Models\CaseAssignment;
use App\Models\ShiftHandover as ShiftHandoverRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShiftHandover
{
    /** @param array<string, mixed> $data */
    public function assign(CallerCase $case, User $user, array $data): CaseAssignment
    {
        return DB::transaction(function () use ($case, $user, $data): CaseAssignment {
            $case = CallerCase::query()->visibleTo($user)->lockForUpdate()->findOrFail($case->id);
            $this->require($case->status !== 'closed', 'case', 'A closed case cannot be reassigned.');
            $owner = User::query()->whereKey((int) $data['assigned_to'])->where('active', true)->first();
            $this->require($owner !== null, 'assigned_to', 'Choose an active owner for this case.');
            $due = CarbonImmutable::parse((string) $data['due_at']);
            $this->require($due->greaterThanOrEqualTo(now()->startOfMinute()), 'due_at', 'Record when the next action is due (now or later).');

            CaseAssignment::query()->where('case_id', $case->id)->whereNull('ended_at')
                ->update(['ended_at' => now(), 'ended_reason' => 'reassigned', 'updated_at' => now()]);
            $assignment = $case->assignments()->create([
                'assigned_to' => $owner->id, 'assigned_by' => $user->id,
                'next_action' => $data['next_action'], 'due_at' => $due, 'started_at' => now(),
            ]);
            $case->update(['assigned_to' => $owner->id]);
            activity('case-workflow')->causedBy($user)->performedOn($case)->withProperties([
                'operation' => 'assigned', 'owner_id' => $owner->id, 'due_at' => $due->toIso8601String(),
            ])->log('Case ownership and next action recorded');

            return $assignment;
        });
    }

    /** @return Collection<int, CallerCase> */
    public function unresolved(User $owner): Collection
    {
        return CallerCase::query()
            ->where('assigned_to', $owner->id)
            ->where('status', '!=', 'closed')
            ->with(['assignments' => fn ($query) => $query->active()->orderByDesc('id')])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /** @param array<string, mixed> $data */
    public function handover(User $user, array $data): ShiftHandoverRecord
    {
        return DB::transaction(function () use ($user, $data): ShiftHandoverRecord {
            $started = CarbonImmutable::parse((string) $data['shift_started_at']);
            $ended = CarbonImmutable::parse((string) $data['shift_ended_at']);
            $this->require($ended->greaterThan($started), 'shift_ended_at', 'The shift end must be after the shift start.');
            $this->require($ended->lessThanOrEqualTo(now()->addMinutes(5)), 'shift_ended_at', 'Record a handover for a completed shift, not a future one.');

            $incoming = null;
            if (isset($data['incoming_user_id'])) {
                $incoming = User::query()->whereKey((int) $data['incoming_user_id'])->where('active', true)->first();
                $this->require($incoming !== null, 'incoming_user_id', 'Choose an active incoming owner.');
                $this->require($incoming->isNot($user), 'incoming_user_id', 'The incoming owner must be a different operator.');
            }

            $cases = CallerCase::query()
                ->where('assigned_to', $user->id)
                ->where('status', '!=', 'closed')
                ->with(['assignments' => fn ($query) => $query->active()->orderByDesc('id')])
                ->lockForUpdate()
                ->get();
            $missing = $cases->filter(fn (CallerCase $case): bool => $case->assignments->isEmpty());
            $this->require(
                $missing->isEmpty(),
                'unresolved_cases',
                'Every unresolved case needs an owner and a due next action before the shift can be handed over. Missing case IDs: '.$missing->pluck('id')->implode(', ').'.',
            );

            $snapshot = $cases->map(fn (CallerCase $case): array => [
                'case_id' => $case->id, 'priority' => $case->priority, 'status' => $case->status,
                'owner_id' => $user->id, 'owner' => $user->name,
                'due_at' => $case->assignments->first()?->due_at?->toIso8601String(),
            ])->values()->all();

            $handover = ShiftHandoverRecord::query()->create([
                'outgoing_user_id' => $user->id,
                'incoming_user_id' => $incoming?->id,
                'recorded_by' => $user->id,
                'shift_started_at' => $started,
                'shift_ended_at' => $ended,
                'unresolved_cases' => $snapshot,
                'notes' => $data['notes'] ?? null,
                'acknowledged_at' => $incoming !== null && (bool) ($data['acknowledged'] ?? false) ? now() : null,
            ]);
            activity('case-workflow')->causedBy($user)->performedOn($handover)->withProperties([
                'operation' => 'shift_handover', 'unresolved' => count($snapshot), 'incoming_id' => $incoming?->id,
            ])->log('Shift handover recorded');

            return $handover;
        });
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
