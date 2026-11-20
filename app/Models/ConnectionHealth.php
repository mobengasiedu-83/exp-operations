<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $last_event_at
 * @property Carbon|null $last_heartbeat_at
 */
class ConnectionHealth extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'connection_health';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'connection_id',
        'status',
        'reconnect_attempts',
        'backoff_seconds',
        'last_heartbeat_at',
        'last_event_at',
        'lag_seconds',
        'last_error',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'reconnect_attempts' => 'integer',
            'backoff_seconds' => 'integer',
            'last_heartbeat_at' => 'datetime',
            'last_event_at' => 'datetime',
            'lag_seconds' => 'integer',
        ];
    }

    /**
     * Get the PBX connection that owns the health record.
     *
     * @return BelongsTo<PbxConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PbxConnection::class, 'connection_id');
    }

    /**
     * Scope a query to only healthy connections.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('status', 'connected')->where('last_heartbeat_at', '>=', now()->subSeconds(config('services.exp_pbx.heartbeat_stale_seconds', 90)));
    }

    /**
     * Scope a query to only unhealthy connections.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnhealthy(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('status', '!=', 'connected')
                ->orWhereNull('last_heartbeat_at')
                ->orWhere('last_heartbeat_at', '<', now()->subSeconds(config('services.exp_pbx.heartbeat_stale_seconds', 90)));
        });
    }

    /**
     * Scope a query to only connected connections.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeConnected(Builder $query): Builder
    {
        return $query->where('status', 'connected');
    }

    /**
     * Scope a query to only disconnected connections.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDisconnected(Builder $query): Builder
    {
        return $query->where('status', 'disconnected');
    }
}
