<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $retired_at
 * @property int $day
 * @property int $pass_mark
 * @property int $revision
 */
class TrainingModule extends Model
{
    public const STATUSES = ['draft', 'published', 'retired'];

    protected $fillable = [
        'day', 'title', 'objectives', 'content', 'requires_role_play', 'pass_mark', 'status',
        'authored_by', 'published_by', 'published_at', 'retired_at', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'pass_mark' => 'integer',
            'revision' => 'integer',
            'requires_role_play' => 'boolean',
            'published_at' => 'immutable_datetime',
            'retired_at' => 'immutable_datetime',
        ];
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    /** @return HasMany<TrainingAssessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(TrainingAssessment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by');
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
