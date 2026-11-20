<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed> $details
 */
class CorrelationException extends Model
{
    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['pbx_connection_id', 'interaction_id', 'call_event_id', 'fingerprint', 'category', 'code', 'subject', 'details'];

    /** @return BelongsTo<CallEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(CallEvent::class, 'call_event_id');
    }

    protected function casts(): array
    {
        return ['details' => 'array'];
    }
}
