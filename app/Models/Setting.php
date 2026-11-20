<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $group
 * @property string|null $value
 * @property string $cast
 * @property bool $is_feature_flag
 * @property string|null $description
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'group',
        'value',
        'cast',
        'is_feature_flag',
        'description',
    ];

    protected $casts = [
        'is_feature_flag' => 'boolean',
    ];

    /**
     * Get the setting value cast to its configured type.
     */
    public function getTypedValueAttribute(): mixed
    {
        $value = $this->value;

        if ($value === null) {
            return null;
        }

        return match ($this->cast) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'array' => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    /**
     * Scope to only feature flags.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFeatureFlags(Builder $query): Builder
    {
        return $query->where('is_feature_flag', true);
    }

    /**
     * Scope to a group of settings.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeGroup(Builder $query, string $group): Builder
    {
        return $query->where('group', $group);
    }

    /**
     * Get a setting value by key, with an optional default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();

        return $setting->typed_value ?? $default;
    }

    /**
     * Check if a feature flag is enabled.
     */
    public static function flag(string $key): bool
    {
        $setting = static::where('key', $key)
            ->where('is_feature_flag', true)
            ->first();

        return $setting->typed_value ?? false;
    }
}
