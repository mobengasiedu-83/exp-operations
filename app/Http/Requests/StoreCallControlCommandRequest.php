<?php

namespace App\Http\Requests;

use App\Services\CallControl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCallControlCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view cases') && $this->user()->can('edit cases') && $this->user()->can('control calls');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'request_key' => ['required', 'uuid'],
            'action' => ['required', Rule::in(collect(CallControl::TRANSITIONS)->flatMap(fn (array $actions): array => array_keys($actions))->unique()->all())],
            'expected_version' => ['required', 'string', 'max:128'],
            'dentist_number_id' => ['exclude_unless:action,dial_dentist', 'required', 'integer', 'exists:dentist_numbers,id'],
            'human_accepted' => ['exclude_unless:action,complete_transfer', 'required', 'accepted', 'boolean'],
        ];
    }
}
