<?php

namespace App\Services;

use App\Models\CallBridge;
use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CallTimingMetric;
use App\Models\CorrelationException;
use App\Models\Interaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class CallTimelineProjector
{
    public function __construct(private TransferTracker $transfers, private TelemetryCoverage $coverage) {}

    public function rebuild(Interaction $interaction): void
    {
        $events = $interaction->events()->orderBy('occurred_at')->orderBy('id')->get();
        $interaction->exceptions()->whereIn('category', ['projection', 'reconciliation'])->delete();
        $interaction->bridges()->delete();
        $interaction->metrics()->delete();
        $legs = [];
        $qualities = [];
        foreach ($events as $event) {
            $data = $event->data;
            $qualities[$event->timing_quality] = true;
            if ($data['invalid_timestamp']) {
                $this->issue($interaction, 'invalid_timestamp', (string) $event->id);
            }
            foreach ($data['participants'] as $participant) {
                $id = $participant['uniqueid'];
                $legs[$id] ??= [
                    'channel' => null, 'role' => 'unknown', 'entry_kind' => null, 'inbound_masked' => null, 'dial_status' => null,
                    'caller_masked' => null, 'caller_hash' => null,
                    'observed_creation' => false, 'started_at' => $event->occurred_at,
                    'dial_started_at' => null, 'ringing_at' => null, 'answered_at' => null, 'ended_at' => null,
                ];
                if ($participant['channel'] !== null) {
                    $legs[$id]['channel'] = $participant['channel'];
                }
                if ($participant['role'] !== 'unknown') {
                    if ($legs[$id]['role'] !== 'unknown' && $legs[$id]['role'] !== $participant['role']) {
                        $this->issue($interaction, 'conflicting_leg_role', $id);
                    } else {
                        $legs[$id]['role'] = $participant['role'];
                    }
                }
                if ($participant['entry_kind'] !== null) {
                    $legs[$id]['entry_kind'] = $participant['entry_kind'];
                }
                if (($participant['inbound_masked'] ?? null) !== null) {
                    $legs[$id]['inbound_masked'] = $participant['inbound_masked'];
                }
                if (($participant['caller_masked'] ?? null) !== null) {
                    $legs[$id]['caller_masked'] = $participant['caller_masked'];
                }
                if (($participant['caller_hash'] ?? null) !== null) {
                    $legs[$id]['caller_hash'] = $participant['caller_hash'];
                }
                if (($participant['number_hash'] ?? null) !== null) {
                    $legs[$id]['destination_hash'] = $participant['number_hash'];
                    $legs[$id]['destination_masked'] = $participant['number_masked'];
                }
            }
            $primary = $data['primary_id'];
            $destination = $data['destination_id'];
            if ($primary !== null && isset($legs[$primary])) {
                if ($event->event_name === 'Newchannel') {
                    $legs[$primary]['observed_creation'] = true;
                }
                if ($event->event_name === 'Newstate' && in_array($data['state'], ['Ring', 'Ringing'], true)) {
                    $legs[$primary]['ringing_at'] ??= $event->occurred_at;
                }
                if ($event->event_name === 'Newstate' && $data['state'] === 'Up') {
                    $legs[$primary]['answered_at'] ??= $event->occurred_at;
                }
                if ($event->event_name === 'Hangup') {
                    $legs[$primary]['ended_at'] ??= $event->occurred_at;
                }
            }
            if ($event->event_name === 'DialBegin' && $destination !== null && isset($legs[$destination])) {
                $legs[$destination]['dial_started_at'] ??= $event->occurred_at;
            }
            if ($event->event_name === 'DialEnd' && $destination !== null && isset($legs[$destination])) {
                $legs[$destination]['dial_status'] = $data['dial_status'];
                if ($data['dial_status'] === 'ANSWER') {
                    $legs[$destination]['answered_at'] ??= $event->occurred_at;
                }
            }
            if (in_array($data['transfer_type'] ?? null, ['Link', 'Threeway', 'App'], true)) {
                $this->issue($interaction, 'transfer_topology_requires_review', (string) $event->id);
            }
            if (in_array($event->event_name, ['AttendedTransfer', 'BlindTransfer'], true) && $data['transfer_result'] !== 'Success') {
                $this->issue($interaction, 'transfer_not_successful', (string) $event->id);
            }
        }
        $saved = [];
        foreach ($legs as $id => $values) {
            $saved[$id] = CallLeg::updateOrCreate([
                'pbx_connection_id' => $interaction->pbx_connection_id, 'uniqueid' => $id,
            ], ['interaction_id' => $interaction->id, ...$values]);
            if ($values['role'] === 'unknown') {
                $this->issue($interaction, 'unknown_leg', $id);
            }
            if (! $values['observed_creation']) {
                $this->issue($interaction, 'missing_channel_creation', $id);
            }
        }
        $intervals = $this->bridgeIntervals($interaction, array_values($events->all()), $legs);
        foreach ($intervals as $interval) {
            CallBridge::create(['interaction_id' => $interaction->id, ...$interval]);
        }
        foreach ($saved as $id => $leg) {
            if ($leg->role !== 'mobile') {
                continue;
            }
            if ($leg->dial_started_at === null) {
                $this->issue($interaction, 'missing_dial_start', $id);
            }
            if ($leg->dial_status === 'ANSWER' && $leg->answered_at === null) {
                $this->issue($interaction, 'missing_answer', $id);
            }
            CallTimingMetric::create([
                'interaction_id' => $interaction->id, 'call_leg_id' => $leg->id,
                'dial_to_answer_seconds' => $this->duration($interaction, $leg->dial_started_at, $leg->answered_at, $id.':dial'),
                'ringing_seconds' => $this->duration($interaction, $leg->ringing_at, $leg->answered_at ?? $this->attemptEnd(array_values($events->all()), $id) ?? $leg->ended_at, $id.':ring'),
                'mobile_connected_seconds' => $this->duration($interaction, $leg->answered_at, $leg->ended_at, $id.':connected'),
                'private_handover_seconds' => $this->stageDuration($interaction, $intervals, $id, 'private_handover'),
                'consultation_seconds' => $this->stageDuration($interaction, $intervals, $id, 'consultation'),
                'acceptance_status' => 'unconfirmed',
            ]);
        }
        $this->projectIvrSteps($interaction, $events);
        $hasCaller = collect($legs)->contains(fn (array $leg): bool => $leg['role'] === 'caller');
        if (! $hasCaller) {
            $this->issue($interaction, 'missing_caller', (string) $interaction->id);
        }
        if (collect($legs)->contains(fn (array $leg): bool => $leg['entry_kind'] === 'agent_route')) {
            $this->issue($interaction, 'entry_route_inferred', (string) $interaction->id);
        }
        $completed = $legs !== [] && collect($legs)->every(fn (array $leg): bool => $leg['ended_at'] !== null);
        $interaction->update([
            'is_exp' => collect($legs)->contains(fn (array $leg): bool => $leg['role'] === 'agent' || $leg['entry_kind'] !== null),
            'status' => $completed ? 'completed' : 'active',
            'started_at' => $events->first()?->occurred_at,
            'ended_at' => $completed ? $events->where('event_name', 'Hangup')->last()?->occurred_at : null,
            'timing_quality' => count($qualities) === 1 ? array_key_first($qualities) : 'mixed',
            'reconciliation_status' => 'pending', 'reconciled_at' => null,
        ]);
        $this->coverage->invalidate($interaction);
        $this->transfers->sync($interaction);
    }

    /**
     * Rebuild the caller's menu selections from retained events.
     *
     * Consecutive repeats of the same menu step collapse into one row so a
     * replay cannot inflate the journey, while a genuine re-selection later in
     * the call is kept with its own timestamp.
     *
     * @param  Collection<int, CallEvent>  $events
     */
    private function projectIvrSteps(Interaction $interaction, Collection $events): void
    {
        $interaction->ivrSteps()->delete();
        $previousOption = null;
        $previousAt = null;

        foreach ($events as $event) {
            $step = $event->data['ivr'] ?? null;

            if (! is_array($step) || ! is_string($step['option'] ?? null)) {
                continue;
            }

            $menu = is_string($step['menu'] ?? null) ? $step['menu'] : null;
            // The same press can surface in two dialplan contexts while the
            // announcement plays, so a repeat of the same digit within two
            // seconds is one selection, not a second journey step.
            $samePress = $previousOption === $step['option']
                && $previousAt !== null
                && abs($previousAt->diffInSeconds($event->occurred_at)) <= 2;

            if ($samePress) {
                continue;
            }

            $previousOption = $step['option'];
            $previousAt = $event->occurred_at;
            $interaction->ivrSteps()->create([
                'pbx_connection_id' => $interaction->pbx_connection_id,
                'uniqueid' => is_string($event->data['primary_id'] ?? null) ? $event->data['primary_id'] : null,
                'menu' => $menu,
                'option' => $step['option'],
                'application' => is_string($step['application'] ?? null) ? $step['application'] : null,
                'app_data' => is_string($step['app_data'] ?? null) ? $step['app_data'] : null,
                'occurred_at' => $event->occurred_at,
                'timing_quality' => $event->timing_quality,
                'fingerprint' => hash('sha256', $interaction->id.':ivr:'.$event->id),
            ]);
        }
    }

    /** @param list<CallEvent> $events
     * @param  array<string, array<string, mixed>>  $legs
     * @return list<array<string, mixed>>
     */
    private function bridgeIntervals(Interaction $interaction, array $events, array $legs): array
    {
        $members = [];
        $open = [];
        $held = [];
        $intervals = [];
        foreach ($events as $event) {
            $data = $event->data;
            $id = $data['primary_id'];
            $bridge = $data['bridge_id'];
            if (in_array($event->event_name, ['BridgeEnter', 'BridgeLeave'], true)) {
                if ($bridge === null || $id === null || ! isset($legs[$id])) {
                    $this->issue($interaction, 'missing_bridge_identity', (string) $event->id);

                    continue;
                }
                if ($event->event_name === 'BridgeEnter') {
                    $members[$bridge][$id] = true;
                } else {
                    if (! isset($members[$bridge][$id])) {
                        $this->issue($interaction, 'bridge_leave_without_enter', (string) $event->id);
                    }
                    unset($members[$bridge][$id]);
                }
            } elseif ($event->event_name === 'BridgeDestroy' && $bridge !== null) {
                $members[$bridge] = [];
            } elseif ($event->event_name === 'Hold' && $id !== null) {
                $held[$id] = true;
            } elseif ($event->event_name === 'Unhold' && $id !== null) {
                unset($held[$id]);
            } elseif ($event->event_name === 'Hangup' && $id !== null) {
                foreach ($members as $key => $value) {
                    unset($members[$key][$id]);
                }
            } else {
                continue;
            }
            foreach ($members as $bridgeId => $membership) {
                $ids = array_map('strval', array_keys($membership));
                sort($ids);
                $roles = array_map(fn (string $uid): string => $legs[$uid]['role'], $ids);
                sort($roles);
                $stage = match (true) {
                    count($ids) <= 1 => 'isolated',
                    array_intersect_key($held, $membership) !== [] => 'held',
                    $roles === ['agent', 'mobile'] => 'private_handover',
                    $roles === ['caller', 'mobile'] => 'consultation',
                    $roles === ['agent', 'caller'] => 'screening',
                    default => 'ambiguous',
                };
                $signature = json_encode([$stage, $ids], JSON_THROW_ON_ERROR);
                if (isset($open[$bridgeId]) && $open[$bridgeId]['signature'] === $signature) {
                    continue;
                }
                if (isset($open[$bridgeId])) {
                    $previous = $open[$bridgeId];
                    unset($previous['signature']);
                    $intervals[] = [...$previous, 'ended_at' => $event->occurred_at, 'duration_seconds' => $previous['started_at']->diffInMicroseconds($event->occurred_at) / 1000000];
                    unset($open[$bridgeId]);
                }
                if ($ids !== []) {
                    $open[$bridgeId] = ['bridge_uniqueid' => $bridgeId, 'stage' => $stage, 'participants' => $ids, 'started_at' => $event->occurred_at, 'signature' => $signature];
                }
                if ($stage === 'ambiguous') {
                    $this->issue($interaction, 'ambiguous_bridge', $bridgeId);
                }
            }
        }
        foreach ($open as $interval) {
            unset($interval['signature']);
            $intervals[] = [...$interval, 'ended_at' => null, 'duration_seconds' => null];
            if (in_array($interval['stage'], ['consultation', 'private_handover'], true)) {
                $this->issue($interaction, 'open_bridge_interval', $interval['bridge_uniqueid']);
            }
        }

        return $intervals;
    }

    /** @param list<CallEvent> $events */
    private function attemptEnd(array $events, string $id): ?CarbonImmutable
    {
        foreach ($events as $event) {
            if ($event->event_name === 'DialEnd' && $event->data['destination_id'] === $id) {
                return $event->occurred_at;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $intervals */
    private function stageDuration(Interaction $interaction, array $intervals, string $id, string $stage): ?float
    {
        $membership = array_values(array_filter($intervals, fn (array $interval): bool => count($interval['participants']) > 1 && in_array($id, $interval['participants'], true)));
        foreach ($membership as $index => $first) {
            foreach (array_slice($membership, $index + 1) as $second) {
                if ($first['bridge_uniqueid'] !== $second['bridge_uniqueid']
                    && ($first['ended_at'] === null || $second['started_at']->lt($first['ended_at']))
                    && ($second['ended_at'] === null || $first['started_at']->lt($second['ended_at']))) {
                    $this->issue($interaction, 'overlapping_bridge_membership', $id);

                    return null;
                }
            }
        }
        $matches = array_filter($intervals, fn (array $interval): bool => $interval['stage'] === $stage && in_array($id, $interval['participants'], true));
        if ($matches === [] || collect($matches)->contains(fn (array $interval): bool => $interval['ended_at'] === null)) {
            return null;
        }

        return array_sum(array_column($matches, 'duration_seconds'));
    }

    private function duration(Interaction $interaction, ?CarbonImmutable $from, ?CarbonImmutable $to, string $subject): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }
        if ($to->lt($from)) {
            $this->issue($interaction, 'invalid_time_sequence', $subject);

            return null;
        }

        return $from->diffInMicroseconds($to) / 1000000;
    }

    public function issue(Interaction $interaction, string $code, string $subject): void
    {
        CorrelationException::firstOrCreate(['fingerprint' => hash('sha256', $interaction->id.':projection:'.$code.':'.$subject)], [
            'pbx_connection_id' => $interaction->pbx_connection_id, 'interaction_id' => $interaction->id,
            'category' => 'projection', 'code' => $code, 'subject' => mb_substr($subject, 0, 255),
        ]);
    }
}
