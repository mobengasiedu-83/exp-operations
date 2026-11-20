<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DentistNumberFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $number
 * @property string $original
 * @property string $status
 * @property bool $is_primary
 * @property CarbonImmutable|null $verified_at
 */
class DentistNumber extends Model
{
    /** @use HasFactory<DentistNumberFactory> */
    use HasFactory;

    public const STATUSES = ['approved', 'pending', 'retired'];

    protected $fillable = ['dentist_id', 'number', 'original', 'label', 'is_primary', 'status', 'verified_at', 'notes'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean', 'verified_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Dentist, $this> */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(Dentist::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeContactable(Builder $query): Builder
    {
        return $query->whereIn('status', ['approved', 'pending']);
    }

    public function masked(): string
    {
        return substr($this->number, 0, 7).str_repeat('*', max(strlen($this->number) - 9, 1)).substr($this->number, -2);
    }
}
