<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $received_at
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $correlation_data
 */
class AmiEvent extends Model
{
    protected $fillable = ['pbx_connection_id', 'event_name', 'uniqueid', 'linkedid', 'channel', 'payload', 'received_at', 'correlation_data'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'correlation_data' => 'array', 'received_at' => 'datetime'];
    }

    /** @return BelongsTo<PbxConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PbxConnection::class, 'pbx_connection_id');
    }
}
