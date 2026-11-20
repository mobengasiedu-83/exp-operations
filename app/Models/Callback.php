<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CallbackFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable|null $completed_at
 */
class Callback extends Model
{
    /** @use HasFactory<CallbackFactory> */
    use HasFactory;

    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public const PRIORITIES = ['routine', 'urgent'];

    public const OUTCOMES = ['answered', 'declined', 'unreachable', 'wrong_number', 'caller_cancelled', 'no_further_action'];

    public const ATTEMPT_OUTCOMES = ['answered', 'no_answer', 'busy', 'voicemail', 'wrong_number', 'declined'];

    /**
     * Minutes after the booked due time before a missed callback SLA escalates.
     */
    public const SLA_MINUTES = 60;

    protected $fillable = [
        'case_id', 'consent_record_id', 'assigned_to', 'booked_by', 'status', 'priority', 'reason',
        'due_at', 'window_start', 'window_end', 'escalated_at', 'outcome', 'outcome_note',
        'completed_at', 'completed_by', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime', 'escalated_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'reason' => 'encrypted', 'outcome_note' => 'encrypted',
            'revision' => 'integer',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('case', fn (Builder $case) => $case->visibleTo($user));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_progress']);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_progress'])->where('due_at', '<=', now());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'in_progress'])->where('due_at', '<=', now()->subMinutes(self::SLA_MINUTES));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'in_progress'], true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at !== null && now()->greaterThan($this->due_at->addMinutes(self::SLA_MINUTES));
    }

    /** @return BelongsTo<CallerCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CallerCase::class, 'case_id');
    }

    /** @return BelongsTo<ConsentRecord, $this> */
    public function consent(): BelongsTo
    {
        return $this->belongsTo(ConsentRecord::class, 'consent_record_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function booker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }

    /** @return HasMany<CallbackAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(CallbackAttempt::class);
    }
}
