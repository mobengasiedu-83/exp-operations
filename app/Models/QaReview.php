<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property CarbonImmutable|null $reviewed_at
 * @property CarbonImmutable|null $coaching_due_at
 * @property CarbonImmutable|null $coaching_completed_at
 * @property CarbonImmutable|null $finalised_at
 * @property int|null $total
 * @property int $revision
 */
class QaReview extends Model
{
    public const STATUSES = ['draft', 'finalised'];

    public const OUTCOMES = ['pass', 'remediate', 'critical_fail'];

    protected $fillable = [
        'reference', 'case_id', 'agent_id', 'reviewer_id', 'reviewed_at', 'status', 'total', 'outcome',
        'critical_fail', 'critical_note', 'notes', 'coaching_required', 'coaching_due_at',
        'coaching_completed_at', 'coaching_note', 'coached_by', 'finalised_at', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_at' => 'immutable_date',
            'coaching_due_at' => 'immutable_datetime',
            'coaching_completed_at' => 'immutable_datetime',
            'finalised_at' => 'immutable_datetime',
            'critical_fail' => 'boolean',
            'coaching_required' => 'boolean',
            'total' => 'integer',
            'revision' => 'integer',
            'critical_note' => 'encrypted',
            'notes' => 'encrypted',
            'coaching_note' => 'encrypted',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFinalised(Builder $query): Builder
    {
        return $query->where('status', 'finalised');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isCriticalFail(): bool
    {
        return $this->critical_fail;
    }

    /** @return HasMany<QaScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(QaScore::class);
    }

    /** @return HasMany<QaCalibration, $this> */
    public function calibrations(): HasMany
    {
        return $this->hasMany(QaCalibration::class);
    }

    /** @return HasOne<Incident, $this> */
    public function incident(): HasOne
    {
        return $this->hasOne(Incident::class);
    }

    /** @return BelongsTo<CallerCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CallerCase::class, 'case_id');
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function coach(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coached_by');
    }
}
