<?php

namespace App\Services;

use App\Models\CorrectiveAction;
use App\Models\Incident;
use App\Models\IncidentEvidenceAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IncidentRegister
{
    /** @var list<string> */
    public const CATEGORIES = Incident::CATEGORIES;

    /** @var list<string> */
    public const SEVERITIES = Incident::SEVERITIES;

    /** @var list<string> */
    public const STATUSES = Incident::STATUSES;

    /** @var array<string, int> */
    public const SLA_HOURS = ['critical' => 1, 'high' => 4, 'medium' => 24, 'low' => 72];

    /** @param array<string, mixed> $data */
    public function register(User $reporter, array $data): Incident
    {
        return DB::transaction(function () use ($reporter, $data): Incident {
            $reference = Str::slug((string) $data['reference']);
            $this->require($reference !== '', 'reference', 'Enter an incident reference containing letters or numbers.');
            $this->require(
                ! Incident::query()->where('reference', $reference)->exists(),
                'reference',
                'This incident reference is already recorded.',
            );
            $this->require(in_array($data['category'], self::CATEGORIES, true), 'category', 'Choose a supported incident category.');
            $this->require(in_array($data['severity'], self::SEVERITIES, true), 'severity', 'Choose a supported incident severity.');
            $occurred = CarbonImmutable::parse((string) $data['occurred_at']);
            $this->require($occurred->lessThanOrEqualTo(now()), 'occurred_at', 'The incident time cannot be in the future.');
            $incident = Incident::query()->create([
                'reference' => $reference,
                'category' => $data['category'],
                'severity' => $data['severity'],
                'status' => 'open',
                'case_id' => $data['case_id'] ?? null,
                'qa_review_id' => $data['qa_review_id'] ?? null,
                'reported_by' => $reporter->id,
                'occurred_at' => $occurred,
                'summary' => $data['summary'],
                'description' => $data['description'],
                'immediate_action' => $data['immediate_action'] ?? null,
                'revision' => 1,
            ]);
            activity('incidents')->causedBy($reporter)->performedOn($incident)->withProperties([
                'operation' => 'reported', 'category' => $incident->category, 'severity' => $incident->severity,
            ])->log('Incident reported');

            return $incident->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function escalate(User $manager, Incident $incident, array $data): Incident
    {
        return DB::transaction(function () use ($manager, $incident, $data): Incident {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $this->require(! $incident->isResolved() && ! $incident->isClosed(), 'status', 'Only open or investigating incidents can be escalated.');
            $severity = (string) $data['severity'];
            $this->require(in_array($severity, self::SEVERITIES, true), 'severity', 'Choose a supported severity.');
            $owner = User::query()->active()->find((int) $data['assigned_to']);
            $this->require($owner instanceof User, 'assigned_to', 'Choose an active incident owner.');
            $this->require((int) $data['revision'] === $incident->revision, 'revision', 'This incident changed. Reload before escalating.');
            $incident->update([
                'severity' => $severity,
                'assigned_to' => $owner->id,
                'status' => 'investigating',
                'escalation_note' => $data['note'] ?? null,
                'escalated_at' => now(),
                'escalation_due_at' => now()->addHours(self::SLA_HOURS[$severity]),
                'revision' => $incident->revision + 1,
            ]);
            activity('incidents')->causedBy($manager)->performedOn($incident)->withProperties([
                'operation' => 'escalated', 'severity' => $severity, 'assigned_to' => $owner->id,
                'sla_hours' => self::SLA_HOURS[$severity],
            ])->log('Incident escalated to a named owner');

            return $incident->refresh();
        });
    }

    public function recordEvidenceAccess(User $manager, Incident $incident, string $purpose): IncidentEvidenceAccess
    {
        return DB::transaction(function () use ($manager, $incident, $purpose): IncidentEvidenceAccess {
            $incident = Incident::query()->findOrFail($incident->id);
            $purpose = trim($purpose);
            $this->require(strlen($purpose) >= 5, 'purpose', 'Record why the incident evidence is being accessed.');
            $access = $incident->evidenceAccesses()->create([
                'user_id' => $manager->id,
                'purpose' => $purpose,
                'accessed_at' => now(),
            ]);
            activity('incidents')->causedBy($manager)->performedOn($incident)->withProperties([
                'operation' => 'evidence_accessed', 'access_id' => $access->id, 'purpose' => $purpose,
            ])->log('Incident evidence accessed');

            return $access;
        });
    }

    /** @param array<string, mixed> $data */
    public function addAction(User $manager, Incident $incident, array $data): CorrectiveAction
    {
        return DB::transaction(function () use ($manager, $incident, $data): CorrectiveAction {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $this->require(! $incident->isClosed(), 'incident', 'Closed incidents cannot receive new corrective actions.');
            $owner = User::query()->active()->find((int) $data['owner_id']);
            $this->require($owner instanceof User, 'owner_id', 'Choose an active corrective action owner.');
            $due = CarbonImmutable::parse((string) $data['due_at']);
            $this->require(! $due->lessThan(now()->startOfDay()), 'due_at', 'Corrective action due dates cannot be in the past.');
            $action = $incident->actions()->create([
                'owner_id' => $owner->id,
                'description' => $data['description'],
                'due_at' => $due,
                'status' => 'open',
            ]);
            activity('incidents')->causedBy($manager)->performedOn($incident)->withProperties([
                'operation' => 'action_assigned', 'action_id' => $action->id, 'owner_id' => $owner->id,
            ])->log('Corrective action assigned');

            return $action->refresh();
        });
    }

    public function completeAction(User $actor, CorrectiveAction $action, string $note): CorrectiveAction
    {
        return DB::transaction(function () use ($actor, $action, $note): CorrectiveAction {
            $action = CorrectiveAction::query()->lockForUpdate()->findOrFail($action->id);
            $this->require($action->status === 'open', 'status', 'Only open corrective actions can be completed.');
            $this->require(trim($note) !== '', 'completion_note', 'Record what was done to complete this action.');
            $action->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completion_note' => $note,
            ]);
            activity('incidents')->causedBy($actor)->performedOn($action->incident)->withProperties([
                'operation' => 'action_completed', 'action_id' => $action->id,
            ])->log('Corrective action completed');

            return $action->refresh();
        });
    }

    public function verifyAction(User $verifier, CorrectiveAction $action, string $note): CorrectiveAction
    {
        return DB::transaction(function () use ($verifier, $action, $note): CorrectiveAction {
            $action = CorrectiveAction::query()->lockForUpdate()->findOrFail($action->id);
            $this->require($action->status === 'completed', 'status', 'Only completed corrective actions can be verified.');
            $this->require($action->owner_id !== $verifier->id, 'verified_by', 'A corrective action must be verified by someone other than its owner.');
            $this->require(trim($note) !== '', 'verification_note', 'Record the evidence that the action is effective.');
            $action->update([
                'status' => 'verified',
                'verified_by' => $verifier->id,
                'verified_at' => now(),
                'verification_note' => $note,
            ]);
            activity('incidents')->causedBy($verifier)->performedOn($action->incident)->withProperties([
                'operation' => 'action_verified', 'action_id' => $action->id,
            ])->log('Corrective action verified');

            return $action->refresh();
        });
    }

    public function resolve(User $manager, Incident $incident, string $summary): Incident
    {
        return DB::transaction(function () use ($manager, $incident, $summary): Incident {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $this->require(in_array($incident->status, ['open', 'investigating'], true), 'status', 'This incident is already resolved or closed.');
            $this->require(strlen(trim($summary)) >= 10, 'resolution_summary', 'Describe the resolution and its evidence.');
            $actions = $incident->actions()->get();
            $this->require(
                $actions->where('status', 'open')->isEmpty(),
                'actions',
                'Complete every corrective action before resolving the incident.',
            );
            $highRisk = in_array($incident->category, ['safety', 'privacy'], true);
            $this->require(
                ! $highRisk || $actions->where('status', 'verified')->isNotEmpty(),
                'actions',
                'Safety and privacy incidents need at least one verified corrective action before resolution.',
            );
            $incident->update([
                'status' => 'resolved',
                'resolved_at' => now(),
                'resolution_summary' => $summary,
                'revision' => $incident->revision + 1,
            ]);
            activity('incidents')->causedBy($manager)->performedOn($incident)->withProperties([
                'operation' => 'resolved', 'actions' => $actions->pluck('id')->all(),
            ])->log('Incident resolved with corrective evidence');

            return $incident->refresh();
        });
    }

    public function close(User $closer, Incident $incident): Incident
    {
        return DB::transaction(function () use ($closer, $incident): Incident {
            $incident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            $this->require($incident->isResolved(), 'status', 'Resolve the incident before closing it.');
            $incident->update([
                'status' => 'closed',
                'closed_by' => $closer->id,
                'closed_at' => now(),
                'revision' => $incident->revision + 1,
            ]);
            activity('incidents')->causedBy($closer)->performedOn($incident)->withProperties([
                'operation' => 'closed',
            ])->log('Incident closed');

            return $incident->refresh();
        });
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
