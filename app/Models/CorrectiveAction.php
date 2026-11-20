<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $verified_at
 */
class CorrectiveAction extends Model
{
    public const STATUSES = ['open', 'completed', 'verified'];

    protected $fillable = [
        'incident_id', 'owner_id', 'description', 'due_at', 'status', 'completed_at',
        'completion_note', 'verified_by', 'verified_at', 'verification_note',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'description' => 'encrypted',
            'completion_note' => 'encrypted',
            'verification_note' => 'encrypted',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<User, $this> */
    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
