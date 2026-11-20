<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PbxTelemetryGapFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 */
class PbxTelemetryGap extends Model
{
    /** @use HasFactory<PbxTelemetryGapFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['pbx_connection_id', 'started_at', 'ended_at', 'reason'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }
}
