<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $accessed_at
 */
class IncidentEvidenceAccess extends Model
{
    protected $fillable = ['incident_id', 'user_id', 'purpose', 'accessed_at'];

    protected function casts(): array
    {
        return ['accessed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
