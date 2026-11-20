<?php

namespace App\Services;

use App\Models\AlertEvent;
use App\Models\AlertRule;
use App\Models\CallLeg;
use App\Models\Interaction;
use App\Models\PbxConnection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AlertEvaluator
{
    public const FAILED_DIAL_STATUSES = ['BUSY', 'CONGESTION', 'NOANSWER', 'CANCEL', 'CHANUNAVAIL', 'DONTCALL', 'TORTURE', 'INVALIDARGS'];

    /**
     * Evaluate every enabled rule and reconcile alert events.
     *
     * @return array{created: int, updated: int, resolved: int}
     */
    public function evaluate(?PbxConnection $connection = null): array
    {
        $rules = AlertRule::query()->enabled()->get()->keyBy('key');
        if ($rules->isEmpty()) {
            return ['created' => 0, 'updated' => 0, 'resolved' => 0];
        }

        $connections = $connection === null ? PbxConnection::query()->get() : collect([$connection]);
        /** @var array<string, true> $active */
        $active = [];
        $counts = ['created' => 0, 'updated' => 0, 'resolved' => 0];

        foreach ($connections as $pbx) {
            $this->telemetry($pbx, $rules, $active, $counts);
            $this->ringing($pbx, $rules, $active, $counts);
            $this->transfers($pbx, $rules, $active, $counts);
        }

        $counts['resolved'] = AlertEvent::query()
            ->whereIn('alert_rule_id', $rules->pluck('id')->all())
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereNotIn('fingerprint', array_keys($active))
            ->update(['status' => 'resolved', 'resolved_at' => now(), 'updated_at' => now()]);

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, true>  $active
     * @param  array{created: int, updated: int, resolved: int}  $counts
     */
    private function record(AlertRule $rule, string $fingerprint, array $attributes, array &$active, array &$counts): void
    {
        $alert = AlertEvent::query()->firstOrNew(['fingerprint' => $fingerprint]);
        $isNew = ! $alert->exists;
        $reopened = $alert->exists && $alert->status === 'resolved';

        $alert->fill([
            'alert_rule_id' => $rule->id,
            'status' => ! $isNew && $alert->status === 'acknowledged' && ! $reopened ? 'acknowledged' : 'open',
            'severity' => $rule->severity,
            'first_seen_at' => $isNew || $reopened ? now() : $alert->first_seen_at,
            'last_seen_at' => now(),
            'resolved_at' => null,
            'acknowledged_at' => $reopened ? null : $alert->acknowledged_at,
            'acknowledged_by' => $reopened ? null : $alert->acknowledged_by,
        ] + $attributes);
        $alert->save();

        $active[$fingerprint] = true;
        $counts[$isNew ? 'created' : 'updated']++;
    }

    /**
     * @param  Collection<string, AlertRule>  $rules
     * @param  array<string, true>  $active
     * @param  array{created: int, updated: int, resolved: int}  $counts
     */
    private function telemetry(PbxConnection $pbx, Collection $rules, array &$active, array &$counts): void
    {
        $rule = $rules->get('ami_stale');
        if (! $rule instanceof AlertRule) {
            return;
        }

        $threshold = $rule->threshold_seconds ?? (int) config('services.exp_pbx.heartbeat_stale_seconds', 90);
        $heartbeat = $pbx->last_heartbeat_at;
        $stale = $pbx->status !== 'connected'
            || $heartbeat === null
            || $heartbeat->getTimestamp() < now()->subSeconds($threshold)->getTimestamp();

        if (! $stale) {
            return;
        }

        $this->record($rule, 'ami_stale:'.$pbx->id, [
            'pbx_connection_id' => $pbx->id,
            'interaction_id' => null,
            'subject' => $pbx->name,
            'details' => [
                'status' => $pbx->status,
                'heartbeat_age_seconds' => $heartbeat === null ? null : max(0, now()->getTimestamp() - $heartbeat->getTimestamp()),
                'last_error' => Str::limit((string) $pbx->last_error, 200),
            ],
        ], $active, $counts);
    }

    /**
     * @param  Collection<string, AlertRule>  $rules
     * @param  array<string, true>  $active
     * @param  array{created: int, updated: int, resolved: int}  $counts
     */
    private function ringing(PbxConnection $pbx, Collection $rules, array &$active, array &$counts): void
    {
        $rule = $rules->get('long_ringing');
        if (! $rule instanceof AlertRule) {
            return;
        }

        $threshold = $rule->threshold_seconds ?? (int) config('services.exp_pbx.long_ringing_seconds', 45);
        $legs = CallLeg::query()->where('pbx_connection_id', $pbx->id)->where('role', 'mobile')
            ->whereNotNull('ringing_at')->whereNull('answered_at')->whereNull('ended_at')
            ->where('ringing_at', '<=', now()->subSeconds($threshold))
            ->limit(200)->get();

        foreach ($legs as $leg) {
            $this->record($rule, 'long_ringing:'.$leg->id, [
                'pbx_connection_id' => $pbx->id,
                'interaction_id' => $leg->interaction_id,
                'subject' => 'Journey '.$leg->interaction_id,
                'details' => [
                    'ringing_seconds' => $leg->ringing_at === null ? null : max(0, now()->getTimestamp() - $leg->ringing_at->getTimestamp()),
                    'threshold_seconds' => $threshold,
                ],
            ], $active, $counts);
        }
    }

    /**
     * @param  Collection<string, AlertRule>  $rules
     * @param  array<string, true>  $active
     * @param  array{created: int, updated: int, resolved: int}  $counts
     */
    private function transfers(PbxConnection $pbx, Collection $rules, array &$active, array &$counts): void
    {
        $rule = $rules->get('failed_transfer');
        if (! $rule instanceof AlertRule) {
            return;
        }

        $since = now()->subMinutes((int) config('services.exp_pbx.alert_lookback_minutes', 1440));
        $interactions = Interaction::query()
            ->where('pbx_connection_id', $pbx->id)
            ->where('status', 'completed')
            ->where('started_at', '>=', $since)
            ->whereHas('legs', fn ($legs) => $legs->where('role', 'mobile')->whereIn('dial_status', self::FAILED_DIAL_STATUSES))
            ->whereDoesntHave('legs', fn ($legs) => $legs->where('role', 'mobile')->whereNotNull('answered_at'))
            ->with('legs')
            ->limit(200)->get();

        foreach ($interactions as $interaction) {
            $failed = $interaction->legs
                ->where('role', 'mobile')
                ->whereIn('dial_status', self::FAILED_DIAL_STATUSES);

            $this->record($rule, 'failed_transfer:'.$interaction->id, [
                'pbx_connection_id' => $pbx->id,
                'interaction_id' => $interaction->id,
                'subject' => 'Journey '.$interaction->id,
                'details' => [
                    'dial_statuses' => $failed->pluck('dial_status')->unique()->values()->all(),
                    'attempts' => $failed->count(),
                ],
            ], $active, $counts);
        }
    }
}
