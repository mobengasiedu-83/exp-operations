<?php

namespace App\Http\Requests;

use App\Services\TransferTracker;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordTransferEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view transfers') && $this->user()->can('edit transfers') && $this->user()->can('view cases');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'revision' => ['required', 'integer', 'min:1'],
            'kind' => ['required', Rule::in(['operator_outcome', 'fallback'])],
            'outcome' => ['required', Rule::in($this->input('kind') === 'fallback' ? TransferTracker::FALLBACKS : TransferTracker::OUTCOMES)],
            'occurred_at' => ['required', 'date'],
            'note' => ['required', 'string', 'max:2000'],
            'attested' => ['required', 'accepted'],
        ];
    }
}
