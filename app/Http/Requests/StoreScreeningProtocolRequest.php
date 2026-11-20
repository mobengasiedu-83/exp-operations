<?php

namespace App\Http\Requests;

use App\Models\ScreeningQuestion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreScreeningProtocolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage screening protocols') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'string', 'max:100', 'unique:screening_protocols,version'],
            'title' => ['required', 'string', 'max:255'],
            'privacy_notice' => ['required', 'string', 'max:5000'],
            'allow_guardian' => ['required', 'boolean'],
            'guidance' => ['required', 'array:P1,P2,P3,S,Review'],
            'guidance.P1' => ['required', 'string', 'max:5000'],
            'guidance.P2' => ['required', 'string', 'max:5000'],
            'guidance.P3' => ['required', 'string', 'max:5000'],
            'guidance.S' => ['required', 'string', 'max:5000'],
            'guidance.Review' => ['required', 'string', 'max:5000'],
            'faq_answer' => ['required', 'string', 'max:5000'],
            'questions' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'questions.*' => ['required', 'array:prompt,type,options,required,help_text,safety_rule,conditional_on_index,conditional_answer,positive_priority'],
            'questions.*.prompt' => ['required', 'string', 'max:1000'],
            'questions.*.type' => ['sometimes', Rule::in(ScreeningQuestion::TYPES)],
            'questions.*.options' => ['nullable', 'array', 'max:20'],
            'questions.*.options.*' => ['required', 'string', 'max:200'],
            'questions.*.required' => ['sometimes', 'boolean'],
            'questions.*.help_text' => ['nullable', 'string', 'max:1000'],
            'questions.*.safety_rule' => ['nullable', Rule::in(ScreeningQuestion::SAFETY_RULES)],
            'questions.*.conditional_on_index' => ['nullable', 'integer', 'min:0'],
            'questions.*.conditional_answer' => ['nullable', 'string', 'max:100'],
            'questions.*.positive_priority' => ['required', Rule::in(['P1', 'P2'])],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var list<array<string, mixed>> $questions */
            $questions = array_values($this->array('questions'));

            $hasSafetyQuestion = collect($questions)->contains(
                fn (array $question): bool => ($question['positive_priority'] ?? null) === 'P1'
                    || ($question['safety_rule'] ?? null) === 'emergency'
            );

            if (! $hasSafetyQuestion) {
                $validator->errors()->add('questions', 'Include at least one emergency safety question.');
            }

            foreach ($questions as $index => $question) {
                if (($question['type'] ?? 'yes_no_unknown') === 'choice' && empty($question['options'])) {
                    $validator->errors()->add("questions.{$index}.options", 'A choice question needs at least one option.');
                }

                $dependsOn = $question['conditional_on_index'] ?? null;

                if ($dependsOn !== null && (int) $dependsOn >= $index) {
                    $validator->errors()->add("questions.{$index}.conditional_on_index", 'A question can only follow an earlier question.');
                }

                if ($dependsOn !== null && empty($question['conditional_answer'])) {
                    $validator->errors()->add("questions.{$index}.conditional_answer", 'State the answer that reveals this question.');
                }
            }
        }];
    }
}
