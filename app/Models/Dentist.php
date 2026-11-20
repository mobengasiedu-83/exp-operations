<?php

namespace App\Models;

use Database\Factories\DentistFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property bool $active
 */
class Dentist extends Model
{
    /** @use HasFactory<DentistFactory> */
    use HasFactory;

    protected $fillable = ['name', 'email', 'active', 'notes'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return HasMany<DentistNumber, $this> */
    public function numbers(): HasMany
    {
        return $this->hasMany(DentistNumber::class);
    }

    /** @return HasMany<DentistRoster, $this> */
    public function rosters(): HasMany
    {
        return $this->hasMany(DentistRoster::class);
    }

    /** @return HasMany<DentistAvailability, $this> */
    public function availability(): HasMany
    {
        return $this->hasMany(DentistAvailability::class);
    }

    /** @return HasMany<DentistCredential, $this> */
    public function credentials(): HasMany
    {
        return $this->hasMany(DentistCredential::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
