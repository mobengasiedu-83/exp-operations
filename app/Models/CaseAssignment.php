<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 */
class CaseAssignment extends Model
{
    protected $fillable = ['case_id', 'assigned_to', 'assigned_by', 'next_action', 'due_at', 'started_at', 'ended_at', 'ended_reason'];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime',
            'next_action' => 'encrypted',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('case', fn (Builder $case) => $case->visibleTo($user));
    }

    public function isDue(): bool
    {
        return $this->ended_at === null && $this->due_at !== null && now()->greaterThanOrEqualTo($this->due_at);
    }

    /** @return BelongsTo<CallerCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CallerCase::class, 'case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }
}
