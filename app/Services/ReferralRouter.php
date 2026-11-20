<?php

namespace App\Services;

use App\Models\CallerCase;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReferralRouter
{
    /** @param array<string, mixed> $data */
    public function create(CallerCase $case, User $user, array $data): Referral
    {
        return DB::transaction(function () use ($case, $user, $data): Referral {
            $case = CallerCase::query()->visibleTo($user)->lockForUpdate()->findOrFail($case->id);
            $this->require($case->status !== 'closed', 'case', 'A closed case cannot receive a new referral.');
            $this->require($case->priority !== 'P1', 'route', 'Emergency (P1) handling is never a referral queue item. Confirm the approved emergency action and supervisor escalation instead.');
            $this->require(
                $data['route'] === 'urgent' ? $case->priority === 'P2' : in_array($case->priority, ['P3', 'S'], true),
                'route',
                $data['route'] === 'urgent'
                    ? 'An urgent referral requires a P2 priority case. Complete the safety screen first.'
                    : 'A routine referral requires a P3 or screener-resolved case.',
            );
            $consent = $case->consents()->latest('id')->first();
            $this->require(
                $consent !== null && $consent->status === 'granted',
                'consent_record_id',
                'Record granted consent before sharing clinical detail with a referral destination.',
            );
            $this->require(
                ! $case->referrals()->open()->where('route', $data['route'])->exists(),
                'route',
                'An open referral already exists for this case and route. Record its action or outcome first.',
            );

            $referral = $case->referrals()->create([
                'referred_by' => $user->id,
                'dentist_id' => $data['dentist_id'] ?? null,
                'route' => $data['route'],
                'destination_name' => $data['destination_name'],
                'destination_contact' => $data['destination_contact'] ?? null,
                'reason' => $data['reason'],
                'status' => 'pending',
                'follow_up_at' => $data['follow_up_at'] ?? null,
            ]);
            activity('referrals')->causedBy($user)->performedOn($referral)->withProperties([
                'operation' => 'created', 'route' => $referral->route, 'destination' => $referral->destination_name,
            ])->log('Referral recorded');
            activity('case-workflow')->causedBy($user)->performedOn($case)
                ->withProperties(['operation' => 'referral_recorded', 'referral_id' => $referral->id, 'route' => $referral->route])
                ->log('Case referral recorded');

            return $referral;
        });
    }

    /** @param array<string, mixed> $data */
    public function act(Referral $referral, User $user, array $data): Referral
    {
        return DB::transaction(function () use ($referral, $user, $data): Referral {
            $referral = Referral::query()->visibleTo($user)->lockForUpdate()->findOrFail($referral->id);
            $this->require($referral->status === 'pending', 'status', 'Only a pending referral can be marked as actioned.');
            $this->require($referral->revision === (int) $data['revision'], 'revision', 'This referral changed. Reload it before saving to avoid overwriting another operator.');
            $referral->update([
                'status' => 'actioned', 'action_reference' => $data['action_reference'], 'actioned_at' => now(),
                'updated_by' => $user->id,
            ]);
            $referral->increment('revision');
            activity('referrals')->causedBy($user)->performedOn($referral)->withProperties([
                'operation' => 'actioned', 'reference' => $referral->action_reference, 'revision' => $referral->revision,
            ])->log('Referral action recorded');

            return $referral;
        });
    }

    /** @param array<string, mixed> $data */
    public function recordOutcome(Referral $referral, User $user, array $data): Referral
    {
        return DB::transaction(function () use ($referral, $user, $data): Referral {
            $referral = Referral::query()->visibleTo($user)->lockForUpdate()->findOrFail($referral->id);
            $this->require(in_array($referral->status, ['pending', 'actioned'], true), 'status', 'This referral is already closed.');
            $this->require($referral->revision === (int) $data['revision'], 'revision', 'This referral changed. Reload it before saving to avoid overwriting another operator.');
            $referral->update([
                'status' => 'outcome_recorded', 'outcome' => $data['outcome'], 'outcome_note' => $data['outcome_note'],
                'outcome_recorded_at' => now(), 'updated_by' => $user->id,
            ]);
            $referral->increment('revision');
            activity('referrals')->causedBy($user)->performedOn($referral)->withProperties([
                'operation' => 'outcome_recorded', 'outcome' => $data['outcome'], 'revision' => $referral->revision,
            ])->log('Referral outcome recorded');

            return $referral;
        });
    }

    /** @param array<string, mixed> $data */
    public function cancel(Referral $referral, User $user, array $data): Referral
    {
        return DB::transaction(function () use ($referral, $user, $data): Referral {
            $referral = Referral::query()->visibleTo($user)->lockForUpdate()->findOrFail($referral->id);
            $this->require(in_array($referral->status, ['pending', 'actioned'], true), 'status', 'This referral is already closed.');
            $this->require($referral->revision === (int) $data['revision'], 'revision', 'This referral changed. Reload it before saving to avoid overwriting another operator.');
            $referral->update([
                'status' => 'cancelled', 'outcome_note' => $data['outcome_note'], 'updated_by' => $user->id,
            ]);
            $referral->increment('revision');
            activity('referrals')->causedBy($user)->performedOn($referral)->withProperties([
                'operation' => 'cancelled', 'revision' => $referral->revision,
            ])->log('Referral cancelled');

            return $referral;
        });
    }

    private function require(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
