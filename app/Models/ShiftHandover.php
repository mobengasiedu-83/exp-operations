<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<int, array<string, mixed>>|null $unresolved_cases
 * @property CarbonImmutable|null $shift_started_at
 * @property CarbonImmutable|null $shift_ended_at
 * @property CarbonImmutable|null $acknowledged_at
 */
class ShiftHandover extends Model
{
    protected $fillable = ['outgoing_user_id', 'incoming_user_id', 'recorded_by', 'shift_started_at', 'shift_ended_at', 'unresolved_cases', 'notes', 'acknowledged_at'];

    protected function casts(): array
    {
        return [
            'shift_started_at' => 'immutable_datetime', 'shift_ended_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime',
            'unresolved_cases' => 'array',
            'notes' => 'encrypted',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function outgoing(): BelongsTo
    {
        return $this->belongsTo(User::class, 'outgoing_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function incoming(): BelongsTo
    {
        return $this->belongsTo(User::class, 'incoming_user_id');
    }
}
