<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TransferAttemptFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property CarbonImmutable|null $dial_started_at
 * @property CarbonImmutable|null $answered_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $transfer_completed_at
 * @property CarbonImmutable|null $human_accepted_at
 * @property string|null $human_outcome
 * @property string $status
 */
class TransferAttempt extends Model
{
    /** @use HasFactory<TransferAttemptFactory> */
    use HasFactory;

    public const STATUSES = ['dialling', 'ringing', 'network_answered', 'unanswered', 'busy', 'cancelled', 'failed', 'transfer_failed', 'voicemail', 'declined', 'accepted', 'consultation_observed', 'completed'];

    public const MATCH_STATUSES = ['approved', 'pending', 'unknown'];

    public const FAILURE_STATUSES = ['busy', 'unanswered', 'failed', 'cancelled', 'transfer_failed', 'voicemail', 'declined'];

    protected $fillable = ['interaction_id', 'call_leg_id', 'dentist_id', 'retry_of_id', 'sequence', 'destination_masked', 'match_status', 'dial_status', 'status', 'failure_reason', 'human_outcome', 'acceptance_confidence', 'fallback_status', 'timing_quality', 'dial_started_at', 'ringing_at', 'answered_at', 'ended_at', 'transfer_completed_at', 'human_accepted_at', 'dial_to_answer_seconds', 'ringing_seconds', 'mobile_connected_seconds', 'private_handover_seconds', 'consultation_seconds', 'revision'];

    protected function casts(): array
    {
        return [
            'dial_started_at' => 'immutable_datetime', 'ringing_at' => 'immutable_datetime', 'answered_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime',
            'transfer_completed_at' => 'immutable_datetime', 'human_accepted_at' => 'immutable_datetime',
            'dial_to_answer_seconds' => 'float', 'ringing_seconds' => 'float', 'mobile_connected_seconds' => 'float', 'private_handover_seconds' => 'float', 'consultation_seconds' => 'float',
            'revision' => 'integer', 'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<Interaction, $this> */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }

    /** @return BelongsTo<CallLeg, $this> */
    public function leg(): BelongsTo
    {
        return $this->belongsTo(CallLeg::class, 'call_leg_id');
    }

    /** @return BelongsTo<Dentist, $this> */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(Dentist::class);
    }

    /** @return HasMany<TransferEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TransferEvent::class);
    }

    /** @return HasOne<ConsultationSession, $this> */
    public function session(): HasOne
    {
        return $this->hasOne(ConsultationSession::class);
    }

    /**
     * A verified connection requires an explicit dentist acceptance recorded
     * against an answered leg and a confirmed consultation bridge. A network
     * answer on its own is never treated as a connected dentist.
     */
    public function isVerifiedConnection(): bool
    {
        return $this->human_accepted_at !== null
            && $this->human_outcome === 'accepted'
            && $this->session?->status === 'human_confirmed'
            && $this->session?->started_at !== null;
    }

    /**
     * Bridge evidence exists but human acceptance was never confirmed, so the
     * connection must be shown as unverified rather than as a consultation.
     */
    public function hasUnverifiedBridgeEvidence(): bool
    {
        if ($this->session?->started_at !== null) {
            return true;
        }

        return $this->transfer_completed_at !== null && $this->answered_at !== null;
    }

    /**
     * Query mirror of {@see isVerifiedConnection()} for history filters.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVerifiedConnection(Builder $query): void
    {
        $query->whereNotNull('human_accepted_at')->where('human_outcome', 'accepted')
            ->whereHas('session', fn (Builder $sessions) => $sessions->where('status', 'human_confirmed')->whereNotNull('started_at'));
    }

    /**
     * Query mirror of {@see hasUnverifiedBridgeEvidence()} for history filters.
     *
     * @param  Builder<static>  $query
     */
    public function scopeUnverifiedBridgeEvidence(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query->whereHas('session', fn (Builder $sessions) => $sessions->whereNotNull('started_at'))
                ->orWhere(fn (Builder $query) => $query->whereNotNull('transfer_completed_at')->whereNotNull('answered_at'));
        });
    }
}
