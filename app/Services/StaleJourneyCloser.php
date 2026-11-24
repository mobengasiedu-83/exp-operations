<?php

namespace App\Services;

use App\Models\CorrelationException;
use App\Models\Interaction;
use App\Models\PbxConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Closes journeys whose PBX evidence stopped arriving mid-call.
 *
 * The AMI listener rebuilds each interaction from the events it receives, and an
 * interaction only becomes `completed` once every observed leg has hung up. A
 * listener reconnect (or a dropped Hangup) therefore leaves the journey `active`
 * forever, which keeps stacking the dashboard's "Active now" card. Once the
 * feed for a connection is confirmed live again, a journey with no events for
 * the configured window is settled at its last evidence timestamp.
 */
class StaleJourneyCloser
{
    public function __construct(private TelemetryCoverage $coverage) {}

    /**
     * @return array{closed: int, skipped: int, candidates: list<int>}
     */
    public function close(int $minutes, bool $dryRun = false): array
    {
        $minutes = max(1, $minutes);
        $cutoff = CarbonImmutable::now()->subMinutes($minutes);
        /** @var array<int, PbxConnection|null> $connections */
        $connections = [];
        $closed = 0;
        $skipped = 0;
        $candidates = [];

        $interactions = Interaction::query()
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('started_at')->orWhere('started_at', '<', $cutoff))
            ->whereDoesntHave('events', fn ($query) => $query->where('occurred_at', '>=', $cutoff))
            ->orderBy('id')
            ->get();

        foreach ($interactions as $interaction) {
            $connectionId = (int) $interaction->pbx_connection_id;
            $connection = array_key_exists($connectionId, $connections)
                ? $connections[$connectionId]
                : ($connections[$connectionId] = PbxConnection::query()->find($connectionId));

            if ($connection === null || ! $this->feedIsLive($connection)) {
                $skipped++;

                continue;
            }

            $candidates[] = $interaction->id;
            if ($dryRun || ! $this->closeInteraction($interaction, $minutes)) {
                continue;
            }

            $closed++;
        }

        return ['closed' => $closed, 'skipped' => $skipped, 'candidates' => $candidates];
    }

    /**
     * A connection counts as live while its heartbeat (or an event) is within the
     * configured freshness window, so an outage never mass-closes real calls.
     */
    private function feedIsLive(PbxConnection $connection): bool
    {
        $staleAfter = max(30, (int) config('services.exp_pbx.heartbeat_stale_seconds', 90));
        $freshAfter = CarbonImmutable::now()->subSeconds($staleAfter);

        return ($connection->last_heartbeat_at !== null && $connection->last_heartbeat_at->greaterThanOrEqualTo($freshAfter))
            || ($connection->last_event_at !== null && $connection->last_event_at->greaterThanOrEqualTo($freshAfter));
    }

    private function closeInteraction(Interaction $interaction, int $minutes): bool
    {
        return DB::transaction(function () use ($interaction, $minutes): bool {
            $locked = Interaction::query()->lockForUpdate()->find($interaction->id);
            if ($locked === null || $locked->status !== 'active') {
                return false;
            }

            $lastEventAt = $locked->events()->max('occurred_at');
            $endedAt = is_string($lastEventAt)
                ? CarbonImmutable::parse($lastEventAt)
                : ($locked->started_at ?? CarbonImmutable::now());

            $locked->legs()->whereNull('ended_at')->update(['ended_at' => $endedAt]);
            $locked->bridges()->whereNull('ended_at')->update(['ended_at' => $endedAt]);
            $locked->update([
                'status' => 'completed',
                'ended_at' => $endedAt,
                'reconciliation_status' => 'pending',
                'reconciled_at' => null,
            ]);
            CorrelationException::firstOrCreate(
                ['fingerprint' => hash('sha256', $locked->id.':projection:journey_closed_stale')],
                [
                    'pbx_connection_id' => $locked->pbx_connection_id,
                    'interaction_id' => $locked->id,
                    'category' => 'projection',
                    'code' => 'journey_closed_stale',
                    'subject' => mb_substr(sprintf('No PBX events for %d minutes', $minutes), 0, 255),
                    'details' => [
                        'reason' => 'no_events',
                        'threshold_minutes' => $minutes,
                        'last_event_at' => $lastEventAt,
                        'closed_at' => CarbonImmutable::now()->toIso8601String(),
                    ],
                ],
            );
            $this->coverage->invalidate($locked);

            return true;
        });
    }
}
