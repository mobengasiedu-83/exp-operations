<?php

namespace App\Models;

use Database\Factories\AlertRuleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $threshold_seconds
 * @property bool $enabled
 */
class AlertRule extends Model
{
    /** @use HasFactory<AlertRuleFactory> */
    use HasFactory;

    protected $fillable = ['key', 'name', 'category', 'metric', 'threshold_seconds', 'severity', 'enabled', 'description'];

    protected function casts(): array
    {
        return ['threshold_seconds' => 'integer', 'enabled' => 'boolean'];
    }

    /** @return HasMany<AlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }
}
