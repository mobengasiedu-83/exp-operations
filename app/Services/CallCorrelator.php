<?php

namespace App\Services;

use App\Models\AmiEvent;
use App\Models\CallerCase;
use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\TransferAttempt;
use Illuminate\Support\Facades\DB;

class CallCorrelator
{
    public function __construct(private AmiEventNormalizer $normalizer, private CallTimelineProjector $projector) {}

    public function process(AmiEvent $source): ?Interaction
    {
        $normalized = $source->correlation_data ?? $this->normalizer->normalize($source->payload, $source->received_at);
        if ($normalized === null) {
            return null;
        }

        return DB::transaction(function () use ($source, $normalized): ?Interaction {
            PbxConnection::whereKey($source->pbx_connection_id)->lockForUpdate()->firstOrFail();
            CorrelationException::where('fingerprint', hash('sha256', 'ami-processing:'.$source->id))->delete();
            $fingerprint = hash('sha256', json_encode([$source->pbx_connection_id, $normalized, $normalized['timing_quality'] === 'received' ? $source->id : null], JSON_THROW_ON_ERROR));
            $event = CallEvent::where('ami_event_id', $source->id)->first()
                ?? CallEvent::firstOrCreate(['fingerprint' => $fingerprint], [
                    'pbx_connection_id' => $source->pbx_connection_id, 'ami_event_id' => $source->id,
                    'event_name' => $normalized['event_name'], 'occurred_at' => $normalized['occurred_at'],
                    'timing_quality' => $normalized['timing_quality'], 'data' => $normalized['data'],
                ]);
            $data = $event->data;
            $ids = [];
            foreach ($data['participants'] as $participant) {
                $ids[] = $participant['uniqueid'];
                if ($participant['linkedid'] !== null) {
                    $ids[] = $participant['linkedid'];
                }
            }
            $ids = array_values(array_unique($ids));
            $candidates = Interaction::where('pbx_connection_id', $source->pbx_connection_id)
                ->where(function ($query) use ($ids, $event, $data): void {
                    foreach ($ids as $alias) {
                        $query->orWhereJsonContains('correlation_aliases', $alias);
                    }
                    $query->orWhereIn('correlation_key', $ids)
                        ->orWhereHas('legs', fn ($legs) => $legs->whereIn('uniqueid', $ids));
                    if ($event->interaction_id !== null) {
                        $query->orWhereKey($event->interaction_id);
                    }
                    if ($event->event_name === 'BridgeDestroy' && $data['bridge_id'] !== null) {
                        $query->orWhereHas('bridges', fn ($bridges) => $bridges->where('bridge_uniqueid', $data['bridge_id']));
                    }
                })->orderBy('id')->lockForUpdate()->get();
            if ($ids === [] && $candidates->count() !== 1) {
                $this->unmatched($event, 'missing_identity');

                return null;
            }
            if (in_array($event->event_name, ['AttendedTransfer', 'BlindTransfer'], true) && $data['transfer_result'] !== 'Success' && $candidates->count() > 1) {
                $this->unmatched($event, 'failed_transfer_crosses_interactions');

                return null;
            }
            $linkedCases = CallerCase::query()->whereIn('interaction_id', $candidates->modelKeys())->lockForUpdate()->get();
            if ($linkedCases->count() > 1) {
                $this->unmatched($event, 'multiple_verified_cases_require_review');

                return null;
            }
            $verifiedInteraction = $linkedCases->isEmpty() ? null : $candidates->firstWhere('id', $linkedCases->first()->interaction_id);
            $interaction = $verifiedInteraction ?? $candidates->first() ?? Interaction::create([
                'pbx_connection_id' => $source->pbx_connection_id,
                'correlation_key' => $data['participants'][0]['linkedid'] ?? $ids[0],
            ]);
            $aliases = $ids;
            foreach ($candidates as $candidate) {
                $aliases = [...$aliases, $candidate->correlation_key, ...($candidate->correlation_aliases ?? [])];
            }
            $interaction->update(['correlation_aliases' => array_values(array_unique($aliases))]);
            foreach ($candidates->where('id', '!=', $interaction->id) as $other) {
                CallLeg::where('interaction_id', $other->id)->update(['interaction_id' => $interaction->id]);
                CallEvent::where('interaction_id', $other->id)->update(['interaction_id' => $interaction->id]);
                TransferAttempt::where('interaction_id', $other->id)->update(['interaction_id' => $interaction->id]);
                $other->delete();
            }
            $event->update(['interaction_id' => $interaction->id]);
            CorrelationException::where('call_event_id', $event->id)->where('category', 'ingestion')->delete();
            if ($this->needsProjection($interaction, $event)) {
                $this->projector->rebuild($interaction);
            }

            return $interaction->fresh();
        }, 3);
    }

    /**
     * Projection rebuilds the whole timeline, so dialplan frames that carry no
     * journey evidence must not trigger one. A VitalPBX call emits hundreds of
     * Newexten rows for the same interaction; replaying the timeline for each
     * of them pushes the AMI listener behind the event stream. A Newexten frame
     * is only projected when it records a key press or changes how a leg is
     * classified.
     */
    private function needsProjection(Interaction $interaction, CallEvent $event): bool
    {
        if ($event->event_name !== 'Newexten') {
            return true;
        }

        if (is_array($event->data['ivr'] ?? null)) {
            return true;
        }

        $legs = $interaction->legs()->get(['uniqueid', 'role', 'entry_kind'])->keyBy('uniqueid');
        $hasCaller = $legs->contains(fn (CallLeg $leg): bool => $leg->role === 'caller');

        foreach ($event->data['participants'] as $participant) {
            $leg = $legs->get($participant['uniqueid']);

            if ($leg === null) {
                if (! $hasCaller && $participant['role'] === 'caller') {
                    return true;
                }

                continue;
            }

            if (($participant['role'] !== 'unknown' && $leg->role !== $participant['role'])
                || ($participant['entry_kind'] !== null && $leg->entry_kind !== $participant['entry_kind'])) {
                return true;
            }
        }

        return false;
    }

    private function unmatched(CallEvent $event, string $code): void
    {
        CorrelationException::updateOrCreate(['fingerprint' => hash('sha256', 'event:'.$event->id.':'.$code)], [
            'pbx_connection_id' => $event->pbx_connection_id, 'call_event_id' => $event->id,
            'category' => 'ingestion', 'code' => $code,
        ]);
    }
}
