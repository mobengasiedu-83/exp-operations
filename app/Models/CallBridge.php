<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<string> $participants
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property float|null $duration_seconds
 */
class CallBridge extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['interaction_id', 'bridge_uniqueid', 'stage', 'participants', 'started_at', 'ended_at', 'duration_seconds'];

    protected function casts(): array
    {
        return ['participants' => 'array', 'started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'duration_seconds' => 'float'];
    }

    /** @return BelongsTo<Interaction, $this> */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }
}
