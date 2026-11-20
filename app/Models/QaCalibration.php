<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property CarbonImmutable|null $agreed_at */
class QaCalibration extends Model
{
    public const STATUSES = ['open', 'agreed'];

    protected $fillable = [
        'qa_review_id', 'reviewer_id', 'secondary_total', 'variance', 'agreed_total',
        'status', 'note', 'agreed_by', 'agreed_at',
    ];

    protected function casts(): array
    {
        return [
            'secondary_total' => 'integer',
            'variance' => 'integer',
            'agreed_total' => 'integer',
            'agreed_at' => 'immutable_datetime',
            'note' => 'encrypted',
        ];
    }

    public function isAgreed(): bool
    {
        return $this->status === 'agreed';
    }

    /** @return BelongsTo<QaReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(QaReview::class, 'qa_review_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /** @return BelongsTo<User, $this> */
    public function agreedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agreed_by');
    }
}
