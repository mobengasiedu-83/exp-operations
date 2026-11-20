<?php

namespace App\Services;

use App\Models\ConsultationSession;
use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use App\Models\PbxTelemetryGap;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class TelemetryCoverage
{
    public function disconnected(PbxConnection $connection, string $reason = 'connection_lost'): void
    {
        DB::transaction(function () use ($connection, $reason): void {
            $connection = PbxConnection::query()->lockForUpdate()->findOrFail($connection->id);
            $lastEvidence = $connection->last_heartbeat_at ?? $connection->last_connected_at;
            if ($lastEvidence === null) {
                return;
            }
            $gap = PbxTelemetryGap::query()->firstOrCreate([
                'pbx_connection_id' => $connection->id, 'ended_at' => null,
            ], ['started_at' => $lastEvidence, 'reason' => $reason]);
            $this->invalidateAffected($gap);
        }, 3);
    }

    public function connected(PbxConnection $connection): void
    {
        DB::transaction(function () use ($connection): void {
            $connection = PbxConnection::query()->lockForUpdate()->findOrFail($connection->id);
            if ($connection->last_heartbeat_at !== null) {
                $this->disconnected($connection, 'listener_restart');
            }
            foreach (PbxTelemetryGap::query()->where('pbx_connection_id', $connection->id)->whereNull('ended_at')->get() as $gap) {
                $gap->update(['ended_at' => now()]);
                $this->invalidateAffected($gap);
            }
        }, 3);
    }

    /** @return Collection<int, PbxTelemetryGap> */
    public function gapsFor(Interaction $interaction): Collection
    {
        if ($interaction->started_at === null) {
            return new Collection;
        }

        return PbxTelemetryGap::query()->where('pbx_connection_id', $interaction->pbx_connection_id)
            ->where('started_at', '<', ($interaction->ended_at ?? now())->format('Y-m-d H:i:s.u'))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $interaction->started_at->format('Y-m-d H:i:s.u')))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhereColumn('ended_at', '>', 'started_at'))
            ->orderBy('started_at')->get();
    }

    /**
     * Retained endpoint timestamps cannot prove uninterrupted coverage. Keep the
     * observed events but withhold derived durations for calls crossing a gap.
     */
    public function invalidate(Interaction $interaction): bool
    {
        $gaps = $this->gapsFor($interaction);
        $interaction->exceptions()->where('category', 'telemetry')->where('code', 'telemetry_gap')->delete();
        foreach ($gaps as $gap) {
            CorrelationException::create([
                'pbx_connection_id' => $interaction->pbx_connection_id, 'interaction_id' => $interaction->id,
                'fingerprint' => hash('sha256', $interaction->id.':telemetry:'.$gap->id),
                'category' => 'telemetry', 'code' => 'telemetry_gap', 'subject' => (string) $gap->id,
                'details' => ['started_at' => $gap->started_at->toIso8601String(), 'ended_at' => $gap->ended_at?->toIso8601String(), 'reason' => $gap->reason],
            ]);
        }
        if ($gaps->isEmpty()) {
            return false;
        }
        $durations = ['dial_to_answer_seconds', 'ringing_seconds', 'mobile_connected_seconds', 'private_handover_seconds', 'consultation_seconds'];
        $interaction->metrics()->update(array_fill_keys([...$durations, 'outbound_setup_seconds'], null));
        $interaction->bridges()->update(['duration_seconds' => null]);
        $interaction->attempts()->update(array_fill_keys($durations, null));
        ConsultationSession::query()->whereIn('transfer_attempt_id', $interaction->attempts()->select('id'))->eachById(function (ConsultationSession $session): void {
            $session->update(['consultation_seconds' => null, 'intervals' => collect($session->intervals ?? [])->map(fn (array $interval): array => [...$interval, 'duration_seconds' => null])->all()]);
        });
        $interaction->update(['reconciliation_status' => 'pending', 'reconciled_at' => null]);

        return true;
    }

    private function invalidateAffected(PbxTelemetryGap $gap): void
    {
        Interaction::query()->where('pbx_connection_id', $gap->pbx_connection_id)
            ->where('started_at', '<=', ($gap->ended_at ?? now())->format('Y-m-d H:i:s.u'))
            ->where(fn ($query) => $query->whereNull('ended_at')->orWhere('ended_at', '>', $gap->started_at->format('Y-m-d H:i:s.u')))
            ->eachById(function (Interaction $interaction): void {
                $this->invalidate($interaction);
            }, 100);
    }
}
