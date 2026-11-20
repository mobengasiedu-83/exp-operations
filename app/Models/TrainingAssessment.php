<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $assessed_at
 * @property int $score
 * @property int $pass_mark
 */
class TrainingAssessment extends Model
{
    public const KINDS = ['knowledge', 'role_play'];

    public const RESULTS = ['pass', 'fail'];

    protected $fillable = [
        'training_module_id', 'user_id', 'kind', 'score', 'pass_mark', 'result',
        'assessed_by', 'assessed_at', 'note',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'pass_mark' => 'integer',
            'assessed_at' => 'immutable_datetime',
            'note' => 'encrypted',
        ];
    }

    public function passed(): bool
    {
        return $this->result === 'pass';
    }

    /** @return BelongsTo<TrainingModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(TrainingModule::class, 'training_module_id');
    }

    /** @return BelongsTo<User, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by');
    }
}
