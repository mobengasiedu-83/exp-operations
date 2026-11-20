<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property list<array{bridge_uniqueid: string, started_at: string|null, ended_at: string|null, duration_seconds: float|null}> $intervals
 */
class ConsultationSession extends Model
{
    protected $fillable = ['transfer_attempt_id', 'status', 'started_at', 'ended_at', 'consultation_seconds', 'intervals'];

    protected function casts(): array
    {
        return ['started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'consultation_seconds' => 'float', 'intervals' => 'array'];
    }
}
