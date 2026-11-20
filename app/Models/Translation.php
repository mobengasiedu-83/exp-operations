<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $effective_from
 * @property CarbonImmutable|null $approved_at
 * @property int $revision
 */
class Translation extends Model
{
    public const STATUSES = ['draft', 'approved', 'retired'];

    protected $fillable = [
        'knowledge_article_id', 'script_version_id', 'language', 'title', 'body', 'status', 'effective_from',
        'translated_by', 'approved_by', 'approved_at', 'approval_reference', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'revision' => 'integer',
        ];
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /** @return BelongsTo<KnowledgeArticle, $this> */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KnowledgeArticle::class, 'knowledge_article_id');
    }

    /** @return BelongsTo<ScriptVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ScriptVersion::class, 'script_version_id');
    }

    /** @return BelongsTo<User, $this> */
    public function translator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'translated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
