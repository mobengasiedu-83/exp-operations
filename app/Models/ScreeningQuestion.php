<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScreeningQuestion extends Model
{
    /** Answer types supported by an approved questionnaire version. */
    public const TYPES = ['yes_no', 'yes_no_unknown', 'text', 'paragraph', 'choice'];

    /** Safety rules that escalate an answer without leaving the questionnaire. */
    public const SAFETY_RULES = ['emergency', 'urgent'];

    protected $fillable = ['screening_protocol_id', 'prompt', 'type', 'options', 'required', 'help_text', 'safety_rule', 'conditional_question_id', 'conditional_answer', 'positive_priority', 'position'];

    protected function casts(): array
    {
        return ['options' => 'array', 'required' => 'boolean'];
    }

    /** @return array<string, string> */
    public static function typeLabels(): array
    {
        return [
            'yes_no' => 'Yes / No',
            'yes_no_unknown' => 'Yes / No / Unknown',
            'text' => 'Short text',
            'paragraph' => 'Long text',
            'choice' => 'Choice list',
        ];
    }

    /** @return BelongsTo<ScreeningProtocol, $this> */
    public function protocol(): BelongsTo
    {
        return $this->belongsTo(ScreeningProtocol::class, 'screening_protocol_id');
    }

    /** @return BelongsTo<ScreeningQuestion, $this> */
    public function conditionalQuestion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'conditional_question_id');
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? $this->type;
    }
}
