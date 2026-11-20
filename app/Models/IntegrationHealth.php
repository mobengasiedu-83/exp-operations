<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $component
 * @property string $status
 * @property \DateTimeInterface|null $last_check_at
 * @property string|null $last_error
 * @property array<string, mixed>|null $metadata
 * @property \DateTimeInterface|null $created_at
 * @property \DateTimeInterface|null $updated_at
 */
class IntegrationHealth extends Model
{
    protected $table = 'integration_health';

    protected $fillable = [
        'component',
        'status',
        'last_check_at',
        'last_error',
        'metadata',
    ];

    protected $casts = [
        'last_check_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * Record a health check result for a component.
     *
     * @param array<string, mixed> $metadata */
    public static function record(string $component, string $status, ?string $lastError = null, array $metadata = []): self
    {
        $health = self::updateOrCreate(
            ['component' => $component],
            [
                'status' => $status,
                'last_check_at' => now(),
                'last_error' => $lastError,
                'metadata' => $metadata,
            ]
        );

        return $health;
    }

    /**
     * Scope to only healthy components.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('status', 'healthy');
    }

    /**
     * Scope to only unhealthy components.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnhealthy(Builder $query): Builder
    {
        return $query->where('status', '!=', 'healthy');
    }
}
