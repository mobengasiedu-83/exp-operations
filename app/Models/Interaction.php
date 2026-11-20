<?php

namespace App\Models;

use App\Services\GhanaPhoneNumber;
use Carbon\CarbonImmutable;
use Database\Factories\InteractionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property list<string>|null $correlation_aliases
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $reconciled_at
 */
class Interaction extends Model
{
    /** @use HasFactory<InteractionFactory> */
    use HasFactory;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $fillable = ['pbx_connection_id', 'correlation_key', 'correlation_aliases', 'is_exp', 'status', 'timing_quality', 'reconciliation_status', 'started_at', 'ended_at', 'reconciled_at'];

    protected function casts(): array
    {
        return ['correlation_aliases' => 'array', 'is_exp' => 'boolean', 'started_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime', 'reconciled_at' => 'immutable_datetime'];
    }

    /** @return HasMany<CallLeg, $this> */
    public function legs(): HasMany
    {
        return $this->hasMany(CallLeg::class);
    }

    /** @return HasMany<CallEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(CallEvent::class);
    }

    /** @return HasMany<CallBridge, $this> */
    public function bridges(): HasMany
    {
        return $this->hasMany(CallBridge::class);
    }

    /** @return HasMany<CallTimingMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(CallTimingMetric::class);
    }

    /** @return HasMany<CorrelationException, $this> */
    public function exceptions(): HasMany
    {
        return $this->hasMany(CorrelationException::class);
    }

    /** @return HasOne<CallerCase, $this> */
    public function callerCase(): HasOne
    {
        return $this->hasOne(CallerCase::class);
    }

    /** @return HasMany<TransferAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(TransferAttempt::class)->orderBy('sequence')->orderBy('id');
    }

    /** @return HasMany<IvrStep, $this> */
    public function ivrSteps(): HasMany
    {
        return $this->hasMany(IvrStep::class)->orderBy('occurred_at')->orderBy('id');
    }

    /** Only explicit option-3 evidence qualifies a call for agent data entry.
     *
     * @param Builder<static> $query
     */
    public function scopeAgentSelected(Builder $query): void
    {
        $query->where('is_exp', true)
            ->whereHas('ivrSteps', fn (Builder $steps) => $steps->where('option', '3'));
    }

    /** @param Builder<static> $query */
    public function scopeVisibleInHistory(Builder $query, User $user): void
    {
        if (! $user->canAny(['view all cases', 'view pbx'])) {
            $query->whereHas('callerCase', fn ($cases) => $cases->visibleTo($user));
        }
    }

    /**
     * Masked caller number held by the listener, falling back to a dialable
     * number embedded in the caller channel when the PBX sent no caller ID.
     */
    protected function callerNumber(): Attribute
    {
        return Attribute::get(function (): ?string {
            $caller = $this->legs->first(fn (CallLeg $leg): bool => $leg->role === 'caller' && $leg->caller_masked !== null)
                ?? $this->callerLeg();

            return $caller?->caller_masked ?? GhanaPhoneNumber::mask(GhanaPhoneNumber::fromChannel($caller?->channel));
        });
    }

    /**
     * Masked inbound line (DID or IVR extension) the caller reached.
     */
    protected function inboundLine(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->callerLeg()?->inbound_masked);
    }

    /**
     * Masked dentist number dialled for the last transfer attempt.
     */
    protected function dentistNumber(): Attribute
    {
        return Attribute::get(function (): ?string {
            $mobile = $this->legs->first(fn (CallLeg $leg): bool => $leg->role === 'mobile');

            return $mobile?->destination_masked ?? GhanaPhoneNumber::mask(GhanaPhoneNumber::fromChannel($mobile?->channel));
        });
    }

    private function callerLeg(): ?CallLeg
    {
        return $this->legs->first(fn (CallLeg $leg): bool => $leg->role === 'caller');
    }
}
