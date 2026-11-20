<?php

namespace App\Services;

use App\Models\CallControlCommand;
use App\Models\CallerCase;
use App\Models\DentistNumber;
use App\Models\PbxConnection;
use App\Models\TransferAttempt;
use App\Models\TransferEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class CallControl
{
    public const TRANSITIONS = [
        'incoming' => ['answer' => ['agent_connected']],
        'agent_connected' => ['hold' => ['caller_held']],
        'caller_held' => ['resume' => ['agent_connected'], 'dial_dentist' => ['dentist_dialling', 'dentist_ringing', 'dentist_connected']],
        'dentist_dialling' => ['cancel_attempt' => ['caller_held']],
        'dentist_ringing' => ['cancel_attempt' => ['caller_held']],
        'dentist_connected' => ['begin_briefing' => ['private_briefing'], 'cancel_attempt' => ['caller_held']],
        'private_briefing' => ['complete_transfer' => ['caller_dentist_bridge'], 'cancel_attempt' => ['caller_held']],
        'caller_dentist_bridge' => ['release_agent' => ['caller_dentist_bridge']],
        'completed' => [],
    ];

    public function __construct(private PbxCallControlGateway $gateway, private LiveOperationsSnapshot $live, private TelemetryCoverage $coverage) {}

    /** @return array<string, mixed> */
    public function status(CallerCase $case): array
    {
        $fallback = ['available' => false, 'state' => 'unverified', 'version' => null, 'actions' => [], 'pending' => null];
        if (! $this->gateway->configured()) {
            return [...$fallback, 'reason' => 'Browser call controls are unavailable. Use the existing Jitsi console.'];
        }
        $pending = null;
        try {
            $pending = CallControlCommand::where('active_interaction_id', $case->interaction_id)->first();
            $result = $pending === null ? null : $this->gateway->result($pending->request_key);
            $snapshot = $this->verifiedSnapshot($case);
            if ($pending !== null && $result === 'rejected') {
                $pending->update(['status' => 'rejected', 'error_code' => 'gateway_rejected', 'active_interaction_id' => null]);
            } elseif ($pending !== null && $result === 'applied' && $this->postcondition($pending, $snapshot)) {
                $pending->update(['status' => 'confirmed', 'error_code' => null, 'active_interaction_id' => null]);
            }
            foreach (CallControlCommand::where('case_id', $case->id)->where('action', 'complete_transfer')->where('status', 'confirmed')->whereNull('acceptance_recorded_at')->get() as $command) {
                $this->recordAcceptance($case, $command);
            }
            $pending = CallControlCommand::where('active_interaction_id', $case->interaction_id)->first();
            $actions = $pending === null ? array_keys(self::TRANSITIONS[$snapshot['state']]) : [];
            if (! $snapshot['agent_present']) {
                $actions = [];
            }

            return ['available' => true, 'reason' => null, 'state' => $snapshot['state'], 'version' => $snapshot['version'],
                'actions' => $actions, 'attempt_outcome' => $snapshot['attempt_outcome'] ?? null, 'pending' => $pending?->only(['request_key', 'action', 'status', 'error_code']),
                'commands' => CallControlCommand::where('case_id', $case->id)->latest('id')->limit(10)->get(['request_key', 'action', 'status', 'error_code', 'destination_masked', 'created_at'])];
        } catch (Throwable) {
            return [...$fallback, 'pending' => $pending?->only(['request_key', 'action', 'status', 'error_code']), 'reason' => 'Call control or verified call evidence is unavailable. Check the current call in Jitsi before trying again.'];
        }
    }

    /** @param array<string, mixed> $data */
    public function execute(CallerCase $case, User $actor, array $data): CallControlCommand
    {
        $hash = hash('sha256', json_encode([$case->id, $data['action'], $data['expected_version'], $data['dentist_number_id'] ?? null, (bool) ($data['human_accepted'] ?? false)], JSON_THROW_ON_ERROR));
        $existing = CallControlCommand::where('request_key', $data['request_key'])->first();
        if ($existing !== null) {
            abort_unless($existing->case_id === $case->id && $existing->requested_by === $actor->id && hash_equals($existing->payload_hash, $hash), 409, 'This command key belongs to a different request.');

            return $existing;
        }
        abort_unless($this->gateway->configured(), 503, 'Browser controls are not enabled and approved.');
        try {
            $snapshot = $this->verifiedSnapshot($case);
        } catch (Throwable) {
            abort(409, 'Fresh, verified call state is required. Continue with the manual console.');
        }
        abort_unless($snapshot['version'] === $data['expected_version'], 409, 'The call changed. Refresh before trying another action.');
        abort_unless(isset(self::TRANSITIONS[$snapshot['state']][$data['action']]) && $snapshot['agent_present'], 409, 'This action is not safe in the current call state.');
        if ($data['action'] === 'complete_transfer') {
            abort_unless(($data['human_accepted'] ?? false) === true && filled($snapshot['dentist_uniqueid'] ?? null), 422, 'Confirm that a human dentist accepted the private briefing.');
            abort_unless($this->approvedAttempt($case, $snapshot['dentist_uniqueid'], true) !== null, 409, 'Approved dentist answer evidence is required before completing transfer.');
        }
        if (in_array($data['action'], ['complete_transfer', 'release_agent'], true)) {
            $stage = $data['action'] === 'complete_transfer' ? 'private_handover' : 'consultation';
            abort_unless($case->interaction->bridges()->where('stage', $stage)->whereNull('ended_at')
                ->whereJsonContains('participants', $snapshot['dentist_uniqueid'])
                ->whereJsonContains('participants', $snapshot[$data['action'] === 'complete_transfer' ? 'agent_uniqueid' : 'caller_uniqueid'])->exists(), 409, 'Verified bridge evidence is required before transferring or releasing the agent.');
        }
        $command = DB::transaction(function () use ($case, $actor, $data, $hash, $snapshot): CallControlCommand {
            $locked = CallerCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless(($locked->assigned_to === $actor->id || $actor->hasRole('Super Administrator')) && $locked->status !== 'closed', 409, 'The case assignment or status changed.');
            $duplicate = CallControlCommand::where('request_key', $data['request_key'])->first();
            if ($duplicate !== null) {
                abort_unless(hash_equals($duplicate->payload_hash, $hash) && $duplicate->requested_by === $actor->id, 409);

                return $duplicate;
            }
            abort_if(CallControlCommand::where('active_interaction_id', $case->interaction_id)->exists(), 409, 'A command is unresolved. Verify it before issuing another command.');
            $number = $data['action'] === 'dial_dentist' ? $this->dialNumber($locked, $data['dentist_number_id']) : null;
            if ($data['action'] === 'complete_transfer') {
                abort_if($locked->priority === 'P1' || $locked->screeningDrafts()->exists(), 422, 'Resolve the current safety screen before transfer.');
            }
            $command = CallControlCommand::create([
                'case_id' => $case->id, 'interaction_id' => $case->interaction_id, 'active_interaction_id' => $case->interaction_id,
                'requested_by' => $actor->id, 'request_key' => $data['request_key'], 'payload_hash' => $hash,
                'action' => $data['action'], 'expected_version' => $data['expected_version'], 'source_state' => $snapshot['state'],
                'dentist_number_id' => $number?->id, 'destination_masked' => $number?->masked(), 'destination' => $number === null ? null : GhanaPhoneNumber::normalize($number->number),
                'dentist_uniqueid' => $snapshot['dentist_uniqueid'] ?? null, 'human_accepted' => $data['human_accepted'] ?? false,
            ]);
            activity('call-control')->causedBy($actor)->performedOn($command)->withProperties(['action' => $command->action, 'request_key' => $command->request_key])->log('Call command reserved');

            return $command;
        });
        if (! $command->wasRecentlyCreated) {
            return $command;
        }
        try {
            $this->gateway->dispatch($command->request_key, [
                'interaction_key' => $case->interaction->correlation_key, 'expected_version' => $command->expected_version,
                'action' => $command->action, 'caller_uniqueid' => $snapshot['caller_uniqueid'], 'agent_uniqueid' => $snapshot['agent_uniqueid'],
                'dentist_uniqueid' => $snapshot['dentist_uniqueid'] ?? null, 'destination' => $command->destination,
            ]);
        } catch (Throwable) {
            $command->update(['status' => 'uncertain', 'error_code' => 'delivery_unverified']);
        }

        return $command->fresh();
    }

    private function dialNumber(CallerCase $case, int $numberId): DentistNumber
    {
        abort_if($case->screeningDrafts()->exists() || $case->priority === 'P1' || ! $case->screenings()->latest('id')->first()?->complete, 422, 'Record a complete safety screen before dialling a dentist. Emergency handling must not wait for a transfer.');
        $number = DentistNumber::query()->approved()->whereHas('dentist', fn ($query) => $query->where('active', true))->lockForUpdate()->find($numberId);
        abort_unless($number !== null && GhanaPhoneNumber::normalize($number->number) !== null, 422, 'Select an active dentist with an approved mobile number.');
        $selectable = collect(app(DentistDirectory::class)->selectable()['dentists'])->firstWhere('id', $number->dentist_id);
        abort_unless(($selectable['availability_status'] ?? null) === 'available', 422, 'The dentist is manually marked busy or unavailable.');

        return $number;
    }

    /** @return array<string, mixed> */
    private function verifiedSnapshot(CallerCase $case): array
    {
        abort_if($case->status === 'closed', 409);
        $interaction = $case->interaction;
        $connection = PbxConnection::findOrFail($interaction->pbx_connection_id);
        abort_unless($this->live->connectionFreshness($connection)['state'] === 'live' && $this->coverage->gapsFor($interaction)->isEmpty(), 409);
        abort_if($interaction->exceptions()->whereIn('code', ['unknown_leg', 'conflicting_leg_role', 'ambiguous_bridge', 'overlapping_bridge_membership', 'transfer_topology_requires_review', 'invalid_timestamp', 'missing_caller'])->exists(), 409);
        abort_if(config('services.exp_call_control.identity') === $connection->configuration()['username'], 409);
        $snapshot = $this->gateway->snapshot($interaction);
        $age = CarbonImmutable::parse($snapshot['observed_at'])->diffInSeconds(CarbonImmutable::now(), false);
        abort_unless($age >= -2 && $age <= config('services.exp_call_control.snapshot_max_age_seconds', 5), 409);
        abort_unless($snapshot['interaction_key'] === $interaction->correlation_key && $snapshot['control_identity'] === config('services.exp_call_control.identity')
            && $snapshot['agent_extension'] === (string) config('services.exp_pbx.screening_extension') && $snapshot['topology_verified'] && $snapshot['agent_media_ready'], 409);
        abort_unless($interaction->legs()->where('role', 'caller')->where('uniqueid', $snapshot['caller_uniqueid'])->exists()
            && $interaction->legs()->where('role', 'agent')->where('uniqueid', $snapshot['agent_uniqueid'])->exists(), 409);
        abort_unless($snapshot['state'] === 'completed' || $snapshot['caller_present'], 409);
        if (in_array($snapshot['state'], ['dentist_dialling', 'dentist_ringing', 'dentist_connected', 'private_briefing', 'caller_dentist_bridge'], true)) {
            abort_unless(filled($snapshot['dentist_uniqueid'] ?? null) && $interaction->legs()->where('role', 'mobile')->where('uniqueid', $snapshot['dentist_uniqueid'])->whereNull('ended_at')->exists(), 409);
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    private function postcondition(CallControlCommand $command, array $snapshot): bool
    {
        return ($snapshot['last_command_key'] ?? null) === $command->request_key && $snapshot['version'] !== $command->expected_version
            && (in_array($snapshot['state'], self::TRANSITIONS[$command->source_state ?? ''][$command->action] ?? [], true)
                || ($command->action === 'dial_dentist' && $snapshot['state'] === 'caller_held' && filled($snapshot['attempt_outcome'] ?? null)))
            && ($command->action !== 'release_agent' || ! $snapshot['agent_present']);
    }

    private function approvedAttempt(CallerCase $case, string $uniqueid, bool $active = false): ?TransferAttempt
    {
        return TransferAttempt::query()->where('interaction_id', $case->interaction_id)->when($active, fn ($query) => $query->whereNull('ended_at'))->where('match_status', 'approved')->whereNotNull('dentist_id')->whereNotNull('answered_at')
            ->whereHas('leg', fn ($query) => $query->where('uniqueid', $uniqueid)->where('role', 'mobile'))->first();
    }

    private function recordAcceptance(CallerCase $case, CallControlCommand $command): void
    {
        $attempt = $this->approvedAttempt($case, (string) $command->dentist_uniqueid);
        if (! $command->human_accepted || $attempt === null || $command->created_at->lt($attempt->answered_at)
            || ($attempt->ended_at !== null && $command->created_at->gt($attempt->ended_at))) {
            return;
        }
        DB::transaction(function () use ($attempt, $case, $command): void {
            TransferEvent::firstOrCreate(['fingerprint' => hash('sha256', 'call-control:'.$command->request_key)], [
                'transfer_attempt_id' => $attempt->id, 'kind' => 'operator_outcome', 'outcome' => 'accepted',
                'note' => 'Agent confirmed human dentist acceptance during private browser handover.',
                'occurred_at' => $command->created_at, 'recorded_by' => $command->requested_by,
            ]);
            app(TransferTracker::class)->sync($case->interaction);
            $command->update(['acceptance_recorded_at' => now()]);
        });
    }
}
