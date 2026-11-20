<?php

namespace App\Models;

use Database\Factories\DashboardPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, string|null>|null $filters
 * @property int $refresh_seconds
 */
class DashboardPreference extends Model
{
    /** @use HasFactory<DashboardPreferenceFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'filters', 'refresh_seconds'];

    protected function casts(): array
    {
        return ['filters' => 'array', 'refresh_seconds' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
