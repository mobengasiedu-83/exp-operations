<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autosaved questionnaire answers for one case and questionnaire version.
 *
 * Drafts never appear in screening history; recording the safety screen promotes
 * the answers into an immutable screening and removes the draft. A draft can
 * target the active version or the version already recorded for the caller's
 * granted consent, so activating a replacement version mid-call does not strand
 * answers that are already being captured.
 *
 * @property list<array<string, mixed>> $answers
 * @property int $revision
 * @property CarbonImmutable|null $draft_saved_at
 */
class ScreeningDraft extends Model
{
    protected $fillable = ['case_id', 'screening_protocol_id', 'recorded_by', 'priority', 'revision', 'answers', 'draft_saved_at'];

    protected function casts(): array
    {
        return ['answers' => 'encrypted:array', 'revision' => 'integer', 'draft_saved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<CallerCase, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CallerCase::class, 'case_id');
    }

    /** @return BelongsTo<ScreeningProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ScreeningProtocol::class, 'screening_protocol_id');
    }
}
