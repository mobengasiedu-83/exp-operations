<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QaScore extends Model
{
    protected $fillable = ['qa_review_id', 'criterion', 'weight', 'rating', 'points', 'critical', 'note'];

    protected function casts(): array
    {
        return [
            'weight' => 'integer',
            'rating' => 'integer',
            'points' => 'integer',
            'critical' => 'boolean',
            'note' => 'encrypted',
        ];
    }

    /** @return BelongsTo<QaReview, $this> */
    public function review(): BelongsTo
    {
        return $this->belongsTo(QaReview::class, 'qa_review_id');
    }
}
