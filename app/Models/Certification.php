<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property list<array<string, mixed>> $curriculum_snapshot
 * @property int $revision
 */
class Certification extends Model
{
    public const STATUSES = ['active', 'revoked'];

    protected $fillable = [
        'user_id', 'status', 'issued_at', 'expires_at', 'authorised_by', 'reference',
        'curriculum_snapshot', 'revoked_at', 'revoked_by', 'revocation_reason', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'immutable_date',
            'expires_at' => 'immutable_date',
            'revoked_at' => 'immutable_datetime',
            'curriculum_snapshot' => 'array',
            'revision' => 'integer',
            'revocation_reason' => 'encrypted',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereDate('expires_at', '>=', now()->toDateString());
    }

    public function isCurrent(): bool
    {
        return $this->status === 'active'
            && $this->expires_at !== null
            && $this->expires_at->greaterThanOrEqualTo(now()->startOfDay());
    }

    public function hasExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lessThan(now()->startOfDay());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function authoriser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorised_by');
    }

    /** @return BelongsTo<User, $this> */
    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }
}
