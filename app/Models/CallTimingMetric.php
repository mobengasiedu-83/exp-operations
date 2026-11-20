<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property float|null $outbound_setup_seconds
 * @property float|null $dial_to_answer_seconds
 * @property float|null $ringing_seconds
 * @property float|null $mobile_connected_seconds
 * @property float|null $private_handover_seconds
 * @property float|null $consultation_seconds
 * @property CarbonImmutable|null $human_accepted_at
 */
class CallTimingMetric extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['interaction_id', 'call_leg_id', 'outbound_setup_seconds', 'dial_to_answer_seconds', 'ringing_seconds', 'mobile_connected_seconds', 'private_handover_seconds', 'consultation_seconds', 'human_accepted_at', 'acceptance_status'];

    protected function casts(): array
    {
        return ['outbound_setup_seconds' => 'float', 'dial_to_answer_seconds' => 'float', 'ringing_seconds' => 'float', 'mobile_connected_seconds' => 'float', 'private_handover_seconds' => 'float', 'consultation_seconds' => 'float', 'human_accepted_at' => 'immutable_datetime'];
    }
}
