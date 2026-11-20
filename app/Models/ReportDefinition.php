<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $category
 * @property string $grain
 * @property string $description
 * @property string $permission
 * @property bool $sensitive
 * @property bool $enabled
 * @property list<string> $kpis
 * @property int $revision
 */
class ReportDefinition extends Model
{
    public const GRAINS = ['daily', 'weekly', 'monthly'];

    public const CATEGORIES = ['operations', 'safety', 'quality', 'follow_up', 'dentists', 'executive'];

    protected $fillable = [
        'key', 'name', 'category', 'grain', 'description', 'permission',
        'sensitive', 'enabled', 'kpis', 'revision',
    ];

    protected $casts = [
        'sensitive' => 'boolean',
        'enabled' => 'boolean',
        'kpis' => 'array',
        'revision' => 'integer',
    ];

    /** @return HasMany<ReportRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }

    /**
     * Limit definitions to the ones an operator's report permissions cover.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        if ($user->can('view executive reports')) {
            return $query;
        }

        return $query->where(function (Builder $inner): void {
            $inner->where('sensitive', false)->orWhere('permission', '!=', 'view executive reports');
        });
    }
}
