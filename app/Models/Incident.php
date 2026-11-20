<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property CarbonImmutable|null $occurred_at
 * @property CarbonImmutable|null $escalated_at
 * @property CarbonImmutable|null $escalation_due_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property int $revision
 */
class Incident extends Model
{
    public const CATEGORIES = ['safety', 'privacy', 'complaint', 'safeguarding'];

    public const SEVERITIES = ['critical', 'high', 'medium', 'low'];

    public const STATUSES = ['open', 'investigating', 'resolved', 'closed'];

    protected $fillable = [
        'reference', 'category', 'severity', 'status', 'case_id', 'qa_review_id', 'reported_by',
        'assigned_to', 'occurred_at', 'summary', 'description', 'immediate_action', 'escalation_note',
        'escalated_at', 'escalation_due_at', 'resolved_at', 'resolution_summary', 'closed_by',
        'closed_at', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'escalation_due_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'revision' => 'integer',
            'description' => 'encrypted',
            'immediate_action' => 'encrypted',
            'escalation_note' => 'encrypted',
            'resolution_summary' => 'encrypted',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    /** @return HasMany<CorrectiveAction, $this> */
    public function actions(): HasMany
    {
        return $this->hasMany(CorrectiveAction::class);
    }

    /** @return HasMany<IncidentEvidenceAccess, $this> */
    public function evidenceAccesses(): HasMany
    {
        return $this->hasMany(IncidentEvidenceAccess::class);
    }

    /** @return BelongsTo<CallerCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CallerCase::class, 'case_id');
    }

    /** @return BelongsTo<QaReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(QaReview::class, 'qa_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<User, $this> */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
