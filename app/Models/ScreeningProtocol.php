<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ScreeningProtocolFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property CarbonImmutable|null $clinical_approved_at
 * @property CarbonImmutable|null $privacy_approved_at
 */
class ScreeningProtocol extends Model
{
    /** @use HasFactory<ScreeningProtocolFactory> */
    use HasFactory;

    protected $fillable = ['version', 'title', 'status', 'is_active', 'privacy_notice', 'allow_guardian', 'guidance', 'faq_answer', 'created_by', 'clinical_approved_by', 'clinical_approved_at', 'clinical_approval_reference', 'privacy_approved_by', 'privacy_approved_at', 'privacy_approval_reference'];

    protected function casts(): array
    {
        return ['guidance' => 'array', 'allow_guardian' => 'boolean', 'is_active' => 'boolean', 'clinical_approved_at' => 'immutable_datetime', 'privacy_approved_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ScreeningQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(ScreeningQuestion::class)->orderBy('position');
    }

    /** @param Builder<ScreeningProtocol> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->is_active && $this->status === 'active';
    }

    /**
     * Make this version the one new calls must use. Every other version is
     * kept for history but stops being selectable.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            self::query()->whereKeyNot($this->getKey())->where('is_active', true)
                ->update(['is_active' => false, 'status' => 'retired']);
            $this->update(['is_active' => true, 'status' => 'active']);
        });
    }
}
