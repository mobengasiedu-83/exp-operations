<?php

namespace App\Services;

use App\Models\Interaction;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregate figures for the filtered call history list.
 *
 * Every figure is measured over the same filtered query the table uses, so the
 * summary describes all matching calls rather than the page in view. Counts are
 * taken in SQL, and duration is summed from retained start and end evidence so
 * a call without a hangup stays out of the average instead of counting as zero.
 */
class CallHistorySummary
{
    /**
     * @param  Builder<Interaction>  $query
     * @return array{
     *     total_calls: int,
     *     completed_calls: int,
     *     active_calls: int,
     *     measured_calls: int,
     *     total_seconds: int,
     *     average_seconds: int|null,
     *     connected_calls: int,
     *     unverified_calls: int,
     *     no_connection_calls: int,
     *     connected_share: float
     * }
     */
    public function for(Builder $query): array
    {
        $totals = (clone $query)->reorder()->toBase()
            ->selectRaw('COUNT(*) as total_calls')
            ->selectRaw('SUM(CASE WHEN interactions.ended_at IS NOT NULL AND interactions.started_at IS NOT NULL THEN 1 ELSE 0 END) as measured_calls')
            ->selectRaw('SUM(CASE WHEN interactions.ended_at IS NOT NULL AND interactions.started_at IS NOT NULL THEN '.$this->durationExpression($query).' ELSE 0 END) as total_seconds')
            ->selectRaw('SUM(CASE WHEN interactions.status = ? THEN 1 ELSE 0 END) as completed_calls', ['completed'])
            ->first();

        $total = (int) ($totals?->total_calls ?? 0);
        $measured = (int) ($totals?->measured_calls ?? 0);
        $totalSeconds = (int) round((float) ($totals?->total_seconds ?? 0));
        $completed = (int) ($totals?->completed_calls ?? 0);

        $connected = (clone $query)
            ->whereHas('attempts', fn (Builder $attempts) => $attempts->verifiedConnection())
            ->count();
        $unverified = (clone $query)
            ->whereHas('attempts', fn (Builder $attempts) => $attempts->unverifiedBridgeEvidence())
            ->whereDoesntHave('attempts', fn (Builder $attempts) => $attempts->verifiedConnection())
            ->count();

        return [
            'total_calls' => $total,
            'completed_calls' => $completed,
            'active_calls' => max(0, $total - $completed),
            'measured_calls' => $measured,
            'total_seconds' => $totalSeconds,
            'average_seconds' => $measured === 0 ? null : (int) round($totalSeconds / $measured),
            'connected_calls' => $connected,
            'unverified_calls' => $unverified,
            'no_connection_calls' => max(0, $total - $connected - $unverified),
            'connected_share' => $total === 0 ? 0.0 : round($connected / $total * 100, 1),
        ];
    }

    /**
     * Seconds between the retained start and end stamps, expressed per driver
     * because SQLite, MySQL/MariaDB and PostgreSQL each use their own function.
     *
     * @param  Builder<Interaction>  $query
     */
    private function durationExpression(Builder $query): string
    {
        return match ($query->getConnection()->getDriverName()) {
            'sqlite' => "(strftime('%s', interactions.ended_at) - strftime('%s', interactions.started_at))",
            'pgsql' => '(EXTRACT(EPOCH FROM (interactions.ended_at - interactions.started_at)))',
            default => 'TIMESTAMPDIFF(SECOND, interactions.started_at, interactions.ended_at)',
        };
    }
}
