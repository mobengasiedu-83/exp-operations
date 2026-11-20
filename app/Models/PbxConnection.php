<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $last_connected_at
 * @property Carbon|null $last_event_at
 * @property Carbon|null $last_heartbeat_at
 */
class PbxConnection extends Model
{
    protected $fillable = ['name', 'host', 'ami_port', 'status', 'last_connected_at', 'last_event_at', 'last_error', 'last_heartbeat_at', 'ami_username', 'ami_password', 'listener_enabled', 'reconnect_generation'];

    protected $hidden = ['ami_username', 'ami_password'];

    protected function casts(): array
    {
        return ['ami_password' => 'encrypted', 'listener_enabled' => 'boolean', 'reconnect_generation' => 'integer', 'last_connected_at' => 'datetime', 'last_event_at' => 'datetime', 'last_heartbeat_at' => 'datetime'];
    }

    /** @return array{host: string, port: int, username: string, password: string, timeout: int} */
    public function configuration(): array
    {
        return [
            'host' => $this->host,
            'port' => (int) $this->ami_port,
            'username' => $this->ami_username ?? config('services.exp_pbx.username'),
            'password' => $this->ami_password ?? config('services.exp_pbx.password'),
            'timeout' => (int) config('services.exp_pbx.timeout', 10),
        ];
    }

    /** @return HasMany<Interaction, $this> */
    public function interactions(): HasMany
    {
        return $this->hasMany(Interaction::class);
    }

    /** @return HasMany<PbxTelemetryGap, $this> */
    public function telemetryGaps(): HasMany
    {
        return $this->hasMany(PbxTelemetryGap::class);
    }

    /** @return HasMany<AmiEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AmiEvent::class);
    }
}
