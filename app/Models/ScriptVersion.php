<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $retired_at
 * @property int $version
 * @property int $revision
 */
class ScriptVersion extends Model
{
    public const STATUSES = ['draft', 'in_review', 'approved', 'published', 'retired'];

    protected $fillable = [
        'knowledge_article_id', 'version', 'language', 'title', 'body', 'change_summary', 'status',
        'effective_from', 'effective_to', 'authored_by', 'published_by', 'published_at', 'retired_at', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer', 'revision' => 'integer',
            'effective_from' => 'immutable_date', 'effective_to' => 'immutable_date',
            'published_at' => 'immutable_datetime', 'retired_at' => 'immutable_datetime',
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

    /**
     * Return the recorded review decision for one approval area.
     */
    public function approval(string $area): ?ApprovalWorkflow
    {
        if ($this->relationLoaded('approvals')) {
            $approval = $this->getRelation('approvals')->firstWhere('area', $area);

            return $approval instanceof ApprovalWorkflow ? $approval : null;
        }

        return $this->approvals()->where('area', $area)->first();
    }

    /** @return BelongsTo<KnowledgeArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
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
