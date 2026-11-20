<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReferralFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable|null $actioned_at
 * @property CarbonImmutable|null $outcome_recorded_at
 * @property CarbonImmutable|null $follow_up_at
 */
class Referral extends Model
{
    /** @use HasFactory<ReferralFactory> */
    use HasFactory;

    public const ROUTES = ['routine', 'urgent'];

    public const STATUSES = ['pending', 'actioned', 'outcome_recorded', 'cancelled'];

    public const OUTCOMES = ['accepted', 'appointment_booked', 'declined', 'no_response', 'closed'];

    protected $fillable = [
        'case_id', 'referred_by', 'dentist_id', 'route', 'destination_name', 'destination_contact', 'reason',
        'status', 'action_reference', 'actioned_at', 'outcome', 'outcome_note', 'outcome_recorded_at',
        'follow_up_at', 'updated_by', 'revision',
    ];

    protected function casts(): array
    {
        return [
            'actioned_at' => 'immutable_datetime', 'outcome_recorded_at' => 'immutable_datetime', 'follow_up_at' => 'immutable_datetime',
            'destination_contact' => 'encrypted', 'reason' => 'encrypted', 'outcome_note' => 'encrypted',
            'revision' => 'integer',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->whereHas('case', fn (Builder $case) => $case->visibleTo($user));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['pending', 'actioned']);
    }

    /** @return BelongsTo<CallerCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CallerCase::class, 'case_id');
    }

    /** @return BelongsTo<Dentist, $this> */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(Dentist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by');
    }
}
