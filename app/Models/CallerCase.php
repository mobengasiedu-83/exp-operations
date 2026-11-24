<?php

namespace App\Models;

use Database\Factories\CallerCaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string|null $concern
 * @property string|null $callback_number
 */
class CallerCase extends Model
{
    /** @use HasFactory<CallerCaseFactory> */
    use HasFactory;

    protected $table = 'cases';

    protected $hidden = ['preferred_name_hash'];

    protected static function booted(): void
    {
        static::saving(function (self $case): void {
            if ($case->isDirty('preferred_name')) {
                $case->preferred_name_hash = self::nameFingerprint($case->preferred_name);
            }
        });
    }

    public static function nameFingerprint(?string $name): ?string
    {
        return filled($name) ? hash_hmac('sha256', mb_strtolower(trim($name)), config('app.key')) : null;
    }

    protected $fillable = ['interaction_id', 'assigned_to', 'association_verified_by', 'association_verified_at', 'status', 'priority', 'contact_kind', 'contact_kind_confirmed_at', 'concern_type', 'age_band', 'preferred_name', 'callback_number', 'guardian_name', 'guardian_relationship', 'guardian_confirmed', 'concern', 'onset', 'impact', 'next_step', 'disposition', 'closed_by', 'closed_at', 'revision'];

    protected function casts(): array
    {
        return [
            'association_verified_at' => 'immutable_datetime', 'contact_kind_confirmed_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'guardian_confirmed' => 'boolean', 'revision' => 'integer',
            'preferred_name' => 'encrypted', 'callback_number' => 'encrypted',
            'guardian_name' => 'encrypted', 'guardian_relationship' => 'encrypted',
            'concern' => 'encrypted', 'onset' => 'encrypted', 'impact' => 'encrypted', 'next_step' => 'encrypted',
        ];
    }

    /** @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->can('view all cases'), fn (Builder $query) => $query->where('assigned_to', $user->id));
    }

    /** @return BelongsTo<User, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return BelongsTo<Interaction, $this> */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }

    /** @return HasMany<Screening, $this> */
    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class, 'case_id');
    }

    /** @return HasMany<ConsentRecord, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(ConsentRecord::class, 'case_id');
    }

    /** @return HasMany<ScreeningDraft, $this> */
    public function screeningDrafts(): HasMany
    {
        return $this->hasMany(ScreeningDraft::class, 'case_id');
    }

    /** @return HasMany<CaseNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(CaseNote::class, 'case_id');
    }

    /** @return HasMany<Callback, $this> */
    public function callbacks(): HasMany
    {
        return $this->hasMany(Callback::class, 'case_id');
    }

    /** @return HasMany<Referral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(Referral::class, 'case_id');
    }

    /** @return HasMany<CaseAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(CaseAssignment::class, 'case_id');
    }
}
