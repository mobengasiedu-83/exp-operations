<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $opens_at
 * @property string $closes_at
 * @property list<int>|null $days
 * @property bool $is_closed
 * @property \DateTimeInterface|null $effective_from
 * @property \DateTimeInterface|null $effective_until
 * @property string|null $description
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
class ServiceHour extends Model
{
    protected $fillable = [
        'name',
        'opens_at',
        'closes_at',
        'days',
        'is_closed',
        'effective_from',
        'effective_until',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'is_closed' => 'boolean',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
        ];
    }

    /**
     * Does this window cover the given ISO-8601 weekday (1 = Monday)?
     */
    public function runsOn(int $weekday): bool
    {
        return $this->days === null || in_array($weekday, $this->days, true);
    }

    /**
     * Scope to only currently effective service hours.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('effective_from')
                ->orWhere('effective_from', '<=', now());
        })->where(function ($q) {
            $q->whereNull('effective_until')
                ->orWhere('effective_until', '>=', now());
        });
    }

    /**
     * Scope to only open service hours.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_closed', false);
    }
}
