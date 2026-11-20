<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property CarbonImmutable $created_at */
class CallControlCommand extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['case_id', 'interaction_id', 'requested_by', 'dentist_number_id', 'active_interaction_id', 'request_key', 'payload_hash', 'action', 'expected_version', 'source_state', 'status', 'error_code', 'destination_masked', 'destination', 'dentist_uniqueid', 'human_accepted', 'acceptance_recorded_at'];

    protected $hidden = ['payload_hash', 'active_interaction_id', 'destination'];

    protected function casts(): array
    {
        return ['destination' => 'encrypted', 'human_accepted' => 'boolean', 'created_at' => 'immutable_datetime', 'acceptance_recorded_at' => 'immutable_datetime'];
    }
}
