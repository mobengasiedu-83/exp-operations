<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DentistAvailabilityFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Manual status overrides recorded against a dentist.
 *
 * @property string $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class DentistAvailability extends Model
{
    /** @use HasFactory<DentistAvailabilityFactory> */
    use HasFactory;

    protected $table = 'dentist_availability';

    public const STATUSES = ['available', 'break', 'leave', 'unavailable', 'busy'];

    /** Statuses that remove a rostered dentist from cover. */
    public const BLOCKING_STATUSES = ['break', 'leave', 'unavailable', 'busy'];

    protected $fillable = ['dentist_id', 'status', 'starts_at', 'ends_at', 'reason', 'recorded_by'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Dentist, $this> */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(Dentist::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveAt(Builder $query, CarbonImmutable $at): Builder
    {
        return $query->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }
}
