<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property bool|null $observed_creation
 * @property string|null $caller_masked
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $dial_started_at
 * @property CarbonImmutable|null $ringing_at
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $ended_at
 */
class CallLeg extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['interaction_id', 'pbx_connection_id', 'uniqueid', 'channel', 'role', 'entry_kind', 'inbound_masked', 'caller_masked', 'caller_hash', 'dial_status', 'observed_creation', 'started_at', 'dial_started_at', 'ringing_at', 'answered_at', 'ended_at', 'destination_hash', 'destination_masked'];

    protected function casts(): array
    {
        return ['observed_creation' => 'boolean', 'started_at' => 'immutable_datetime', 'dial_started_at' => 'immutable_datetime', 'ringing_at' => 'immutable_datetime', 'answered_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Interaction, $this> */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }
}
