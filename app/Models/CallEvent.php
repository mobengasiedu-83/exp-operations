<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable|null $occurred_at
 * @property array<string, mixed> $data
 */
class CallEvent extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['pbx_connection_id', 'interaction_id', 'ami_event_id', 'fingerprint', 'event_name', 'occurred_at', 'timing_quality', 'data'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'data' => 'array'];
    }
}
