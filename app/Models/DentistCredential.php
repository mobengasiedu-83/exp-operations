<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DentistCredentialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $type
 * @property string $number
 * @property CarbonImmutable|null $expires_on
 * @property CarbonImmutable|null $verified_at
 */
class DentistCredential extends Model
{
    /** @use HasFactory<DentistCredentialFactory> */
    use HasFactory;

    public const TYPES = ['license', 'registration', 'indemnity'];

    /** Days before expiry at which a credential is reported as expiring. */
    public const EXPIRING_WINDOW_DAYS = 30;

    protected $fillable = ['dentist_id', 'type', 'number', 'issuer', 'issued_on', 'expires_on', 'verified_at', 'notes'];

    protected function casts(): array
    {
        return ['issued_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'verified_at' => 'immutable_datetime'];
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
    public function scopeExpired(Builder $query, ?CarbonImmutable $at = null): Builder
    {
        return $query->whereNotNull('expires_on')->where('expires_on', '<', ($at ?? CarbonImmutable::now())->toDateString());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeExpiringBefore(Builder $query, CarbonImmutable $at): Builder
    {
        return $query->whereNotNull('expires_on')
            ->where('expires_on', '>=', CarbonImmutable::now()->toDateString())
            ->where('expires_on', '<=', $at->toDateString());
    }

    /**
     * Validity is derived from the expiry date so a stale row can never be reported as valid.
     */
    public function validity(?CarbonImmutable $at = null): string
    {
        if ($this->expires_on === null) {
            return 'no_expiry';
        }

        $today = ($at ?? CarbonImmutable::now())->startOfDay();

        return match (true) {
            $this->expires_on->lt($today) => 'expired',
            $this->expires_on->lte($today->addDays(self::EXPIRING_WINDOW_DAYS)) => 'expiring',
            default => 'valid',
        };
    }
}
