<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DentistRosterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $kind
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 */
class DentistRoster extends Model
{
    /** @use HasFactory<DentistRosterFactory> */
    use HasFactory;

    public const KINDS = ['shift', 'cover', 'leave'];

    /** Kinds that put a dentist on duty. */
    public const COVERING_KINDS = ['shift', 'cover'];

    protected $fillable = ['dentist_id', 'kind', 'starts_at', 'ends_at', 'notes', 'created_by'];

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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCovering(Builder $query): Builder
    {
        return $query->whereIn('kind', self::COVERING_KINDS);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOnDutyAt(Builder $query, CarbonImmutable $at): Builder
    {
        return $query->covering()->where('starts_at', '<=', $at)->where('ends_at', '>', $at);
    }
}
