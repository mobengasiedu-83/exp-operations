<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $review_due_at
 */
class KnowledgeArticle extends Model
{
    public const CATEGORIES = ['faq', 'script', 'red_flag', 'referral_guidance', 'emergency', 'outage'];

    public const OFFLINE_CATEGORIES = ['red_flag', 'emergency', 'outage'];

    public const STATUSES = ['draft', 'in_review', 'approved', 'published', 'retired'];

    protected $fillable = [
        'reference', 'category', 'title', 'summary', 'status', 'current_version_id', 'created_by', 'review_due_at',
    ];

    protected function casts(): array
    {
        return ['review_due_at' => 'immutable_datetime'];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereHas('currentVersion', fn (Builder $version) => $version->where('status', 'published'));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('reference', 'like', $like)
                ->orWhere('title', 'like', $like)
                ->orWhere('summary', 'like', $like)
                ->orWhereHas('versions', fn (Builder $version) => $version
                    ->where('title', 'like', $like)
                    ->orWhere('body', 'like', $like));
        });
    }

    public function isRetired(): bool
    {
        return $this->status === 'retired';
    }

    /** @return HasMany<ScriptVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ScriptVersion::class);
    }

    /** @return BelongsTo<ScriptVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ScriptVersion::class, 'current_version_id');
    }

    /** @return HasMany<ApprovalWorkflow, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(ApprovalWorkflow::class);
    }

    /** @return HasMany<Translation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(Translation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
