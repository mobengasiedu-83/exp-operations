<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $attempted_at
 */
class CallbackAttempt extends Model
{
    public const VOICEMAIL_SAFE_STATEMENT = 'Left a neutral message asking the caller to return the call; no clinical or personal detail was disclosed.';

    protected $fillable = ['callback_id', 'attempted_by', 'outcome', 'neutral_voicemail_confirmed', 'note', 'attempted_at'];

    protected function casts(): array
    {
        return [
            'attempted_at' => 'immutable_datetime',
            'neutral_voicemail_confirmed' => 'boolean',
            'note' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Callback, $this> */
    public function callback(): BelongsTo
    {
        return $this->belongsTo(Callback::class);
    }

    /** @return BelongsTo<User, $this> */
    public function attemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attempted_by');
    }
}
