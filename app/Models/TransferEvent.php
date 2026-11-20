<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $occurred_at */
class TransferEvent extends Model
{
    protected $fillable = ['transfer_attempt_id', 'call_event_id', 'recorded_by', 'fingerprint', 'kind', 'outcome', 'note', 'occurred_at'];

    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'note' => 'encrypted'];
    }
}
