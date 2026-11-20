<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $decided_at
 */
class ApprovalWorkflow extends Model
{
    public const AREAS = ['clinical', 'brand'];

    public const DECISIONS = ['pending', 'approved', 'changes_requested'];

    protected $fillable = [
        'knowledge_article_id', 'script_version_id', 'area', 'decision', 'requested_by',
        'approver_id', 'approver_name', 'reference', 'note', 'decided_at',
    ];

    protected function casts(): array
    {
        return ['note' => 'encrypted', 'decided_at' => 'immutable_datetime'];
    }

    public function isPending(): bool
    {
        return $this->decision === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->decision === 'approved';
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }
}
